<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$configPath = __DIR__ . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'config.php';
$config = is_file($configPath) ? include $configPath : [];
if (!is_array($config)) $config = [];

$normalizeOnly = in_array('--normalize-only', $argv ?? [], true);
$_GET['action'] = $normalizeOnly ? 'tick' : 'sync_sources';
$_GET['token'] = (string)($config['TUBETV_ADMIN_TOKEN'] ?? $config['ADMIN_TOKEN'] ?? '');
$_SERVER['REQUEST_METHOD'] = 'GET';

require __DIR__ . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'bot-v3.php';
