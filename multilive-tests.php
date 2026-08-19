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
        $channel('crime_a', ['crime', 'docu']),
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

$firstChannels = array_column(array_slice($crime['schedule'], 0, 12), 'channelId');
for ($i = 1; $i < count($firstChannels); $i++) {
    ml_check($firstChannels[$i] !== $firstChannels[$i - 1], 'same source aired twice consecutively');
}

$docu = $stations['docu'];
ml_check($docu['sourceCount'] === 1 && ($docu['liveQueue'][0]['channelId'] ?? '') === 'crime_a', 'one source cannot belong to multiple stations');
ml_check(($stations['kids']['liveState']['status'] ?? '') === 'NO_SOURCES', 'empty station status is not explicit');

$firstScheduleIds = array_column(array_slice($crime['schedule'], 0, 3), 'id');
$same = ml_tick_all($data, $now + 30)['crime'];
ml_check(array_column(array_slice($same['schedule'], 0, 3), 'id') === $firstScheduleIds, 'tick rebuilt an active queue before its boundary');

$transitionAt = se_ts((string)$crime['schedule'][0]['endDateTime']) + 1;
$after = ml_tick_all($data, $transitionAt)['crime'];
ml_check(count($after['history']) >= 1, 'completed secondary programme was not recorded');
ml_check(($after['liveQueue'][0]['id'] ?? '') === ($crime['schedule'][1]['id'] ?? ''), 'secondary channel did not advance at the exact boundary');

// Prova non distruttiva sul catalogo reale: seleziona in memoria tre sorgenti
// che possiedono contenuti validi negli ultimi 30 giorni.
$realPath = __DIR__ . '/data/tubetv-data.json';
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

echo "multilive-tests PASS\n";
