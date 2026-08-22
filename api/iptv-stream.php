<?php
declare(strict_types=1);
require __DIR__ . '/iptv-lib.php';

function iptv_segment_cache_dir(): string {
    $configured = trim((string)(getenv('TUBETV_SEGMENT_CACHE_DIR') ?: ''));
    return $configured !== '' ? rtrim($configured, '/\\') : iptv_private_dir() . DIRECTORY_SEPARATOR . 'iptv-segment-cache';
}
function iptv_fetch_shared_hls_playlist(string $url): array {
    $cacheDir = iptv_private_dir() . DIRECTORY_SEPARATOR . 'iptv-playlist-cache';
    iptv_ensure_private_dir($cacheDir);
    $id = hash('sha256', $url); $bodyPath = $cacheDir . DIRECTORY_SEPARATOR . $id . '.m3u8';
    $metaPath = $cacheDir . DIRECTORY_SEPARATOR . $id . '.json'; $lockPath = $cacheDir . DIRECTORY_SEPARATOR . $id . '.lock';
    $read = static function(int $maxAge = 3) use ($bodyPath, $metaPath): array {
        if (!is_file($bodyPath) || (int)@filemtime($bodyPath) < time() - max(1, $maxAge)) return [];
        $body = (string)@file_get_contents($bodyPath); $meta = json_decode((string)@file_get_contents($metaPath), true);
        if (!str_starts_with(ltrim($body), '#EXTM3U')) return [];
        return ['ok' => true, 'body' => $body, 'effectiveUrl' => is_array($meta) ? (string)($meta['effectiveUrl'] ?? '') : '', 'error' => '', 'sharedCache' => true];
    };
    $cached = $read(); if ($cached) return $cached;
    $lock = @fopen($lockPath, 'c');
    if ($lock && @flock($lock, LOCK_EX)) {
        $cached = $read();
        if (!$cached) {
            // Only one upstream manifest request may start at a time across
            // all channels. With several viewers this turns simultaneous
            // polling bursts into a short round-robin rotation.
            $schedulerLock = @fopen($cacheDir . DIRECTORY_SEPARATOR . '.manifest-scheduler.lock', 'c');
            $schedulerState = $cacheDir . DIRECTORY_SEPARATOR . '.manifest-scheduler.json';
            if ($schedulerLock && @flock($schedulerLock, LOCK_EX)) {
                $last = json_decode((string)@file_get_contents($schedulerState), true);
                $elapsed = microtime(true) - (float)(is_array($last) ? ($last['lastAt'] ?? 0) : 0);
                if ($elapsed < .35) usleep((int)((.35 - $elapsed) * 1000000));
            }
            $fresh = iptv_fetch_hls_playlist($url, 10485760);
            if ($schedulerLock) {
                @file_put_contents($schedulerState . '.tmp', json_encode(['lastAt' => microtime(true)]), LOCK_EX);
                @rename($schedulerState . '.tmp', $schedulerState);
                @flock($schedulerLock, LOCK_UN); @fclose($schedulerLock);
            }
            if (!empty($fresh['ok']) && str_starts_with(ltrim((string)($fresh['body'] ?? '')), '#EXTM3U')) {
                @file_put_contents($bodyPath . '.tmp', (string)$fresh['body'], LOCK_EX); @rename($bodyPath . '.tmp', $bodyPath);
                @file_put_contents($metaPath, json_encode(['effectiveUrl' => (string)($fresh['effectiveUrl'] ?? '')], JSON_UNESCAPED_SLASHES), LOCK_EX);
                @chmod($bodyPath, 0600); @chmod($metaPath, 0600); $cached = $fresh; $cached['sharedCache'] = false;
            } else {
                // Live origins occasionally reject one manifest refresh while
                // the stream itself remains healthy. Reusing the last valid
                // window briefly prevents a transient provider error from
                // turning every connected player black at the same instant.
                $stale = $read(45);
                if ($stale) {
                    $stale['sharedCache'] = true;
                    $stale['staleFallback'] = true;
                    $cached = $stale;
                } else $cached = $fresh;
            }
        }
        @flock($lock, LOCK_UN); @fclose($lock);
        return is_array($cached) ? $cached : ['ok' => false, 'error' => 'IPTV_PLAYLIST_CACHE_FAILED'];
    }
    if ($lock) @fclose($lock);
    return iptv_fetch_hls_playlist($url, 10485760);
}
function iptv_prune_segment_cache(string $cacheDir, int $maxBytes = 2147483648): void {
    $lock = @fopen($cacheDir . DIRECTORY_SEPARATOR . '.prune.lock', 'c');
    if (!$lock || !@flock($lock, LOCK_EX | LOCK_NB)) { if ($lock) @fclose($lock); return; }
    $files = []; $total = 0; $now = time();
    // Interrupted viewers can leave partial downloads behind. On a tmpfs
    // cache these files consume real RAM and eventually prevent every new
    // segment from being finalized, so remove only pieces older than the
    // longest segment request timeout.
    foreach (['*.tmp', '*.warm'] as $pattern) {
        foreach (glob($cacheDir . DIRECTORY_SEPARATOR . $pattern) ?: [] as $path) {
            if ((int)@filemtime($path) < $now - 45) @unlink($path);
        }
    }
    foreach (glob($cacheDir . DIRECTORY_SEPARATOR . '*.bin') ?: [] as $path) {
        $mtime = (int)@filemtime($path); $size = max(0, (int)@filesize($path));
        if ($mtime < $now - 360) { @unlink($path); @unlink(substr($path, 0, -4) . '.json'); continue; }
        $files[] = ['path' => $path, 'mtime' => $mtime, 'size' => $size]; $total += $size;
    }
    if ($total > $maxBytes) {
        usort($files, static fn(array $a, array $b): int => $a['mtime'] <=> $b['mtime']);
        foreach ($files as $file) { if ($total <= (int)($maxBytes * .8)) break; @unlink($file['path']); @unlink(substr($file['path'], 0, -4) . '.json'); $total -= $file['size']; }
    }
    @flock($lock, LOCK_UN); @fclose($lock);
}

function iptv_queue_live_prefetch(string $manifestUrl, array $entries, array $meta = [], int $viewerCount = 1, int $targetDelay = 0): array {
    if (!$entries) return [];
    $queueDir = iptv_private_dir() . DIRECTORY_SEPARATOR . 'iptv-prefetch-queue';
    iptv_ensure_private_dir($queueDir);
    $channelKey = hash('sha256', $manifestUrl);
    $clean = [];
    foreach ($entries as $entry) {
        $entryUrl = trim((string)($entry['url'] ?? ''));
        if ($entryUrl === '' || !iptv_url_allowed($entryUrl)) continue;
        $clean[] = [
            'url' => $entryUrl,
            'duration' => max(1.0, min(30.0, (float)($entry['duration'] ?? 10.0))),
            'sequence' => max(0, (int)($entry['sequence'] ?? 0)),
            'discontinuity' => !empty($entry['discontinuity']),
            'protected' => !empty($entry['protected']),
            'seenAt' => microtime(true),
        ];
    }
    if (!$clean) return [];
    $path = $queueDir . DIRECTORY_SEPARATOR . $channelKey . '.json';
    $lock = @fopen($path . '.lock', 'c');
    if ($lock) @flock($lock, LOCK_EX);
    $previous = json_decode((string)@file_get_contents($path), true);
    $merged = [];
    foreach (array_merge(is_array($previous['entries'] ?? null) ? $previous['entries'] : [], $clean) as $entry) {
        if (!is_array($entry) || !iptv_url_allowed((string)($entry['url'] ?? ''))) continue;
        $identity = (int)($entry['sequence'] ?? 0) > 0 ? 's:' . (int)$entry['sequence'] : 'u:' . hash('sha256', (string)$entry['url']);
        $merged[$identity] = $entry;
    }
    $merged = array_values($merged);
    usort($merged, static function(array $a, array $b): int {
        $aSequence = (int)($a['sequence'] ?? 0); $bSequence = (int)($b['sequence'] ?? 0);
        if ($aSequence > 0 && $bSequence > 0) return $aSequence <=> $bSequence;
        return ((float)($a['seenAt'] ?? 0)) <=> ((float)($b['seenAt'] ?? 0));
    });
    $merged = array_slice($merged, -36);
    $payload = [
        'version' => 2,
        'channelKey' => $channelKey,
        'channel' => substr((string)($meta['name'] ?? 'Canale live'), 0, 160),
        'group' => substr((string)($meta['group'] ?? 'TV'), 0, 160),
        'updatedAt' => microtime(true),
        'viewerCount' => max(1, min(12, $viewerCount)),
        'targetDelaySeconds' => max(0, min(180, $targetDelay)),
        'entries' => $merged,
    ];
    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded)) { if ($lock) { @flock($lock, LOCK_UN); @fclose($lock); } return []; }
    @file_put_contents($path . '.tmp', $encoded, LOCK_EX);
    @rename($path . '.tmp', $path);
    @chmod($path, 0600);
    if ($lock) { @flock($lock, LOCK_UN); @fclose($lock); }
    return $payload;
}

function iptv_active_live_viewers(): int {
    $now = time(); $clients = [];
    $activityDir = iptv_private_dir() . DIRECTORY_SEPARATOR . 'iptv-activity';
    foreach (glob($activityDir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $path) {
        $item = json_decode((string)@file_get_contents($path), true);
        if (!is_array($item) || (int)($item['lastSeen'] ?? 0) < $now - 35) continue;
        $client = trim((string)($item['clientId'] ?? ''));
        if ($client !== '') $clients[$client] = true;
    }
    return max(1, count($clients));
}

function iptv_build_buffered_playlist(array $queue, int $targetDelay, string $token, array &$session): array {
    if ($targetDelay < 10 || !is_array($queue['entries'] ?? null)) return [];
    $cacheDir = iptv_segment_cache_dir(); $entries = [];
    foreach ($queue['entries'] as $entry) {
        if (!is_array($entry) || !empty($entry['protected'])) return [];
        $url = trim((string)($entry['url'] ?? ''));
        if ($url === '' || !iptv_url_allowed($url)) continue;
        $bodyPath = $cacheDir . DIRECTORY_SEPARATOR . hash('sha256', $url) . '.bin';
        $entry['cached'] = is_file($bodyPath) && (int)@filesize($bodyPath) > 100000 && (int)@filemtime($bodyPath) >= time() - 360;
        $entries[] = $entry;
    }
    if (count($entries) < 4) return [];
    usort($entries, static fn(array $a, array $b): int => ((int)($a['sequence'] ?? 0)) <=> ((int)($b['sequence'] ?? 0)));
    $cutoff = count($entries) - 1; $behind = 0.0;
    while ($cutoff >= 0 && $behind < $targetDelay) {
        $behind += max(1.0, (float)($entries[$cutoff]['duration'] ?? 10.0)); $cutoff--;
    }
    if ($cutoff < 2) return [];
    $end = $cutoff;
    while ($end >= 2) {
        $start = max(0, $end - 5); $candidate = array_slice($entries, $start, $end - $start + 1);
        $contiguous = count($candidate) >= 3;
        foreach ($candidate as $position => $entry) {
            if (empty($entry['cached'])) { $contiguous = false; break; }
            if ($position > 0) {
                $previousSequence = (int)($candidate[$position - 1]['sequence'] ?? 0);
                $sequence = (int)($entry['sequence'] ?? 0);
                if ($previousSequence > 0 && $sequence > 0 && $sequence !== $previousSequence + 1) { $contiguous = false; break; }
            }
        }
        if ($contiguous) break;
        $end--;
    }
    if ($end < 2 || empty($candidate) || count($candidate) < 3) return [];
    $actualDelay = 0.0;
    for ($index = $end + 1; $index < count($entries); $index++) $actualDelay += max(1.0, (float)($entries[$index]['duration'] ?? 10.0));
    $targetDuration = 1;
    foreach ($candidate as $entry) $targetDuration = max($targetDuration, (int)ceil((float)($entry['duration'] ?? 10.0)));
    $firstSequence = max(0, (int)($candidate[0]['sequence'] ?? 0));
    $lines = ['#EXTM3U', '#EXT-X-VERSION:3', '#EXT-X-TARGETDURATION:' . $targetDuration, '#EXT-X-MEDIA-SEQUENCE:' . $firstSequence, '#EXT-X-START:TIME-OFFSET=-8.0,PRECISE=NO'];
    foreach ($candidate as $entry) {
        if (!empty($entry['discontinuity'])) $lines[] = '#EXT-X-DISCONTINUITY';
        $lines[] = '#EXTINF:' . rtrim(rtrim(number_format((float)($entry['duration'] ?? 10.0), 3, '.', ''), '0'), '.') . ',';
        $lines[] = (string)$entry['url'];
    }
    return ['lines' => $lines, 'actualDelay' => (int)round($actualDelay), 'segments' => count($candidate)];
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
$resourceHint = strtolower(trim((string)($_GET['resource'] ?? '')));
if (!in_array($resourceHint, ['manifest', 'segment'], true)) $resourceHint = '';
$channelMeta = $channel !== '' && is_array($session['channelMeta'][$channel] ?? null) ? $session['channelMeta'][$channel] : [];
$takeoverMeta = $channelMeta;
if (!$takeoverMeta && count((array)($session['channels'] ?? [])) === 1) {
    $onlyId = (string)array_key_first((array)$session['channels']);
    $takeoverMeta = is_array($session['channelMeta'][$onlyId] ?? null) ? $session['channelMeta'][$onlyId] : [];
}
$takeoverText = strtolower(trim((string)($takeoverMeta['group'] ?? '') . ' ' . (string)($takeoverMeta['name'] ?? '')));
$takeoverVod = !empty($takeoverMeta['isVod']) || preg_match('/film|movie|cinema|vod|serie|series|24\/7/i', $takeoverText) === 1;
$takeoverHeavy = !$takeoverVod && preg_match('/4k|super\s*hd|uhd|2160p|hevc|high\s*bitrate/i', $takeoverText) === 1;
$takeoverState = json_decode((string)@file_get_contents(iptv_private_dir() . DIRECTORY_SEPARATOR . 'adaptive-takeover.json'), true);
$takeoverActive = is_array($takeoverState) && (int)($takeoverState['activeUntil'] ?? 0) >= time();
// A takeover may interrupt only the exact heavy play session. Blocking every
// mapped segment here would also freeze unrelated DAZN/origin viewers.
if ($takeoverActive && $takeoverHeavy) {
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
$looksLikePlaylist = $resourceHint !== 'segment' && ($resourceHint === 'manifest' || (!$looksBinary && (
    $declaredFormat === 'hls'
    ||
    str_ends_with($path, '.m3u8')
    || str_contains($urlLower, 'output=m3u8')
    || str_contains($urlLower, 'format=m3u8')
    || str_contains($urlLower, 'type=hls')
)));
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
$streamMeta = $channelMeta ?: $takeoverMeta;
$metaText = strtolower(trim((string)($streamMeta['group'] ?? '') . ' ' . (string)($streamMeta['name'] ?? '')));
$isVod = !empty($streamMeta['isVod'])
    || preg_match('/\.(mp4|m4v|webm|mkv|avi|flv)$/', $path) === 1;
$isContinuousLive = $channel !== '' && !$isVod && !$looksLikePlaylist;

// Two viewers of the same HLS channel otherwise make two identical provider
// requests for every segment. On a small home server that doubles both the
// provider traffic and Wi-Fi work, so segment delivery can exceed its playback
// duration and both players start buffering. Cache immutable HLS media pieces
// by their upstream URL and serialize only identical in-flight downloads.
$isHlsMediaSegment = $channel === '' && $key !== '' && !$looksLikePlaylist
    && ($resourceHint === 'segment' || preg_match('/\.(?:ts|m4s|mp4|aac)(?:$|\?)/i', $url) === 1);
if ($isHlsMediaSegment && function_exists('curl_init')) {
    $cacheDir = iptv_segment_cache_dir();
    iptv_ensure_private_dir($cacheDir);
    $freeBytes = @disk_free_space($cacheDir);
    if (($freeBytes !== false && $freeBytes < 1073741824) || random_int(1, 20) === 1) {
        iptv_prune_segment_cache($cacheDir);
    }
    $cacheId = hash('sha256', $url);
    $bodyPath = $cacheDir . DIRECTORY_SEPARATOR . $cacheId . '.bin';
    $metaPath = $cacheDir . DIRECTORY_SEPARATOR . $cacheId . '.json';
    $lockPath = $cacheDir . DIRECTORY_SEPARATOR . $cacheId . '.lock';
    $serveCachedSegment = static function(string $bodyPath, string $metaPath): bool {
        if (!is_file($bodyPath) || (int)@filesize($bodyPath) < 1 || (int)@filemtime($bodyPath) < time() - 360) return false;
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
    $deliveredLive = false;
    $lock = @fopen($lockPath, 'c');
    if ($lock && @flock($lock, LOCK_EX)) {
        // A concurrent request may have completed while this one waited.
        if ($serveCachedSegment($bodyPath, $metaPath)) { @flock($lock, LOCK_UN); @fclose($lock); exit; }
        $tmpPath = $bodyPath . '.' . getmypid() . '.tmp';
        $tmp = @fopen($tmpPath, 'wb');
        if ($tmp) {
            $bytes = 0; $status = 0; $mime = ''; $responseHeadersSent = false;
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_FOLLOWLOCATION => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 25,
                CURLOPT_MAXREDIRS => 5, CURLOPT_USERAGENT => 'Lavf/61.1.100', CURLOPT_TCP_NODELAY => 1,
                CURLOPT_HTTPHEADER => ['Accept: video/mp2t, video/mp4, audio/aac, */*'],
                CURLOPT_HEADERFUNCTION => static function($curl, string $header) use (&$status, &$mime, &$responseHeadersSent): int {
                    $trimmed = trim($header);
                    if (preg_match('~^HTTP/\S+\s+(\d{3})~i', $trimmed, $match)) $status = (int)$match[1];
                    elseif (preg_match('/^Content-Type:\s*([^;\r\n]+)/i', $trimmed, $match)) $mime = strtolower(trim($match[1]));
                    elseif ($trimmed === '' && !$responseHeadersSent && $status >= 200 && $status < 300) {
                        http_response_code(200); header('Content-Type: ' . ($mime !== '' ? $mime : 'video/mp2t'));
                        header('Cache-Control: private, max-age=90, immutable'); header('X-Accel-Buffering: no');
                        header('X-Content-Type-Options: nosniff'); $responseHeadersSent = true;
                    }
                    return strlen($header);
                },
                CURLOPT_WRITEFUNCTION => static function($curl, string $chunk) use ($tmp, &$bytes, &$deliveredLive): int {
                    if (connection_aborted()) return 0;
                    $bytes += strlen($chunk);
                    if ($bytes > 33554432) return 0;
                    $written = fwrite($tmp, $chunk);
                    if ($written === false) return 0;
                    $deliveredLive = true; $GLOBALS['IPTV_METRIC_BYTES'] = (int)($GLOBALS['IPTV_METRIC_BYTES'] ?? 0) + strlen($chunk);
                    echo $chunk; flush();
                    return strlen($chunk);
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
    if ($deliveredLive) exit;
    if ($serveCachedSegment($bodyPath, $metaPath)) {
        if (random_int(1, 100) === 1) foreach (glob($cacheDir . DIRECTORY_SEPARATOR . '*') ?: [] as $old) if ((int)@filemtime($old) < time() - 600) @unlink($old);
        exit;
    }
}
if ($looksLikePlaylist) {
    $fetch = iptv_fetch_shared_hls_playlist($url);
    if (!$fetch['ok']) { http_response_code(502); exit($fetch['error']); }
    $body = $fetch['body'];
    if (!str_starts_with(ltrim((string)$body), '#EXTM3U')) {
        http_response_code(415);
        exit('IPTV_STREAM_IS_NOT_HLS');
    }
    $baseUrl = iptv_url_allowed((string)($fetch['effectiveUrl'] ?? '')) ? (string)$fetch['effectiveUrl'] : $url;
    $lines = preg_split('/\r\n|\r|\n/', (string)$body) ?: [];
    $sourceMedia = [];
    $pendingDuration = 10.0;
    $mediaSequence = 0; $nextSequence = 0; $pendingDiscontinuity = false; $protectedPlaylist = false;
    foreach ($lines as $sourceLine) if (preg_match('/^#EXT-X-MEDIA-SEQUENCE:(\d+)/i', trim($sourceLine), $sequenceMatch)) {
        $mediaSequence = max(0, (int)$sequenceMatch[1]); $nextSequence = $mediaSequence; break;
    }
    foreach ($lines as $sourceIndex => $sourceLine) {
        $sourceTrim = trim($sourceLine);
        if (preg_match('/^#EXTINF:([0-9.]+)/i', $sourceTrim, $durationMatch)) {
            $pendingDuration = max(1.0, min(30.0, (float)$durationMatch[1]));
        } elseif (preg_match('/^#EXT-X-DISCONTINUITY/i', $sourceTrim)) {
            $pendingDiscontinuity = true;
        } elseif (preg_match('/^#EXT-X-(?:KEY|MAP|BYTERANGE):/i', $sourceTrim)) {
            $protectedPlaylist = true;
        } elseif ($sourceTrim !== '' && $sourceTrim[0] !== '#') {
            $sourceMedia[] = [
                'line' => $sourceIndex,
                'url' => iptv_resolve_url($baseUrl, $sourceTrim),
                'duration' => $pendingDuration,
                'sequence' => $nextSequence++,
                'discontinuity' => $pendingDiscontinuity,
                'protected' => $protectedPlaylist,
            ];
            $pendingDuration = 10.0; $pendingDiscontinuity = false;
        }
    }
    // Some IPTV origins advertise their newest 10-second segment before it is
    // completely available. Playing at that edge leaves zero download margin
    // and causes periodic stalls. Hide only that newest media segment; the
    // playlist still advances normally, with about ten seconds of safe delay.
    if (!$isVod && str_contains((string)$body, '#EXTINF:') && !str_contains((string)$body, '#EXT-X-STREAM-INF:')) {
        $viewerCount = iptv_active_live_viewers();
        $targetDelay = min(120, max(0, ($viewerCount - 1) * 20));
        $queue = $targetDelay > 0 ? iptv_queue_live_prefetch($url, $sourceMedia, $streamMeta, $viewerCount, $targetDelay) : [];
        $buffered = $targetDelay > 0 ? iptv_build_buffered_playlist($queue, $targetDelay, $token, $session) : [];
        $effectiveDelay = 0;
        if ($buffered) {
            $lines = $buffered['lines'];
            $effectiveDelay = (int)($buffered['actualDelay'] ?? $targetDelay);
        }
        $mediaUris = [];
        foreach ($lines as $index => $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '' && $candidate[0] !== '#') $mediaUris[] = $index;
        }
        $lastSafePosition = count($mediaUris) - 1;
        if (!$buffered && count($mediaUris) >= 4) {
            $averageDuration = $sourceMedia ? array_sum(array_column($sourceMedia, 'duration')) / count($sourceMedia) : 10.0;
            $holdBack = max(1, (int)ceil($targetDelay / max(1.0, $averageDuration)));
            $holdBack = min($holdBack, count($mediaUris) - 3);
            $lastSafePosition = count($mediaUris) - 1 - $holdBack;
            $lastSafeUri = $mediaUris[count($mediaUris) - 1 - $holdBack];
            $lines = array_slice($lines, 0, $lastSafeUri + 1);
            $effectiveDelay = (int)round($holdBack * $averageDuration);
        }
        if (!str_contains(implode("\n", $lines), '#EXT-X-START:')) {
            array_splice($lines, 1, 0, ['#EXT-X-START:TIME-OFFSET=-8.0,PRECISE=NO']);
        }
        // Never delay the playlist while downloading media. The previous
        // synchronous prefetch could hold this response for 20+ seconds and
        // compete with the segment the player actually needed. On-demand
        // segment caching below still deduplicates concurrent viewers.
    }
    $previousTag = '';
    foreach ($lines as &$line) {
        $trim = trim($line);
        if ($trim === '') continue;
        if ($trim[0] === '#') {
            $attributeKind = preg_match('/^#EXT-X-(?:MEDIA|I-FRAME-STREAM-INF):/i', $trim) === 1 ? 'manifest' : 'segment';
            $line = preg_replace_callback('/URI="([^"]+)"/', function($match) use ($baseUrl, $token, &$session, $attributeKind) {
                $absolute = iptv_resolve_url($baseUrl, $match[1]);
                return 'URI="' . iptv_map_url($token, $session, $absolute) . '&resource=' . $attributeKind . '"';
            }, $line);
            $previousTag = $trim;
        } else {
            $resourceKind = str_starts_with(strtoupper($previousTag), '#EXT-X-STREAM-INF:') ? 'manifest' : 'segment';
            $line = iptv_map_url($token, $session, iptv_resolve_url($baseUrl, $trim)) . '&resource=' . $resourceKind;
            $previousTag = '';
        }
    }
    unset($line);
    iptv_save_session($token, $session);
    header('Content-Type: application/vnd.apple.mpegurl');
    header('Cache-Control: no-store');
    if (!$isVod) {
        header('X-TubeTV-Live-Delay: adaptive-' . (string)($effectiveDelay ?? 0) . 's');
        header('X-TubeTV-Viewer-Count: ' . (string)($viewerCount ?? 1));
        header('X-TubeTV-Buffer-Target: ' . (string)($targetDelay ?? 0));
    }
    header('X-Content-Type-Options: nosniff');
    $playlistOutput = implode("\n", $lines); $GLOBALS['IPTV_METRIC_BYTES'] = strlen($playlistOutput);
    if (!empty($fetch['sharedCache'])) $GLOBALS['IPTV_METRIC_CACHE'] = 1;
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
