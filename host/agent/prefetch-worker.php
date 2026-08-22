<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require '/opt/tubetv-host/api/iptv-lib.php';

function prefetch_cache_dir(): string {
    $configured = trim((string)(getenv('TUBETV_SEGMENT_CACHE_DIR') ?: ''));
    return $configured !== '' ? rtrim($configured, '/\\') : iptv_private_dir() . DIRECTORY_SEPARATOR . 'iptv-segment-cache';
}

function prefetch_prune(string $cacheDir, int $maxBytes = 201326592): void {
    $lock = @fopen($cacheDir . DIRECTORY_SEPARATOR . '.prefetch-prune.lock', 'c');
    if (!$lock || !@flock($lock, LOCK_EX | LOCK_NB)) { if ($lock) fclose($lock); return; }
    $now = time();
    foreach (['*.tmp', '*.warm', '*.prefetch'] as $pattern) {
        foreach (glob($cacheDir . DIRECTORY_SEPARATOR . $pattern) ?: [] as $path) {
            if ((int)@filemtime($path) < $now - 45) @unlink($path);
        }
    }
    $files = []; $total = 0;
    foreach (glob($cacheDir . DIRECTORY_SEPARATOR . '*.bin') ?: [] as $path) {
        $size = max(0, (int)@filesize($path)); $mtime = (int)@filemtime($path);
        if ($mtime < $now - 300) { @unlink($path); @unlink(substr($path, 0, -4) . '.json'); continue; }
        $files[] = ['path' => $path, 'size' => $size, 'mtime' => $mtime]; $total += $size;
    }
    if ($total > $maxBytes) {
        usort($files, static fn(array $a, array $b): int => $a['mtime'] <=> $b['mtime']);
        foreach ($files as $file) {
            if ($total <= (int)($maxBytes * .8)) break;
            @unlink($file['path']); @unlink(substr($file['path'], 0, -4) . '.json'); $total -= $file['size'];
        }
    }
    @flock($lock, LOCK_UN); @fclose($lock);
}

function prefetch_download(array $entries, string $cacheDir): array {
    $multi = curl_multi_init(); $jobs = [];
    foreach ($entries as $entry) {
        $url = trim((string)($entry['url'] ?? ''));
        if ($url === '' || !iptv_url_allowed($url)) continue;
        $id = hash('sha256', $url); $body = $cacheDir . DIRECTORY_SEPARATOR . $id . '.bin';
        $meta = $cacheDir . DIRECTORY_SEPARATOR . $id . '.json';
        if (is_file($body) && (int)@filesize($body) > 0 && (int)@filemtime($body) >= time() - 240) {
            $jobs[] = ['ready' => true, 'duration' => (float)($entry['duration'] ?? 10), 'bytes' => (int)@filesize($body)];
            continue;
        }
        $lock = @fopen($cacheDir . DIRECTORY_SEPARATOR . $id . '.prefetch.lock', 'c');
        if (!$lock || !@flock($lock, LOCK_EX | LOCK_NB)) { if ($lock) fclose($lock); continue; }
        $tmpPath = $body . '.' . getmypid() . '.prefetch'; $stream = @fopen($tmpPath, 'wb');
        if (!$stream) { @flock($lock, LOCK_UN); fclose($lock); continue; }
        $job = (object)['url' => $url, 'body' => $body, 'meta' => $meta, 'tmp' => $tmpPath, 'stream' => $stream, 'lock' => $lock, 'bytes' => 0, 'status' => 0, 'mime' => '', 'duration' => (float)($entry['duration'] ?? 10), 'started' => microtime(true)];
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_FOLLOWLOCATION => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 28, CURLOPT_MAXREDIRS => 5,
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
    $running = null;
    do {
        do { $state = curl_multi_exec($multi, $running); } while ($state === CURLM_CALL_MULTI_PERFORM);
        if ($running) { $selected = curl_multi_select($multi, .25); if ($selected === -1) usleep(10000); }
    } while ($running && $state === CURLM_OK);
    $result = ['downloaded' => 0, 'failed' => 0, 'bytes' => 0, 'lastMs' => 0, 'ready' => 0, 'readySeconds' => 0.0];
    foreach ($jobs as $item) {
        if (!isset($item['curl'])) {
            if (!empty($item['ready'])) { $result['ready']++; $result['readySeconds'] += (float)$item['duration']; }
            continue;
        }
        $curl = $item['curl']; $job = $item['job']; $status = (int)(curl_getinfo($curl, CURLINFO_HTTP_CODE) ?: $job->status);
        $ok = curl_errno($curl) === CURLE_OK && $status >= 200 && $status < 300 && $job->bytes > 0 && $job->bytes <= 33554432;
        @fflush($job->stream); @fclose($job->stream);
        if ($ok) {
            if (!is_file($job->body)) @rename($job->tmp, $job->body); else @unlink($job->tmp);
            @chmod($job->body, 0600);
            @file_put_contents($job->meta, json_encode(['mime' => $job->mime], JSON_UNESCAPED_SLASHES), LOCK_EX); @chmod($job->meta, 0600);
            $result['downloaded']++; $result['bytes'] += $job->bytes; $result['ready']++; $result['readySeconds'] += $job->duration;
        } else { @unlink($job->tmp); $result['failed']++; }
        $result['lastMs'] = max($result['lastMs'], (int)round((microtime(true) - $job->started) * 1000));
        curl_multi_remove_handle($multi, $curl); curl_close($curl); @flock($job->lock, LOCK_UN); @fclose($job->lock);
    }
    curl_multi_close($multi);
    return $result;
}

$queueDir = iptv_private_dir() . DIRECTORY_SEPARATOR . 'iptv-prefetch-queue';
$cacheDir = prefetch_cache_dir(); iptv_ensure_private_dir($queueDir); iptv_ensure_private_dir($cacheDir);
$statePath = iptv_private_dir() . DIRECTORY_SEPARATOR . 'iptv-prefetch-state.json';
$totals = ['downloadsTotal' => 0, 'failuresTotal' => 0, 'bytesTotal' => 0];
while (true) {
    $cycleStarted = microtime(true); $active = []; $now = microtime(true);
    foreach (glob($queueDir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $path) {
        $queue = json_decode((string)@file_get_contents($path), true);
        if (!is_array($queue) || $now - (float)($queue['updatedAt'] ?? 0) > 30) { @unlink($path); continue; }
        $active[] = $queue;
    }
    usort($active, static fn(array $a, array $b): int => ((float)($b['updatedAt'] ?? 0)) <=> ((float)($a['updatedAt'] ?? 0)));
    $cycle = ['downloaded' => 0, 'failed' => 0, 'bytes' => 0, 'lastMs' => 0, 'ready' => 0, 'readySeconds' => 0.0];
    foreach (array_slice($active, 0, 4) as $queue) {
        $part = prefetch_download(array_slice((array)($queue['entries'] ?? []), 0, 3), $cacheDir);
        foreach (['downloaded', 'failed', 'bytes', 'ready'] as $key) $cycle[$key] += $part[$key];
        $cycle['readySeconds'] += $part['readySeconds']; $cycle['lastMs'] = max($cycle['lastMs'], $part['lastMs']);
    }
    $totals['downloadsTotal'] += $cycle['downloaded']; $totals['failuresTotal'] += $cycle['failed']; $totals['bytesTotal'] += $cycle['bytes'];
    $state = array_merge($totals, [
        'ok' => true, 'updatedAt' => microtime(true), 'activeChannels' => count($active),
        'queueDepth' => array_sum(array_map(static fn(array $q): int => count((array)($q['entries'] ?? [])), $active)),
        'readySegments' => $cycle['ready'], 'readySeconds' => round($cycle['readySeconds'], 1),
        'downloadedCycle' => $cycle['downloaded'], 'failedCycle' => $cycle['failed'],
        'lastDownloadMs' => $cycle['lastMs'], 'cacheBytes' => 0,
    ]);
    foreach (glob($cacheDir . DIRECTORY_SEPARATOR . '*.bin') ?: [] as $cached) $state['cacheBytes'] += max(0, (int)@filesize($cached));
    @file_put_contents($statePath . '.tmp', json_encode($state, JSON_UNESCAPED_SLASHES), LOCK_EX); @rename($statePath . '.tmp', $statePath); @chmod($statePath, 0600);
    if (disk_free_space($cacheDir) < 134217728 || random_int(1, 12) === 1) prefetch_prune($cacheDir);
    $elapsed = microtime(true) - $cycleStarted; if ($elapsed < 1.0) usleep((int)((1.0 - $elapsed) * 1000000));
}
