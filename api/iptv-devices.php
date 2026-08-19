<?php
declare(strict_types=1);
require __DIR__ . '/iptv-lib.php';
require __DIR__ . '/auth/_lib.php';

iptv_require_admin();
$users = auth_read_users(); $userMap = [];
foreach ($users as $user) $userMap[(string)($user['id'] ?? '')] = ['name' => (string)($user['name'] ?? ''), 'email' => (string)($user['email'] ?? '')];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $devices = iptv_read_devices();
    $activeByDevice = [];
    foreach (glob(iptv_session_dir() . DIRECTORY_SEPARATOR . '*.json') ?: [] as $sessionPath) {
        $activeSession = json_decode((string)@file_get_contents($sessionPath), true);
        if (!is_array($activeSession) || (int)($activeSession['expiresAt'] ?? 0) < time() || (int)@filemtime($sessionPath) < time() - 120) continue;
        $recordId = (string)($activeSession['deviceRecordId'] ?? '');
        if ($recordId !== '') $activeByDevice[$recordId] = (int)($activeByDevice[$recordId] ?? 0) + 1;
    }
    foreach ($devices as &$device) {
        $account = $userMap[(string)($device['userId'] ?? '')] ?? ['name' => 'Account rimosso', 'email' => ''];
        $device['accountName'] = $account['name']; $device['accountEmail'] = $account['email'];
        $device['deviceCode'] = strtoupper(substr((string)($device['id'] ?? ''), -8));
        $device['activeSessions'] = (int)($activeByDevice[(string)($device['id'] ?? '')] ?? 0);
        unset($device['deviceId'], $device['ipHash']);
    }
    unset($device);
    usort($devices, fn($a, $b) => strcmp((string)($b['lastSeenAt'] ?? ''), (string)($a['lastSeenAt'] ?? '')));
    iptv_json(['ok' => true, 'devices' => $devices]);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') iptv_json(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED'], 405);
$input = iptv_input(); $id = trim((string)($input['id'] ?? '')); $action = trim((string)($input['action'] ?? ''));
if (!preg_match('/^[a-f0-9]{32}$/', $id)) iptv_json(['ok' => false, 'error' => 'DEVICE_INVALID'], 400);
$devices = iptv_read_devices(); $found = false;
foreach ($devices as $index => &$device) {
    if ((string)($device['id'] ?? '') !== $id) continue;
    $found = true;
    if ($action === 'delete') { array_splice($devices, $index, 1); }
    elseif (in_array($action, ['approve', 'block', 'pending'], true)) {
        $device['status'] = $action === 'approve' ? 'approved' : ($action === 'block' ? 'blocked' : 'pending');
        $device['statusUpdatedAt'] = gmdate('c');
    } else iptv_json(['ok' => false, 'error' => 'ACTION_INVALID'], 400);
    break;
}
unset($device);
if (!$found) iptv_json(['ok' => false, 'error' => 'DEVICE_NOT_FOUND'], 404);
iptv_save_devices($devices);
iptv_json(['ok' => true]);
