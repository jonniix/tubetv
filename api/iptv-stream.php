<?php
declare(strict_types=1);
require __DIR__ . '/iptv-lib.php';

$token = trim((string)($_GET['session'] ?? ''));
$session = iptv_load_session($token);
if (!$session) { http_response_code(401); exit('IPTV_SESSION_EXPIRED'); }
$channel = trim((string)($_GET['channel'] ?? ''));
$key = trim((string)($_GET['key'] ?? ''));
$url = $channel !== '' ? (string)($session['channels'][$channel] ?? '') : (string)($session['urlMap'][$key] ?? '');
if ($url === '' || !iptv_url_allowed($url)) { http_response_code(404); exit('IPTV_STREAM_NOT_FOUND'); }

if (($_GET['asset'] ?? '') === 'logo') {
    $cacheDir = iptv_logo_cache_dir();
    iptv_ensure_private_dir($cacheDir);
    $cacheKey = hash('sha256', $url);
    $bodyPath = $cacheDir . DIRECTORY_SEPARATOR . $cacheKey . '.bin';
    $metaPath = $cacheDir . DIRECTORY_SEPARATOR . $cacheKey . '.json';
    $body = ''; $mime = '';
    if (is_file($bodyPath) && is_file($metaPath) && (int)@filemtime($bodyPath) > time() - 604800) {
        $body = (string)@file_get_contents($bodyPath);
        $meta = json_decode((string)@file_get_contents($metaPath), true);
        $mime = is_array($meta) ? (string)($meta['mime'] ?? '') : '';
    }
    if ($body === '' || !str_starts_with(strtolower($mime), 'image/')) {
        $fetch = iptv_fetch_logo($url, 8388608);
        if ($fetch['ok']) {
            $candidate = (string)$fetch['body'];
            $info = function_exists('getimagesizefromstring') ? @getimagesizefromstring($candidate) : false;
            $declared = strtolower(trim(explode(';', (string)($fetch['contentType'] ?? ''))[0]));
            $isSvg = str_contains(strtolower(substr(ltrim($candidate), 0, 300)), '<svg');
            $detected = is_array($info) ? (string)($info['mime'] ?? '') : ($isSvg ? 'image/svg+xml' : '');
            if (str_starts_with($detected, 'image/') || (str_starts_with($declared, 'image/') && $candidate !== '')) {
                $body = $candidate;
                $mime = $detected !== '' ? $detected : $declared;
                @file_put_contents($bodyPath . '.tmp', $body, LOCK_EX);
                @rename($bodyPath . '.tmp', $bodyPath);
                @file_put_contents($metaPath, json_encode(['mime' => $mime]), LOCK_EX);
                @chmod($bodyPath, 0600); @chmod($metaPath, 0600);
            }
        }
    }
    if ($body === '' || !str_starts_with(strtolower($mime), 'image/')) {
        $mime = 'image/svg+xml';
        $body = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 96 96"><rect width="96" height="96" rx="18" fill="#17171f"/><rect x="17" y="25" width="62" height="45" rx="8" fill="none" stroke="#e63946" stroke-width="5"/><path d="M35 17l13 9 13-9" fill="none" stroke="#e63946" stroke-width="5" stroke-linecap="round"/><text x="48" y="54" fill="#fff" font-family="Arial,sans-serif" font-size="20" font-weight="700" text-anchor="middle">TV</text></svg>';
    }
    $etag = '"' . substr(hash('sha256', $body), 0, 24) . '"';
    if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) { http_response_code(304); exit; }
    header('Content-Type: ' . $mime);
    header('Cache-Control: private, max-age=86400, stale-if-error=604800');
    header('ETag: ' . $etag);
    header('X-Content-Type-Options: nosniff');
    echo $body;
    exit;
}

$path = strtolower((string)(parse_url($url, PHP_URL_PATH) ?? ''));
$binaryExtensions = ['.ts', '.mp4', '.m4v', '.webm', '.mkv', '.avi', '.flv', '.mp3', '.aac'];
$looksBinary = false;
foreach ($binaryExtensions as $extension) {
    if (str_ends_with($path, $extension)) { $looksBinary = true; break; }
}
// Some providers expose MPEG-TS and HLS through extensionless URLs. Probe a
// small prefix instead of assuming that every top-level channel is HLS.
$urlLower = strtolower($url);
$looksLikePlaylist = !$looksBinary && (
    str_ends_with($path, '.m3u8')
    || str_contains($urlLower, 'output=m3u8')
    || str_contains($urlLower, 'format=m3u8')
    || str_contains($urlLower, 'type=hls')
);
if (!$looksBinary && !$looksLikePlaylist && $channel !== '' && function_exists('curl_init')) {
    $probeBody = '';
    $probeType = '';
    $probe = curl_init($url);
    curl_setopt_array($probe, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 7,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_USERAGENT => 'TubeTV-IPTV/1.0',
        CURLOPT_HTTPHEADER => ['Accept: application/vnd.apple.mpegurl, application/x-mpegURL, video/mp2t, */*'],
        CURLOPT_HEADERFUNCTION => static function($curl, string $header) use (&$probeType): int {
            if (preg_match('/^Content-Type:\s*([^;\r\n]+)/i', trim($header), $match)) $probeType = strtolower(trim($match[1]));
            return strlen($header);
        },
        CURLOPT_WRITEFUNCTION => static function($curl, string $chunk) use (&$probeBody): int {
            $remaining = 65536 - strlen($probeBody);
            if ($remaining <= 0) return 0;
            $probeBody .= substr($chunk, 0, $remaining);
            return strlen($chunk) > $remaining ? 0 : strlen($chunk);
        },
    ]);
    @curl_exec($probe);
    curl_close($probe);
    $looksLikePlaylist = str_starts_with(ltrim($probeBody), '#EXTM3U') || str_contains($probeType, 'mpegurl');
}
$channelMeta = $channel !== '' && is_array($session['channelMeta'][$channel] ?? null) ? $session['channelMeta'][$channel] : [];
$metaText = strtolower(trim((string)($channelMeta['group'] ?? '') . ' ' . (string)($channelMeta['name'] ?? '')));
$isVod = preg_match('/film|movie|cinema|vod|serie|series|24\/7/i', $metaText) === 1
    || preg_match('/\.(mp4|m4v|webm|mkv|avi|flv)$/', $path) === 1;
$isContinuousLive = $channel !== '' && !$isVod && !$looksLikePlaylist;
if ($looksLikePlaylist) {
    $fetch = iptv_fetch_text($url, 10485760);
    if (!$fetch['ok']) { http_response_code(502); exit($fetch['error']); }
    $body = $fetch['body'];
    if (!str_starts_with(ltrim((string)$body), '#EXTM3U')) {
        http_response_code(415);
        exit('IPTV_STREAM_IS_NOT_HLS');
    }
    $baseUrl = iptv_url_allowed((string)($fetch['effectiveUrl'] ?? '')) ? (string)$fetch['effectiveUrl'] : $url;
    $body = preg_replace_callback('/URI="([^"]+)"/', function($m) use ($baseUrl, $token, &$session) {
        $absolute = iptv_resolve_url($baseUrl, $m[1]);
        return 'URI="' . iptv_map_url($token, $session, $absolute) . '"';
    }, $body);
    $lines = preg_split('/\r\n|\r|\n/', (string)$body) ?: [];
    foreach ($lines as &$line) {
        $trim = trim($line);
        if ($trim !== '' && $trim[0] !== '#') {
            $line = iptv_map_url($token, $session, iptv_resolve_url($baseUrl, $trim));
        }
    }
    unset($line);
    iptv_save_session($token, $session);
    header('Content-Type: application/vnd.apple.mpegurl');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo implode("\n", $lines);
    exit;
}

@set_time_limit(0);
while (ob_get_level() > 0) @ob_end_flush();
header($channel === '' ? 'Cache-Control: private, max-age=30' : 'Cache-Control: no-store');
header('X-Accel-Buffering: no');
header('X-Content-Type-Options: nosniff');
if (function_exists('curl_init')) {
    $requestHeaders = ['User-Agent: TubeTV-IPTV/1.0'];
    // A Range repeated after reconnect would replay the same finite TS bytes.
    // Keep byte seeking only for films and other on-demand assets.
    if (!$isContinuousLive && !empty($_SERVER['HTTP_RANGE'])) $requestHeaders[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
    $emptyReconnects = 0; $responseStarted = false;
    do {
        $ch = curl_init($url); $upstreamStatus = 0; $responseHeaders = []; $roundBytes = 0;
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 0,
            CURLOPT_HTTPHEADER => $requestHeaders, CURLOPT_BUFFERSIZE => 262144, CURLOPT_TCP_NODELAY => 1,
            CURLOPT_HEADERFUNCTION => function($curl, $header) use (&$upstreamStatus, &$responseHeaders, &$responseStarted, $isContinuousLive) {
                $trimmed = trim($header);
                if (preg_match('~^HTTP/\S+\s+(\d{3})~i', $trimmed, $m)) {
                    $upstreamStatus = (int)$m[1]; $responseHeaders = [];
                } elseif ($trimmed === '' && !$responseStarted && $upstreamStatus >= 200 && $upstreamStatus < 300) {
                    http_response_code($isContinuousLive ? 200 : $upstreamStatus);
                    foreach ($responseHeaders as $name => $value) header($name . ': ' . $value);
                    if ($isContinuousLive) { header_remove('Content-Length'); header_remove('Content-Range'); header_remove('Accept-Ranges'); }
                    $responseStarted = true;
                } elseif (preg_match('/^(Content-Type|Content-Length|Content-Range|Accept-Ranges):\s*(.+)$/i', $trimmed, $m)) {
                    if (!$isContinuousLive || strcasecmp($m[1], 'Content-Type') === 0) $responseHeaders[$m[1]] = trim($m[2]);
                }
                return strlen($header);
            },
            CURLOPT_WRITEFUNCTION => function($curl, $chunk) use (&$roundBytes) {
                if (connection_aborted()) return 0;
                $roundBytes += strlen($chunk);
                echo $chunk;
                flush();
                return strlen($chunk);
            },
        ]);
        curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if (!$isContinuousLive || connection_aborted() || in_array($status, [401, 403, 404], true)) break;
        $emptyReconnects = $roundBytes > 0 ? 0 : $emptyReconnects + 1;
        if ($emptyReconnects >= 3) break;
        usleep(250000);
    } while (!connection_aborted());
    if (!$responseStarted && $status >= 400) http_response_code(502);
    exit;
}

$context = stream_context_create(['http' => ['timeout' => 30, 'follow_location' => 1, 'header' => "User-Agent: TubeTV-IPTV/1.0\r\n"]]);
$stream = @fopen($url, 'rb', false, $context);
if (!$stream) { http_response_code(502); exit('IPTV_TRANSPORT_UNAVAILABLE'); }
header('Content-Type: video/mp2t');
while (!feof($stream) && !connection_aborted()) { echo fread($stream, 65536); flush(); }
fclose($stream);
