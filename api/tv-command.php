<?php
declare(strict_types=1);
require __DIR__ . '/tv-lib.php';
$input = auth_request_data(); $deviceId = trim((string)($input['deviceId'] ?? $_GET['deviceId'] ?? '')); $token = trim((string)($input['token'] ?? $_GET['token'] ?? '')); $after = max(0, (int)($input['after'] ?? $_GET['after'] ?? 0));
$deviceLock = tv_devices_lock();
$devices = tv_read_file(tv_devices_path()); $index = tv_find_device($devices, $deviceId);
if ($index < 0 || (string)$devices[$index]['status'] !== 'active' || !hash_equals((string)$devices[$index]['tokenHash'], hash('sha256', $token)) || !tv_device_trusted($devices[$index])) auth_json_response(['ok' => false, 'error' => 'TV_AUTH_INVALID'], 401);
$currentSeq = max(0, (int)($devices[$index]['commandSeq'] ?? 0));
if ($after > $currentSeq) $after = 0;
$commands = array_values(array_filter((array)($devices[$index]['commands'] ?? []), fn($item) => (int)($item['seq'] ?? 0) > $after));
$devices[$index]['lastSeenUnix'] = time(); $devices[$index]['lastSeenAt'] = gmdate('c');
$devices[$index]['lastAcknowledgedSeq'] = $after;
tv_write_file(tv_devices_path(), $devices);
$lastSeq = $commands ? (int)end($commands)['seq'] : $after;
tv_devices_unlock($deviceLock);
auth_json_response(['ok' => true, 'commands' => $commands, 'lastSeq' => $lastSeq, 'currentSeq' => $currentSeq]);
