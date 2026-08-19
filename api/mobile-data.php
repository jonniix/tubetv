<?php
declare(strict_types=1);

$source = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'tubetv-data.json';
if (!is_file($source)) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'DATA_NOT_FOUND']);
    exit;
}

$mtime = (int)@filemtime($source);
$etag = '"mobile-' . $mtime . '-' . (int)@filesize($source) . '"';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=30, must-revalidate');
header('ETag: ' . $etag);
header('X-Content-Type-Options: nosniff');
if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}

$decoded = json_decode((string)@file_get_contents($source), true);
if (!is_array($decoded)) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'DATA_INVALID']);
    exit;
}

function mobile_trim_text($value, int $limit = 700) {
    if (!is_string($value) || strlen($value) <= $limit) return $value;
    $cut = function_exists('mb_strcut') ? mb_strcut($value, 0, $limit, 'UTF-8') : substr($value, 0, $limit);
    if (function_exists('iconv')) $cut = (string)@iconv('UTF-8', 'UTF-8//IGNORE', $cut);
    return $cut . '…';
}

function mobile_compact_item($item): array {
    if (!is_array($item)) return [];
    foreach (['description', 'desc'] as $key) if (isset($item[$key])) $item[$key] = mobile_trim_text($item[$key]);
    if (is_array($item['localized'] ?? null)) {
        $localized = $item['localized'];
        if (isset($localized['description'])) $localized['description'] = mobile_trim_text($localized['description']);
        $item['localized'] = $localized;
    }
    return $item;
}

function mobile_compact_list($items): array {
    if (!is_array($items)) return [];
    return array_values(array_map('mobile_compact_item', $items));
}

$out = [];
$simpleKeys = ['channels', 'events', 'liveQueue', 'schedule', 'topContent', 'liveState', 'settings', 'publicLiveSchedule', 'liveRuntime', 'adSettings', 'playerPolicy', 'exportedAt', 'version'];
foreach ($simpleKeys as $key) if (array_key_exists($key, $decoded)) $out[$key] = $decoded[$key];
$out['videos'] = mobile_compact_list($decoded['videos'] ?? []);
$out['series'] = mobile_compact_list($decoded['series'] ?? []);
foreach ($out['series'] as &$series) unset($series['episodes']);
unset($series);
$out['seriesEpisodes'] = [];
foreach ((array)($decoded['seriesEpisodes'] ?? []) as $id => $episodes) $out['seriesEpisodes'][(string)$id] = mobile_compact_list($episodes);
$out['videoLibrary'] = [];
foreach ((array)($decoded['videoLibrary'] ?? []) as $key => $items) $out['videoLibrary'][(string)$key] = mobile_compact_list($items);

$json = json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
if ($json === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'ENCODE_FAILED']);
    exit;
}
echo $json;
