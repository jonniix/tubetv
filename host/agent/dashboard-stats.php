<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

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
    $parts = preg_split('/\s+/', trim((string)(@file('/proc/stat')[0] ?? ''))) ?: [];
    array_shift($parts); $values = array_map('intval', array_slice($parts, 0, 10));
    return ['total' => array_sum($values), 'idle' => ($values[3] ?? 0) + ($values[4] ?? 0)];
}
function dash_network(): array {
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
    foreach (glob('/sys/class/thermal/thermal_zone*/temp') ?: [] as $path) {
        $value = (float)trim((string)@file_get_contents($path));
        if ($value > 1000) $value /= 1000;
        if ($value > 0 && $value < 130) return round($value, 1);
    }
    return null;
}
function dash_ping(): ?float {
    if (!is_executable('/usr/bin/ping')) return null;
    $lines = []; $code = 1; @exec('/usr/bin/ping -n -c 1 -W 1 1.1.1 2>/dev/null', $lines, $code);
    return $code === 0 && preg_match('/time[=<]([0-9.]+)\s*ms/i', implode(' ', $lines), $m) ? round((float)$m[1], 1) : null;
}
function dash_device(string $agent): string {
    if (preg_match('/iPhone|iPad/i', $agent)) return 'Apple mobile';
    if (preg_match('/Android/i', $agent)) return 'Android';
    if (preg_match('/Windows/i', $agent)) return 'PC Windows';
    if (preg_match('/Macintosh|Mac OS/i', $agent)) return 'Mac';
    if (preg_match('/TV|Tizen|Web0S|SmartTV/i', $agent)) return 'Smart TV';
    return 'Dispositivo web';
}

$cpu = dash_cpu(); $oldCpu = dash_delta('cpu', $cpu);
$total = max(1, $cpu['total'] - (int)$oldCpu['value']['total']);
$idle = max(0, $cpu['idle'] - (int)$oldCpu['value']['idle']);
$cpuPercent = round(max(0, min(100, 100 * (1 - $idle / $total))), 1);
$memory = dash_values('/proc/meminfo');
$memoryTotal = (int)($memory['MemTotal'] ?? 0) * 1024;
$memoryUsed = max(0, $memoryTotal - (int)($memory['MemAvailable'] ?? 0) * 1024);
$network = dash_network(); $oldNetwork = dash_delta('network', $network); $seconds = (float)$oldNetwork['seconds'];
$rxRate = max(0, ($network['rx'] - (int)$oldNetwork['value']['rx']) / $seconds);
$txRate = max(0, ($network['tx'] - (int)$oldNetwork['value']['tx']) / $seconds);

$viewers = [];
foreach (glob(__DIR__ . '/private/iptv-activity/*.json') ?: [] as $path) {
    $item = json_decode((string)@file_get_contents($path), true);
    if (!is_array($item)) { @unlink($path); continue; }
    $pid = (int)($item['pid'] ?? 0); $lastSeen = (int)($item['lastSeen'] ?? 0); $expires = (int)($item['expiresAt'] ?? 0);
    $running = $pid > 0 && is_dir('/proc/' . $pid);
    if (!$running && $expires < time() && $lastSeen < time() - 20) { @unlink($path); continue; }
    $viewers[] = ['device' => dash_device((string)($item['userAgent'] ?? '')), 'ip' => (string)($item['ip'] ?? ''), 'channel' => (string)($item['channel'] ?? 'Contenuto IPTV'), 'group' => (string)($item['group'] ?? 'TV'), 'startedAt' => (int)($item['startedAt'] ?? $lastSeen), 'lastSeen' => $lastSeen, 'live' => $running || $expires >= time()];
}
usort($viewers, static fn(array $a, array $b): int => $b['lastSeen'] <=> $a['lastSeen']);
$sessions = 0;
foreach (glob(__DIR__ . '/private/iptv-sessions/*.json') ?: [] as $path) if ((int)@filemtime($path) >= time() - 21600) $sessions++;
$diskTotal = (int)@disk_total_space('/'); $diskFree = (int)@disk_free_space('/');

echo json_encode(['ok' => true, 'time' => gmdate('c'), 'server' => ['hostname' => gethostname() ?: 'tubetv-host', 'uptime' => (int)(float)trim((string)@file_get_contents('/proc/uptime')), 'load' => array_map(static fn($v): float => round((float)$v, 2), sys_getloadavg() ?: [0,0,0]), 'cpuPercent' => $cpuPercent, 'memoryUsed' => $memoryUsed, 'memoryTotal' => $memoryTotal, 'diskUsed' => max(0, $diskTotal - $diskFree), 'diskTotal' => $diskTotal, 'temperature' => dash_temperature(), 'pingMs' => dash_ping()], 'network' => ['downloadBps' => round($rxRate), 'uploadBps' => round($txRate), 'received' => $network['rx'], 'sent' => $network['tx']], 'streaming' => ['activeViewers' => count(array_filter($viewers, static fn(array $v): bool => $v['live'])), 'sessions' => $sessions, 'viewers' => array_slice($viewers, 0, 12)]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
