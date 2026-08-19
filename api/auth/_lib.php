<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    $secure = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') || (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

function auth_root_dir(): string {
    return dirname(__DIR__, 2);
}

function auth_private_dir(): string {
    return auth_root_dir() . DIRECTORY_SEPARATOR . 'private';
}

function auth_users_path(): string {
    $override = getenv('TUBETV_USERS_PATH');
    if (is_string($override) && trim($override) !== '') return trim($override);
    return auth_private_dir() . DIRECTORY_SEPARATOR . 'users.json';
}

function auth_json_response(array $payload, int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function auth_request_data(): array {
    $raw = file_get_contents('php://input');
    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return $_POST;
}

function auth_normalize_email(?string $email): string {
    return strtolower(trim((string) $email));
}

function auth_trim_string($value): string {
    return trim((string) $value);
}

function auth_string_length(string $value): int {
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function auth_rate_limit(string $action, ?bool $success = null): bool {
    $dir = auth_private_dir(); if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $path = $dir . DIRECTORY_SEPARATOR . 'auth-rate-limit.json';
    $all = is_file($path) ? json_decode((string)@file_get_contents($path), true) : [];
    if (!is_array($all)) $all = [];
    $now = time(); $window = $action === 'register' ? 3600 : 900; $max = $action === 'register' ? 5 : 10;
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'); $key = hash('sha256', $action . '|' . $ip);
    $attempts = array_values(array_filter((array)($all[$key] ?? []), fn($ts) => (int)$ts > $now - $window));
    if ($success === true) $attempts = [];
    elseif ($success === false) $attempts[] = $now;
    $all[$key] = $attempts; @file_put_contents($path, json_encode($all), LOCK_EX); @chmod($path, 0600);
    return count($attempts) < $max;
}

function auth_users_default(): array {
    return [];
}

function auth_read_users(): array {
    $path = auth_users_path();
    if (!is_file($path)) {
        return auth_users_default();
    }

    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return auth_users_default();
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : auth_users_default();
}

function auth_save_users(array $users): void {
    $dir = auth_private_dir();
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $path = auth_users_path();
    $tmpPath = $path . '.tmp';
    $json = json_encode(array_values($users), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        auth_json_response(['ok' => false, 'error' => 'Impossibile serializzare gli utenti.'], 500);
    }

    file_put_contents($tmpPath, $json, LOCK_EX);
    if (is_file($path)) {
        @unlink($path);
    }
    rename($tmpPath, $path);
    @chmod($path, 0600);
}

function auth_public_user(array $user): array {
    unset($user['passwordHash']);
    return $user;
}

function auth_find_user_index_by_id(array $users, string $userId): int {
    foreach ($users as $index => $user) {
        if ((string) ($user['id'] ?? '') === $userId) {
            return (int) $index;
        }
    }

    return -1;
}

function auth_find_user_index_by_email(array $users, string $email): int {
    $needle = auth_normalize_email($email);
    foreach ($users as $index => $user) {
        if (auth_normalize_email($user['email'] ?? '') === $needle) {
            return (int) $index;
        }
    }

    return -1;
}

function auth_current_user_id(): ?string {
    $userId = $_SESSION['user_id'] ?? null;
    return is_string($userId) && $userId !== '' ? $userId : null;
}

function auth_current_user(?array $users = null): ?array {
    $userId = auth_current_user_id();
    if ($userId === null) {
        return null;
    }

    $users = $users ?? auth_read_users();
    $index = auth_find_user_index_by_id($users, $userId);
    if ($index < 0) {
        return null;
    }

    return auth_public_user($users[$index]);
}

function auth_require_user(?array $users = null): array {
    $user = auth_current_user($users);
    if ($user === null) {
        auth_json_response(['ok' => false, 'error' => 'Sessione non attiva.'], 401);
    }

    return $user;
}

function auth_generate_user_id(): string {
    try {
        return 'user_' . bin2hex(random_bytes(8));
    } catch (Throwable $e) {
        return 'user_' . uniqid('', true);
    }
}

function auth_default_preferences(): array {
    return [
        'language' => 'it',
        'subtitlesDefault' => false,
        'autoplay' => true,
        'theme' => 'system',
        'iptvFavorites' => [],
        'iptvRecent' => []
    ];
}

function auth_default_subscription(): array {
    return [
        'plan' => 'free',
        'adsDisabled' => false,
        'startedAt' => null,
        'expiresAt' => null
    ];
}

function auth_build_user_record(array $input): array {
    $now = gmdate('c');
    $name = auth_trim_string($input['name'] ?? '');
    $email = auth_normalize_email($input['email'] ?? '');
    $passwordHash = (string) ($input['passwordHash'] ?? '');

    return [
        'id' => auth_generate_user_id(),
        'name' => $name,
        'email' => $email,
        'passwordHash' => $passwordHash,
        'createdAt' => $now,
        'updatedAt' => $now,
        'language' => auth_trim_string($input['language'] ?? 'it') ?: 'it',
        'preferences' => array_replace(auth_default_preferences(), is_array($input['preferences'] ?? null) ? $input['preferences'] : []),
        'subscription' => array_replace(auth_default_subscription(), is_array($input['subscription'] ?? null) ? $input['subscription'] : []),
        'profile' => is_array($input['profile'] ?? null) ? $input['profile'] : new stdClass()
    ];
}
