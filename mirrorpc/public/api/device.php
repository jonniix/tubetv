<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

$data = request_data();
$action = (string)($data['action'] ?? '');
$deviceId = preg_replace('/\D/', '', (string)($data['deviceId'] ?? ''));
if (!preg_match('/^\d{12}$/', $deviceId)) json_response(['message' => 'ID PC non valido'], 400);

$registryDir = runtime_dir() . '/device-registry';
if (!is_dir($registryDir) && !mkdir($registryDir, 0700, true) && !is_dir($registryDir)) json_response(['message' => 'Registro dispositivi non disponibile'], 500);
$devicePath = $registryDir . '/' . hash('sha256', $deviceId) . '.json';

if ($action === 'register') {
    $token = (string)($data['token'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_-]{40,64}$/', $token)) json_response(['message' => 'Token dispositivo non valido'], 403);
    $sessionCode = (string)($data['sessionCode'] ?? '');
    if ($sessionCode !== '' && !preg_match('/^\d{6}$/', $sessionCode)) json_response(['message' => 'Sessione non valida'], 400);
    $handle = fopen($devicePath, 'c+');
    if (!$handle || !flock($handle, LOCK_EX)) json_response(['message' => 'Registro occupato'], 503);
    rewind($handle); $raw = stream_get_contents($handle); $saved = $raw ? json_decode($raw, true) : null;
    $tokenHash = hash('sha256', $token);
    if (is_array($saved) && !safe_equal($saved['tokenHash'] ?? '', $tokenHash)) {
        flock($handle, LOCK_UN); fclose($handle); json_response(['message' => 'Dispositivo non autorizzato'], 403);
    }
    $record = ['deviceId' => $deviceId, 'tokenHash' => $tokenHash, 'sessionCode' => $sessionCode, 'lastSeen' => time()];
    rewind($handle); ftruncate($handle, 0); fwrite($handle, json_encode($record)); fflush($handle); flock($handle, LOCK_UN); fclose($handle);
    json_response(['ok' => true, 'registered' => true]);
}

if ($action === 'resolve') {
    enforce_join_rate_limit();
    if (!is_file($devicePath)) json_response(['message' => 'PC non registrato'], 404);
    $record = json_decode((string)file_get_contents($devicePath), true);
    if (!is_array($record) || (int)($record['lastSeen'] ?? 0) < time() - 90) json_response(['message' => 'PC offline'], 409);
    $code = (string)($record['sessionCode'] ?? '');
    if (!preg_match('/^\d{6}$/', $code)) json_response(['message' => 'PC online, ma nessuna condivisione Ã¨ attiva'], 409);
    $sessionFile = session_path($code);
    $session = is_file($sessionFile) ? json_decode((string)file_get_contents($sessionFile), true) : null;
    if (!is_array($session) || empty($session['hostRegistered']) || (int)($session['expiresAt'] ?? 0) < time()) {
        json_response(['message' => 'La condivisione del PC non Ã¨ ancora pronta'], 409);
    }
    json_response(['ok' => true, 'code' => $code]);
}

json_response(['message' => 'Azione non valida'], 400);

