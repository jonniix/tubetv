<?php
declare(strict_types=1);
require __DIR__ . '/api/iptv-lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    iptv_json(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED'], 405);
}
$config = iptv_load_config();
if (empty($config['enabled'])) iptv_json(['ok' => false, 'error' => 'IPTV_DISABLED'], 404);
$input = iptv_input();
$claims = iptv_host_verify_claims((string)($input['ticket'] ?? ''), 'tubetv-host');
if (!$claims || (string)($claims['iss'] ?? '') !== 'tubetv.online') iptv_json(['ok' => false, 'error' => 'HOST_TICKET_INVALID'], 401);
$userId = (string)($claims['sub'] ?? '');
$deviceId = iptv_clean_device_id((string)($claims['deviceId'] ?? ''));
$recordId = (string)($claims['deviceRecordId'] ?? '');
if ($userId === '' || $deviceId === '' || !preg_match('/^[a-f0-9]{32}$/', $recordId) || !hash_equals(iptv_device_record_id($userId, $deviceId), $recordId)) {
    iptv_json(['ok' => false, 'error' => 'HOST_TICKET_INVALID'], 401);
}
if (!iptv_pin_rate_check(null)) iptv_json(['ok' => false, 'error' => 'TOO_MANY_ATTEMPTS'], 429);
if (!iptv_verify_pin($config, trim((string)($input['pin'] ?? '')))) {
    iptv_pin_rate_check(false);
    iptv_json(['ok' => false, 'error' => 'PIN_INVALID'], 401);
}
iptv_pin_rate_check(true);

$playlistUrl = iptv_playlist_url($config);
$fetch = iptv_fetch_playlist_channels($playlistUrl);
if (empty($fetch['ok'])) iptv_json(['ok' => false, 'error' => (string)($fetch['error'] ?? 'PLAYLIST_ERROR')], 502);
$channels = is_array($fetch['channels'] ?? null) ? $fetch['channels'] : [];
if (!$channels) iptv_json(['ok' => false, 'error' => 'PLAYLIST_EMPTY_OR_INVALID'], 422);

$device = iptv_register_device($userId, $deviceId, (string)($claims['deviceName'] ?? 'Dispositivo TubeTV'));
iptv_set_device_status((string)$device['id'], 'approved');

$session = iptv_create_session(
    $channels,
    (string)($fetch['epgUrl'] ?? '') ?: iptv_epg_url($config),
    $userId,
    (string)$device['id'],
    ['remoteApproval' => true]
);
$groups = array_values(array_unique(array_map(static fn($item): string => (string)($item['group'] ?? ''), $session['channels'])));
sort($groups, SORT_NATURAL | SORT_FLAG_CASE);

$scheme = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? 'https')) === 'http' ? 'http' : 'https';
$incomingHost = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
$host = preg_match('/^[a-z0-9.-]+(?::\d+)?$/i', $incomingHost) ? $incomingHost : 'localhost';
$publicBase = $scheme . '://' . $host . '/';
foreach ($session['channels'] as &$channel) {
    if (!empty($channel['logo']) && !preg_match('~^https?://~i', (string)$channel['logo'])) {
        $channel['logo'] = $publicBase . ltrim((string)$channel['logo'], '/');
    }
}
unset($channel);

iptv_json([
    'ok' => true,
    'hostMode' => true,
    'label' => (string)($config['label'] ?? 'Catalogo IPTV completo'),
    'session' => $session['token'],
    'expiresAt' => gmdate('c', (int)$session['expiresAt']),
    'groups' => $groups,
    'channels' => $session['channels'],
]);
