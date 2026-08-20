<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
require_once __DIR__ . '/web-live-assignments-lib.php';

function channel_reply(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function channel_clean(array $channel): array {
    unset($channel['apiKey'], $channel['youtubeApiKey']);
    foreach (['id', 'name', 'url'] as $key) $channel[$key] = trim((string)($channel[$key] ?? ''));
    if ($channel['id'] === '' || $channel['name'] === '' || $channel['url'] === '') {
        channel_reply(['ok' => false, 'error' => 'CHANNEL_FIELDS_REQUIRED'], 400);
    }
    $channel['rating'] = max(1, min(10, (int)($channel['rating'] ?? 5)));
    $channel['slots'] = array_values(array_unique(array_filter(array_map('strval', is_array($channel['slots'] ?? null) ? $channel['slots'] : []))));
    $channel['webLiveIds'] = array_values(array_unique(array_intersect(
        ['live2', 'kids', 'crime', 'docu', 'cucina'],
        array_map('strval', is_array($channel['webLiveIds'] ?? null) ? $channel['webLiveIds'] : [])
    )));
    foreach (['active', 'enabled'] as $key) $channel[$key] = (bool)($channel[$key] ?? true);
    foreach (['isKids', 'isTv'] as $key) $channel[$key] = (bool)($channel[$key] ?? false);
    return $channel;
}

$root = dirname(__DIR__);
$configPath = $root . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'config.php';
$config = is_file($configPath) ? include $configPath : [];
$config = is_array($config) ? $config : [];
$adminToken = trim((string)($config['TUBETV_ADMIN_TOKEN'] ?? $config['ADMIN_TOKEN'] ?? ''));
$provided = trim((string)($_SERVER['HTTP_X_TUBETV_ADMIN'] ?? ''));
if ($adminToken === '') channel_reply(['ok' => false, 'error' => 'ADMIN_TOKEN_NOT_CONFIGURED'], 503);
if ($provided === '' || !hash_equals($adminToken, $provided)) channel_reply(['ok' => false, 'error' => 'UNAUTHORIZED'], 401);
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') channel_reply(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED'], 405);

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) channel_reply(['ok' => false, 'error' => 'INVALID_JSON'], 400);
$action = strtolower(trim((string)($input['action'] ?? 'upsert')));
if (!in_array($action, ['upsert', 'delete'], true)) channel_reply(['ok' => false, 'error' => 'INVALID_ACTION'], 400);
$channel = $action === 'upsert' ? channel_clean(is_array($input['channel'] ?? null) ? $input['channel'] : []) : [];
$channelId = $action === 'upsert' ? $channel['id'] : trim((string)($input['channelId'] ?? ''));
if ($channelId === '') channel_reply(['ok' => false, 'error' => 'CHANNEL_ID_REQUIRED'], 400);

$dataPath = $root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'tubetv-data.json';
$lockPath = $root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . '.bot-v3.lock';
$lock = @fopen($lockPath, 'c');
if (!$lock || !flock($lock, LOCK_EX)) {
    if (is_resource($lock)) fclose($lock);
    channel_reply(['ok' => false, 'error' => 'CHANNEL_SAVE_LOCK_FAILED'], 503);
}
try {
    $data = is_file($dataPath) ? json_decode((string)file_get_contents($dataPath), true) : [];
    if (!is_array($data)) throw new RuntimeException('DATA_FILE_INVALID');
    $channels = is_array($data['channels'] ?? null) ? array_values($data['channels']) : [];
    $found = false;
    foreach ($channels as $index => $existing) {
        if (!is_array($existing) || (string)($existing['id'] ?? '') !== $channelId) continue;
        $found = true;
        if ($action === 'delete') unset($channels[$index]);
        else $channels[$index] = array_merge($existing, $channel);
        break;
    }
    if ($action === 'upsert' && !$found) $channels[] = $channel;
    $data['channels'] = array_values($channels);
    wla_write_assignment($channelId, $action === 'upsert' ? $channel['webLiveIds'] : []);
    wla_apply_assignments($data);
    $data['version'] = time();
    $data['exportedAt'] = gmdate('c');
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) throw new RuntimeException('JSON_ENCODE_FAILED');
    $temporary = $dataPath . '.channel.tmp';
    if (file_put_contents($temporary, $json, LOCK_EX) === false || !rename($temporary, $dataPath)) {
        @unlink($temporary);
        throw new RuntimeException('CHANNEL_WRITE_FAILED');
    }
    flock($lock, LOCK_UN); fclose($lock);
    channel_reply(['ok' => true, 'action' => $action, 'channelId' => $channelId, 'channelCount' => count($data['channels']), 'updatedAt' => gmdate('c')]);
} catch (Throwable $error) {
    flock($lock, LOCK_UN); fclose($lock);
    channel_reply(['ok' => false, 'error' => $error->getMessage()], 500);
}
