<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

$allowed = [
    'iptv-catalog.php',
    'iptv-catalog-browse.php',
    'iptv-catalog-section.php',
    'iptv-play-session.php',
    'iptv-epg.php',
    'client-heartbeat.php',
];
$target = basename(trim((string)($_GET['path'] ?? '')));
if (!in_array($target, $allowed, true)) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'IPTV_CONTROL_ROUTE_INVALID']);
    exit;
}
if (!function_exists('curl_init')) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'IPTV_CONTROL_PROXY_UNAVAILABLE']);
    exit;
}

$query = $_GET;
unset($query['path']);
$url = 'https://desktop-9q1u4sk.tail4f29b5.ts.net/api/' . $target;
if ($query) $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    exit;
}

$responseType = 'application/json; charset=utf-8';
$curl = curl_init($url);
$headers = [
    'Accept: application/json',
    'Origin: https://tubetv.online',
    'User-Agent: ' . substr((string)($_SERVER['HTTP_USER_AGENT'] ?? 'TubeTV-Control-Proxy/1.0'), 0, 500),
];
$options = [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT => $target === 'iptv-catalog.php' ? 90 : 35,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_HEADERFUNCTION => static function($handle, string $line) use (&$responseType): int {
        if (preg_match('/^Content-Type:\s*([^\r\n]+)/i', trim($line), $match)) $responseType = trim($match[1]);
        return strlen($line);
    },
];
if ($method === 'POST') {
    $options[CURLOPT_POST] = true;
    $options[CURLOPT_POSTFIELDS] = (string)file_get_contents('php://input');
    $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
}
curl_setopt_array($curl, $options);
$body = curl_exec($curl);
$status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
$failed = !is_string($body) || curl_errno($curl) !== 0 || $status < 100;
curl_close($curl);
if ($failed) {
    http_response_code(502);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'IPTV_HOST_UNREACHABLE']);
    exit;
}
http_response_code($status);
header('Content-Type: ' . $responseType);
echo $body;
