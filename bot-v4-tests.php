<?php
declare(strict_types=1);

require_once __DIR__ . '/api/bot-v4-engine.php';

function v4t_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

function v4t_video(string $id, string $publishedAt, int $duration = 600): array {
    return [
        'id' => $id, 'videoId' => $id, 'title' => 'Video ' . $id,
        'channelId' => 'source-1', 'channel' => 'Fonte Uno',
        'publishedAt' => $publishedAt, 'durationSeconds' => $duration,
        'defaultAudioLanguage' => 'it', 'italianVerified' => true,
        'embeddable' => true, 'privacyStatus' => 'public', 'liveBroadcastContent' => 'none',
    ];
}

function v4t_data(array $videos): array {
    return [
        'settings' => ['timezone' => 'Europe/Zurich', 'playbackCountry' => 'CH'],
        'botV3Settings' => ['minDurationMinutes' => 1, 'maxDurationMinutes' => 120],
        'channels' => [['id' => 'source-1', 'name' => 'Fonte Uno', 'active' => true, 'rating' => 9]],
        'slots' => [['id' => 'all-day', 'name' => 'Tutto il giorno', 'start' => '00:00', 'end' => '24:00', 'channelIds' => ['source-1']]],
        'videos' => $videos,
    ];
}

// A fresh item may appear at most three times: PREMIERE + two NOVITA.
$now = strtotime('2026-08-24T10:00:00Z');
$data = v4t_data([
    v4t_video('fresh', '2026-08-24T09:00:00Z'),
    v4t_video('archive-oldest', '2024-09-01T10:00:00Z'),
    v4t_video('archive-newer', '2025-09-01T10:00:00Z'),
]);
$built = v4_build_shadow_schedule($data, $now, []);
$fresh = array_values(array_filter($built['schedule'], static fn($item): bool => se_video_id($item) === 'fresh'));
v4t_assert(count($fresh) === 3, 'A fresh video must have exactly three eligible passes in the test horizon.');
v4t_assert(($fresh[0]['classification'] ?? '') === 'PREMIERE', 'First fresh airing must be PREMIERE.');
v4t_assert(($fresh[1]['classification'] ?? '') === 'NOVITA' && ($fresh[2]['classification'] ?? '') === 'NOVITA', 'Second and third fresh airings must be NOVITA.');
$archiveItems = array_values(array_filter($built['schedule'], static fn($item): bool => ($item['classification'] ?? '') === 'ARCHIVIO'));
v4t_assert(se_video_id($archiveItems[0] ?? []) === 'archive-oldest', 'Archive must start from the oldest eligible item.');
v4t_assert($built['cursorEnd'] !== $built['cursorStart'] || $built['archiveCycle'] > 0, 'Global archive cursor must advance.');

// Official mode publishes V4 as the only authority for Live Web 1.
$officialData = $data;
$officialResult = v4_shadow_tick($officialData, $now, 'force_rebuild_queue', true);
v4t_assert(!empty($officialResult['ok']), 'Official V4 tick must publish successfully.');
v4t_assert(($officialData['activeScheduleEngine'] ?? '') === 'bot-v4', 'V4 must be marked as the active schedule engine.');
v4t_assert((int)($officialData['publicLiveSchedule']['engineVersion'] ?? 0) === 4, 'Public Live Web 1 queue must declare engine V4.');
v4t_assert(($officialData['liveState']['currentChangedBy'] ?? '') === 'bot-v4', 'Live authority must be Bot V4.');
v4t_assert(count($officialData['publicLiveSchedule']['liveQueue'] ?? []) === 3, 'Official V4 must publish the locked three-item queue.');

// Actual history is deduplicated and future entries are never counted as aired.
$historyData = $data;
$historyData['scheduleArchive'] = ['2026-08-20' => [[
    'videoId' => 'archive-oldest', 'title' => 'Video archive-oldest',
    'startDateTime' => '2026-08-20T10:00:00Z', 'endDateTime' => '2026-08-20T10:10:00Z',
]]];
$historyData['schedule'] = [[
    'videoId' => 'future', 'startDateTime' => '2026-08-25T10:00:00Z', 'endDateTime' => '2026-08-25T10:10:00Z',
]];
$history = v4_rebuild_history($historyData, $now);
v4t_assert(count($history) === 1 && se_video_id($history[0]) === 'archive-oldest', 'History must contain only completed real airings.');

// Sunday replay waits for the running programme, shows a 7-second slate, then replays.
$sundayNow = strtotime('2026-08-23T15:50:00Z'); // 17:50 Europe/Zurich
$sundayData = v4t_data([
    v4t_video('weekly-new', '2026-08-23T12:00:00Z', 1200),
    v4t_video('archive-sun', '2025-01-01T10:00:00Z', 600),
]);
$sunday = v4_build_shadow_schedule($sundayData, $sundayNow, []);
$introIndex = null;
foreach ($sunday['schedule'] as $index => $item) if (($item['classification'] ?? '') === 'REPLICA_INTRO') { $introIndex = $index; break; }
v4t_assert($introIndex !== null, 'Sunday schedule must contain the replay intro.');
$intro = $sunday['schedule'][$introIndex];
v4t_assert(se_ts($intro['startDateTime']) === $sundayNow + 1200, 'Replay intro must wait for the programme to finish naturally.');
v4t_assert((int)$intro['durationSeconds'] === 7, 'Replay intro must last seven seconds.');
v4t_assert(($sunday['schedule'][$introIndex + 1]['classification'] ?? '') === 'REPLICA', 'Weekly replay must start after the intro.');

$realPath = (string)($argv[1] ?? '');
if ($realPath !== '') {
    $real = json_decode((string)file_get_contents($realPath), true);
    v4t_assert(is_array($real), 'The supplied real JSON must be valid.');
    $protected = [];
    foreach (['futureSchedule', 'schedule', 'publicLiveSchedule', 'liveQueue', 'liveState'] as $key) $protected[$key] = $real[$key] ?? null;
    $result = v4_shadow_tick($real, time(), 'force_rebuild_queue');
    v4t_assert(!empty($result['ok']), 'Real-data simulation must produce a schedule.');
    foreach ($protected as $key => $value) v4t_assert(($real[$key] ?? null) === $value, 'V4 shadow changed protected official field: ' . $key);
    fwrite(STDOUT, 'Real data: ' . json_encode($result['meta'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    $officialReal = json_decode((string)file_get_contents($realPath), true);
    $official = v4_shadow_tick($officialReal, time(), 'force_rebuild_queue', true);
    v4t_assert(!empty($official['ok']), 'Real-data official V4 simulation must publish a current queue.');
    v4t_assert(($officialReal['activeScheduleEngine'] ?? '') === 'bot-v4', 'Real-data official V4 must become active authority.');
    fwrite(STDOUT, 'Real official queue: ' . count($officialReal['publicLiveSchedule']['liveQueue'] ?? []) . PHP_EOL);
}

echo "Bot V4 tests: OK\n";
