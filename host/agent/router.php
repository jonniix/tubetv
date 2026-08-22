<?php
declare(strict_types=1);

$origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
$allowedOrigins = [
    'https://tubetv.online',
    'https://www.tubetv.online',
    'http://localhost',
    'http://127.0.0.1',
];
$originAllowed = $origin === '' || in_array($origin, $allowedOrigins, true);
if (!$originAllowed) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('ORIGIN_NOT_ALLOWED');
}
if ($origin !== '') {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Range');
header('Access-Control-Expose-Headers: Content-Length, Content-Range, Accept-Ranges');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$path = rawurldecode((string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/'));
$requestMetricStarted = microtime(true);
if ($path === '/api/iptv-stream.php' || $path === '/api/iptv-adaptive.php' || $path === '/api/iptv-relay.php' || $path === '/api/iptv-transcode.php') {
    $requestMetricType = $path === '/api/iptv-adaptive.php' ? 'adaptive' : ($path === '/api/iptv-relay.php' ? 'relay' : ($path === '/api/iptv-transcode.php' ? 'transcode' : (isset($_GET['asset']) ? 'logo' : (isset($_GET['channel']) ? 'channel' : 'segment'))));
    $requestMetricClient = substr(hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? '') . '|' . (string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 16);
    register_shutdown_function(static function() use ($requestMetricStarted, $requestMetricType): void {
        $metricPath = __DIR__ . '/private/request-metrics.log';
        $responseStatus = http_response_code(); if ($responseStatus === false || $responseStatus < 100) $responseStatus = 200;
        $line = json_encode(['time' => time(), 'type' => $requestMetricType, 'client' => $GLOBALS['requestMetricClient'] ?? '', 'status' => $responseStatus, 'ms' => (int)round((microtime(true) - $requestMetricStarted) * 1000), 'bytes' => (int)($GLOBALS['IPTV_METRIC_BYTES'] ?? 0), 'cache' => !empty($GLOBALS['IPTV_METRIC_CACHE']) ? 1 : 0, 'takeover' => !empty($GLOBALS['IPTV_METRIC_TAKEOVER']) ? 1 : 0, 'aborted' => connection_aborted() ? 1 : 0], JSON_UNESCAPED_SLASHES) . "\n";
        $handle = @fopen($metricPath, 'c+');
        if (!$handle) return;
        if (@flock($handle, LOCK_EX)) {
            $stat = fstat($handle);
            if ((int)($stat['size'] ?? 0) > 2097152) ftruncate($handle, 0);
            fseek($handle, 0, SEEK_END); fwrite($handle, $line); fflush($handle); flock($handle, LOCK_UN);
        }
        fclose($handle); @chmod($metricPath, 0600);
    });
    $GLOBALS['requestMetricClient'] = $requestMetricClient;
}

// Publish a credential-free snapshot of what each PHP worker is doing. The
// dashboard reads these small files locally; provider URLs are never stored.
$workerPid = getmypid();
$workerDir = __DIR__ . '/private/worker-activity';
if (!is_dir($workerDir)) { @mkdir($workerDir, 0700, true); @chmod($workerDir, 0700); }
$workerTask = match ($path) {
    '/api/iptv-stream.php' => match ($requestMetricType ?? '') {
        'channel' => 'Apertura canale', 'segment' => 'Segmento video', 'logo' => 'Logo canale', default => 'Stream IPTV'
    },
    '/api/iptv-adaptive.php' => isset($_GET['file']) && !str_ends_with((string)$_GET['file'], '.m3u8') ? 'Segmento RTX adattivo' : 'Qualità automatica RTX',
    '/api/iptv-relay.php' => 'Relay video',
    '/api/iptv-transcode.php' => 'Conversione video',
    '/api/iptv-catalog.php' => 'Catalogo IPTV',
    '/api/iptv-play-session.php' => 'Preparazione canale',
    '/api/iptv-catalog-section.php' => 'Sezione catalogo',
    '/api/iptv-catalog-browse.php' => 'Navigazione catalogo',
    '/api/iptv-epg.php' => 'Guida TV',
    '/api/client-heartbeat.php' => 'Presenza dispositivo',
    '/api/dashboard-stats.php' => 'Monitor server',
    '/dashboard', '/dashboard/' => 'Console server',
    default => 'Servizio web',
};
$workerPath = $workerDir . '/' . $workerPid . '.json';
$workerStarted = microtime(true);
$workerWrite = static function(array $record) use ($workerPath, $workerPid): void {
    $tmp = $workerPath . '.' . $workerPid . '.tmp';
    if (@file_put_contents($tmp, json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false) {
        @chmod($tmp, 0600); @rename($tmp, $workerPath);
    }
};
$workerWrite(['pid' => $workerPid, 'state' => 'busy', 'task' => $workerTask, 'startedAt' => $workerStarted, 'updatedAt' => microtime(true)]);
register_shutdown_function(static function() use ($workerWrite, $workerPid, $workerTask, $workerStarted): void {
    $workerWrite(['pid' => $workerPid, 'state' => 'idle', 'task' => $workerTask, 'startedAt' => $workerStarted, 'updatedAt' => microtime(true), 'durationMs' => (int)round((microtime(true) - $workerStarted) * 1000)]);
});
$dashboardPaths = ['/dashboard', '/dashboard/', '/api/dashboard-stats.php'];
$requestHost = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
$dashboardLocal = in_array((string)($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true)
    && preg_match('/^(?:127\.0\.0\.1|localhost|\[::1\])(?::8765)?$/', $requestHost) === 1
    && trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')) === '';
if (in_array($path, $dashboardPaths, true) && !$dashboardLocal) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('DASHBOARD_LOCAL_ONLY');
}
$root = __DIR__;
$routes = [
    '/' => $root . '/health.php',
    '/health' => $root . '/health.php',
    '/dashboard' => $root . '/dashboard.php',
    '/dashboard/' => $root . '/dashboard.php',
    '/api/dashboard-stats.php' => $root . '/dashboard-stats.php',
    '/api/iptv-catalog.php' => $root . '/catalog.php',
    '/api/iptv-play-session.php' => $root . '/play-session.php',
    '/api/iptv-catalog-section.php' => $root . '/catalog-section.php',
    '/api/iptv-catalog-browse.php' => $root . '/catalog-browse.php',
    '/api/iptv-stream.php' => $root . '/api/iptv-stream.php',
    '/api/iptv-adaptive.php' => $root . '/api/iptv-adaptive.php',
    '/api/iptv-relay.php' => $root . '/api/iptv-relay.php',
    '/api/iptv-transcode.php' => $root . '/api/iptv-transcode.php',
    '/api/iptv-epg.php' => $root . '/api/iptv-epg.php',
    '/api/client-heartbeat.php' => $root . '/client-heartbeat.php',
];

$target = $routes[$path] ?? '';
if ($target === '' || !is_file($target)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('TUBETV_HOST_ROUTE_NOT_FOUND');
}
require $target;
