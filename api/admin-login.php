<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function admin_login_reply(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    admin_login_reply(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED'], 405);
}

$configPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'config.php';
$config = is_file($configPath) ? include $configPath : [];
$config = is_array($config) ? $config : [];
$adminUser = trim((string)($config['TUBETV_ADMIN_USER'] ?? ''));
$passwordHash = trim((string)($config['TUBETV_ADMIN_PASSWORD_HASH'] ?? ''));
$adminToken = trim((string)($config['TUBETV_ADMIN_TOKEN'] ?? $config['ADMIN_TOKEN'] ?? ''));
if ($adminUser === '' || $passwordHash === '' || $adminToken === '') {
    admin_login_reply(['ok' => false, 'error' => 'ADMIN_LOGIN_NOT_CONFIGURED'], 503);
}

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) admin_login_reply(['ok' => false, 'error' => 'INVALID_JSON'], 400);
$username = trim((string)($input['username'] ?? ''));
$password = (string)($input['password'] ?? '');
if ($username === '' || $password === '') admin_login_reply(['ok' => false, 'error' => 'CREDENTIALS_REQUIRED'], 400);

if (!hash_equals($adminUser, $username) || !password_verify($password, $passwordHash)) {
    usleep(650000);
    admin_login_reply(['ok' => false, 'error' => 'INVALID_CREDENTIALS'], 401);
}

admin_login_reply(['ok' => true, 'username' => $adminUser, 'adminToken' => $adminToken]);
