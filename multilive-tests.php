<?php
declare(strict_types=1);

require_once __DIR__ . '/api/multilive-engine.php';

function ml_check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$now = strtotime('2026-08-19T12:00:00Z');
$channel = static fn(string $id, array $stations): array => [
    'id' => $id, 'name' => strtoupper($id), 'active' => true, 'enabled' => true,
    'webLiveIds' => $stations, 'trustedItalianChannel' => true,
];
$video = static fn(string $id, string $channelId, int $published, string $title): array => [
    'videoId' => $id, 'channelId' => $channelId, 'sourceChannelId' => $channelId,
    'title' => $title, 'durationSeconds' => 600, 'publishedAt' => se_iso($published),
    'defaultAudioLanguage' => 'it', 'privacyStatus' => 'public', 'embeddable' => true,
];

$data = [
    'settings' => ['contentLanguage' => 'it', 'playbackCountry' => 'CH'],
    'channels' => [
        $channel('crime_a', ['crime', 'docu', 'cucina', 'girl']),
        $channel('crime_b', ['crime']),
        $channel('crime_c', ['crime']),
    ],
    'videos' => [
        $video('crimeA00001', 'crime_a', $now - 3600, 'Novita A'),
        $video('crimeA00002', 'crime_a', $now - 8 * 86400, 'Archivio A'),
        $video('crimeB00001', 'crime_b', $now - 2 * 86400, 'Novita B'),
        $video('crimeB00002', 'crime_b', $now - 12 * 86400, 'Archivio B'),
        $video('crimeC00001', 'crime_c', $now - 5 * 86400, 'Recente C'),
        $video('crimeC00002', 'crime_c', $now - 31 * 86400, 'Troppo vecchio'),
    ],
];

$stations = ml_tick_all($data, $now);
$crime = $stations['crime'];
ml_check($crime['sourceCount'] === 3, 'crime source assignments missing');
ml_check(count($crime['schedule']) >= 100, 'crime 24h rolling schedule is too short');
ml_check(se_ts((string)end($crime['schedule'])['endDateTime']) >= $now + 23 * 3600, 'crime schedule does not cover 24 hours');
ml_check(count($crime['liveQueue']) === 3, 'crime micro queue must expose current plus two');
ml_check(($crime['liveQueue'][0]['channelId'] ?? '') === 'crime_a', 'freshest source did not receive the first opportunity');
ml_check(!in_array('crimeC00002', array_column($crime['schedule'], 'videoId'), true), 'video older than 30 days entered the station');
ml_check(!empty($crime['liveQueue'][0]['newReleasePromotion']), 'new upload was not promoted during its first 72 hours');
$promotedA = array_values(array_filter($crime['schedule'], static fn(array $item): bool =>
    ($item['videoId'] ?? '') === 'crimeA00001' && !empty($item['newReleasePromotion'])
));
ml_check(count($promotedA) <= 3, 'new upload received more than three promoted airings');

$firstChannels = array_column($crime['schedule'], 'channelId');
for ($i = 1; $i < count($firstChannels); $i++) {
    ml_check($firstChannels[$i] !== $firstChannels[$i - 1], 'same source aired twice consecutively');
}
ml_check(($crime['rules']['strictSourceRotation'] ?? false) === true, 'strict source rotation rule is not exposed');

// A source must use every recent item before repeating one of its videos.
$firstCrimeAVideos = array_values(array_map(
    static fn(array $item): string => (string)$item['videoId'],
    array_slice(array_values(array_filter($crime['schedule'], static fn(array $item): bool => ($item['channelId'] ?? '') === 'crime_a')), 0, 2)
));
ml_check(count(array_unique($firstCrimeAVideos)) === 2, 'same source video repeated before its recent pool was exhausted');

// When only one assigned source has recent material, it may legitimately fill the station alone.
$singleRecentData = $data;
$singleRecentData['videos'] = array_values(array_filter($singleRecentData['videos'], static fn(array $item): bool => ($item['channelId'] ?? '') === 'crime_a'));
$singleRecent = ml_tick_station($singleRecentData, ml_definitions()['crime'], [], $now);
ml_check($singleRecent['sourceCount'] === 1, 'source without recent videos was not excluded');
ml_check(count(array_unique(array_column(array_slice($singleRecent['schedule'], 0, 4), 'channelId'))) === 1, 'single recent source did not fill the station');

$docu = $stations['docu'];
ml_check($docu['sourceCount'] === 1 && ($docu['liveQueue'][0]['channelId'] ?? '') === 'crime_a', 'one source cannot belong to multiple stations');
$cucina = $stations['cucina'];
ml_check($cucina['sourceCount'] === 1 && ($cucina['liveQueue'][0]['channelId'] ?? '') === 'crime_a', 'Live Cucina station was not generated');
$girl = $stations['girl'];
ml_check($girl['sourceCount'] === 1 && ($girl['liveQueue'][0]['channelId'] ?? '') === 'crime_a', 'Live Girl station was not generated');
ml_check(($stations['kids']['liveState']['status'] ?? '') === 'NO_SOURCES', 'empty station status is not explicit');

// Rewind stations use every active source and enforce their publication window.
$rewind24 = $stations['rewind24'];
$rewind7 = $stations['rewind7'];
$rewind30 = $stations['rewind30'];
ml_check(($rewind24['eligibleVideoCount'] ?? -1) === 1, 'Rewind 24h did not enforce the 24-hour window');
ml_check(($rewind7['eligibleVideoCount'] ?? -1) === 3, 'Rewind 7 did not enforce the seven-day window');
ml_check(($rewind30['eligibleVideoCount'] ?? -1) === 5, 'Rewind 30 did not enforce the 30-day window');
ml_check(($rewind24['liveQueue'][0]['videoId'] ?? '') === 'crimeA00001', 'Rewind 24h did not start from the newest eligible upload');
ml_check(count($rewind24['schedule'] ?? []) > 10, 'Rewind 24h did not loop its short pool continuously');
ml_check(($rewind24['rules']['newUploadsBecomeNext'] ?? false) === true && ($rewind24['rules']['personalSkip'] ?? false) === true, 'Rewind rules are not exposed');

// A new upload never interrupts on-air content, but becomes the next item.
$withNewUpload = $data;
$withNewUpload['videos'][] = $video('brandNew001', 'crime_b', $now + 20, 'Ultimissima pubblicazione');
$rewindUpdated = ml_tick_station($withNewUpload, ml_definitions()['rewind7'], $rewind7, $now + 30);
ml_check(($rewindUpdated['liveQueue'][0]['videoId'] ?? '') === ($rewind7['liveQueue'][0]['videoId'] ?? ''), 'new upload interrupted Rewind content already on air');
ml_check(($rewindUpdated['liveQueue'][1]['videoId'] ?? '') === 'brandNew001', 'new upload did not become the next Rewind item');

$firstScheduleIds = array_column(array_slice($crime['schedule'], 0, 3), 'id');
$same = ml_tick_all($data, $now + 30)['crime'];
ml_check(array_column(array_slice($same['schedule'], 0, 3), 'id') === $firstScheduleIds, 'tick rebuilt an active queue before its boundary');

$transitionAt = se_ts((string)$crime['schedule'][0]['endDateTime']) + 1;
$after = ml_tick_all($data, $transitionAt)['crime'];
ml_check(count($after['history']) >= 1, 'completed secondary programme was not recorded');
ml_check(($after['liveQueue'][0]['id'] ?? '') === ($crime['schedule'][1]['id'] ?? ''), 'secondary channel did not advance at the exact boundary');

// Manual rebuild keeps the programme on air but releases and regenerates next/after-next.
$stale = $crime;
$stale['schedule'] = array_merge([$crime['schedule'][0]], array_fill(0, 5, $crime['schedule'][0]));
$rebuilt = ml_force_rebuild_station($data, ml_definitions()['crime'], $stale, $now + 30);
ml_check(($rebuilt['schedule'][0]['id'] ?? '') === ($crime['schedule'][0]['id'] ?? ''), 'manual rebuild interrupted the on-air programme');
ml_check(($rebuilt['schedule'][1]['channelId'] ?? '') !== ($rebuilt['schedule'][0]['channelId'] ?? ''), 'manual rebuild did not unlock and rotate the next programme');
ml_check(!empty($rebuilt['manuallyRebuiltAt']), 'manual rebuild timestamp missing');

// Prova non distruttiva sul catalogo reale, quando disponibile localmente.
// I dump Hostpoint sono intenzionalmente ignorati da Git e non esistono in CI.
$realCandidates = glob(__DIR__ . '/tubetv-data*.json') ?: [];
$serverDataPath = __DIR__ . '/data/tubetv-data.json';
if (is_file($serverDataPath)) $realCandidates[] = $serverDataPath;
usort($realCandidates, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
$realPath = $realCandidates[0] ?? null;
if ($realPath !== null) {
    $real = json_decode((string)file_get_contents($realPath), true);
    ml_check(is_array($real), 'real TubeTV JSON is not readable');
    $real['webLiveChannels'] = [];
    $assigned = 0;
    $real['channels'] = is_array($real['channels'] ?? null) ? $real['channels'] : [];
    foreach ($real['channels'] as &$realChannel) {
        if (!is_array($realChannel) || $assigned >= 3 || !ml_recent_pool($real, $realChannel, time())) continue;
        $realChannel['webLiveIds'] = ['docu'];
        $assigned++;
    }
    unset($realChannel);
    ml_check($assigned >= 1, 'real catalog has no recent playable source for multi-live');
    $realDocu = ml_tick_all($real, time())['docu'];
    ml_check(($realDocu['sourceCount'] ?? 0) === $assigned, 'real source assignments were not respected');
    ml_check(count($realDocu['liveQueue'] ?? []) === 3, 'real catalog did not generate current plus two');
    ml_check(se_ts((string)end($realDocu['schedule'])['endDateTime']) >= time() + 23 * 3600, 'real catalog does not cover the next 24 hours');
    echo 'multilive-real-data PASS (' . basename($realPath) . ")\n";
}

echo "multilive-tests PASS\n";
