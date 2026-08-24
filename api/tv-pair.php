<?php
declare(strict_types=1);
require __DIR__ . '/tv-lib.php';

$input = auth_request_data(); $action = trim((string)($input['action'] ?? $_GET['action'] ?? ''));
if ($action === 'create') {
    $deviceId = tv_clean_id((string)($input['deviceId'] ?? '')); if ($deviceId === '') auth_json_response(['ok' => false, 'error' => 'DEVICE_ID_INVALID'], 400);
    $pairings = tv_active_pairings(); $now = time();
    foreach ($pairings as $i => $pair) if ((string)($pair['deviceId'] ?? '') === $deviceId && (string)($pair['status'] ?? '') === 'pending') unset($pairings[$i]);
    $id = tv_random_hex(16); $secret = tv_random_hex(24); $code = (string)random_int(100000, 999999);
    $pairings[] = ['id' => $id, 'codeHash' => password_hash($code, PASSWORD_DEFAULT), 'secretHash' => hash('sha256', $secret), 'deviceId' => $deviceId, 'deviceName' => tv_clean_name((string)($input['deviceName'] ?? 'Smart TV')), 'status' => 'pending', 'createdAt' => gmdate('c'), 'expiresAt' => $now + 300];
    tv_write_file(tv_pairings_path(), $pairings);
    auth_json_response(['ok' => true, 'pairId' => $id, 'secret' => $secret, 'code' => $code, 'expiresAt' => gmdate('c', $now + 300)]);
}
if ($action === 'inspect') {
    $user = auth_require_user(); $pairId = trim((string)($_GET['pair'] ?? '')); $code = trim((string)($_GET['code'] ?? ''));
    $pairings = tv_active_pairings(); $index = tv_find_pair($pairings, $pairId);
    if ($index < 0 || !password_verify($code, (string)($pairings[$index]['codeHash'] ?? ''))) auth_json_response(['ok' => false, 'error' => 'PAIR_INVALID_OR_EXPIRED'], 404);
    auth_json_response(['ok' => true, 'tv' => ['name' => (string)$pairings[$index]['deviceName'], 'code' => $code], 'account' => ['name' => (string)$user['name'], 'email' => (string)$user['email']]]);
}
if ($action === 'resolve') {
    $user = auth_require_user(); $code = trim((string)($input['code'] ?? ''));
    if (!preg_match('/^\d{6}$/', $code)) auth_json_response(['ok' => false, 'error' => 'CODE_INVALID'], 422);
    $now = time(); $attempts = array_values(array_filter((array)($_SESSION['tv_pair_code_attempts'] ?? []), fn($time) => (int)$time > $now - 600));
    if (count($attempts) >= 10) auth_json_response(['ok' => false, 'error' => 'TOO_MANY_ATTEMPTS'], 429);
    foreach (tv_active_pairings() as $pair) {
        if ((string)($pair['status'] ?? '') === 'pending' && password_verify($code, (string)($pair['codeHash'] ?? ''))) {
            $_SESSION['tv_pair_code_attempts'] = [];
            auth_json_response(['ok' => true, 'pairId' => (string)$pair['id'], 'tv' => ['name' => (string)$pair['deviceName'], 'code' => $code], 'account' => ['name' => (string)$user['name'], 'email' => (string)$user['email']]]);
        }
    }
    $attempts[] = $now; $_SESSION['tv_pair_code_attempts'] = $attempts;
    auth_json_response(['ok' => false, 'error' => 'PAIR_INVALID_OR_EXPIRED'], 404);
}
if ($action === 'claim') {
    $user = auth_require_user(); $pairId = trim((string)($input['pairId'] ?? '')); $code = trim((string)($input['code'] ?? ''));
    $pairings = tv_active_pairings(); $index = tv_find_pair($pairings, $pairId);
    if ($index < 0 || (int)$pairings[$index]['expiresAt'] < time() || (string)($pairings[$index]['status'] ?? '') !== 'pending' || !password_verify($code, (string)$pairings[$index]['codeHash'])) auth_json_response(['ok' => false, 'error' => 'PAIR_INVALID_OR_EXPIRED'], 404);
    $tvToken = tv_random_hex(32); $tvId = tv_random_hex(16); $now = gmdate('c');
    $devices = array_values(array_filter(tv_read_file(tv_devices_path()), fn($item) => !((string)($item['userId'] ?? '') === (string)$user['id'] && (string)($item['deviceId'] ?? '') === (string)$pairings[$index]['deviceId'])));
    $devices[] = ['id' => $tvId, 'userId' => (string)$user['id'], 'name' => (string)$pairings[$index]['deviceName'], 'deviceId' => (string)$pairings[$index]['deviceId'], 'tokenHash' => hash('sha256', $tvToken), 'status' => 'active', 'createdAt' => $now, 'lastSeenAt' => $now, 'lastSeenUnix' => time(), 'commandSeq' => 0, 'commands' => []];
    tv_write_file(tv_devices_path(), $devices);
    $pairings[$index]['status'] = 'paired'; $pairings[$index]['userId'] = (string)$user['id']; $pairings[$index]['tvDeviceId'] = $tvId; $pairings[$index]['tvToken'] = $tvToken; $pairings[$index]['pairedAt'] = $now; $pairings[$index]['expiresAt'] = time() + 600;
    tv_write_file(tv_pairings_path(), $pairings); auth_json_response(['ok' => true, 'device' => tv_public_device($devices[array_key_last($devices)])]);
}
if ($action === 'status') {
    $pairId = trim((string)($input['pairId'] ?? '')); $secret = trim((string)($input['secret'] ?? '')); $pairings = tv_active_pairings(); $index = tv_find_pair($pairings, $pairId);
    if ($index < 0 || !hash_equals((string)($pairings[$index]['secretHash'] ?? ''), hash('sha256', $secret))) auth_json_response(['ok' => false, 'error' => 'PAIR_INVALID'], 403);
    $pair = $pairings[$index]; if ((string)$pair['status'] !== 'paired') auth_json_response(['ok' => true, 'status' => 'pending', 'expiresAt' => gmdate('c', (int)$pair['expiresAt'])]);
    session_regenerate_id(true); $_SESSION['user_id'] = (string)$pair['userId'];
    auth_json_response(['ok' => true, 'status' => 'paired', 'deviceId' => (string)$pair['tvDeviceId'], 'deviceToken' => (string)$pair['tvToken']]);
}
if ($action === 'resume') {
    $deviceId = trim((string)($input['deviceId'] ?? '')); $token = trim((string)($input['deviceToken'] ?? '')); $devices = tv_read_file(tv_devices_path()); $index = tv_find_device($devices, $deviceId);
    if ($index < 0 || (string)($devices[$index]['status'] ?? '') !== 'active' || !hash_equals((string)($devices[$index]['tokenHash'] ?? ''), hash('sha256', $token)) || !tv_device_trusted($devices[$index])) auth_json_response(['ok' => false, 'error' => 'TV_AUTH_INVALID'], 401);
    session_regenerate_id(true); $_SESSION['user_id'] = (string)$devices[$index]['userId'];
    auth_json_response(['ok' => true, 'status' => 'connected', 'device' => tv_public_device($devices[$index])]);
}
auth_json_response(['ok' => false, 'error' => 'ACTION_INVALID'], 400);
