<?php
declare(strict_types=1);
require __DIR__ . '/iptv-lib.php';
require __DIR__ . '/auth/_lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') iptv_json(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED'], 405);
if (iptv_host_shared_secret() === '') iptv_json(['ok' => false, 'error' => 'HOST_AUTH_NOT_CONFIGURED'], 503);

$user = auth_current_user();
if (!$user) iptv_json(['ok' => false, 'error' => 'ACCOUNT_REQUIRED'], 401);
$input = iptv_input();
$deviceId = iptv_clean_device_id((string)($input['deviceId'] ?? ''));
if ($deviceId === '') iptv_json(['ok' => false, 'error' => 'DEVICE_ID_INVALID'], 400);
$device = iptv_register_device((string)$user['id'], $deviceId, (string)($input['deviceName'] ?? ''));
$status = (string)($device['status'] ?? 'pending');
if ($status !== 'approved') {
    $error = $status === 'blocked' ? 'DEVICE_BLOCKED' : 'DEVICE_PENDING';
    iptv_json(['ok' => false, 'error' => $error, 'deviceCode' => strtoupper(substr((string)$device['id'], -8))], 403);
}

$now = time();
$ticket = iptv_host_sign_claims([
    'v' => 1,
    'iss' => 'tubetv.online',
    'aud' => 'tubetv-host',
    'sub' => (string)$user['id'],
    'deviceId' => $deviceId,
    'deviceRecordId' => (string)$device['id'],
    'deviceName' => substr((string)($device['name'] ?? 'Dispositivo TubeTV'), 0, 100),
    'iat' => $now,
    'exp' => $now + 120,
    'nonce' => bin2hex(random_bytes(12)),
]);
if ($ticket === '') iptv_json(['ok' => false, 'error' => 'HOST_TICKET_FAILED'], 500);
iptv_json(['ok' => true, 'ticket' => $ticket, 'expiresAt' => gmdate('c', $now + 120)]);
