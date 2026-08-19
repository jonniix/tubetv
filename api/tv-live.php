<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

$path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'tubetv-data.json';
$data = is_file($path) ? json_decode((string)@file_get_contents($path), true) : null;
if (!is_array($data)) { http_response_code(503); echo json_encode(['ok' => false, 'error' => 'LIVE_DATA_UNAVAILABLE']); exit; }

function tv_live_id($item): string { return is_array($item) ? trim((string)($item['videoId'] ?? $item['id'] ?? '')) : ''; }
function tv_live_compact($item): ?array {
    if (!is_array($item) || tv_live_id($item) === '') return null;
    return [
        'videoId' => tv_live_id($item), 'title' => (string)($item['title'] ?? 'TubeTV Live'),
        'channel' => (string)($item['channel'] ?? $item['channelTitle'] ?? ''),
        'thumbnail' => (string)($item['thumbnail'] ?? $item['thumb'] ?? ''),
        'startDateTime' => (string)($item['startDateTime'] ?? $item['scheduledStartDateTime'] ?? ''),
        'endDateTime' => (string)($item['endDateTime'] ?? $item['scheduledEndDateTime'] ?? ''),
        'durationSeconds' => (int)($item['durationSeconds'] ?? $item['durationSecs'] ?? 0),
    ];
}

$state = is_array($data['liveState'] ?? null) ? $data['liveState'] : [];
$currentId = trim((string)($state['currentVideoId'] ?? ''));
$queue = $data['publicLiveSchedule']['liveQueue'] ?? $data['liveQueue'] ?? [];
$schedule = $data['schedule'] ?? $data['palinsesto'] ?? [];
$now = time();
require_once __DIR__ . DIRECTORY_SEPARATOR . 'schedule-engine.php';
if (function_exists('se_ensure_daily_schedule')) se_ensure_daily_schedule($data, $now, false);
$schedule = $data['schedule'] ?? $data['palinsesto'] ?? [];
$current = null; $scheduleCurrent = null;
foreach (is_array($schedule) ? $schedule : [] as $item) {
    if (!is_array($item) || (($item['type'] ?? 'content') !== 'content')) continue;
    $startAt = strtotime((string)($item['startDateTime'] ?? $item['scheduledStartDateTime'] ?? '')) ?: 0;
    $endAt = strtotime((string)($item['endDateTime'] ?? $item['scheduledEndDateTime'] ?? '')) ?: 0;
    if ($startAt > 0 && $endAt > $startAt && $now >= $startAt && $now < $endAt) { $scheduleCurrent = tv_live_compact($item); break; }
}
$stateEnd = strtotime((string)($state['currentEndsAt'] ?? '')) ?: 0;
// The absolute daily timeline is the broadcast clock. It must win immediately
// at a programme boundary even when the background cron is late or stopped.
if ($scheduleCurrent) {
    $current = $scheduleCurrent; $currentId = (string)$scheduleCurrent['videoId'];
}
foreach ([$queue, $schedule, $data['videos'] ?? []] as $items) {
    if ($current) break;
    if (!is_array($items)) continue;
    foreach ($items as $item) if (tv_live_id($item) === $currentId) { $current = tv_live_compact($item); break 2; }
}
if (!$current && $scheduleCurrent) $current = $scheduleCurrent;
if (!$current && is_array($queue) && !empty($queue[0])) $current = tv_live_compact($queue[0]);
if (!$current && $currentId !== '') $current = tv_live_compact(array_merge($state, ['videoId' => $currentId]));

$offset = 0; $usingAuthoritativeState = $current && $currentId === trim((string)($state['currentVideoId'] ?? '')) && ($stateEnd === 0 || $stateEnd >= $now - 30);
$baseOffset = max(0, (int)($state['currentVideoOffset'] ?? $state['offset'] ?? 0));
$snapshot = strtotime((string)($state['serverNowAtPublish'] ?? $state['updatedAt'] ?? '')) ?: 0;
$started = strtotime((string)($state['currentStartedAt'] ?? ($current['startDateTime'] ?? ''))) ?: 0;
if ($usingAuthoritativeState && $baseOffset > 0 && $snapshot > 0) $offset = $baseOffset + max(0, $now - $snapshot);
elseif ($usingAuthoritativeState && $started > 0) $offset = max(0, $now - $started);
elseif ($current && !empty($current['startDateTime'])) $offset = max(0, $now - (strtotime((string)$current['startDateTime']) ?: $now));
$duration = $usingAuthoritativeState
    ? (int)($state['currentDurationSeconds'] ?? ($current['durationSeconds'] ?? 0))
    : (int)($current['durationSeconds'] ?? 0);
if ($duration > 0) $offset = min($offset, max(0, $duration - 1));

$next = []; $nextPool = $scheduleCurrent ? $schedule : $queue;
foreach (is_array($nextPool) ? $nextPool : [] as $item) {
    $compact = tv_live_compact($item); if (!$compact || $compact['videoId'] === ($current['videoId'] ?? '')) continue;
    $nextStart = strtotime((string)($compact['startDateTime'] ?? '')) ?: 0;
    if ($nextStart > 0 && $nextStart <= $now) continue;
    $next[] = $compact; if (count($next) >= 3) break;
}
echo json_encode(['ok' => true, 'phase' => $scheduleCurrent ? 'content' : (string)($state['phase'] ?? 'content'), 'current' => $current, 'offsetSeconds' => $offset, 'next' => $next, 'serverNow' => gmdate('c')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
