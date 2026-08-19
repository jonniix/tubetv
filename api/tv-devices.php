<?php
declare(strict_types=1);
require __DIR__ . '/tv-lib.php';
$user = auth_require_user(); $deviceLock = tv_devices_lock(); $devices = tv_read_file(tv_devices_path());
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') { $mine = array_values(array_filter($devices, fn($item) => (string)($item['userId'] ?? '') === (string)$user['id'] && (string)($item['status'] ?? '') === 'active' && tv_device_trusted($item))); auth_json_response(['ok' => true, 'devices' => array_map('tv_public_device', $mine)]); }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') auth_json_response(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED'], 405);
$input = auth_request_data(); $id = trim((string)($input['deviceId'] ?? '')); $action = trim((string)($input['action'] ?? 'command')); $command = trim((string)($input['command'] ?? ''));
$index = tv_find_device($devices, $id); if ($index < 0 || (string)$devices[$index]['userId'] !== (string)$user['id'] || (string)$devices[$index]['status'] !== 'active' || !tv_device_trusted($devices[$index])) auth_json_response(['ok' => false, 'error' => 'TV_NOT_FOUND'], 404);
if ($action === 'create_remote_pair') {
    $code = (string)random_int(1000, 9999); $pairId = tv_random_hex(12); $expires = time() + 180;
    $devices[$index]['remotePair'] = ['id' => $pairId, 'codeHash' => password_hash($code, PASSWORD_DEFAULT), 'expiresAt' => $expires];
    $seq = (int)($devices[$index]['commandSeq'] ?? 0) + 1; $devices[$index]['commandSeq'] = $seq;
    $devices[$index]['commands'][] = ['seq' => $seq, 'command' => 'REMOTE_CODE', 'payload' => ['code' => $code, 'expiresAt' => $expires], 'createdAt' => gmdate('c')];
    $devices[$index]['commands'] = array_slice($devices[$index]['commands'], -30); tv_write_file(tv_devices_path(), $devices); tv_devices_unlock($deviceLock);
    auth_json_response(['ok' => true, 'pairId' => $pairId, 'expiresAt' => gmdate('c', $expires)]);
}
if ($action === 'verify_remote_pair') {
    $pair = is_array($devices[$index]['remotePair'] ?? null) ? $devices[$index]['remotePair'] : []; $code = trim((string)($input['code'] ?? '')); $pairId = trim((string)($input['pairId'] ?? ''));
    if ((string)($pair['id'] ?? '') !== $pairId || (int)($pair['expiresAt'] ?? 0) < time() || !preg_match('/^\d{4}$/', $code) || !password_verify($code, (string)($pair['codeHash'] ?? ''))) auth_json_response(['ok' => false, 'error' => 'REMOTE_CODE_INVALID'], 401);
    $remoteToken = tv_random_hex(32); $devices[$index]['remoteTokenHash'] = hash('sha256', $remoteToken); $devices[$index]['remoteTokenExpiresAt'] = time() + 43200; unset($devices[$index]['remotePair']);
    $seq = (int)($devices[$index]['commandSeq'] ?? 0) + 1; $devices[$index]['commandSeq'] = $seq;
    $devices[$index]['commands'][] = ['seq' => $seq, 'command' => 'REMOTE_PAIRED', 'payload' => [], 'createdAt' => gmdate('c')];
    $devices[$index]['commands'] = array_slice($devices[$index]['commands'], -30);
    tv_write_file(tv_devices_path(), $devices); tv_devices_unlock($deviceLock); auth_json_response(['ok' => true, 'remoteToken' => $remoteToken, 'expiresAt' => gmdate('c', time() + 43200)]);
}
$allowed = ['HOME','TV','LIVE','TV_LITE','OPEN_IPTV','PLAY_PAUSE','VOLUME_UP','VOLUME_DOWN','BACK','UP','DOWN','LEFT','RIGHT','OK'];
if (!in_array($command, $allowed, true)) auth_json_response(['ok' => false, 'error' => 'COMMAND_INVALID'], 400);
// The authenticated account already owns this approved TV. No second 4-digit pairing is required.
$payload = is_array($input['payload'] ?? null) ? $input['payload'] : [];
if ($command === 'OPEN_IPTV') $payload = ['session' => substr(trim((string)($payload['session'] ?? '')), 0, 128), 'channel' => is_array($payload['channel'] ?? null) ? array_intersect_key($payload['channel'], array_flip(['id','name','logo','group','format'])) : []]; else $payload = [];
$seq = (int)($devices[$index]['commandSeq'] ?? 0) + 1; $devices[$index]['commandSeq'] = $seq; $devices[$index]['commands'][] = ['seq' => $seq, 'command' => $command, 'payload' => $payload, 'createdAt' => gmdate('c')]; $devices[$index]['commands'] = array_slice($devices[$index]['commands'], -30); tv_write_file(tv_devices_path(), $devices); tv_devices_unlock($deviceLock);
auth_json_response(['ok' => true, 'seq' => $seq]);
