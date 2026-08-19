<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=30, must-revalidate');
header('X-Content-Type-Options: nosniff');
$path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'tubetv-data.json';
$data = is_file($path) ? json_decode((string)@file_get_contents($path), true) : null;
if (!is_array($data)) { http_response_code(503); echo json_encode(['ok' => false, 'error' => 'DATA_UNAVAILABLE']); exit; }

function tvl_id($item): string { return is_array($item) ? trim((string)($item['videoId'] ?? $item['id'] ?? '')) : ''; }
function tvl_text($value, int $max): string { $value = trim((string)$value); return function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max); }
function tvl_item(array $item): ?array {
    $id = tvl_id($item); if (!preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) return null;
    $duration = (int)($item['durationSeconds'] ?? $item['durationSecs'] ?? 0);
    if ($duration > 0 && ($duration < 180 || $duration > 10800)) return null;
    return ['videoId' => $id, 'title' => tvl_text($item['title'] ?? 'Video', 150), 'channel' => tvl_text($item['channel'] ?? $item['channelTitle'] ?? '', 80), 'thumbnail' => (string)($item['thumbnail'] ?? $item['thumb'] ?? ('https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg')), 'publishedAt' => (string)($item['publishedAt'] ?? $item['date'] ?? ''), 'durationSeconds' => $duration, 'category' => tvl_text($item['category'] ?? 'Generale', 50) ?: 'Generale'];
}

$unique = [];
foreach ((array)($data['videos'] ?? []) as $raw) { if (!is_array($raw)) continue; $item = tvl_item($raw); if ($item) $unique[$item['videoId']] = $item; }
$items = array_values($unique);
usort($items, fn($a, $b) => strcmp((string)$b['publishedAt'], (string)$a['publishedAt']));
$groups = ['novita' => ['id' => 'novita', 'label' => 'Novità', 'items' => array_slice($items, 0, 48)]];
foreach ($items as $item) {
    $label = $item['category'] ?: 'Generale'; $id = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $label) ?? 'generale'); $id = trim($id, '-') ?: 'generale';
    if (!isset($groups[$id])) $groups[$id] = ['id' => $id, 'label' => $label, 'items' => []];
    if (count($groups[$id]['items']) < 60) $groups[$id]['items'][] = $item;
}
$categories = [];
foreach ($groups as $group) { $group['count'] = count($group['items']); if ($group['count']) $categories[] = $group; }
echo json_encode(['ok' => true, 'categories' => $categories, 'generatedAt' => gmdate('c')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

