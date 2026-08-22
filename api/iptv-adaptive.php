<?php
declare(strict_types=1);
require __DIR__ . '/iptv-lib.php';

$token = trim((string)($_GET['session'] ?? ''));
$channelId = preg_replace('/[^0-9]/', '', (string)($_GET['channel'] ?? '')) ?? '';
$asset = trim((string)($_GET['file'] ?? 'master.m3u8'));
$session = iptv_load_session($token);
$sourceUrl = $session ? (string)($session['channels'][$channelId] ?? '') : '';
if (!$session) { http_response_code(401); exit('IPTV_SESSION_EXPIRED'); }
if ($channelId === '' || $sourceUrl === '' || !iptv_url_allowed($sourceUrl)) { http_response_code(404); exit('IPTV_STREAM_NOT_FOUND'); }
if (!preg_match('~^(?:master\.m3u8|(?:sd|hd|fullhd)/(?:index\.m3u8|segment_[0-9]+\.ts))$~', $asset)) { http_response_code(400); exit('IPTV_ADAPTIVE_ASSET_INVALID'); }

$workerConfigPath = iptv_private_dir() . DIRECTORY_SEPARATOR . 'desktop-worker.json';
$workerConfig = is_file($workerConfigPath) ? json_decode((string)@file_get_contents($workerConfigPath), true) : [];
$workerUrl = is_array($workerConfig) ? rtrim(trim((string)($workerConfig['url'] ?? '')), '/') : '';
$workerSecret = is_array($workerConfig) ? trim((string)($workerConfig['secret'] ?? '')) : '';
$workerEnabled = is_array($workerConfig) && !empty($workerConfig['enabled']) && $workerUrl !== '' && strlen($workerSecret) >= 32;
$fallback = static function() use ($token, $channelId): never {
    header('Location: iptv-stream.php?session=' . rawurlencode($token) . '&channel=' . rawurlencode($channelId), true, 307);
    exit;
};
if (!$workerEnabled || !function_exists('curl_init')) $fallback();

$viewer = substr(hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? '') . '|' . (string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 16);
function adaptive_worker_call(string $workerUrl, string $secret, string $method, string $path, string $body = '', int $timeout = 20): array {
    $timestamp = (string)round(microtime(true) * 1000);
    $signed = $method === 'GET' ? $timestamp . "\n" . $path : $timestamp . "\n" . $body;
    $signature = hash_hmac('sha256', $signed, $secret);
    $contentType = ''; $headers = [];
    $curl = curl_init($workerUrl . $path);
    $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT_MS => 1400, CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => ['X-TubeTV-Timestamp: ' . $timestamp, 'X-TubeTV-Signature: ' . $signature, 'Accept: */*'],
        CURLOPT_HEADERFUNCTION => static function($handle, string $line) use (&$contentType, &$headers): int {
            $trimmed = trim($line);
            if (preg_match('/^Content-Type:\s*([^;\r\n]+)/i', $trimmed, $m)) $contentType = strtolower(trim($m[1]));
            if (str_contains($trimmed, ':')) { [$name, $value] = array_map('trim', explode(':', $trimmed, 2)); $headers[strtolower($name)] = $value; }
            return strlen($line);
        }];
    if ($method === 'POST') { $options[CURLOPT_POST] = true; $options[CURLOPT_POSTFIELDS] = $body; $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json'; }
    curl_setopt_array($curl, $options);
    $responseBody = curl_exec($curl); $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); $error = (string)curl_error($curl); curl_close($curl);
    return ['ok' => is_string($responseBody) && $status >= 200 && $status < 300, 'status' => $status, 'body' => is_string($responseBody) ? $responseBody : '', 'type' => $contentType, 'headers' => $headers, 'error' => $error];
}

$job = is_array($session['adaptiveJobs'][$channelId] ?? null) ? $session['adaptiveJobs'][$channelId] : [];
$jobId = preg_match('/^[a-f0-9]{32}$/', (string)($job['id'] ?? '')) ? (string)$job['id'] : '';
if ($asset === 'master.m3u8' || $jobId === '') {
    $body = json_encode(['url' => $sourceUrl, 'viewer' => $viewer], JSON_UNESCAPED_SLASHES);
    $started = is_string($body) ? adaptive_worker_call($workerUrl, $workerSecret, 'POST', '/hls/start', $body, 22) : ['ok' => false];
    $data = !empty($started['ok']) ? json_decode((string)$started['body'], true) : null;
    $jobId = is_array($data) && preg_match('/^[a-f0-9]{32}$/', (string)($data['id'] ?? '')) ? (string)$data['id'] : '';
    if ($jobId === '') $fallback();
    $session['adaptiveJobs'][$channelId] = ['id' => $jobId, 'updatedAt' => time(), 'mode' => 'desktop-nvenc'];
    iptv_save_session($token, $session);
}

$workerPath = '/hls/' . $jobId . '/' . $asset . '?viewer=' . rawurlencode($viewer);
$result = adaptive_worker_call($workerUrl, $workerSecret, 'GET', $workerPath, '', str_ends_with($asset, '.m3u8') ? 8 : 25);
if (!$result['ok']) {
    if (str_ends_with($asset, '.m3u8')) $fallback();
    http_response_code((int)($result['status'] ?? 0) === 404 ? 404 : 502); exit('IPTV_ADAPTIVE_ASSET_UNAVAILABLE');
}

$content = (string)$result['body'];
if (str_ends_with($asset, '.m3u8')) {
    $baseDir = $asset === 'master.m3u8' ? '' : dirname($asset) . '/';
    $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
    foreach ($lines as &$line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) continue;
        $resolved = $baseDir . $trimmed;
        $line = 'iptv-adaptive.php?session=' . rawurlencode($token) . '&channel=' . rawurlencode($channelId) . '&file=' . rawurlencode($resolved);
    }
    unset($line); $content = implode("\n", $lines);
    header('Content-Type: application/vnd.apple.mpegurl'); header('Cache-Control: no-store');
} else {
    header('Content-Type: video/mp2t'); header('Cache-Control: private, max-age=90, immutable'); header('Content-Length: ' . strlen($content));
}
header('X-TubeTV-Delivery: desktop-adaptive'); header('X-TubeTV-Profiles: 480p,720p,1080p'); header('X-Content-Type-Options: nosniff');
$GLOBALS['IPTV_METRIC_BYTES'] = strlen($content); $GLOBALS['IPTV_METRIC_CACHE'] = 1; echo $content;
