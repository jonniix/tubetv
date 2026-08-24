<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/bot-v4-engine.php';

$path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'tubetv-data.json';
$data = is_file($path) ? json_decode((string)file_get_contents($path), true) : [];
if (!is_array($data)) $data = [];

echo json_encode(v4_shadow_status($data, time()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
