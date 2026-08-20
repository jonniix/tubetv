<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

$path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'tubetv-data.json';
$data = is_file($path) ? json_decode((string)@file_get_contents($path), true) : null;
if (!is_array($data)) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'LIVE_DATA_UNAVAILABLE']);
    exit;
}

function live_state_ts($value): int {
    $parsed = strtotime(trim((string)$value));
    return $parsed === false ? 0 : $parsed;
}

function live_state_id($item): string {
    return is_array($item) ? trim((string)($item['videoId'] ?? $item['id'] ?? '')) : '';
}

function live_state_duration(array $item): int {
    $duration = (int)($item['durationSeconds'] ?? $item['durationSecs'] ?? 0);
    if ($duration <= 0 && !empty($item['durationMin'])) $duration = (int)$item['durationMin'] * 60;
    return max(1, $duration ?: 1800);
}

$now = time();
$stationId = strtolower(trim((string)($_GET['channel'] ?? 'main')));
$allowedStations = ['main', 'live2', 'kids', 'crime', 'docu', 'cucina'];
if (!in_array($stationId, $allowedStations, true)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'LIVE_CHANNEL_NOT_FOUND']);
    exit;
}
$secondary = $stationId !== 'main';
$station = $secondary && is_array($data['webLiveChannels'][$stationId] ?? null)
    ? $data['webLiveChannels'][$stationId]
    : [];
require_once __DIR__ . DIRECTORY_SEPARATOR . 'schedule-engine.php';
if (!$secondary && function_exists('se_ensure_daily_schedule')) {
    // Read-time fallback: a viewer still receives today's deterministic
    // timeline when the hosting cron has not regenerated it yet.
    se_ensure_daily_schedule($data, $now, false);
}
$schedule = $secondary
    ? (is_array($station['schedule'] ?? null) ? $station['schedule'] : [])
    : (is_array($data['schedule'] ?? null) ? $data['schedule'] : []);
$timeline = [];
foreach ($schedule as $item) {
    if (!is_array($item) || live_state_id($item) === '' || (($item['type'] ?? 'content') !== 'content')) continue;
    $start = live_state_ts($item['startDateTime'] ?? $item['scheduledStartDateTime'] ?? '');
    $duration = live_state_duration($item);
    $end = live_state_ts($item['endDateTime'] ?? $item['scheduledEndDateTime'] ?? '');
    if ($start <= 0) continue;
    if ($end <= $start) $end = $start + $duration;
    $item['startDateTime'] = gmdate('Y-m-d\TH:i:s', $start) . '.000Z';
    $item['actualStartDateTime'] = $item['startDateTime'];
    $item['endDateTime'] = gmdate('Y-m-d\TH:i:s', $end) . '.000Z';
    $item['durationSeconds'] = $duration;
    $timeline[] = ['start' => $start, 'end' => $end, 'item' => $item];
}
usort($timeline, static fn(array $a, array $b): int => $a['start'] <=> $b['start']);

$currentIndex = null;
foreach ($timeline as $index => $entry) {
    if ($now >= $entry['start'] && $now < $entry['end']) { $currentIndex = $index; break; }
}

$projected = $currentIndex !== null;
if ($projected) {
    $queue = [];
    for ($i = $currentIndex; $i < count($timeline) && count($queue) < 3; $i++) $queue[] = $timeline[$i]['item'];
    $current = $queue[0];
    $duration = live_state_duration($current);
    $existing = $secondary
        ? (is_array($station['liveState'] ?? null) ? $station['liveState'] : [])
        : (is_array($data['liveState'] ?? null) ? $data['liveState'] : []);
    $state = array_merge($existing, [
        'type' => 'video',
        'status' => 'playing',
        'mode' => 'LIVE',
        'phase' => 'content',
        'currentVideoId' => live_state_id($current),
        'currentTitle' => (string)($current['title'] ?? 'TubeTV Live'),
        'currentChannel' => (string)($current['channel'] ?? $current['channelTitle'] ?? 'TubeTV'),
        'currentDurationSeconds' => $duration,
        'currentStartedAt' => (string)$current['startDateTime'],
        'actualStartDateTime' => (string)$current['startDateTime'],
        'currentEndsAt' => (string)$current['endDateTime'],
        'offset' => max(0, $now - $timeline[$currentIndex]['start']),
        'currentVideoOffset' => max(0, $now - $timeline[$currentIndex]['start']),
        'pendingNext' => $queue[1] ?? null,
        'transitionState' => ['active' => false, 'startedAt' => null, 'durationSeconds' => 0],
        'adState' => ['active' => false, 'startedAt' => null, 'durationSeconds' => 0],
        'serverNowAtPublish' => gmdate('Y-m-d\TH:i:s', $now) . '.000Z',
        'updatedAt' => gmdate('Y-m-d\TH:i:s', $now) . '.000Z',
        'currentChangedBy' => 'bot-v3-projection',
        'currentChangeReason' => 'wall_clock_read_projection',
        'projectedFromSchedule' => true,
    ]);
} else {
    $state = $secondary
        ? (is_array($station['liveState'] ?? null) ? $station['liveState'] : [])
        : (is_array($data['liveState'] ?? null) ? $data['liveState'] : []);
    $queue = $secondary
        ? array_values(array_slice(is_array($station['liveQueue'] ?? null) ? $station['liveQueue'] : [], 0, 3))
        : (is_array($data['publicLiveSchedule']['liveQueue'] ?? null)
            ? array_values(array_slice($data['publicLiveSchedule']['liveQueue'], 0, 3))
            : array_values(array_slice(is_array($data['liveQueue'] ?? null) ? $data['liveQueue'] : [], 0, 3)));
}

echo json_encode([
    'ok' => true,
    'channelId' => $stationId,
    'channelName' => $secondary ? (string)($station['name'] ?? ucfirst($stationId)) : 'Live Web 1',
    'projected' => $projected,
    'serverNow' => gmdate('Y-m-d\TH:i:s', $now) . '.000Z',
    'liveState' => $state,
    'liveQueue' => $queue,
    'publicLiveSchedule' => ['current' => $queue[0] ?? null, 'next' => $queue[1] ?? null, 'afterNext' => $queue[2] ?? null, 'liveQueue' => $queue],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
