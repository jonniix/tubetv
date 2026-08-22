<?php
declare(strict_types=1);
require __DIR__ . '/iptv-lib.php';

function iptv_segment_cache_dir(): string {
    $configured = trim((string)(getenv('TUBETV_SEGMENT_CACHE_DIR') ?: ''));
    return $configured !== '' ? rtrim($configured, '/\\') : iptv_private_dir() . DIRECTORY_SEPARATOR . 'iptv-segment-cache';
}
function iptv_prune_segment_cache(string $cacheDir, int $maxBytes = 201326592): void {
    $lock = @fopen($cacheDir . DIRECTORY_SEPARATOR . '.prune.lock', 'c');
    if (!$lock || !@flock($lock, LOCK_EX | LOCK_NB)) { if ($lock) @fclose($lock); return; }
    $files = []; $total = 0; $now = time();
    foreach (glob($cacheDir . DIRECTORY_SEPARATOR . '*.bin') ?: [] as $path) {
        $mtime = (int)@filemtime($path); $size = max(0, (int)@filesize($path));
        if ($mtime < $now - 300) { @unlink($path); @unlink(substr($path, 0, -4) . '.json'); continue; }
        $files[] = ['path' => $path, 'mtime' => $mtime, 'size' => $size]; $total += $size;
    }
    if ($total > $maxBytes) {
        usort($files, static fn(array $a, array $b): int => $a['mtime'] <=> $b['mtime']);
        foreach ($files as $file) { if ($total <= (int)($maxBytes * .8)) break; @unlink($file['path']); @unlink(substr($file['path'], 0, -4) . '.json'); $total -= $file['size']; }
    }
    @flock($lock, LOCK_UN); @fclose($lock);
}

function iptv_warm_hls_segments(array $urls): void {
    if (!function_exists('curl_multi_init')) return;
    $cacheDir = iptv_segment_cache_dir();
    iptv_ensure_private_dir($cacheDir);
    iptv_prune_segment_cache($cacheDir);
    $jobs = []; $multi = curl_multi_init();
    foreach (array_slice(array_values(array_unique($urls)), -3) as $url) {
        if (!iptv_url_allowed($url) || preg_match('/\.(?:ts|m4s|mp4|aac)(?:$|\?)/i', $url) !== 1) continue;
        $cacheId = hash('sha256', $url); $bodyPath = $cacheDir . DIRECTORY_SEPARATOR . $cacheId . '.bin';
        $metaPath = $cacheDir . DIRECTORY_SEPARATOR . $cacheId . '.json'; $lockPath = $cacheDir . DIRECTORY_SEPARATOR . $cacheId . '.lock';
        if (is_file($bodyPath) && (int)@filesize($bodyPath) > 0 && (int)@filemtime($bodyPath) >= time() - 240) continue;
        $lock = @fopen($lockPath, 'c');
        if (!$lock || !@flock($lock, LOCK_EX | LOCK_NB)) { if ($lock) @fclose($lock); continue; }
        if (is_file($bodyPath) && (int)@filesize($bodyPath) > 0 && (int)@filemtime($bodyPath) >= time() - 240) { @flock($lock, LOCK_UN); @fclose($lock); continue; }
        $job = (object)['url' => $url, 'body' => $bodyPath, 'meta' => $metaPath, 'tmp' => $bodyPath . '.' . getmypid() . '.warm', 'lock' => $lock, 'stream' => null, 'bytes' => 0, 'status' => 0, 'mime' => ''];
        $job->stream = @fopen($job->tmp, 'wb');
        if (!$job->stream) { @flock($lock, LOCK_UN); @fclose($lock); continue; }
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_FOLLOWLOCATION => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 20, CURLOPT_MAXREDIRS => 5,
            CURLOPT_USERAGENT => 'Lavf/61.1.100', CURLOPT_TCP_NODELAY => 1,
            CURLOPT_HTTPHEADER => ['Accept: video/mp2t, video/mp4, audio/aac, */*'],
            CURLOPT_HEADERFUNCTION => static function($curl, string $header) use ($job): int {
                $trimmed = trim($header);
                if (preg_match('~^HTTP/\S+\s+(\d{3})~i', $trimmed, $match)) $job->status = (int)$match[1];
                elseif (preg_match('/^Content-Type:\s*([^;\r\n]+)/i', $trimmed, $match)) $job->mime = strtolower(trim($match[1]));
                return strlen($header);
            },
            CURLOPT_WRITEFUNCTION => static function($curl, string $chunk) use ($job): int {
                $job->bytes += strlen($chunk); if ($job->bytes > 33554432) return 0;
                $written = fwrite($job->stream, $chunk); return $written === false ? 0 : $written;
            },
        ]);
        $jobs[spl_object_id($curl)] = ['curl' => $curl, 'job' => $job]; curl_multi_add_handle($multi, $curl);
    }
    if (!$jobs) { curl_multi_close($multi); return; }
    $running = null; $deadline = microtime(true) + 21;
    do {
        do { $state = curl_multi_exec($multi, $running); } while ($state === CURLM_CALL_MULTI_PERFORM);
        if ($running && microtime(true) < $deadline) { $selected = curl_multi_select($multi, 0.25); if ($selected === -1) usleep(10000); }
        else break;
    } while ($running && $state === CURLM_OK);
    foreach ($jobs as $item) {
        $curl = $item['curl']; $job = $item['job']; $status = (int)(curl_getinfo($curl, CURLINFO_HTTP_CODE) ?: $job->status);
        @fflush($job->stream); @fclose($job->stream);
        if (!$running && $status >= 200 && $status < 300 && $job->bytes > 0 && $job->bytes <= 33554432) {
            @rename($job->tmp, $job->body); @chmod($job->body, 0600);
            @file_put_contents($job->meta, json_encode(['mime' => $job->mime], JSON_UNESCAPED_SLASHES), LOCK_EX); @chmod($job->meta, 0600);
        } else @unlink($job->tmp);
        curl_multi_remove_handle($multi, $curl); curl_close($curl); @flock($job->lock, LOCK_UN); @fclose($job->lock);
    }
    curl_multi_close($multi);
}

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
    // Logos are fetched only when their category rows are rendered. The cache
    // key is based on the provider URL, so it remains reusable across sessions.
    header('Cache-Control: private, max-age=604800, stale-if-error=2592000');
    header('ETag: ' . $etag);
    header('X-Content-Type-Options: nosniff');
    $GLOBALS['IPTV_METRIC_BYTES'] = strlen($body);
    echo $body;
    exit;
}

$path = strtolower((string)(parse_url($url, PHP_URL_PATH) ?? ''));
$channelMeta = $channel !== '' && is_array($session['channelMeta'][$channel] ?? null) ? $session['channelMeta'][$channel] : [];
$takeoverMeta = $channelMeta;
if (!$takeoverMeta && count((array)($session['channels'] ?? [])) === 1) {
    $onlyId = (string)array_key_first((array)$session['channels']);
    $takeoverMeta = is_array($session['channelMeta'][$onlyId] ?? null) ? $session['channelMeta'][$onlyId] : [];
}
$takeoverText = strtolower(trim((string)($takeoverMeta['group'] ?? '') . ' ' . (string)($takeoverMeta['name'] ?? '')));
$takeoverVod = !empty($takeoverMeta['isVod']) || preg_match('/film|movie|cinema|vod|serie|series|24\/7/i', $takeoverText) === 1;
$takeoverHeavy = !$takeoverVod && preg_match('/dazn/i', $takeoverText) !== 1 && preg_match('/4k|super\s*hd|uhd/i', $takeoverText) === 1;
$takeoverState = json_decode((string)@file_get_contents(iptv_private_dir() . DIRECTORY_SEPARATOR . 'adaptive-takeover.json'), true);
$takeoverActive = is_array($takeoverState) && (int)($takeoverState['activeUntil'] ?? 0) >= time();
$takeoverDraining = $takeoverActive && in_array((string)($takeoverState['phase'] ?? ''), ['draining', 'starting', 'retrying'], true);
// Old catalog sessions do not attach channel metadata to each segment. During
// the short drain phase stop those already-open segment requests too; as soon
// as RTX is ready (or fails) unrelated direct channels are released again.
if ($takeoverActive && ($takeoverHeavy || ($key !== '' && $takeoverDraining))) {
    $GLOBALS['IPTV_METRIC_TAKEOVER'] = 1;
    http_response_code(409); header('Retry-After: 3'); header('Cache-Control: no-store');
    exit('IPTV_ADAPTIVE_TAKEOVER');
}
$declaredFormat = strtolower((string)($channelMeta['format'] ?? ''));
$binaryExtensions = ['.ts', '.mp4', '.m4v', '.webm', '.mkv', '.avi', '.flv', '.mp3', '.aac'];
$looksBinary = false;
foreach ($binaryExtensions as $extension) {
    if (str_ends_with($path, $extension)) { $looksBinary = true; break; }
}
// Some providers expose MPEG-TS and HLS through extensionless URLs. Probe a
// small prefix instead of assuming that every top-level channel is HLS.
$urlLower = strtolower($url);
$looksLikePlaylist = !$looksBinary && (
    $declaredFormat === 'hls'
    ||
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
        CURLOPT_USERAGENT => 'Lavf/61.1.100',
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
$activityPath = '';
if ($channel !== '') {
    $activityDir = iptv_private_dir() . DIRECTORY_SEPARATOR . 'iptv-activity';
    iptv_ensure_private_dir($activityDir);
    $activityKey = substr(hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? '') . '|' . (string)($_SERVER['HTTP_USER_AGENT'] ?? '') . '|' . $channel), 0, 32);
    $activityPath = $activityDir . DIRECTORY_SEPARATOR . $activityKey . '.json';
    $previousActivity = json_decode((string)@file_get_contents($activityPath), true);
    $activity = array_merge(is_array($previousActivity) ? $previousActivity : [], [
        'pid' => getmypid(),
        'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        'userAgent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300),
        'clientId' => substr(hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? '') . '|' . (string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 16),
        'userId' => (string)($session['userId'] ?? ''),
        'deviceRecordId' => (string)($session['deviceRecordId'] ?? ''),
        'channelId' => $channel,
        'channel' => (string)($channelMeta['name'] ?? 'Canale IPTV'),
        'group' => (string)($channelMeta['group'] ?? 'TV'),
        'startedAt' => (int)(is_array($previousActivity) ? ($previousActivity['startedAt'] ?? time()) : time()),
        'lastSeen' => time(),
        'expiresAt' => time() + 86400,
    ]);
    @file_put_contents($activityPath . '.tmp', json_encode($activity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    @rename($activityPath . '.tmp', $activityPath);
    @chmod($activityPath, 0600);
    register_shutdown_function(static function() use ($activityPath, $activity): void {
        $latest = json_decode((string)@file_get_contents($activityPath), true);
        if (is_array($latest)) $activity = array_merge($activity, $latest);
        $activity['pid'] = 0; $activity['lastSeen'] = time(); $activity['expiresAt'] = time() + 20;
        @file_put_contents($activityPath . '.tmp', json_encode($activity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        @rename($activityPath . '.tmp', $activityPath);
        @chmod($activityPath, 0600);
    });
}
$metaText = strtolower(trim((string)($channelMeta['group'] ?? '') . ' ' . (string)($channelMeta['name'] ?? '')));
$isVod = !empty($channelMeta['isVod']) || preg_match('/film|movie|cinema|vod|serie|series|24\/7/i', $metaText) === 1
    || preg_match('/\.(mp4|m4v|webm|mkv|avi|flv)$/', $path) === 1;
$isContinuousLive = $channel !== '' && !$isVod && !$looksLikePlaylist;

// Two viewers of the same HLS channel otherwise make two identical provider
// requests for every segment. On a small home server that doubles both the
// provider traffic and Wi-Fi work, so segment delivery can exceed its playback
// duration and both players start buffering. Cache immutable HLS media pieces
// by their upstream URL and serialize only identical in-flight downloads.
$isHlsMediaSegment = $channel === '' && $key !== '' && !$looksLikePlaylist
    && preg_match('/\.(?:ts|m4s|mp4|aac)(?:$|\?)/i', $url) === 1;
if ($isHlsMediaSegment && function_exists('curl_init')) {
    $cacheDir = iptv_segment_cache_dir();
    iptv_ensure_private_dir($cacheDir);
    $cacheId = hash('sha256', $url);
    $bodyPath = $cacheDir . DIRECTORY_SEPARATOR . $cacheId . '.bin';
    $metaPath = $cacheDir . DIRECTORY_SEPARATOR . $cacheId . '.json';
    $lockPath = $cacheDir . DIRECTORY_SEPARATOR . $cacheId . '.lock';
    $serveCachedSegment = static function(string $bodyPath, string $metaPath): bool {
        if (!is_file($bodyPath) || (int)@filesize($bodyPath) < 1 || (int)@filemtime($bodyPath) < time() - 240) return false;
        $meta = json_decode((string)@file_get_contents($metaPath), true);
        $mime = is_array($meta) ? trim((string)($meta['mime'] ?? '')) : '';
        if ($mime === '') $mime = str_ends_with(strtolower($bodyPath), '.m4s') ? 'video/iso.segment' : 'video/mp2t';
        header('Content-Type: ' . $mime);
        $size = (int)filesize($bodyPath);
        header('Content-Length: ' . (string)$size);
        header('Cache-Control: private, max-age=90, immutable');
        header('X-Content-Type-Options: nosniff');
        $GLOBALS['IPTV_METRIC_BYTES'] = $size; $GLOBALS['IPTV_METRIC_CACHE'] = 1;
        readfile($bodyPath);
        return true;
    };
    if ($serveCachedSegment($bodyPath, $metaPath)) exit;
    $lock = @fopen($lockPath, 'c');
    if ($lock && @flock($lock, LOCK_EX)) {
        // A concurrent request may have completed while this one waited.
        if ($serveCachedSegment($bodyPath, $metaPath)) { @flock($lock, LOCK_UN); @fclose($lock); exit; }
        $tmpPath = $bodyPath . '.' . getmypid() . '.tmp';
        $tmp = @fopen($tmpPath, 'wb');
        if ($tmp) {
            $bytes = 0; $status = 0; $mime = '';
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_FOLLOWLOCATION => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 25,
                CURLOPT_MAXREDIRS => 5, CURLOPT_USERAGENT => 'Lavf/61.1.100', CURLOPT_TCP_NODELAY => 1,
                CURLOPT_HTTPHEADER => ['Accept: video/mp2t, video/mp4, audio/aac, */*'],
                CURLOPT_HEADERFUNCTION => static function($curl, string $header) use (&$status, &$mime): int {
                    $trimmed = trim($header);
                    if (preg_match('~^HTTP/\S+\s+(\d{3})~i', $trimmed, $match)) $status = (int)$match[1];
                    elseif (preg_match('/^Content-Type:\s*([^;\r\n]+)/i', $trimmed, $match)) $mime = strtolower(trim($match[1]));
                    return strlen($header);
                },
                CURLOPT_WRITEFUNCTION => static function($curl, string $chunk) use ($tmp, &$bytes): int {
                    $bytes += strlen($chunk);
                    if ($bytes > 33554432) return 0;
                    $written = fwrite($tmp, $chunk);
                    return $written === false ? 0 : $written;
                },
            ]);
            $ok = curl_exec($curl); $curlStatus = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl);
            @fflush($tmp); @fclose($tmp);
            $status = $curlStatus ?: $status;
            if ($ok !== false && $status >= 200 && $status < 300 && $bytes > 0 && $bytes <= 33554432) {
                @rename($tmpPath, $bodyPath); @chmod($bodyPath, 0600);
                @file_put_contents($metaPath, json_encode(['mime' => $mime], JSON_UNESCAPED_SLASHES), LOCK_EX); @chmod($metaPath, 0600);
            } else @unlink($tmpPath);
        }
        @flock($lock, LOCK_UN); @fclose($lock);
    } elseif ($lock) @fclose($lock);
    if ($serveCachedSegment($bodyPath, $metaPath)) {
        if (random_int(1, 100) === 1) foreach (glob($cacheDir . DIRECTORY_SEPARATOR . '*') ?: [] as $old) if ((int)@filemtime($old) < time() - 600) @unlink($old);
        exit;
    }
}
if ($looksLikePlaylist) {
    $fetch = iptv_fetch_hls_playlist($url, 10485760);
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
    // Some IPTV origins advertise their newest 10-second segment before it is
    // completely available. Playing at that edge leaves zero download margin
    // and causes periodic stalls. Hide only that newest media segment; the
    // playlist still advances normally, with about ten seconds of safe delay.
    if (str_contains((string)$body, '#EXTINF:') && !str_contains((string)$body, '#EXT-X-STREAM-INF:')) {
        $mediaUris = [];
        foreach ($lines as $index => $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '' && $candidate[0] !== '#') $mediaUris[] = $index;
        }
        if (count($mediaUris) >= 4) {
            $lastSafeUri = $mediaUris[count($mediaUris) - 2];
            $lines = array_slice($lines, 0, $lastSafeUri + 1);
        }
        $warmUrls = [];
        foreach ($lines as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '' && $candidate[0] !== '#') $warmUrls[] = iptv_resolve_url($baseUrl, $candidate);
        }
        iptv_warm_hls_segments($warmUrls);
    }
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
    $playlistOutput = implode("\n", $lines); $GLOBALS['IPTV_METRIC_BYTES'] = strlen($playlistOutput);
    echo $playlistOutput;
    exit;
}

@set_time_limit(0);
while (ob_get_level() > 0) @ob_end_flush();
header($channel === '' ? 'Cache-Control: private, max-age=30' : 'Cache-Control: no-store');
header('X-Accel-Buffering: no');
header('X-Content-Type-Options: nosniff');
if (function_exists('curl_init')) {
    $requestHeaders = ['User-Agent: Lavf/61.1.100'];
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
                $GLOBALS['IPTV_METRIC_BYTES'] = (int)($GLOBALS['IPTV_METRIC_BYTES'] ?? 0) + strlen($chunk);
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
while (!feof($stream) && !connection_aborted()) { $chunk = fread($stream, 65536); if ($chunk === false) break; $GLOBALS['IPTV_METRIC_BYTES'] = (int)($GLOBALS['IPTV_METRIC_BYTES'] ?? 0) + strlen($chunk); echo $chunk; flush(); }
fclose($stream);
