<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$windowsHostStats = json_decode((string)@file_get_contents(__DIR__ . '/private/windows-host-stats.json'), true);
if (!is_array($windowsHostStats) || (float)($windowsHostStats['updatedAt'] ?? 0) < (microtime(true) * 1000) - 10000) $windowsHostStats = null;

function dash_values(string $path): array {
    $out = [];
    foreach (@file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (!str_contains($line, ':')) continue;
        [$key, $value] = array_map('trim', explode(':', $line, 2));
        $out[$key] = $value;
    }
    return $out;
}
function dash_cpu(): array {
    global $windowsHostStats;
    if (is_array($windowsHostStats)) {
        $percent = max(0.0, min(100.0, (float)($windowsHostStats['cpuPercent'] ?? 0)));
        return ['total' => 1000000, 'idle' => (int)round(1000000 * (1 - $percent / 100))];
    }
    $parts = preg_split('/\s+/', trim((string)(@file('/proc/stat')[0] ?? ''))) ?: [];
    array_shift($parts); $values = array_map('intval', array_slice($parts, 0, 10));
    return ['total' => array_sum($values), 'idle' => ($values[3] ?? 0) + ($values[4] ?? 0)];
}
function dash_network(): array {
    global $windowsHostStats;
    if (is_array($windowsHostStats)) {
        $network = is_array($windowsHostStats['network'] ?? null) ? $windowsHostStats['network'] : [];
        return ['rx' => (int)($network['rx'] ?? 0), 'tx' => (int)($network['tx'] ?? 0)];
    }
    $rx = 0; $tx = 0;
    foreach (@file('/proc/net/dev', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (!str_contains($line, ':')) continue;
        [$name, $data] = array_map('trim', explode(':', $line, 2));
        if ($name === 'lo') continue;
        $fields = preg_split('/\s+/', trim($data)) ?: [];
        $rx += (int)($fields[0] ?? 0); $tx += (int)($fields[8] ?? 0);
    }
    return ['rx' => $rx, 'tx' => $tx];
}
function dash_delta(string $name, array $value): array {
    $path = __DIR__ . '/private/dashboard-' . $name . '.json';
    $old = json_decode((string)@file_get_contents($path), true);
    $now = microtime(true);
    @file_put_contents($path . '.tmp', json_encode(['time' => $now, 'value' => $value]), LOCK_EX);
    @rename($path . '.tmp', $path); @chmod($path, 0600);
    return ['value' => is_array($old['value'] ?? null) ? $old['value'] : $value, 'seconds' => max(.001, $now - (float)($old['time'] ?? $now))];
}
function dash_temperature(): ?float {
    global $windowsHostStats;
    if (is_array($windowsHostStats) && isset($windowsHostStats['temperature'])) return round((float)$windowsHostStats['temperature'], 1);
    $temperatures = [];
    foreach (glob('/sys/class/thermal/thermal_zone*/temp') ?: [] as $path) {
        $value = (float)trim((string)@file_get_contents($path));
        if ($value > 1000) $value /= 1000;
        if ($value > 0 && $value < 130) $temperatures[] = $value;
    }
    return $temperatures ? round(max($temperatures), 1) : null;
}
function dash_ping(): ?float {
    global $windowsHostStats;
    if (is_array($windowsHostStats)) return isset($windowsHostStats['pingMs']) ? (float)$windowsHostStats['pingMs'] : null;
    $cachePath = __DIR__ . '/private/dashboard-ping.json';
    $cached = json_decode((string)@file_get_contents($cachePath), true);
    if (is_array($cached) && (int)($cached['time'] ?? 0) >= time() - 30) return isset($cached['value']) ? (float)$cached['value'] : null;
    $value = null;
    if (is_executable('/usr/bin/ping')) {
        $lines = []; $code = 1; @exec('/usr/bin/ping -n -c 1 -W 1 1.1.1 2>/dev/null', $lines, $code);
        if ($code === 0 && preg_match('/time[=<]([0-9.]+)\s*ms/i', implode(' ', $lines), $m)) $value = round((float)$m[1], 1);
    }
    // Minimal Debian installations may not include ping or may forbid raw
    // sockets. A TCP handshake still provides a useful internet-latency KPI.
    if ($value === null) {
        $started = microtime(true); $error = 0; $message = '';
        $socket = @stream_socket_client('tcp://1.1.1.1:443', $error, $message, 1.2, STREAM_CLIENT_CONNECT);
        if (is_resource($socket)) { $value = round((microtime(true) - $started) * 1000, 1); @fclose($socket); }
    }
    if ($value === null && function_exists('curl_init')) {
        $curl = curl_init('https://tubetv.online/favicon.ico?server-ping=1');
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_NOBODY => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT_MS => 1500, CURLOPT_TIMEOUT_MS => 2500, CURLOPT_USERAGENT => 'TubeTV-Host-Monitor/1.0']);
        $ok = curl_exec($curl); $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); $elapsed = (float)curl_getinfo($curl, CURLINFO_TOTAL_TIME);
        curl_close($curl);
        if ($ok !== false && $status > 0 && $elapsed > 0) $value = round($elapsed * 1000, 1);
    }
    @file_put_contents($cachePath, json_encode(['time' => time(), 'value' => $value]), LOCK_EX); @chmod($cachePath, 0600);
    return $value;
}
function dash_device(string $agent): string {
    if (preg_match('/iPhone|iPad/i', $agent)) return 'Apple mobile';
    if (preg_match('/Android/i', $agent)) return 'Android';
    if (preg_match('/Windows/i', $agent)) return 'PC Windows';
    if (preg_match('/Macintosh|Mac OS/i', $agent)) return 'Mac';
    if (preg_match('/TV|Tizen|Web0S|SmartTV/i', $agent)) return 'Smart TV';
    return 'Dispositivo web';
}
function dash_provider_host(): string {
    $config = json_decode((string)@file_get_contents(__DIR__ . '/private/iptv-config.json'), true);
    if (!is_array($config)) return 'Non configurato';
    $values = [];
    $walk = static function($value) use (&$walk, &$values): void {
        if (is_array($value)) { foreach ($value as $item) $walk($item); return; }
        if (is_string($value) && preg_match('~^https?://~i', trim($value))) $values[] = trim($value);
    };
    $walk($config);
    foreach ($values as $value) {
        $host = strtolower((string)(parse_url($value, PHP_URL_HOST) ?? ''));
        if ($host === '' || str_contains($host, 'tubetv.') || str_ends_with($host, '.ts.net')) continue;
        $port = (int)(parse_url($value, PHP_URL_PORT) ?? 0);
        return $host . ($port > 0 ? ':' . $port : '');
    }
    return 'Provider configurato';
}
function dash_desktop_assist(): array {
    $cachePath = __DIR__ . '/private/dashboard-desktop-assist.json';
    $cached = json_decode((string)@file_get_contents($cachePath), true);
    if (is_array($cached) && (float)($cached['checkedAt'] ?? 0) >= microtime(true) - 3.0) return $cached;
    $config = json_decode((string)@file_get_contents(__DIR__ . '/private/desktop-worker.json'), true);
    $result = ['enabled' => !empty($config['enabled']), 'online' => false, 'checkedAt' => microtime(true)];
    $url = is_array($config) ? rtrim(trim((string)($config['url'] ?? '')), '/') : '';
    if ($result['enabled'] && preg_match('~^https://[a-z0-9.-]+(?::[0-9]+)?$~i', $url) && function_exists('curl_init')) {
        $curl = curl_init($url . '/health');
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT_MS => 700, CURLOPT_TIMEOUT_MS => 1300, CURLOPT_USERAGENT => 'TubeTV-Host-Monitor/2.0']);
        $body = curl_exec($curl); $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl);
        $data = is_string($body) ? json_decode($body, true) : null;
        if ($status === 200 && is_array($data) && !empty($data['ok'])) $result = array_merge($result, $data, ['online' => true, 'checkedAt' => microtime(true)]);
    }
    @file_put_contents($cachePath . '.tmp', json_encode($result, JSON_UNESCAPED_SLASHES), LOCK_EX); @rename($cachePath . '.tmp', $cachePath); @chmod($cachePath, 0600);
    return $result;
}

$cpu = dash_cpu(); $oldCpu = dash_delta('cpu', $cpu);
$total = max(1, $cpu['total'] - (int)$oldCpu['value']['total']);
$idle = max(0, $cpu['idle'] - (int)$oldCpu['value']['idle']);
$cpuPercent = is_array($windowsHostStats) ? round((float)($windowsHostStats['cpuPercent'] ?? 0), 1) : round(max(0, min(100, 100 * (1 - $idle / $total))), 1);
$memory = dash_values('/proc/meminfo');
$memoryTotal = is_array($windowsHostStats) ? (int)($windowsHostStats['memoryTotal'] ?? 0) : (int)($memory['MemTotal'] ?? 0) * 1024;
$memoryUsed = is_array($windowsHostStats) ? (int)($windowsHostStats['memoryUsed'] ?? 0) : max(0, $memoryTotal - (int)($memory['MemAvailable'] ?? 0) * 1024);
$network = dash_network(); $oldNetwork = dash_delta('network', $network); $seconds = (float)$oldNetwork['seconds'];
$rxRate = is_array($windowsHostStats) ? (float)($windowsHostStats['network']['downloadBps'] ?? 0) : max(0, ($network['rx'] - (int)$oldNetwork['value']['rx']) / $seconds);
$txRate = is_array($windowsHostStats) ? (float)($windowsHostStats['network']['uploadBps'] ?? 0) : max(0, ($network['tx'] - (int)$oldNetwork['value']['tx']) / $seconds);

$deviceNames = [];
foreach ((json_decode((string)@file_get_contents(__DIR__ . '/private/iptv-devices.json'), true) ?: []) as $device) {
    if (is_array($device) && !empty($device['id'])) $deviceNames[(string)$device['id']] = trim((string)($device['name'] ?? ''));
}
$viewers = [];
foreach (glob(__DIR__ . '/private/iptv-activity/*.json') ?: [] as $path) {
    $item = json_decode((string)@file_get_contents($path), true);
    if (!is_array($item)) { @unlink($path); continue; }
    $pid = (int)($item['pid'] ?? 0); $lastSeen = (int)($item['lastSeen'] ?? 0); $expires = (int)($item['expiresAt'] ?? 0);
    $heartbeatAt = (int)($item['heartbeatAt'] ?? 0);
    $running = ($pid > 0 && $lastSeen >= time() - 12) || $expires >= time() || $heartbeatAt >= time() - 35;
    if (!$running && $lastSeen < time() - 60) { @unlink($path); continue; }
    $recordId = (string)($item['deviceRecordId'] ?? ''); $fallbackDevice = dash_device((string)($item['userAgent'] ?? ''));
    $viewers[] = ['id' => (string)($item['clientId'] ?? substr(hash('sha256', (string)($item['ip'] ?? '') . '|' . (string)($item['userAgent'] ?? '')), 0, 16)), 'device' => $deviceNames[$recordId] ?? $fallbackDevice, 'deviceType' => $fallbackDevice, 'ip' => (string)($item['ip'] ?? ''), 'channel' => (string)($item['channel'] ?? 'Contenuto IPTV'), 'group' => (string)($item['group'] ?? 'TV'), 'startedAt' => (int)($item['startedAt'] ?? $lastSeen), 'lastSeen' => $lastSeen, 'live' => $running, 'rttMs' => (float)($item['rttMs'] ?? 0), 'bufferSeconds' => (float)($item['bufferSeconds'] ?? 0), 'readyState' => (int)($item['readyState'] ?? 0), 'stalled' => !empty($item['stalled']), 'effectiveType' => (string)($item['effectiveType'] ?? ''), 'downlinkMbps' => (float)($item['downlinkMbps'] ?? 0), 'deliveryMode' => (string)($item['deliveryMode'] ?? ''), 'droppedFrames' => (int)($item['droppedFrames'] ?? 0), 'totalFrames' => (int)($item['totalFrames'] ?? 0)];
}
usort($viewers, static fn(array $a, array $b): int => $b['lastSeen'] <=> $a['lastSeen']);
$sessions = 0;
foreach (glob(__DIR__ . '/private/iptv-sessions/*.json') ?: [] as $path) if ((int)@filemtime($path) >= time() - 21600) $sessions++;
$diskTotal = is_array($windowsHostStats) ? (int)($windowsHostStats['diskTotal'] ?? 0) : (int)@disk_total_space('/');
$diskFree = is_array($windowsHostStats) ? max(0, $diskTotal - (int)($windowsHostStats['diskUsed'] ?? 0)) : (int)@disk_free_space('/');
$catalog = json_decode((string)@file_get_contents(__DIR__ . '/private/catalog-stats.json'), true);
if (!is_array($catalog)) $catalog = ['updatedAt' => 0, 'channels' => 0, 'groups' => 0, 'cached' => false, 'loadMs' => 0];
$requestMetrics = ['requestsMinute' => 0, 'segmentsMinute' => 0, 'adaptiveMinute' => 0, 'adaptiveBytesMinute' => 0, 'logosMinute' => 0, 'relayMinute' => 0, 'errorsFiveMinutes' => 0, 'segmentAvgMs' => 0, 'abortedFiveMinutes' => 0, 'providerCallsMinute' => 0, 'providerAvgMs' => 0, 'providerErrorsFiveMinutes' => 0, 'lastProviderStatus' => 0];
$segmentTimes = []; $providerTimes = []; $latestProviderTime = 0; $clientMetrics = [];
foreach (@file(__DIR__ . '/private/request-metrics.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $metric = json_decode($line, true); if (!is_array($metric)) continue;
    $age = time() - (int)($metric['time'] ?? 0); if ($age < 0 || $age > 300) continue;
    $status = (int)($metric['status'] ?? 0); $type = (string)($metric['type'] ?? '');
    $isTakeover = !empty($metric['takeover']);
    $isProviderCall = !$isTakeover && in_array($type, ['channel', 'segment', 'relay'], true);
    if ($status >= 400 || $status === 0) $requestMetrics['errorsFiveMinutes']++;
    if ($isProviderCall && ($status >= 400 || $status === 0)) $requestMetrics['providerErrorsFiveMinutes']++;
    if (!empty($metric['aborted'])) $requestMetrics['abortedFiveMinutes']++;
    if ($isProviderCall && (int)($metric['time'] ?? 0) >= $latestProviderTime) {
        $latestProviderTime = (int)($metric['time'] ?? 0);
        $requestMetrics['lastProviderStatus'] = $status;
    }
    if ($age <= 60) {
        $requestMetrics['requestsMinute']++;
        if ($isProviderCall) { $requestMetrics['providerCallsMinute']++; $providerTimes[] = (int)($metric['ms'] ?? 0); }
        if ($type === 'segment') { $requestMetrics['segmentsMinute']++; $segmentTimes[] = (int)($metric['ms'] ?? 0); }
        if ($type === 'adaptive') { $requestMetrics['adaptiveMinute']++; $requestMetrics['adaptiveBytesMinute'] += max(0, (int)($metric['bytes'] ?? 0)); }
        if ($type === 'logo') $requestMetrics['logosMinute']++;
        if ($type === 'relay') $requestMetrics['relayMinute']++;
        $client = (string)($metric['client'] ?? '');
        if ($client !== '' && in_array($type, ['channel', 'segment', 'adaptive', 'relay', 'transcode'], true)) {
            if (!isset($clientMetrics[$client])) $clientMetrics[$client] = ['bytes' => 0, 'times' => [], 'segments' => 0, 'cached' => 0, 'errors' => 0];
            $clientMetrics[$client]['bytes'] += max(0, (int)($metric['bytes'] ?? 0));
            $clientMetrics[$client]['times'][] = max(0, (int)($metric['ms'] ?? 0));
            if (in_array($type, ['segment', 'adaptive'], true)) { $clientMetrics[$client]['segments']++; if (!empty($metric['cache'])) $clientMetrics[$client]['cached']++; }
            if ($status >= 400 || $status === 0 || !empty($metric['aborted'])) $clientMetrics[$client]['errors']++;
        }
    }
}
if ($segmentTimes) $requestMetrics['segmentAvgMs'] = (int)round(array_sum($segmentTimes) / count($segmentTimes));
if ($providerTimes) $requestMetrics['providerAvgMs'] = (int)round(array_sum($providerTimes) / count($providerTimes));
$requestMetrics['providerHost'] = dash_provider_host();
foreach ($viewers as &$viewer) {
    $metric = $clientMetrics[(string)$viewer['id']] ?? ['bytes' => 0, 'times' => [], 'segments' => 0, 'cached' => 0, 'errors' => 0];
    $viewer['bandwidthBps'] = (int)round((int)$metric['bytes'] / 60);
    $viewer['streamLatencyMs'] = $metric['times'] ? (int)round(array_sum($metric['times']) / count($metric['times'])) : 0;
    $viewer['cachePercent'] = $metric['segments'] ? (int)round(100 * $metric['cached'] / $metric['segments']) : 0;
    $viewer['errorsMinute'] = (int)$metric['errors'];
    $viewer['droppedPercent'] = $viewer['totalFrames'] > 0 ? round(100 * $viewer['droppedFrames'] / $viewer['totalFrames'], 2) : 0;
    $viewer['health'] = !$viewer['live'] ? 'recent' : (($viewer['stalled'] || ($viewer['readyState'] > 0 && $viewer['bufferSeconds'] < 2)) ? 'critical' : ($viewer['bufferSeconds'] < 8 || $viewer['rttMs'] > 600 ? 'warning' : 'good'));
}
unset($viewer);
if (is_array($windowsHostStats) && is_array($windowsHostStats['runtime'] ?? null)) {
    $runtime = $windowsHostStats['runtime'];
    $runtime['activeStreams'] = count(array_filter($viewers, static fn(array $v): bool => $v['live']));
} else {
    $phpProcessParents = [];
    foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $path) {
        $cmd = str_replace("\0", ' ', (string)@file_get_contents($path));
        if (!str_contains($cmd, '/usr/bin/php') || !str_contains($cmd, '0.0.0.0:8765')) continue;
        $pid = (int)basename(dirname($path));
        $status = dash_values('/proc/' . $pid . '/status');
        $phpProcessParents[$pid] = (int)($status['PPid'] ?? 0);
    }
    $phpProcesses = count($phpProcessParents);
    $childCounts = [];
    foreach ($phpProcessParents as $parent) if (isset($phpProcessParents[$parent])) $childCounts[$parent] = ($childCounts[$parent] ?? 0) + 1;
    $masterPid = 0;
    if ($childCounts) { arsort($childCounts); $masterPid = (int)array_key_first($childCounts); }
    $workerPids = array_values(array_filter(array_keys($phpProcessParents), static fn(int $pid): bool => $pid !== $masterPid));
    sort($workerPids, SORT_NUMERIC);
    $workerDetails = []; $busyWorkers = 0; $mediaWorkers = 0;
    foreach ($workerPids as $index => $pid) {
        $activity = json_decode((string)@file_get_contents(__DIR__ . '/private/worker-activity/' . $pid . '.json'), true);
        if (!is_array($activity)) $activity = [];
        $busy = ($activity['state'] ?? '') === 'busy';
        $task = trim((string)($activity['task'] ?? '')) ?: 'In attesa';
        if ($busy) { $busyWorkers++; if (!in_array($task, ['Monitor server', 'Console server', 'Presenza dispositivo'], true)) $mediaWorkers++; }
        $status = dash_values('/proc/' . $pid . '/status');
        $rssKb = (int)preg_replace('/\D+/', '', (string)($status['VmRSS'] ?? '0'));
        $startedAt = (float)($activity['startedAt'] ?? 0);
        $workerDetails[] = ['number' => $index + 1, 'pid' => $pid, 'busy' => $busy, 'task' => $task, 'durationMs' => $busy && $startedAt > 0 ? (int)round((microtime(true) - $startedAt) * 1000) : (int)($activity['durationMs'] ?? 0), 'memoryMb' => round($rssKb / 1024, 1), 'updatedAt' => (float)($activity['updatedAt'] ?? 0)];
    }
    $runtime = ['workers' => count($workerPids), 'processes' => $phpProcesses, 'busyWorkers' => $busyWorkers, 'mediaWorkers' => $mediaWorkers, 'idleWorkers' => max(0, count($workerPids) - $busyWorkers), 'workerDetails' => $workerDetails, 'activeStreams' => count(array_filter($viewers, static fn(array $v): bool => $v['live']))];
}
$prefetch = json_decode((string)@file_get_contents(__DIR__ . '/private/iptv-prefetch-state.json'), true);
if (!is_array($prefetch)) $prefetch = [];
$prefetchUpdatedAt = (float)($prefetch['updatedAt'] ?? 0);
$prefetch = [
    'online' => $prefetchUpdatedAt > microtime(true) - 45,
    'updatedAt' => $prefetchUpdatedAt,
    'activeChannels' => max(0, (int)($prefetch['activeChannels'] ?? 0)),
    'queueDepth' => max(0, (int)($prefetch['queueDepth'] ?? 0)),
    'readySegments' => max(0, (int)($prefetch['readySegments'] ?? 0)),
    'readySeconds' => max(0, round((float)($prefetch['readySeconds'] ?? 0), 1)),
    'viewerCount' => max(1, (int)($prefetch['viewerCount'] ?? 1)),
    'targetDelaySeconds' => max(0, (int)($prefetch['targetDelaySeconds'] ?? 0)),
    'downloadedCycle' => max(0, (int)($prefetch['downloadedCycle'] ?? 0)),
    'downloadsTotal' => max(0, (int)($prefetch['downloadsTotal'] ?? 0)),
    'failuresTotal' => max(0, (int)($prefetch['failuresTotal'] ?? 0)),
    'bytesTotal' => max(0, (int)($prefetch['bytesTotal'] ?? 0)),
    'cacheBytes' => max(0, (int)($prefetch['cacheBytes'] ?? 0)),
    'cacheLimitBytes' => max(0, (int)($prefetch['cacheLimitBytes'] ?? 0)),
    'retentionSeconds' => max(0, (int)($prefetch['retentionSeconds'] ?? 0)),
    'cacheBackend' => in_array((string)($prefetch['cacheBackend'] ?? ''), ['ram', 'ssd'], true) ? (string)$prefetch['cacheBackend'] : 'unknown',
    'lastDownloadMs' => max(0, (int)($prefetch['lastDownloadMs'] ?? 0)),
];
$desktopAssist = dash_desktop_assist();
foreach ($viewers as &$viewer) {
    $requested = in_array((string)($viewer['deliveryMode'] ?? ''), ['adaptive-requested', 'desktop-adaptive'], true);
    if ($requested) $viewer['deliveryMode'] = !empty($desktopAssist['online']) && (int)($desktopAssist['activeStreams'] ?? 0) > 0 ? 'desktop-adaptive' : 'adaptive-fallback';
}
unset($viewer);

$serverUptime = is_array($windowsHostStats) ? (int)($windowsHostStats['uptime'] ?? 0) : (int)(float)trim((string)@file_get_contents('/proc/uptime'));
$serverLoad = is_array($windowsHostStats) ? [round($cpuPercent / 100, 2), 0, 0] : array_map(static fn($v): float => round((float)$v, 2), sys_getloadavg() ?: [0,0,0]);
$serverHostname = is_array($windowsHostStats) ? trim((string)($windowsHostStats['hostname'] ?? '')) : (gethostname() ?: 'tubetv-host');
echo json_encode(['ok' => true, 'time' => gmdate('c'), 'server' => ['hostname' => $serverHostname ?: 'tubetv-desktop-host', 'uptime' => $serverUptime, 'load' => $serverLoad, 'cpuPercent' => $cpuPercent, 'memoryUsed' => $memoryUsed, 'memoryTotal' => $memoryTotal, 'diskUsed' => max(0, $diskTotal - $diskFree), 'diskTotal' => $diskTotal, 'temperature' => dash_temperature(), 'pingMs' => dash_ping()], 'network' => ['downloadBps' => round($rxRate), 'uploadBps' => round($txRate), 'received' => $network['rx'], 'sent' => $network['tx']], 'streaming' => ['activeViewers' => count(array_filter($viewers, static fn(array $v): bool => $v['live'])), 'sessions' => $sessions, 'viewers' => array_slice($viewers, 0, 12)], 'requests' => $requestMetrics, 'catalog' => $catalog, 'runtime' => $runtime, 'prefetch' => $prefetch, 'desktopAssist' => $desktopAssist], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
