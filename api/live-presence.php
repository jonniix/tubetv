<?php
error_reporting(0);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    echo json_encode(['ok' => true]);
    exit;
}

$root = dirname(__DIR__);
$dataDir = $root . DIRECTORY_SEPARATOR . 'data';
$file = $dataDir . DIRECTORY_SEPARATOR . 'live-presence.json';

if (!is_dir($dataDir)) {
    mkdir($dataDir, 0775, true);
}

function lp_now_iso(): string {
    return gmdate('Y-m-d\TH:i:s') . '.000Z';
}

function lp_detect_device(string $ua): string {
    $u = strtolower($ua);
    if (strpos($u, 'ipad') !== false || strpos($u, 'tablet') !== false) return 'tablet';
    if (strpos($u, 'smart-tv') !== false || strpos($u, 'smarttv') !== false || strpos($u, 'hbbtv') !== false || strpos($u, 'netcast') !== false) return 'smartTv';
    if (strpos($u, 'mobile') !== false || strpos($u, 'android') !== false || strpos($u, 'iphone') !== false) return 'mobile';
    return 'desktop';
}

function lp_read(string $file): array {
    if (!is_file($file)) {
        return [
            'sessions' => [],
            'stats' => [],
            'createdAt' => lp_now_iso(),
            'updatedAt' => null
        ];
    }

    $raw = file_get_contents($file);
    $json = json_decode($raw, true);

    if (!is_array($json)) {
        return [
            'sessions' => [],
            'stats' => [],
            'createdAt' => lp_now_iso(),
            'updatedAt' => null,
            'corruptedRecoveredAt' => lp_now_iso()
        ];
    }

    if (!isset($json['sessions']) || !is_array($json['sessions'])) $json['sessions'] = [];
    if (!isset($json['stats']) || !is_array($json['stats'])) $json['stats'] = [];

    return $json;
}

function lp_write(string $file, array $data): bool {
    $data['updatedAt'] = lp_now_iso();
    return file_put_contents(
        $file,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    ) !== false;
}

function lp_clean_session_id(string $id): string {
    $id = preg_replace('/[^a-zA-Z0-9_\-]/', '', $id);
    return substr($id ?: bin2hex(random_bytes(12)), 0, 80);
}

$data = lp_read($file);
$now = time();
$today = gmdate('Y-m-d');

if (!isset($data['stats'][$today])) {
    $data['stats'][$today] = [
        'viewsToday' => 0,
        'peakToday' => 0,
        'uniqueToday' => []
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = [];

    $sessionId = lp_clean_session_id((string)($input['sessionId'] ?? ''));
    $currentVideoId = trim((string)($input['currentVideoId'] ?? ''));
    $page = trim((string)($input['page'] ?? 'live'));

    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $device = lp_detect_device($ua);
    $ipHash = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . $ua);

    $isNew = !isset($data['sessions'][$sessionId]);

    $data['sessions'][$sessionId] = [
        'sessionId' => $sessionId,
        'page' => $page,
        'currentVideoId' => $currentVideoId,
        'firstSeenAt' => $data['sessions'][$sessionId]['firstSeenAt'] ?? $now,
        'lastSeenAt' => $now,
        'lastSeenIso' => lp_now_iso(),
        'userAgent' => $ua,
        'device' => $device,
        'ipHash' => $ipHash,
        'referrer' => $_SERVER['HTTP_REFERER'] ?? ''
    ];

    if ($isNew) {
        $data['stats'][$today]['viewsToday'] = intval($data['stats'][$today]['viewsToday'] ?? 0) + 1;
    }

    if (!in_array($sessionId, $data['stats'][$today]['uniqueToday'], true)) {
        $data['stats'][$today]['uniqueToday'][] = $sessionId;
    }
}

$activeCutoff = $now - 45;
$cutoff5m = $now - 300;
$cutoff30m = $now - 1800;
$cleanupCutoff = $now - 86400;

$active = [];
$viewers5m = [];
$viewers30m = [];
$devices = [
    'mobile' => 0,
    'desktop' => 0,
    'tablet' => 0,
    'smartTv' => 0
];
$byVideo = [];

foreach ($data['sessions'] as $sid => $s) {
    $last = intval($s['lastSeenAt'] ?? 0);

    if ($last < $cleanupCutoff) {
        unset($data['sessions'][$sid]);
        continue;
    }

    if ($last >= $activeCutoff) {
        $active[$sid] = $s;
        $device = $s['device'] ?? 'desktop';
        if (!isset($devices[$device])) $devices[$device] = 0;
        $devices[$device]++;

        $vid = (string)($s['currentVideoId'] ?? '');
        if ($vid !== '') {
            if (!isset($byVideo[$vid])) $byVideo[$vid] = 0;
            $byVideo[$vid]++;
        }
    }

    if ($last >= $cutoff5m) $viewers5m[$sid] = $s;
    if ($last >= $cutoff30m) $viewers30m[$sid] = $s;
}

$data['stats'][$today]['peakToday'] = max(
    intval($data['stats'][$today]['peakToday'] ?? 0),
    count($active)
);

lp_write($file, $data);

$response = [
    'ok' => true,
    'analytics' => [
        'liveUsers' => count($active),
        'audienceNow' => count($active),
        'activeSessions' => count($active),
        'viewers5m' => count($viewers5m),
        'viewers30m' => count($viewers30m),
        'peakToday' => intval($data['stats'][$today]['peakToday'] ?? 0),
        'audiencePeak24h' => intval($data['stats'][$today]['peakToday'] ?? 0),
        'viewsToday' => intval($data['stats'][$today]['viewsToday'] ?? 0),
        'uniqueToday' => count($data['stats'][$today]['uniqueToday'] ?? []),
        'devices' => $devices,
        'byVideo' => $byVideo,
        'updatedAt' => lp_now_iso()
    ],
    'serverNow' => lp_now_iso(),
    'file' => $file,
    'writable' => is_writable($dataDir)
];

echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
