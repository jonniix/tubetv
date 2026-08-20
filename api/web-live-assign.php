<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/web-live-assignments-lib.php';

function wla_reply(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$root = dirname(__DIR__);
$configPath = $root . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'config.php';
$config = is_file($configPath) ? include $configPath : [];
$config = is_array($config) ? $config : [];
$adminToken = trim((string)($config['TUBETV_ADMIN_TOKEN'] ?? $config['ADMIN_TOKEN'] ?? ''));
$provided = trim((string)($_SERVER['HTTP_X_TUBETV_ADMIN'] ?? ''));
if ($adminToken === '') wla_reply(['ok' => false, 'error' => 'ADMIN_TOKEN_NOT_CONFIGURED'], 503);
if ($provided === '' || !hash_equals($adminToken, $provided)) wla_reply(['ok' => false, 'error' => 'UNAUTHORIZED'], 401);

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'GET') {
    wla_reply(['ok' => true, 'assignments' => wla_read_assignments()]);
}
if ($method !== 'POST') wla_reply(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED'], 405);

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) wla_reply(['ok' => false, 'error' => 'INVALID_JSON'], 400);
$channelId = trim((string)($input['channelId'] ?? ''));
if ($channelId === '') wla_reply(['ok' => false, 'error' => 'CHANNEL_ID_REQUIRED'], 400);
$requested = is_array($input['webLiveIds'] ?? null) ? $input['webLiveIds'] : [];

try {
    $webLiveIds = wla_write_assignment($channelId, $requested);
    wla_reply(['ok' => true, 'channelId' => $channelId, 'webLiveIds' => $webLiveIds, 'updatedAt' => gmdate('c')]);
} catch (Throwable $error) {
    wla_reply(['ok' => false, 'error' => $error->getMessage()], 500);
}
