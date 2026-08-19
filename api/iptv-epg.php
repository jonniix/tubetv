<?php
declare(strict_types=1);
require __DIR__ . '/iptv-lib.php';
@set_time_limit(300);

$token = trim((string)($_GET['session'] ?? ''));
$channelId = trim((string)($_GET['channel'] ?? ''));
$session = iptv_load_session($token);
if (!$session) iptv_json(['ok' => false, 'error' => 'IPTV_SESSION_EXPIRED'], 401);
$meta = is_array($session['channelMeta'][$channelId] ?? null) ? $session['channelMeta'][$channelId] : [];
if (!$meta) iptv_json(['ok' => false, 'error' => 'IPTV_CHANNEL_NOT_FOUND'], 404);

$config = iptv_load_config();
$epgUrl = trim((string)($session['epgUrl'] ?? '')) ?: iptv_epg_url($config);
if ($epgUrl === '' || !iptv_url_allowed($epgUrl)) iptv_json(['ok' => true, 'available' => false, 'programmes' => []]);

$cacheDir = iptv_private_dir() . DIRECTORY_SEPARATOR . 'iptv-epg-cache';
iptv_ensure_private_dir($cacheDir);
$sourcePath = $cacheDir . DIRECTORY_SEPARATOR . hash('sha256', $epgUrl) . '.xml';

if (!is_file($sourcePath) || (int)@filemtime($sourcePath) < time() - 14400) {
    $downloadPath = $sourcePath . '.download';
    $handle = @fopen($downloadPath, 'wb');
    if (!$handle) iptv_json(['ok' => false, 'error' => 'EPG_CACHE_NOT_WRITABLE'], 500);
    $ok = false; $status = 0; $error = '';
    if (function_exists('curl_init')) {
        $tooLarge = false;
        $ch = curl_init($epgUrl);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $handle, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 12, CURLOPT_TIMEOUT => 240, CURLOPT_MAXREDIRS => 7,
            CURLOPT_USERAGENT => 'TubeTV-IPTV/1.0', CURLOPT_ENCODING => '', CURLOPT_NOPROGRESS => false,
            CURLOPT_XFERINFOFUNCTION => function($curl, $total, $done) use (&$tooLarge) {
                if ($done > 536870912 || $total > 536870912) { $tooLarge = true; return 1; }
                return 0;
            },
        ]);
        $ok = curl_exec($ch) !== false;
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = $tooLarge ? 'EPG_TOO_LARGE' : (string)curl_error($ch);
        curl_close($ch);
    } else {
        $context = stream_context_create(['http' => ['timeout' => 240, 'follow_location' => 1, 'header' => "User-Agent: TubeTV-IPTV/1.0\r\nAccept-Encoding: identity\r\n"]]);
        $input = @fopen($epgUrl, 'rb', false, $context);
        if ($input) {
            $bytes = stream_copy_to_stream($input, $handle, 536870913);
            fclose($input); $ok = is_int($bytes) && $bytes <= 536870912;
            if (!$ok) $error = 'EPG_TOO_LARGE';
        } else $error = 'EPG_DOWNLOAD_FAILED';
    }
    fclose($handle);
    if (!$ok || ($status !== 0 && ($status < 200 || $status >= 300))) {
        @unlink($downloadPath);
        iptv_json(['ok' => false, 'error' => $error !== '' ? $error : 'EPG_HTTP_' . $status], 502);
    }
    $magic = (string)@file_get_contents($downloadPath, false, null, 0, 2);
    if ($magic === "\x1f\x8b") {
        $gz = @gzopen($downloadPath, 'rb'); $out = @fopen($sourcePath . '.tmp', 'wb');
        if (!$gz || !$out) { if ($gz) gzclose($gz); if ($out) fclose($out); @unlink($downloadPath); iptv_json(['ok' => false, 'error' => 'EPG_GZIP_FAILED'], 502); }
        while (!gzeof($gz)) { $chunk = gzread($gz, 1048576); if ($chunk === false) break; fwrite($out, $chunk); }
        gzclose($gz); fclose($out); @unlink($downloadPath); @rename($sourcePath . '.tmp', $sourcePath);
    } else {
        @rename($downloadPath, $sourcePath);
    }
    @chmod($sourcePath, 0600);
}

function epg_norm(string $value): string {
    $value = function_exists('mb_strtolower') ? mb_strtolower(trim($value), 'UTF-8') : strtolower(trim($value));
    return preg_replace('/[^\pL\pN]+/u', '', $value) ?? $value;
}
function epg_time(string $raw): int {
    if (!preg_match('/^(\d{14})(?:\s*([+-]\d{4}|Z))?/', trim($raw), $m)) return 0;
    $zone = ($m[2] ?? '') === 'Z' || ($m[2] ?? '') === '' ? '+0000' : $m[2];
    $date = DateTimeImmutable::createFromFormat('!YmdHis O', $m[1] . ' ' . $zone);
    return $date ? $date->getTimestamp() : 0;
}

$wantedId = trim((string)($meta['tvgId'] ?? ''));
$wantedName = epg_norm((string)($meta['name'] ?? ''));
$matchIds = $wantedId !== '' ? [strtolower($wantedId) => true] : [];
$programmes = []; $now = time(); $maxEnd = $now + 172800;
$reader = new XMLReader();
if (!$reader->open($sourcePath, null, LIBXML_NONET | LIBXML_COMPACT)) iptv_json(['ok' => false, 'error' => 'EPG_XML_INVALID'], 502);
while ($reader->read()) {
    if ($reader->nodeType !== XMLReader::ELEMENT) continue;
    if ($reader->name === 'channel') {
        $id = trim((string)$reader->getAttribute('id'));
        if ($id !== '' && ($wantedId === '' || !isset($matchIds[strtolower($id)]))) {
            $xml = @simplexml_load_string($reader->readOuterXml(), SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
            if ($xml && $wantedName !== '') foreach ($xml->{'display-name'} as $display) {
                if (epg_norm((string)$display) === $wantedName) { $matchIds[strtolower($id)] = true; break; }
            }
        }
        continue;
    }
    if ($reader->name !== 'programme') continue;
    $programmeChannel = strtolower(trim((string)$reader->getAttribute('channel')));
    if (!isset($matchIds[$programmeChannel])) continue;
    $start = epg_time((string)$reader->getAttribute('start'));
    $stop = epg_time((string)$reader->getAttribute('stop'));
    if ($start === 0 || $stop < $now - 7200 || $start > $maxEnd) continue;
    $xml = @simplexml_load_string($reader->readOuterXml(), SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
    if (!$xml) continue;
    $programmes[] = [
        'start' => gmdate('c', $start), 'stop' => $stop > 0 ? gmdate('c', $stop) : '',
        'title' => trim((string)($xml->title ?? 'Programma TV')),
        'description' => trim((string)($xml->desc ?? '')),
        'category' => trim((string)($xml->category ?? '')),
        'isNow' => $start <= $now && ($stop === 0 || $stop > $now),
    ];
    if (count($programmes) >= 16) break;
}
$reader->close();
usort($programmes, fn($a, $b) => strcmp((string)$a['start'], (string)$b['start']));
iptv_json(['ok' => true, 'available' => count($programmes) > 0, 'programmes' => $programmes]);
