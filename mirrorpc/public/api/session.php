<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
request_data();
cleanup_expired_sessions();

$attempts = 0;
do {
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $path = session_path($code);
    $attempts++;
} while (file_exists($path) && $attempts < 20);
if (file_exists($path)) json_response(['message' => 'Impossibile creare il codice'], 503);

$token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$challenge = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
$session = ['code' => $code, 'token' => $token, 'challenge' => $challenge, 'createdAt' => time(), 'expiresAt' => time() + MIRROR_TTL, 'hostRegistered' => false, 'viewers' => [], 'messages' => ['host' => []]];
if (file_put_contents($path, json_encode($session, JSON_UNESCAPED_SLASHES), LOCK_EX) === false) json_response(['message' => 'Impossibile salvare la sessione'], 500);

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$scheme = $https ? 'https' : 'http';
$hostName = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', $_SERVER['HTTP_HOST'] ?? 'tubetv.online');
$base = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/mirrorpc/api/session.php'))), '/');
$joinUrl = $scheme . '://' . $hostName . $base . '/display.html?code=' . $code;
json_response(['code' => $code, 'token' => $token, 'challenge' => $challenge, 'joinUrl' => $joinUrl, 'expiresIn' => 300, 'transport' => 'php-poll']);
