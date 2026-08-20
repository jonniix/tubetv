<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

define('TUBE_ROOT', dirname(__DIR__));
require_once __DIR__ . '/schedule-engine.php';

function ycm_reply(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$configPath = TUBE_ROOT . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'config.php';
$config = is_file($configPath) ? include $configPath : [];
$config = is_array($config) ? $config : [];
$adminToken = trim((string)($config['TUBETV_ADMIN_TOKEN'] ?? $config['ADMIN_TOKEN'] ?? ''));
$provided = trim((string)($_SERVER['HTTP_X_TUBETV_ADMIN'] ?? ''));
if ($adminToken === '' || $provided === '' || !hash_equals($adminToken, $provided)) {
    ycm_reply(['ok' => false, 'error' => 'UNAUTHORIZED'], 401);
}

$input = json_decode((string)file_get_contents('php://input'), true);
$url = trim((string)($input['url'] ?? ''));
if ($url === '') ycm_reply(['ok' => false, 'error' => 'URL_REQUIRED'], 400);

$params = ['part' => 'snippet'];
if (preg_match('~(?:youtube\.com/)?channel/(UC[A-Za-z0-9_-]{20,})~i', $url, $match)) {
    $params['id'] = $match[1];
} elseif (preg_match('~(?:youtube\.com/)?@([^/?&#]+)~i', $url, $match)) {
    $params['forHandle'] = '@' . ltrim($match[1], '@');
} elseif (preg_match('~(?:youtube\.com/)?user/([^/?&#]+)~i', $url, $match)) {
    $params['forUsername'] = $match[1];
} elseif (preg_match('/^@?([A-Za-z0-9._-]{3,})$/', $url, $match)) {
    $params['forHandle'] = '@' . ltrim($match[1], '@');
} else {
    ycm_reply(['ok' => false, 'error' => 'YOUTUBE_CHANNEL_URL_NOT_RECOGNIZED'], 400);
}

$apiKey = se_youtube_api_key();
if ($apiKey === '') ycm_reply(['ok' => false, 'error' => 'YOUTUBE_API_KEY_NOT_CONFIGURED'], 503);
$params['key'] = $apiKey;
$result = se_http_json('https://www.googleapis.com/youtube/v3/channels?' . http_build_query($params));
$item = is_array($result['items'][0] ?? null) ? $result['items'][0] : null;
if (!$item) ycm_reply(['ok' => false, 'error' => 'YOUTUBE_CHANNEL_NOT_FOUND'], 404);
$snippet = is_array($item['snippet'] ?? null) ? $item['snippet'] : [];
$thumbnails = is_array($snippet['thumbnails'] ?? null) ? $snippet['thumbnails'] : [];
$customUrl = ltrim(trim((string)($snippet['customUrl'] ?? '')), '@');
ycm_reply([
    'ok' => true,
    'name' => trim((string)($snippet['title'] ?? '')),
    'handle' => $customUrl !== '' ? '@' . $customUrl : '',
    'youtubeChannelId' => trim((string)($item['id'] ?? '')),
    'thumbnail' => (string)($thumbnails['high']['url'] ?? $thumbnails['medium']['url'] ?? $thumbnails['default']['url'] ?? ''),
    'description' => mb_substr((string)($snippet['description'] ?? ''), 0, 200),
]);
