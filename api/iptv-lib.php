<?php
declare(strict_types=1);

function iptv_json(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function iptv_root(): string { return dirname(__DIR__); }
function iptv_private_dir(): string { return iptv_root() . DIRECTORY_SEPARATOR . 'private'; }
function iptv_config_path(): string { return iptv_private_dir() . DIRECTORY_SEPARATOR . 'iptv-config.json'; }
function iptv_session_dir(): string { return iptv_private_dir() . DIRECTORY_SEPARATOR . 'iptv-sessions'; }
function iptv_logo_cache_dir(): string { return iptv_private_dir() . DIRECTORY_SEPARATOR . 'iptv-logo-cache'; }
function iptv_devices_path(): string { $override = getenv('TUBETV_IPTV_DEVICES_PATH'); return is_string($override) && trim($override) !== '' ? trim($override) : iptv_private_dir() . DIRECTORY_SEPARATOR . 'iptv-devices.json'; }

function iptv_ensure_private_dir(string $dir): void {
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('PRIVATE_DIRECTORY_NOT_WRITABLE');
    }
}

function iptv_default_config(): array {
    return [
        'enabled' => false,
        'label' => 'Catalogo IPTV completo',
        'mode' => 'm3u',
        'm3uUrl' => '',
        'epgUrl' => '',
        'serverUrl' => '',
        'username' => '',
        'password' => '',
        'accessPinHash' => '',
        'updatedAt' => '',
    ];
}

function iptv_load_config(): array {
    $defaults = iptv_default_config();
    $path = iptv_config_path();
    if (!is_file($path)) return $defaults;
    $decoded = json_decode((string)@file_get_contents($path), true);
    return is_array($decoded) ? array_merge($defaults, $decoded) : $defaults;
}

function iptv_save_config(array $config): void {
    iptv_ensure_private_dir(iptv_private_dir());
    $path = iptv_config_path();
    $tmp = $path . '.tmp';
    $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || @file_put_contents($tmp, $json, LOCK_EX) === false || !@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('IPTV_CONFIG_SAVE_FAILED');
    }
    @chmod($path, 0600);
}

function iptv_admin_token(): string {
    $path = iptv_private_dir() . DIRECTORY_SEPARATOR . 'config.php';
    if (is_file($path)) {
        $config = include $path;
        if (is_array($config)) {
            foreach (['TUBETV_ADMIN_TOKEN', 'ADMIN_TOKEN'] as $key) {
                if (is_string($config[$key] ?? null) && trim($config[$key]) !== '') return trim($config[$key]);
            }
        }
    }
    foreach (['TUBETV_ADMIN_TOKEN', 'ADMIN_TOKEN'] as $key) {
        $value = getenv($key);
        if (is_string($value) && trim($value) !== '') return trim($value);
    }
    return '';
}

function iptv_header(string $wanted): string {
    $headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
    foreach ($headers as $name => $value) {
        if (strcasecmp((string)$name, $wanted) === 0) return trim((string)$value);
    }
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $wanted));
    return trim((string)($_SERVER[$serverKey] ?? ''));
}

function iptv_require_admin(): void {
    $expected = iptv_admin_token();
    if ($expected === '') iptv_json(['ok' => false, 'error' => 'ADMIN_TOKEN_NOT_CONFIGURED'], 503);
    $provided = iptv_header('X-TubeTV-Admin');
    if ($provided === '' || !hash_equals($expected, $provided)) iptv_json(['ok' => false, 'error' => 'UNAUTHORIZED'], 401);
}

function iptv_input(): array {
    $decoded = json_decode((string)file_get_contents('php://input'), true);
    return is_array($decoded) ? $decoded : [];
}

function iptv_playlist_url(array $config): string {
    if (($config['mode'] ?? 'm3u') === 'xtream') {
        $base = rtrim(trim((string)($config['serverUrl'] ?? '')), '/');
        if ($base === '' || trim((string)($config['username'] ?? '')) === '' || trim((string)($config['password'] ?? '')) === '') return '';
        return $base . '/get.php?' . http_build_query([
            'username' => (string)$config['username'],
            'password' => (string)$config['password'],
            'type' => 'm3u_plus',
            'output' => 'm3u8',
        ]);
    }
    return trim((string)($config['m3uUrl'] ?? ''));
}

function iptv_epg_url(array $config): string {
    $custom = trim((string)($config['epgUrl'] ?? ''));
    if ($custom !== '') return $custom;
    if (($config['mode'] ?? 'm3u') !== 'xtream') return '';
    $base = rtrim(trim((string)($config['serverUrl'] ?? '')), '/');
    $username = trim((string)($config['username'] ?? ''));
    $password = trim((string)($config['password'] ?? ''));
    if ($base === '' || $username === '' || $password === '') return '';
    return $base . '/xmltv.php?' . http_build_query(['username' => $username, 'password' => $password]);
}

function iptv_url_allowed(string $url): bool {
    $parts = parse_url($url);
    if (!is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)) return false;
    $host = strtolower(trim((string)($parts['host'] ?? '')));
    if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')) return false;
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
    return true;
}

function iptv_fetch_text(string $url, int $maxBytes = 26214400): array {
    if (!iptv_url_allowed($url)) return ['ok' => false, 'error' => 'URL_NOT_ALLOWED', 'status' => 0, 'body' => '', 'effectiveUrl' => $url, 'contentType' => ''];
    $body = false; $status = 0; $error = ''; $effectiveUrl = $url; $contentType = '';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_USERAGENT => 'TubeTV-IPTV/1.0',
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => ['Accept: application/vnd.apple.mpegurl, application/x-mpegURL, */*'],
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $effectiveUrl = (string)(curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url);
        $contentType = trim((string)(curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: ''));
        $error = (string)curl_error($ch);
        curl_close($ch);
    } else {
        $context = stream_context_create(['http' => [
            'timeout' => 45, 'ignore_errors' => true, 'follow_location' => 1,
            'header' => "User-Agent: TubeTV-IPTV/1.0\r\n",
        ]]);
        $body = @file_get_contents($url, false, $context, 0, $maxBytes + 1);
        foreach (($http_response_header ?? []) as $header) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $header, $m)) $status = (int)$m[1];
        }
        if ($body === false) $error = 'HTTPS_TRANSPORT_UNAVAILABLE';
    }
    if (!is_string($body)) return ['ok' => false, 'error' => $error ?: 'FETCH_FAILED', 'status' => $status, 'body' => '', 'effectiveUrl' => $effectiveUrl, 'contentType' => $contentType];
    if (strlen($body) > $maxBytes) return ['ok' => false, 'error' => 'PLAYLIST_TOO_LARGE', 'status' => $status, 'body' => '', 'effectiveUrl' => $effectiveUrl, 'contentType' => $contentType];
    if ($status !== 0 && ($status < 200 || $status >= 300)) return ['ok' => false, 'error' => 'UPSTREAM_HTTP_' . $status, 'status' => $status, 'body' => '', 'effectiveUrl' => $effectiveUrl, 'contentType' => $contentType];
    return ['ok' => true, 'error' => '', 'status' => $status, 'body' => $body, 'effectiveUrl' => $effectiveUrl, 'contentType' => $contentType];
}

function iptv_m3u_attributes(string $line): array {
    $attrs = [];
    if (preg_match_all('/([A-Za-z0-9_-]+)="([^"]*)"/', $line, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) $attrs[strtolower($match[1])] = trim(html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
    return $attrs;
}

function iptv_normalize_remote_url(string $baseUrl, string $candidate): string {
    $candidate = trim(html_entity_decode($candidate, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($candidate === '') return '';
    if (str_starts_with($candidate, '//')) {
        $scheme = strtolower((string)(parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https'));
        $candidate = $scheme . ':' . $candidate;
    } elseif (!preg_match('~^https?://~i', $candidate)) {
        $candidate = iptv_resolve_url($baseUrl, $candidate);
    }
    return iptv_url_allowed($candidate) ? $candidate : '';
}

function iptv_fetch_logo(string $url, int $maxBytes = 8388608): array {
    if (!iptv_url_allowed($url)) return ['ok' => false, 'body' => '', 'contentType' => '', 'error' => 'URL_NOT_ALLOWED'];
    $originParts = parse_url($url);
    $referer = is_array($originParts) ? (($originParts['scheme'] ?? 'https') . '://' . ($originParts['host'] ?? '') . (isset($originParts['port']) ? ':' . $originParts['port'] : '') . '/') : '';
    if (function_exists('curl_init')) {
        $attempt = function(bool $verifyTls) use ($url, $maxBytes, $referer): array {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 25, CURLOPT_MAXREDIRS => 7,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124 Safari/537.36',
                CURLOPT_HTTPHEADER => ['Accept: image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8'],
                CURLOPT_REFERER => $referer, CURLOPT_ENCODING => '',
                CURLOPT_SSL_VERIFYPEER => $verifyTls, CURLOPT_SSL_VERIFYHOST => $verifyTls ? 2 : 0,
            ]);
            $body = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $mime = trim((string)(curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: ''));
            $error = (string)curl_error($ch); curl_close($ch);
            if (!is_string($body) || $status < 200 || $status >= 300 || strlen($body) > $maxBytes) {
                return ['ok' => false, 'body' => '', 'contentType' => $mime, 'error' => $error !== '' ? $error : 'LOGO_HTTP_' . $status];
            }
            return ['ok' => true, 'body' => $body, 'contentType' => $mime, 'error' => ''];
        };
        $result = $attempt(true);
        if (!$result['ok'] && str_starts_with(strtolower((string)(parse_url($url, PHP_URL_SCHEME) ?? '')), 'https')) $result = $attempt(false);
        return $result;
    }
    return iptv_fetch_text($url, $maxBytes);
}

function iptv_parse_m3u(string $body, int $limit = 12000): array {
    $lines = preg_split('/\r\n|\r|\n/', $body) ?: [];
    $channels = []; $pending = null;
    foreach ($lines as $raw) {
        $line = trim((string)$raw);
        if ($line === '') continue;
        if (str_starts_with($line, '#EXTINF:')) {
            $attrs = iptv_m3u_attributes($line);
            $comma = strrpos($line, ',');
            $name = $comma !== false ? trim(substr($line, $comma + 1)) : '';
            $pending = [
                'name' => $name !== '' ? $name : ($attrs['tvg-name'] ?? 'Canale IPTV'),
                'group' => $attrs['group-title'] ?? 'Altri canali',
                'logo' => $attrs['tvg-logo'] ?? '',
                'tvgId' => $attrs['tvg-id'] ?? '',
            ];
            continue;
        }
        if ($line[0] === '#') continue;
        if ($pending && iptv_url_allowed($line)) {
            $pending['url'] = $line;
            $channels[] = $pending;
            if (count($channels) >= $limit) break;
        }
        $pending = null;
    }
    return $channels;
}

function iptv_parse_m3u_file(string $path, int $limit = 50000): array {
    $handle = @fopen($path, 'rb');
    if (!$handle) return [];
    $channels = []; $pending = null;
    while (($raw = fgets($handle)) !== false) {
        $line = trim($raw);
        if ($line === '') continue;
        if (str_starts_with($line, '#EXTINF:')) {
            $attrs = iptv_m3u_attributes($line);
            $comma = strrpos($line, ',');
            $name = $comma !== false ? trim(substr($line, $comma + 1)) : '';
            $pending = [
                'name' => $name !== '' ? $name : ($attrs['tvg-name'] ?? 'Canale IPTV'),
                'group' => $attrs['group-title'] ?? 'Altri canali',
                'logo' => $attrs['tvg-logo'] ?? '',
                'tvgId' => $attrs['tvg-id'] ?? '',
            ];
            continue;
        }
        if ($line[0] === '#') continue;
        if ($pending && iptv_url_allowed($line)) {
            $pending['url'] = $line;
            $channels[] = $pending;
            if (count($channels) >= $limit) break;
        }
        $pending = null;
    }
    fclose($handle);
    return $channels;
}

function iptv_m3u_epg_url_file(string $path, string $baseUrl): string {
    $handle = @fopen($path, 'rb'); if (!$handle) return '';
    $url = ''; $lines = 0;
    while (($line = fgets($handle)) !== false && $lines++ < 30) {
        if (preg_match('/(?:x-tvg-url|url-tvg)=["\']([^"\']+)["\']/i', $line, $match)) {
            $url = iptv_normalize_remote_url($baseUrl, $match[1]); break;
        }
    }
    fclose($handle); return $url;
}

function iptv_fetch_playlist_channels(string $url, int $channelLimit = 50000, int $maxBytes = 536870912): array {
    if (!iptv_url_allowed($url)) return ['ok' => false, 'error' => 'URL_NOT_ALLOWED', 'channels' => []];
    $tmp = tempnam(sys_get_temp_dir(), 'tubetv_iptv_');
    if ($tmp === false) return ['ok' => false, 'error' => 'TEMP_FILE_UNAVAILABLE', 'channels' => []];
    $output = @fopen($tmp, 'wb');
    if (!$output) { @unlink($tmp); return ['ok' => false, 'error' => 'TEMP_FILE_UNAVAILABLE', 'channels' => []]; }
    $status = 0; $error = ''; $tooLarge = false; $written = 0; $effectiveUrl = $url;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $output,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_USERAGENT => 'TubeTV-IPTV/1.0',
            CURLOPT_ENCODING => '',
            CURLOPT_NOPROGRESS => false,
            CURLOPT_XFERINFOFUNCTION => function($curl, $downloadTotal, $downloaded) use ($maxBytes, &$tooLarge) {
                if ($downloaded > $maxBytes || $downloadTotal > $maxBytes) { $tooLarge = true; return 1; }
                return 0;
            },
        ]);
        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $effectiveUrl = (string)(curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url);
        $error = (string)curl_error($ch);
        curl_close($ch);
        if ($ok === false && !$tooLarge && $error === '') $error = 'PLAYLIST_DOWNLOAD_FAILED';
    } else {
        $context = stream_context_create(['http' => [
            'timeout' => 180, 'ignore_errors' => true, 'follow_location' => 1,
            'header' => "User-Agent: TubeTV-IPTV/1.0\r\nAccept-Encoding: identity\r\n",
        ]]);
        $input = @fopen($url, 'rb', false, $context);
        if (!$input) $error = 'HTTPS_TRANSPORT_UNAVAILABLE';
        else {
            while (!feof($input)) {
                $chunk = fread($input, 1048576);
                if ($chunk === false) { $error = 'PLAYLIST_DOWNLOAD_FAILED'; break; }
                $written += strlen($chunk);
                if ($written > $maxBytes) { $tooLarge = true; break; }
                if ($chunk !== '' && fwrite($output, $chunk) === false) { $error = 'TEMP_FILE_WRITE_FAILED'; break; }
            }
            fclose($input);
            foreach (($http_response_header ?? []) as $header) {
                if (preg_match('~^HTTP/\S+\s+(\d{3})~', $header, $m)) $status = (int)$m[1];
            }
        }
    }
    fclose($output);
    if ($tooLarge) { @unlink($tmp); return ['ok' => false, 'error' => 'PLAYLIST_EXCEEDS_512_MB', 'channels' => []]; }
    if ($error !== '') { @unlink($tmp); return ['ok' => false, 'error' => $error, 'channels' => []]; }
    if ($status !== 0 && ($status < 200 || $status >= 300)) { @unlink($tmp); return ['ok' => false, 'error' => 'UPSTREAM_HTTP_' . $status, 'channels' => []]; }
    $channels = iptv_parse_m3u_file($tmp, $channelLimit);
    $discoveredEpgUrl = iptv_m3u_epg_url_file($tmp, $effectiveUrl);
    foreach ($channels as &$parsedChannel) {
        $parsedChannel['logo'] = iptv_normalize_remote_url($effectiveUrl, (string)($parsedChannel['logo'] ?? ''));
    }
    unset($parsedChannel);
    $bytes = (int)@filesize($tmp);
    @unlink($tmp);
    return ['ok' => count($channels) > 0, 'error' => $channels ? '' : 'PLAYLIST_EMPTY_OR_INVALID', 'channels' => $channels, 'bytes' => $bytes, 'epgUrl' => $discoveredEpgUrl];
}

function iptv_verify_pin(array $config, string $pin): bool {
    $hash = trim((string)($config['accessPinHash'] ?? ''));
    if ($hash === '') return hash_equals('6594', $pin);
    return password_verify($pin, $hash);
}

function iptv_stream_format(string $url): string {
    $path = strtolower((string)(parse_url($url, PHP_URL_PATH) ?? ''));
    if (preg_match('/\.(mp4|m4v|webm|mp3|aac)$/', $path)) return 'native';
    if (preg_match('/\.(mkv|avi|flv)$/', $path)) return 'transcode';
    if (preg_match('/\.(ts|mpegts)$/', $path)) return 'mpegts';
    return 'hls';
}

function iptv_read_devices(): array {
    $path = iptv_devices_path(); $data = is_file($path) ? json_decode((string)@file_get_contents($path), true) : [];
    return is_array($data) ? $data : [];
}
function iptv_save_devices(array $devices): void {
    iptv_ensure_private_dir(iptv_private_dir()); $path = iptv_devices_path(); $tmp = $path . '.tmp';
    @file_put_contents($tmp, json_encode(array_values($devices), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    @rename($tmp, $path); @chmod($path, 0600);
}
function iptv_clean_device_id(string $id): string {
    $id = strtolower(trim($id)); return preg_match('/^[a-z0-9_-]{24,96}$/', $id) ? $id : '';
}
function iptv_device_record_id(string $userId, string $deviceId): string { return substr(hash('sha256', $userId . '|' . $deviceId), 0, 32); }
function iptv_register_device(string $userId, string $deviceId, string $name): array {
    $devices = iptv_read_devices(); $recordId = iptv_device_record_id($userId, $deviceId); $now = gmdate('c');
    $index = -1; foreach ($devices as $i => $device) if ((string)($device['id'] ?? '') === $recordId) { $index = (int)$i; break; }
    $safeName = trim(preg_replace('/[^\pL\pN ._()\/-]+/u', '', $name) ?? '');
    $record = $index >= 0 && is_array($devices[$index]) ? $devices[$index] : [
        'id' => $recordId, 'userId' => $userId, 'deviceId' => $deviceId, 'status' => 'pending', 'createdAt' => $now,
    ];
    $record['name'] = substr($safeName !== '' ? $safeName : 'Browser IPTV', 0, 100);
    $record['lastSeenAt'] = $now;
    $record['userAgent'] = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 240);
    $record['ipHash'] = substr(hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? '')), 0, 20);
    if ($index >= 0) $devices[$index] = $record; else $devices[] = $record;
    iptv_save_devices($devices); return $record;
}
function iptv_device_approved(string $recordId, string $userId): bool {
    foreach (iptv_read_devices() as $device) if ((string)($device['id'] ?? '') === $recordId && (string)($device['userId'] ?? '') === $userId) return (string)($device['status'] ?? '') === 'approved';
    return false;
}
function iptv_set_device_status(string $recordId, string $status): bool {
    if (!in_array($status, ['pending', 'approved', 'blocked'], true)) return false;
    $devices = iptv_read_devices(); $found = false;
    foreach ($devices as &$device) if ((string)($device['id'] ?? '') === $recordId) { $device['status'] = $status; $device['statusUpdatedAt'] = gmdate('c'); $found = true; break; }
    unset($device); if ($found) iptv_save_devices($devices); return $found;
}

function iptv_client_key(): string {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    return hash('sha256', $ip);
}

function iptv_pin_rate_check(?bool $success = null): bool {
    $path = iptv_private_dir() . DIRECTORY_SEPARATOR . 'iptv-rate-limit.json';
    iptv_ensure_private_dir(iptv_private_dir());
    $all = is_file($path) ? json_decode((string)@file_get_contents($path), true) : [];
    if (!is_array($all)) $all = [];
    $now = time(); $key = iptv_client_key();
    $entry = is_array($all[$key] ?? null) ? $all[$key] : ['attempts' => [], 'blockedUntil' => 0];
    $entry['attempts'] = array_values(array_filter((array)$entry['attempts'], fn($ts) => (int)$ts > $now - 600));
    if ($success === true) { $entry = ['attempts' => [], 'blockedUntil' => 0]; }
    elseif ($success === false && (int)($entry['blockedUntil'] ?? 0) <= $now) {
        $entry['attempts'][] = $now;
        if (count($entry['attempts']) >= 5) $entry['blockedUntil'] = $now + 600;
    }
    $all[$key] = $entry;
    @file_put_contents($path, json_encode($all), LOCK_EX); @chmod($path, 0600);
    return (int)($entry['blockedUntil'] ?? 0) <= $now;
}

function iptv_create_session(array $channels, string $epgUrl = '', string $userId = '', string $deviceRecordId = ''): array {
    $dir = iptv_session_dir(); iptv_ensure_private_dir($dir);
    $token = bin2hex(random_bytes(24));
    $public = []; $private = []; $urlMap = [];
    foreach ($channels as $index => $channel) {
        $identity = (string)($channel['tvgId'] ?? '') . '|' . (string)($channel['name'] ?? '') . '|' . (string)($channel['group'] ?? '') . '|' . (string)($channel['url'] ?? '');
        $id = sprintf('%u', crc32((string)($channel['url'] ?? ''))) . str_pad(sprintf('%u', crc32($identity)), 10, '0', STR_PAD_LEFT);
        $logo = '';
        if (iptv_url_allowed((string)$channel['logo'])) {
            $logoKey = substr(hash('sha256', $token . '|' . $channel['logo']), 0, 32);
            $urlMap[$logoKey] = (string)$channel['logo'];
            $logo = 'api/iptv-stream.php?session=' . rawurlencode($token) . '&key=' . rawurlencode($logoKey) . '&asset=logo';
        }
        $public[] = ['id' => $id, 'name' => $channel['name'], 'group' => $channel['group'], 'logo' => $logo, 'tvgId' => $channel['tvgId'], 'format' => iptv_stream_format((string)$channel['url'])];
        $private[$id] = (string)$channel['url'];
    }
    $meta = [];
    foreach ($public as $item) $meta[(string)$item['id']] = ['name' => (string)$item['name'], 'group' => (string)$item['group'], 'tvgId' => (string)$item['tvgId'], 'format' => (string)$item['format']];
    $session = ['createdAt' => time(), 'expiresAt' => time() + 21600, 'userId' => $userId, 'deviceRecordId' => $deviceRecordId, 'channels' => $private, 'channelMeta' => $meta, 'epgUrl' => iptv_url_allowed($epgUrl) ? $epgUrl : '', 'urlMap' => $urlMap];
    $path = $dir . DIRECTORY_SEPARATOR . $token . '.json';
    @file_put_contents($path, json_encode($session, JSON_UNESCAPED_SLASHES), LOCK_EX); @chmod($path, 0600);
    return ['token' => $token, 'channels' => $public, 'expiresAt' => $session['expiresAt']];
}

function iptv_load_session(string $token): array {
    if (!preg_match('/^[a-f0-9]{48}$/', $token)) return [];
    $path = iptv_session_dir() . DIRECTORY_SEPARATOR . $token . '.json';
    $session = is_file($path) ? json_decode((string)@file_get_contents($path), true) : [];
    if (!is_array($session) || (int)($session['expiresAt'] ?? 0) < time()) { @unlink($path); return []; }
    $userId = (string)($session['userId'] ?? ''); $deviceRecordId = (string)($session['deviceRecordId'] ?? '');
    if ($userId === '' || $deviceRecordId === '' || !iptv_device_approved($deviceRecordId, $userId)) { @unlink($path); return []; }
    // Sliding lifetime for an actively watched channel. Refresh only near the
    // midpoint to avoid writing the session file for every HLS segment.
    if ((int)$session['expiresAt'] < time() + 10800) {
        $session['expiresAt'] = time() + 21600;
        @file_put_contents($path . '.tmp', json_encode($session, JSON_UNESCAPED_SLASHES), LOCK_EX);
        @rename($path . '.tmp', $path); @chmod($path, 0600);
    }
    @touch($path);
    return $session;
}

function iptv_save_session(string $token, array $session): void {
    $path = iptv_session_dir() . DIRECTORY_SEPARATOR . $token . '.json';
    @file_put_contents($path . '.tmp', json_encode($session, JSON_UNESCAPED_SLASHES), LOCK_EX);
    @rename($path . '.tmp', $path); @chmod($path, 0600);
}

function iptv_resolve_url(string $base, string $relative): string {
    if (preg_match('~^https?://~i', $relative)) return $relative;
    $parts = parse_url($base); if (!is_array($parts)) return '';
    $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    if (str_starts_with($relative, '/')) return $origin . $relative;
    $path = (string)($parts['path'] ?? '/');
    return $origin . rtrim(str_replace('\\', '/', dirname($path)), '/') . '/' . $relative;
}

function iptv_map_url(string $token, array &$session, string $url): string {
    $key = substr(hash('sha256', $token . '|' . $url), 0, 32);
    $session['urlMap'][$key] = $url;
    return 'iptv-stream.php?session=' . rawurlencode($token) . '&key=' . rawurlencode($key);
}
