<?php
declare(strict_types=1);
require __DIR__ . '/iptv-lib.php';
require __DIR__ . '/auth/_lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') iptv_json(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED'], 405);
$input = iptv_input();
$claims = iptv_host_verify_claims((string)($input['proof'] ?? ''), 'device-check');
if (!$claims || (string)($claims['iss'] ?? '') !== 'tubetv-host') iptv_json(['ok' => false, 'error' => 'HOST_PROOF_INVALID'], 401);
$userId = (string)($claims['sub'] ?? '');
$recordId = (string)($claims['deviceRecordId'] ?? '');
if ($userId === '' || !preg_match('/^[a-f0-9]{32}$/', $recordId)) iptv_json(['ok' => false, 'error' => 'HOST_PROOF_INVALID'], 401);

$status = 'missing';
$users = auth_read_users();
if (auth_find_user_index_by_id($users, $userId) >= 0) {
    foreach (iptv_read_devices() as $device) {
        if ((string)($device['id'] ?? '') === $recordId && (string)($device['userId'] ?? '') === $userId) {
            $status = (string)($device['status'] ?? 'pending');
            break;
        }
    }
}
iptv_json(['ok' => true, 'approved' => $status === 'approved', 'status' => $status]);
