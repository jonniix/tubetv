<?php
declare(strict_types=1);
require __DIR__ . '/iptv-lib.php';

header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-TubeTV-Admin');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }
iptv_require_admin();

$config = iptv_load_config();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    iptv_json(['ok' => true, 'config' => [
        'enabled' => !empty($config['enabled']),
        'configured' => iptv_playlist_url($config) !== '',
        'label' => (string)$config['label'],
        'mode' => (string)$config['mode'],
        'm3uUrl' => '',
        'hasM3uUrl' => (string)$config['m3uUrl'] !== '',
        'hasEpgUrl' => (string)($config['epgUrl'] ?? '') !== '',
        'serverUrl' => (string)$config['serverUrl'],
        'username' => (string)$config['username'],
        'hasPassword' => (string)$config['password'] !== '',
        'hasCustomPin' => (string)$config['accessPinHash'] !== '',
        'updatedAt' => (string)$config['updatedAt'],
    ]]);
}

$input = iptv_input();
$mode = in_array(($input['mode'] ?? ''), ['m3u', 'xtream'], true) ? (string)$input['mode'] : 'm3u';
$next = $config;
$next['enabled'] = !empty($input['enabled']);
$next['label'] = trim((string)($input['label'] ?? '')) ?: 'Catalogo IPTV completo';
$next['mode'] = $mode;
$incomingM3u = trim((string)($input['m3uUrl'] ?? ''));
if ($incomingM3u !== '') $next['m3uUrl'] = $incomingM3u;
if (!empty($input['clearM3uUrl'])) $next['m3uUrl'] = '';
$incomingEpg = trim((string)($input['epgUrl'] ?? ''));
if ($incomingEpg !== '') $next['epgUrl'] = $incomingEpg;
if (!empty($input['clearEpgUrl'])) $next['epgUrl'] = '';
$next['serverUrl'] = rtrim(trim((string)($input['serverUrl'] ?? '')), '/');
$next['username'] = trim((string)($input['username'] ?? ''));
if (array_key_exists('password', $input) && trim((string)$input['password']) !== '') $next['password'] = (string)$input['password'];
if (!empty($input['clearPassword'])) $next['password'] = '';
$pin = trim((string)($input['accessPin'] ?? ''));
if ($pin !== '') {
    if (!preg_match('/^\d{4,12}$/', $pin)) iptv_json(['ok' => false, 'error' => 'PIN_FORMAT_INVALID'], 400);
    $next['accessPinHash'] = password_hash($pin, PASSWORD_DEFAULT);
} elseif ((string)($next['accessPinHash'] ?? '') === '') {
    $next['accessPinHash'] = password_hash('6594', PASSWORD_DEFAULT);
}
$url = iptv_playlist_url($next);
if ($next['enabled'] && ($url === '' || !iptv_url_allowed($url))) iptv_json(['ok' => false, 'error' => 'IPTV_CONFIGURATION_INCOMPLETE'], 400);
$next['updatedAt'] = gmdate('c');

try { iptv_save_config($next); }
catch (Throwable $e) { iptv_json(['ok' => false, 'error' => $e->getMessage()], 500); }

$result = ['ok' => true, 'saved' => true, 'configured' => $url !== ''];
if (($input['action'] ?? '') === 'save_and_test' && $url !== '') {
    $fetch = iptv_fetch_playlist_channels($url);
    if (!$fetch['ok']) iptv_json(array_merge($result, ['testOk' => false, 'testError' => $fetch['error']]), 200);
    $channels = $fetch['channels'];
    $result['testOk'] = count($channels) > 0;
    $result['channelCount'] = count($channels);
    $result['groupCount'] = count(array_unique(array_column($channels, 'group')));
    $result['playlistBytes'] = (int)($fetch['bytes'] ?? 0);
}
iptv_json($result);
