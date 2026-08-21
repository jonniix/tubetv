<?php
declare(strict_types=1);

$origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
$allowedOrigins = [
    'https://tubetv.online',
    'https://www.tubetv.online',
    'http://localhost',
    'http://127.0.0.1',
];
$originAllowed = $origin === '' || in_array($origin, $allowedOrigins, true);
if (!$originAllowed) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('ORIGIN_NOT_ALLOWED');
}
if ($origin !== '') {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Range');
header('Access-Control-Expose-Headers: Content-Length, Content-Range, Accept-Ranges');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$path = rawurldecode((string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/'));
$root = __DIR__;
$routes = [
    '/' => $root . '/health.php',
    '/health' => $root . '/health.php',
    '/api/iptv-catalog.php' => $root . '/catalog.php',
    '/api/iptv-stream.php' => $root . '/api/iptv-stream.php',
    '/api/iptv-transcode.php' => $root . '/api/iptv-transcode.php',
    '/api/iptv-epg.php' => $root . '/api/iptv-epg.php',
];

$target = $routes[$path] ?? '';
if ($target === '' || !is_file($target)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('TUBETV_HOST_ROUTE_NOT_FOUND');
}
require $target;

