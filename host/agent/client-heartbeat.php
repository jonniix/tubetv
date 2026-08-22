<?php
declare(strict_types=1);
require __DIR__ . '/api/iptv-lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') iptv_json(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED'], 405);
$input = iptv_input();
$token = trim((string)($input['session'] ?? ''));
$channel = trim((string)($input['channel'] ?? ''));
$session = iptv_load_session($token);
if (!$session) iptv_json(['ok' => false, 'error' => 'IPTV_SESSION_EXPIRED'], 401);
if ($channel === '' || !isset($session['channels'][$channel])) iptv_json(['ok' => false, 'error' => 'IPTV_CHANNEL_INVALID'], 404);

$meta = is_array($session['channelMeta'][$channel] ?? null) ? $session['channelMeta'][$channel] : [];
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$agent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300);
$clientId = substr(hash('sha256', $ip . '|' . $agent), 0, 16);
$activityDir = iptv_private_dir() . DIRECTORY_SEPARATOR . 'iptv-activity';
iptv_ensure_private_dir($activityDir);
$activityKey = substr(hash('sha256', $ip . '|' . $agent . '|' . $channel), 0, 32);
$path = $activityDir . DIRECTORY_SEPARATOR . $activityKey . '.json';
$old = json_decode((string)@file_get_contents($path), true); if (!is_array($old)) $old = [];
$number = static fn($value, float $min, float $max): float => round(max($min, min($max, (float)$value)), 2);
$quality = is_array($input['quality'] ?? null) ? $input['quality'] : [];
$activity = array_merge($old, [
    'pid' => 0, 'ip' => $ip, 'userAgent' => $agent, 'clientId' => $clientId,
    'userId' => (string)($session['userId'] ?? ''), 'deviceRecordId' => (string)($session['deviceRecordId'] ?? ''),
    'channelId' => $channel, 'channel' => (string)($meta['name'] ?? 'Canale IPTV'), 'group' => (string)($meta['group'] ?? 'TV'),
    'startedAt' => (int)($old['startedAt'] ?? time()), 'lastSeen' => time(), 'heartbeatAt' => time(), 'expiresAt' => time() + 30,
    'rttMs' => $number($input['rttMs'] ?? 0, 0, 10000), 'bufferSeconds' => $number($input['bufferSeconds'] ?? 0, 0, 600),
    'readyState' => max(0, min(4, (int)($input['readyState'] ?? 0))), 'paused' => !empty($input['paused']),
    'stalled' => !empty($input['stalled']), 'effectiveType' => substr(preg_replace('/[^a-zA-Z0-9._-]/', '', (string)($input['effectiveType'] ?? '')) ?? '', 0, 20),
    'deliveryMode' => substr(preg_replace('/[^a-zA-Z0-9._-]/', '', (string)($input['deliveryMode'] ?? '')) ?? '', 0, 32),
    'downlinkMbps' => $number($input['downlinkMbps'] ?? 0, 0, 10000),
    'droppedFrames' => max(0, (int)($quality['dropped'] ?? 0)), 'totalFrames' => max(0, (int)($quality['total'] ?? 0)),
]);
@file_put_contents($path . '.tmp', json_encode($activity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
@rename($path . '.tmp', $path); @chmod($path, 0600);
iptv_json(['ok' => true, 'time' => microtime(true)]);
