<?php
declare(strict_types=1);
require __DIR__ . '/iptv-lib.php';
require __DIR__ . '/auth/_lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $config = iptv_load_config();
    iptv_json(['ok' => true, 'enabled' => !empty($config['enabled']) && iptv_playlist_url($config) !== '', 'label' => (string)$config['label']]);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') iptv_json(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED'], 405);

$config = iptv_load_config();
if (empty($config['enabled'])) iptv_json(['ok' => false, 'error' => 'IPTV_DISABLED'], 404);
$input = iptv_input();
$user = auth_current_user();
if (!$user) iptv_json(['ok' => false, 'error' => 'ACCOUNT_REQUIRED'], 401);
if (!iptv_pin_rate_check(null)) iptv_json(['ok' => false, 'error' => 'TOO_MANY_ATTEMPTS'], 429);
if (!iptv_verify_pin($config, trim((string)($input['pin'] ?? '')))) {
    iptv_pin_rate_check(false);
    iptv_json(['ok' => false, 'error' => 'PIN_INVALID'], 401);
}
iptv_pin_rate_check(true);
$deviceId = iptv_clean_device_id((string)($input['deviceId'] ?? ''));
if ($deviceId === '') iptv_json(['ok' => false, 'error' => 'DEVICE_ID_INVALID'], 400);
$device = iptv_register_device((string)$user['id'], $deviceId, (string)($input['deviceName'] ?? ''));
if ((string)($device['status'] ?? '') !== 'approved') {
    $error = (string)($device['status'] ?? '') === 'blocked' ? 'DEVICE_BLOCKED' : 'DEVICE_PENDING';
    iptv_json(['ok' => false, 'error' => $error, 'deviceCode' => strtoupper(substr((string)$device['id'], -8))], 403);
}

$url = iptv_playlist_url($config);
$fetch = iptv_fetch_playlist_channels($url);
if (!$fetch['ok']) iptv_json(['ok' => false, 'error' => $fetch['error']], 502);
$channels = $fetch['channels'];
if (!$channels) iptv_json(['ok' => false, 'error' => 'PLAYLIST_EMPTY_OR_INVALID'], 422);
$session = iptv_create_session($channels, (string)($fetch['epgUrl'] ?? '') ?: iptv_epg_url($config), (string)$user['id'], (string)$device['id']);
$groups = array_values(array_unique(array_map(fn($item) => (string)$item['group'], $session['channels'])));
sort($groups, SORT_NATURAL | SORT_FLAG_CASE);
iptv_json(['ok' => true, 'label' => (string)$config['label'], 'session' => $session['token'], 'expiresAt' => gmdate('c', $session['expiresAt']), 'groups' => $groups, 'channels' => $session['channels']]);
