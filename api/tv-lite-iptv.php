<?php
declare(strict_types=1);

require __DIR__ . '/tv-lib.php';

$input = auth_request_data();
$tvDeviceId = trim((string)($input['tvDeviceId'] ?? ''));
$deviceToken = trim((string)($input['deviceToken'] ?? ''));

if ($tvDeviceId === '' || $deviceToken === '') {
    auth_json_response(['ok' => false, 'error' => 'TV_AUTH_REQUIRED'], 401);
}

$lock = tv_devices_lock();
$devices = tv_read_file(tv_devices_path());
$index = tv_find_device($devices, $tvDeviceId);

if (
    $index < 0
    || (string)($devices[$index]['status'] ?? '') !== 'active'
    || !hash_equals((string)($devices[$index]['tokenHash'] ?? ''), hash('sha256', $deviceToken))
    || !tv_device_trusted($devices[$index])
) {
    tv_devices_unlock($lock);
    auth_json_response(['ok' => false, 'error' => 'TV_AUTH_INVALID'], 401);
}

$userId = (string)$devices[$index]['userId'];
$browserId = (string)$devices[$index]['deviceId'];
$devices[$index]['lastSeenUnix'] = time();
$devices[$index]['lastSeenAt'] = gmdate('c');
tv_write_file(tv_devices_path(), $devices);
tv_devices_unlock($lock);

$recordId = iptv_device_record_id($userId, $browserId);
$config = iptv_load_config();
if (empty($config['enabled']) || iptv_playlist_url($config) === '') {
    auth_json_response(['ok' => false, 'error' => 'IPTV_DISABLED'], 404);
}

$fetch = iptv_fetch_playlist_channels(iptv_playlist_url($config));
if (empty($fetch['ok'])) {
    auth_json_response(['ok' => false, 'error' => (string)($fetch['error'] ?? 'PLAYLIST_ERROR')], 502);
}

$session = iptv_create_session(
    (array)$fetch['channels'],
    (string)($fetch['epgUrl'] ?? '') ?: iptv_epg_url($config),
    $userId,
    $recordId
);

$groups = array_values(array_unique(array_map(
    static fn(array $channel): string => (string)($channel['group'] ?? 'Altri canali'),
    $session['channels']
)));
sort($groups, SORT_NATURAL | SORT_FLAG_CASE);

auth_json_response([
    'ok' => true,
    'label' => (string)($config['label'] ?? 'Catalogo TV'),
    'session' => $session['token'],
    'channels' => $session['channels'],
    'groups' => $groups,
    'expiresAt' => gmdate('c', $session['expiresAt']),
]);
