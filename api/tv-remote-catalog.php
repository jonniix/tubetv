<?php
declare(strict_types=1);
require __DIR__ . '/tv-lib.php';
$user = auth_require_user(); $input = auth_request_data(); $id = trim((string)($input['deviceId'] ?? ''));
$lock = tv_devices_lock(); $devices = tv_read_file(tv_devices_path()); $index = tv_find_device($devices, $id);
if ($index < 0 || (string)($devices[$index]['userId'] ?? '') !== (string)$user['id'] || !tv_device_trusted($devices[$index])) auth_json_response(['ok' => false, 'error' => 'TV_NOT_FOUND'], 404);
$tvBrowserId = (string)($devices[$index]['deviceId'] ?? ''); tv_devices_unlock($lock);
$config = iptv_load_config(); if (empty($config['enabled']) || iptv_playlist_url($config) === '') auth_json_response(['ok' => false, 'error' => 'IPTV_DISABLED'], 404);
$fetch = iptv_fetch_playlist_channels(iptv_playlist_url($config)); if (empty($fetch['ok'])) auth_json_response(['ok' => false, 'error' => (string)($fetch['error'] ?? 'PLAYLIST_ERROR')], 502);
$recordId = iptv_device_record_id((string)$user['id'], $tvBrowserId); if (!iptv_device_approved($recordId, (string)$user['id'])) auth_json_response(['ok' => false, 'error' => 'DEVICE_PENDING'], 403);
$session = iptv_create_session((array)$fetch['channels'], (string)($fetch['epgUrl'] ?? '') ?: iptv_epg_url($config), (string)$user['id'], $recordId);
auth_json_response(['ok' => true, 'label' => (string)($config['label'] ?? 'Catalogo TV'), 'session' => $session['token'], 'expiresAt' => gmdate('c', $session['expiresAt']), 'channels' => $session['channels']]);
