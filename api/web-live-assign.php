<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

function wla_reply(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    wla_reply(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED'], 405);
}

$root = dirname(__DIR__);
$configPath = $root . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'config.php';
$config = is_file($configPath) ? include $configPath : [];
$config = is_array($config) ? $config : [];
$adminToken = trim((string)($config['TUBETV_ADMIN_TOKEN'] ?? $config['ADMIN_TOKEN'] ?? getenv('TUBETV_ADMIN_TOKEN') ?: getenv('ADMIN_TOKEN') ?: ''));
$provided = trim((string)($_SERVER['HTTP_X_TUBETV_ADMIN'] ?? ''));
if ($adminToken === '') wla_reply(['ok' => false, 'error' => 'ADMIN_TOKEN_NOT_CONFIGURED'], 503);
if ($provided === '' || !hash_equals($adminToken, $provided)) wla_reply(['ok' => false, 'error' => 'UNAUTHORIZED'], 401);

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) wla_reply(['ok' => false, 'error' => 'INVALID_JSON'], 400);
$channelId = trim((string)($input['channelId'] ?? ''));
if ($channelId === '') wla_reply(['ok' => false, 'error' => 'CHANNEL_ID_REQUIRED'], 400);

$allowed = ['live2', 'kids', 'crime', 'docu'];
$requested = is_array($input['webLiveIds'] ?? null) ? array_map('strval', $input['webLiveIds']) : [];
$webLiveIds = array_values(array_unique(array_intersect($allowed, $requested)));

$dataDir = $root . DIRECTORY_SEPARATOR . 'data';
$dataPath = $dataDir . DIRECTORY_SEPARATOR . 'tubetv-data.json';
$lockPath = $dataDir . DIRECTORY_SEPARATOR . '.bot-v3.lock';
$lock = @fopen($lockPath, 'c');
if (!$lock) wla_reply(['ok' => false, 'error' => 'LOCK_UNAVAILABLE'], 503);
if (!flock($lock, LOCK_EX)) {
    fclose($lock);
    wla_reply(['ok' => false, 'error' => 'LOCK_FAILED'], 503);
}

try {
    $data = is_file($dataPath) ? json_decode((string)file_get_contents($dataPath), true) : null;
    if (!is_array($data)) throw new RuntimeException('DATA_UNAVAILABLE');
    $data['channels'] = is_array($data['channels'] ?? null) ? $data['channels'] : [];
    $found = false;
    foreach ($data['channels'] as &$channel) {
        if (!is_array($channel) || (string)($channel['id'] ?? '') !== $channelId) continue;
        $channel['webLiveIds'] = $webLiveIds;
        $channel['webLiveUpdatedAt'] = gmdate('Y-m-d\TH:i:s.000\Z');
        $found = true;
        break;
    }
    unset($channel);
    if (!$found) throw new OutOfBoundsException('CHANNEL_NOT_FOUND');

    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) throw new RuntimeException('JSON_ENCODE_FAILED');
    $temporary = $dataPath . '.web-live.tmp';
    if (file_put_contents($temporary, $json, LOCK_EX) === false || !rename($temporary, $dataPath)) {
        @unlink($temporary);
        throw new RuntimeException('WRITE_FAILED');
    }
    flock($lock, LOCK_UN);
    fclose($lock);
    wla_reply(['ok' => true, 'channelId' => $channelId, 'webLiveIds' => $webLiveIds, 'updatedAt' => gmdate('c')]);
} catch (OutOfBoundsException $error) {
    flock($lock, LOCK_UN); fclose($lock);
    wla_reply(['ok' => false, 'error' => $error->getMessage()], 404);
} catch (Throwable $error) {
    flock($lock, LOCK_UN); fclose($lock);
    wla_reply(['ok' => false, 'error' => $error->getMessage()], 500);
}
