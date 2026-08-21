<?php
declare(strict_types=1);

function iptv_host_b64url_encode(string $value): string {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function iptv_host_b64url_decode(string $value): string|false {
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $value)) return false;
    $padding = (4 - strlen($value) % 4) % 4;
    return base64_decode(strtr($value . str_repeat('=', $padding), '-_', '+/'), true);
}

function iptv_host_shared_secret(): string {
    $override = getenv('TUBETV_IPTV_HOST_SECRET_PATH');
    $path = is_string($override) && trim($override) !== '' ? trim($override) : iptv_private_dir() . DIRECTORY_SEPARATOR . 'iptv-host-secret';
    if (!is_file($path)) return '';
    $secret = trim((string)@file_get_contents($path));
    return preg_match('/^[a-f0-9]{64}$/', $secret) ? $secret : '';
}

function iptv_host_sign_claims(array $claims): string {
    $secret = iptv_host_shared_secret();
    if ($secret === '') return '';
    $json = json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) return '';
    $payload = iptv_host_b64url_encode($json);
    $signature = hash_hmac('sha256', $payload, $secret, true);
    return $payload . '.' . iptv_host_b64url_encode($signature);
}

function iptv_host_verify_claims(string $signed, string $audience): array {
    $secret = iptv_host_shared_secret();
    $parts = explode('.', trim($signed));
    if ($secret === '' || count($parts) !== 2) return [];
    [$payload, $signature] = $parts;
    $decodedSignature = iptv_host_b64url_decode($signature);
    if (!is_string($decodedSignature) || !hash_equals(hash_hmac('sha256', $payload, $secret, true), $decodedSignature)) return [];
    $json = iptv_host_b64url_decode($payload);
    $claims = is_string($json) ? json_decode($json, true) : null;
    if (!is_array($claims)) return [];
    $now = time();
    if ((string)($claims['aud'] ?? '') !== $audience || (int)($claims['iat'] ?? 0) > $now + 30 || (int)($claims['exp'] ?? 0) < $now) return [];
    return $claims;
}
