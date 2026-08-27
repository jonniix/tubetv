<?php
declare(strict_types=1);

const MIRROR_TTL = 21600;

function mirror_cors(): void {
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    $local = preg_match('#^http://(?:localhost|127\.0\.0\.1|10(?:\.\d{1,3}){3}|192\.168(?:\.\d{1,3}){2}|172\.(?:1[6-9]|2\d|3[01])(?:\.\d{1,3}){2}):4177$#', $origin);
    if ($local) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Allow-Methods: POST, OPTIONS');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code($local ? 204 : 403);
        exit;
    }
}

mirror_cors();

function json_response(array $payload, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function request_data(): array {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_response(['message' => 'Metodo non consentito'], 405);
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    if (!is_array($data)) json_response(['message' => 'Richiesta non valida'], 400);
    return $data;
}

function runtime_dir(): string {
    $dir = dirname(__DIR__, 2) . '/private/mirrorpc';
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) json_response(['message' => 'Archivio sessioni non disponibile'], 500);
    return $dir;
}

function enforce_join_rate_limit(): void {
    $address = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $path = runtime_dir() . '/rate-' . hash('sha256', $address) . '.json';
    $now = time();
    $handle = @fopen($path, 'c+');
    if (!$handle || !flock($handle, LOCK_EX)) json_response(['message' => 'Controllo accessi non disponibile'], 503);
    rewind($handle); $raw = stream_get_contents($handle); $rate = $raw ? json_decode($raw, true) : null;
    if (!is_array($rate) || ($rate['since'] ?? 0) < $now - 300) $rate = ['since' => $now, 'count' => 0];
    $rate['count']++;
    rewind($handle); ftruncate($handle, 0); fwrite($handle, json_encode($rate)); fflush($handle); flock($handle, LOCK_UN); fclose($handle);
    if ($rate['count'] > 20) json_response(['message' => 'Troppi tentativi. Riprova tra qualche minuto'], 429);
}

function cleanup_expired_sessions(): void {
    $limit = time() - MIRROR_TTL;
    foreach (glob(runtime_dir() . '/*.json') ?: [] as $file) {
        if (str_contains(basename($file), 'rate-')) { if ((filemtime($file) ?: 0) < time() - 600) @unlink($file); continue; }
        if ((filemtime($file) ?: 0) < $limit) @unlink($file);
    }
}

function valid_code(mixed $value): string {
    $code = (string)$value;
    if (!preg_match('/^\d{6}$/', $code)) json_response(['message' => 'Codice non valido'], 400);
    return $code;
}

function session_path(string $code): string { return runtime_dir() . '/' . $code . '.json'; }

function with_session(string $code, callable $callback): mixed {
    $path = session_path($code);
    $handle = @fopen($path, 'c+');
    if (!$handle) json_response(['message' => 'Sessione non disponibile'], 404);
    try {
        if (!flock($handle, LOCK_EX)) json_response(['message' => 'Sessione occupata'], 503);
        rewind($handle); $raw = stream_get_contents($handle); $session = $raw ? json_decode($raw, true) : null;
        if (!is_array($session) || ($session['expiresAt'] ?? 0) < time()) { flock($handle, LOCK_UN); fclose($handle); @unlink($path); json_response(['message' => 'Sessione scaduta'], 404); }
        $result = $callback($session);
        rewind($handle); ftruncate($handle, 0); fwrite($handle, json_encode($session, JSON_UNESCAPED_SLASHES)); fflush($handle); flock($handle, LOCK_UN);
        return $result;
    } finally { if (is_resource($handle)) @fclose($handle); }
}

function safe_equal(mixed $expected, mixed $received): bool {
    $a = (string)$expected; $b = (string)$received;
    return strlen($a) === strlen($b) && hash_equals($a, $b);
}

function queue_message(array &$session, string $recipient, array $message): void {
    $session['messages'][$recipient] ??= [];
    $session['messages'][$recipient][] = $message;
    if (count($session['messages'][$recipient]) > 100) $session['messages'][$recipient] = array_slice($session['messages'][$recipient], -100);
}
