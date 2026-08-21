<?php
declare(strict_types=1);
require __DIR__ . '/api/iptv-lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    iptv_json(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED'], 405);
}
$config = iptv_load_config();
if (empty($config['enabled'])) iptv_json(['ok' => false, 'error' => 'IPTV_DISABLED'], 404);
if (!iptv_pin_rate_check(null)) iptv_json(['ok' => false, 'error' => 'TOO_MANY_ATTEMPTS'], 429);
$input = iptv_input();
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

// Tailscale already authorizes the device at network level. A local approved
// record preserves the same expiring-session checks used by TubeTV production.
$clientSeed = (string)($_SERVER['REMOTE_ADDR'] ?? '') . '|' . (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
$deviceId = 'tailnet_' . substr(hash('sha256', $clientSeed), 0, 48);
$userId = 'local-tailnet-user';
$device = iptv_register_device($userId, $deviceId, 'Dispositivo Tailscale');
iptv_set_device_status((string)$device['id'], 'approved');

$session = iptv_create_session(
    $channels,
    (string)($fetch['epgUrl'] ?? '') ?: iptv_epg_url($config),
    $userId,
    (string)$device['id']
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
