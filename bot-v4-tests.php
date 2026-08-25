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
v4t_assert(se_ts($fresh[1]['startDateTime']) - se_ts($fresh[0]['startDateTime']) >= 12 * 3600, 'Fresh repeats must be separated by at least twelve hours.');
v4t_assert(se_ts($fresh[2]['startDateTime']) - se_ts($fresh[1]['startDateTime']) >= 12 * 3600, 'Every fresh repeat must respect the cooldown.');
$archiveItems = array_values(array_filter($built['schedule'], static fn($item): bool => ($item['strategy'] ?? '') === 'v4_archivio'));
v4t_assert(se_video_id($archiveItems[0] ?? []) === 'archive-oldest', 'Archive must start from the oldest eligible item.');
v4t_assert(($archiveItems[0]['classification'] ?? '') === 'PRIMA_TV', 'An older video never aired by TubeTV must be labelled PRIMA_TV.');
v4t_assert(($built['sourceCursorsEnd']['source-1'] ?? 0) !== ($built['sourceCursorsStart']['source-1'] ?? 0) || ($built['sourceCyclesEnd']['source-1'] ?? 0) > 0, 'The per-source archive cursor must advance.');

// Each source owns its chronological cursor, even when other sources are interleaved.
$multi = v4t_data([]);
$multi['channels'] = [
    ['id' => 'source-a', 'name' => 'Fonte A', 'active' => true, 'rating' => 8],
    ['id' => 'source-b', 'name' => 'Fonte B', 'active' => true, 'rating' => 8],
];
$multi['slots'][0]['channelIds'] = ['source-a', 'source-b'];
$multi['videos'] = [
    array_merge(v4t_video('a-old', '2024-09-01T10:00:00Z'), ['channelId' => 'source-a', 'channel' => 'Fonte A']),
    array_merge(v4t_video('b-old', '2024-09-02T10:00:00Z'), ['channelId' => 'source-b', 'channel' => 'Fonte B']),
    array_merge(v4t_video('a-new', '2024-10-01T10:00:00Z'), ['channelId' => 'source-a', 'channel' => 'Fonte A']),
    array_merge(v4t_video('b-new', '2024-10-02T10:00:00Z'), ['channelId' => 'source-b', 'channel' => 'Fonte B']),
];
$multiBuilt = v4_build_shadow_schedule($multi, $now, []);
$sourceA = array_values(array_filter($multiBuilt['schedule'], static fn($item): bool => ($item['archiveSourceKey'] ?? '') === 'source-a'));
v4t_assert(se_video_id($sourceA[0] ?? []) === 'a-old' && se_video_id($sourceA[1] ?? []) === 'a-new', 'A source archive must advance from its oldest video to the next one.');

// Official mode publishes V4 as the only authority for Live Web 1.
$officialData = $data;
$officialResult = v4_shadow_tick($officialData, $now, 'force_rebuild_queue', true);
v4t_assert(!empty($officialResult['ok']), 'Official V4 tick must publish successfully.');
v4t_assert(($officialData['activeScheduleEngine'] ?? '') === 'bot-v4', 'V4 must be marked as the active schedule engine.');
v4t_assert((int)($officialData['publicLiveSchedule']['engineVersion'] ?? 0) === 4, 'Public Live Web 1 queue must declare engine V4.');
v4t_assert(($officialData['liveState']['currentChangedBy'] ?? '') === 'bot-v4', 'Live authority must be Bot V4.');
v4t_assert(count($officialData['publicLiveSchedule']['liveQueue'] ?? []) === 3, 'Official V4 must publish the locked three-item queue.');
$nextReplay = v4_next_replay($built['schedule'], 0, $now);
v4t_assert(($nextReplay['videoId'] ?? '') === 'fresh', 'The engine must expose the next scheduled airing of the current video.');

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
v4t_assert(v4_editorial_classification(v4t_video('old', '2025-01-01T10:00:00Z'), $now, 1) === 'REPLICA', 'An older video already aired must be labelled REPLICA.');
v4t_assert(v4_editorial_classification(v4t_video('old-new', '2025-01-01T10:00:00Z'), $now, 0) === 'PRIMA_TV', 'An older video never aired must be labelled PRIMA_TV.');
$safeDetail = ['status' => ['privacyStatus' => 'public', 'embeddable' => true], 'contentDetails' => [], 'snippet' => ['liveBroadcastContent' => 'none', 'defaultAudioLanguage' => 'it']];
$ageDetail = $safeDetail;
$ageDetail['contentDetails']['contentRating']['ytRating'] = 'ytAgeRestricted';
v4t_assert(v4_watchdog_detail_reason($ageDetail, v4t_video('age-blocked', '2025-01-01T10:00:00Z'), null, $data, 'CH') === 'age_restricted', 'The watchdog must reject age-restricted videos.');
$englishDetail = $safeDetail;
$englishDetail['snippet']['defaultAudioLanguage'] = 'en';
v4t_assert(v4_watchdog_detail_reason($englishDetail, v4t_video('english', '2025-01-01T10:00:00Z'), null, $data, 'CH') === 'italian_audio_not_guaranteed', 'The watchdog must reject a non-Italian default audio track.');
v4t_assert(v4_watchdog_detail_reason($safeDetail, v4t_video('safe', '2025-01-01T10:00:00Z'), null, $data, 'CH') === '', 'The watchdog must accept a public embeddable Italian video.');

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
