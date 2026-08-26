<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/auth/_lib.php';

const MIRROR_CODE_TTL = 60;
const MIRROR_SESSION_TTL = 21600;
const MIRROR_MAX_SIGNALS = 180;
const MIRROR_MAX_COMMANDS = 100;

function mirror_path(): string { return auth_private_dir() . DIRECTORY_SEPARATOR . 'mirror-sessions.json'; }
function mirror_lock_path(): string { return mirror_path() . '.lock'; }
function mirror_random(int $bytes = 24): string { return bin2hex(random_bytes($bytes)); }
function mirror_now(): int { return time(); }
function mirror_client_ip(): string { return (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'); }

function mirror_lock() {
    $dir = auth_private_dir();
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $handle = @fopen(mirror_lock_path(), 'c');
    if (!$handle || !@flock($handle, LOCK_EX)) auth_json_response(['ok' => false, 'error' => 'STORAGE_BUSY'], 503);
    return $handle;
}

function mirror_unlock($handle): void {
    if (is_resource($handle)) { @flock($handle, LOCK_UN); @fclose($handle); }
}

function mirror_load(): array {
    $raw = is_file(mirror_path()) ? @file_get_contents(mirror_path()) : false;
    $data = is_string($raw) ? json_decode($raw, true) : [];
    if (!is_array($data)) $data = [];
    $now = mirror_now();
    return array_values(array_filter($data, static fn($s) => (int)($s['sessionExpiresAt'] ?? 0) > $now));
}

function mirror_save(array $sessions): void {
    $path = mirror_path(); $tmp = $path . '.tmp';
    $json = json_encode(array_values($sessions), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false || @file_put_contents($tmp, $json, LOCK_EX) === false || !@rename($tmp, $path)) {
        auth_json_response(['ok' => false, 'error' => 'STORAGE_WRITE_FAILED'], 500);
    }
    @chmod($path, 0600);
}

function mirror_find(array $sessions, string $id): int {
    foreach ($sessions as $i => $session) if (hash_equals((string)($session['id'] ?? ''), $id)) return (int)$i;
    return -1;
}

function mirror_clean_name($value, string $fallback): string {
    $name = trim((string)$value);
    $name = preg_replace('/[^\pL\pN ._()\/-]+/u', '', $name) ?: '';
    return substr($name !== '' ? $name : $fallback, 0, 60);
}

function mirror_auth(array $session, string $role, string $secret): bool {
    $field = $role === 'receiver' ? 'receiverSecretHash' : 'senderSecretHash';
    $expected = (string)($session[$field] ?? '');
    return $expected !== '' && $secret !== '' && hash_equals($expected, hash('sha256', $secret));
}

function mirror_unique_code(array $sessions): string {
    for ($try = 0; $try < 40; $try++) {
        $code = str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        $used = false;
        foreach ($sessions as $session) {
            if ((int)($session['codeExpiresAt'] ?? 0) > mirror_now() && hash_equals((string)($session['codeHash'] ?? ''), hash('sha256', $code))) { $used = true; break; }
        }
        if (!$used) return $code;
    }
    auth_json_response(['ok' => false, 'error' => 'CODE_POOL_BUSY'], 503);
}

function mirror_rate_limit(bool $failed = false): void {
    if (!auth_rate_limit('mirror_join')) auth_json_response(['ok' => false, 'error' => 'TOO_MANY_ATTEMPTS'], 429);
    if (!isset($_SESSION['mirror_join_attempts']) || !is_array($_SESSION['mirror_join_attempts'])) $_SESSION['mirror_join_attempts'] = [];
    $now = mirror_now();
    $_SESSION['mirror_join_attempts'] = array_values(array_filter($_SESSION['mirror_join_attempts'], static fn($t) => (int)$t > $now - 300));
    if (count($_SESSION['mirror_join_attempts']) >= 12) auth_json_response(['ok' => false, 'error' => 'TOO_MANY_ATTEMPTS'], 429);
    if ($failed) { $_SESSION['mirror_join_attempts'][] = $now; auth_rate_limit('mirror_join', false); }
}

$input = auth_request_data();
$action = trim((string)($input['action'] ?? $_GET['action'] ?? ''));
$lock = mirror_lock();
$sessions = mirror_load();

if ($action === 'create') {
    $code = mirror_unique_code($sessions); $id = mirror_random(16); $joinToken = mirror_random(24); $receiverSecret = mirror_random(32); $now = mirror_now();
    $sessions[] = [
        'id' => $id, 'codeHash' => hash('sha256', $code), 'joinTokenHash' => hash('sha256', $joinToken),
        'receiverSecretHash' => hash('sha256', $receiverSecret), 'senderSecretHash' => '',
        'receiverName' => mirror_clean_name($input['deviceName'] ?? '', 'Schermo TubeTV'), 'senderName' => '',
        'status' => 'waiting', 'createdAt' => $now, 'codeExpiresAt' => $now + MIRROR_CODE_TTL,
        'sessionExpiresAt' => $now + MIRROR_SESSION_TTL, 'lastReceiverSeen' => $now, 'lastSenderSeen' => 0,
        'signals' => [], 'commands' => [], 'signalSeq' => 0, 'commandSeq' => 0
    ];
    mirror_save($sessions); mirror_unlock($lock);
    auth_json_response(['ok' => true, 'sessionId' => $id, 'receiverSecret' => $receiverSecret, 'joinToken' => $joinToken, 'code' => $code, 'codeExpiresAt' => $now + MIRROR_CODE_TTL]);
}

if ($action === 'join') {
    mirror_rate_limit(); $code = trim((string)($input['code'] ?? '')); $joinToken = trim((string)($input['joinToken'] ?? '')); $index = -1; $now = mirror_now();
    foreach ($sessions as $i => $session) {
        if ((int)($session['codeExpiresAt'] ?? 0) < $now || (string)($session['status'] ?? '') !== 'waiting') continue;
        $tokenOk = $joinToken !== '' && hash_equals((string)$session['joinTokenHash'], hash('sha256', $joinToken));
        $codeOk = preg_match('/^\d{4}$/', $code) && hash_equals((string)$session['codeHash'], hash('sha256', $code));
        if ($tokenOk || $codeOk) { $index = (int)$i; break; }
    }
    if ($index < 0) { mirror_rate_limit(true); mirror_unlock($lock); auth_json_response(['ok' => false, 'error' => 'CODE_INVALID_OR_EXPIRED'], 404); }
    $senderSecret = mirror_random(32); $sessions[$index]['senderSecretHash'] = hash('sha256', $senderSecret); $sessions[$index]['senderName'] = mirror_clean_name($input['deviceName'] ?? '', 'Telecomando');
    $sessions[$index]['status'] = 'paired'; $sessions[$index]['lastSenderSeen'] = $now; $sessions[$index]['sessionExpiresAt'] = $now + MIRROR_SESSION_TTL;
    $_SESSION['mirror_join_attempts'] = []; auth_rate_limit('mirror_join', true); mirror_save($sessions); mirror_unlock($lock);
    auth_json_response(['ok' => true, 'sessionId' => (string)$sessions[$index]['id'], 'senderSecret' => $senderSecret, 'receiverName' => (string)$sessions[$index]['receiverName']]);
}

$id = trim((string)($input['sessionId'] ?? $_GET['sessionId'] ?? ''));
$role = trim((string)($input['role'] ?? $_GET['role'] ?? ''));
$secret = trim((string)($input['secret'] ?? $_GET['secret'] ?? ''));
$index = mirror_find($sessions, $id);
if ($index < 0 || !in_array($role, ['receiver', 'sender'], true) || !mirror_auth($sessions[$index], $role, $secret)) {
    mirror_unlock($lock); auth_json_response(['ok' => false, 'error' => 'SESSION_UNAUTHORIZED'], 401);
}
$now = mirror_now();
$seenField = $role === 'receiver' ? 'lastReceiverSeen' : 'lastSenderSeen';
$sessions[$index][$seenField] = $now; $sessions[$index]['sessionExpiresAt'] = $now + MIRROR_SESSION_TTL;

if ($action === 'status') {
    $peerSeen = (int)($sessions[$index][$role === 'receiver' ? 'lastSenderSeen' : 'lastReceiverSeen'] ?? 0);
    $response = ['ok' => true, 'status' => (string)$sessions[$index]['status'], 'peerOnline' => $peerSeen > $now - 12, 'senderName' => (string)$sessions[$index]['senderName'], 'receiverName' => (string)$sessions[$index]['receiverName']];
    mirror_save($sessions); mirror_unlock($lock); auth_json_response($response);
}

if ($action === 'signal') {
    $payload = $input['payload'] ?? null;
    if (!is_array($payload) || strlen((string)json_encode($payload)) > 200000) { mirror_unlock($lock); auth_json_response(['ok' => false, 'error' => 'SIGNAL_INVALID'], 422); }
    $sessions[$index]['signalSeq'] = (int)$sessions[$index]['signalSeq'] + 1;
    $sessions[$index]['signals'][] = ['seq' => (int)$sessions[$index]['signalSeq'], 'from' => $role, 'payload' => $payload, 'at' => $now];
    $sessions[$index]['signals'] = array_slice($sessions[$index]['signals'], -MIRROR_MAX_SIGNALS);
    mirror_save($sessions); mirror_unlock($lock); auth_json_response(['ok' => true, 'seq' => (int)$sessions[$index]['signalSeq']]);
}

if ($action === 'poll') {
    $afterSignal = max(0, (int)($input['afterSignal'] ?? $_GET['afterSignal'] ?? 0));
    $afterCommand = max(0, (int)($input['afterCommand'] ?? $_GET['afterCommand'] ?? 0));
    $signals = array_values(array_filter($sessions[$index]['signals'], static fn($s) => (int)$s['seq'] > $afterSignal && (string)$s['from'] !== $role));
    $commands = $role === 'receiver' ? array_values(array_filter($sessions[$index]['commands'], static fn($c) => (int)$c['seq'] > $afterCommand)) : [];
    $peerSeen = (int)($sessions[$index][$role === 'receiver' ? 'lastSenderSeen' : 'lastReceiverSeen'] ?? 0);
    mirror_save($sessions); mirror_unlock($lock);
    auth_json_response(['ok' => true, 'status' => (string)$sessions[$index]['status'], 'peerOnline' => $peerSeen > $now - 12, 'signals' => $signals, 'commands' => $commands]);
}

if ($action === 'command' && $role === 'sender') {
    $command = trim((string)($input['command'] ?? '')); $value = $input['value'] ?? null;
    $allowed = ['up','down','left','right','select','back','home','playPause','mute','volumeUp','volumeDown','next','previous','app'];
    if (!in_array($command, $allowed, true)) { mirror_unlock($lock); auth_json_response(['ok' => false, 'error' => 'COMMAND_INVALID'], 422); }
    if (is_string($value)) $value = substr($value, 0, 300); elseif (!is_null($value)) $value = null;
    $sessions[$index]['commandSeq'] = (int)$sessions[$index]['commandSeq'] + 1;
    $sessions[$index]['commands'][] = ['seq' => (int)$sessions[$index]['commandSeq'], 'command' => $command, 'value' => $value, 'at' => $now];
    $sessions[$index]['commands'] = array_slice($sessions[$index]['commands'], -MIRROR_MAX_COMMANDS);
    mirror_save($sessions); mirror_unlock($lock); auth_json_response(['ok' => true]);
}

if ($action === 'close') {
    $sessions[$index]['status'] = 'closed'; mirror_save($sessions); mirror_unlock($lock); auth_json_response(['ok' => true]);
}

mirror_save($sessions); mirror_unlock($lock);
auth_json_response(['ok' => false, 'error' => 'ACTION_INVALID'], 400);
