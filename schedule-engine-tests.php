<?php
require_once __DIR__ . '/api/schedule-engine.php';

function check($condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$now = strtotime('2026-08-18T10:15:00+02:00');
$videos = [];
for ($i = 1; $i <= 18; $i++) {
    $channel = $i % 2 ? 'news' : 'docs';
    $videos[] = [
        'videoId' => 'video_' . str_pad((string)$i, 4, '0', STR_PAD_LEFT),
        'title' => 'Video ' . $i,
        'sourceChannelId' => $channel,
        'channel' => $channel === 'news' ? 'News Italia' : 'Documentari Italia',
        'durationSeconds' => 1800,
        'publishedAt' => se_iso($now - $i * 3600),
        'language' => 'it', 'defaultAudioLanguage' => 'it',
    ];
}
$videos[] = [
    'videoId' => 'english_0001', 'title' => 'English only',
    'sourceChannelId' => 'news', 'durationSeconds' => 1800,
    'publishedAt' => se_iso($now - 600), 'language' => 'en',
];

$data = [
    'settings' => ['timezone' => 'Europe/Zurich', 'contentLanguage' => 'it'],
    'botSettings' => ['allowUnknownLanguageFromSelectedChannels' => true],
    'channels' => [
        ['id' => 'news', 'name' => 'News Italia', 'rating' => 9, 'active' => true],
        ['id' => 'docs', 'name' => 'Documentari Italia', 'rating' => 7, 'active' => true],
    ],
    'slots' => [
        ['id' => 'morning', 'name' => 'Mattino', 'start' => '00:00', 'end' => '12:00', 'channelIds' => ['news']],
        ['id' => 'afternoon', 'name' => 'Pomeriggio', 'start' => '12:00', 'end' => '24:00', 'channelIds' => ['docs']],
    ],
    'videos' => $videos,
    'botHistory' => [],
];

check(se_ensure_daily_schedule($data, $now), 'first generation did not run');
check(count($data['schedule']) > 10, 'daily schedule is unexpectedly short');
check($data['scheduleMeta']['date'] === '2026-08-18', 'wrong local schedule date');
check(!empty($data['schedule'][0]['publishedAt']), 'schedule item lost its publication date');
check(!in_array('english_0001', array_column($data['schedule'], 'videoId'), true), 'explicit English video was scheduled');

$firstIds = array_column(array_slice($data['schedule'], 0, 9), 'videoId');
check(count($firstIds) === count(array_unique($firstIds)), 'video repeated before unique candidates were exhausted');
foreach ($data['schedule'] as $item) {
    if ($item['slotId'] === 'morning') check($item['channelId'] === 'news', 'morning used a channel outside its slot');
    if ($item['slotId'] === 'afternoon') check($item['channelId'] === 'docs', 'afternoon used a channel outside its slot');
}

$stable = json_encode($data['schedule']);
check(!se_ensure_daily_schedule($data, $now + 60), 'unchanged catalog rebuilt the schedule');
check(json_encode($data['schedule']) === $stable, 'stable schedule changed without catalog changes');

$pastIds = array_column(array_filter($data['schedule'], function ($item) use ($now) {
    return se_ts($item['startDateTime']) <= $now;
}), 'id');
$data['videos'][] = [
    'videoId' => 'brand_new_01', 'title' => 'Ultimissima',
    'sourceChannelId' => 'docs', 'durationSeconds' => 1800,
    'publishedAt' => se_iso($now), 'language' => 'it', 'defaultAudioLanguage' => 'it',
];
check(se_ensure_daily_schedule($data, $now + 120), 'catalog update did not rebuild future schedule');
$pastAfter = array_column(array_filter($data['schedule'], function ($item) use ($now) {
    return se_ts($item['startDateTime']) <= $now;
}), 'id');
check($pastIds === $pastAfter, 'catalog update rewrote past programme entries');
check(in_array('brand_new_01', array_column($data['schedule'], 'videoId'), true), 'fresh upload was not inserted in the remaining day');

$priorityData = [
    'settings' => ['timezone' => 'Europe/Zurich', 'contentLanguage' => 'it'],
    'channels' => [['id' => 'priority_channel', 'name' => 'Canale', 'rating' => 5]],
    'slots' => [['id' => 'all', 'start' => '00:00', 'end' => '24:00', 'channelIds' => ['priority_channel']]],
    'videos' => [
        ['videoId' => 'old_unseen', 'title' => 'Vecchio', 'channelId' => 'priority_channel', 'durationSeconds' => 1800, 'publishedAt' => se_iso($now - 400 * 86400), 'language' => 'it', 'defaultAudioLanguage' => 'it'],
        ['videoId' => 'recent_10_days', 'title' => 'Recente', 'channelId' => 'priority_channel', 'durationSeconds' => 1800, 'publishedAt' => se_iso($now - 10 * 86400), 'language' => 'it', 'defaultAudioLanguage' => 'it'],
    ],
];
$prioritySchedule = se_build_schedule($priorityData, $now);
check(($prioritySchedule[0]['videoId'] ?? '') === 'recent_10_days', 'a recent 30-day video did not beat old catalogue content');
check(($prioritySchedule[0]['strategy'] ?? '') === 'recent_30d', 'recent 30-day strategy was not labelled');

$realFixturePath = __DIR__ . '/tubetv-data (23).json';
if (is_file($realFixturePath)) {
    $realFixture = json_decode((string)file_get_contents($realFixturePath), true);
    check(is_array($realFixture), 'real TubeTV JSON fixture is not readable');
    check(count($realFixture['channels'] ?? []) === 18, 'unexpected real fixture channel count');
    $bootstrappedChannels = 0;
    foreach ($realFixture['channels'] as $realChannel) {
        if (count(se_existing_video_ids_for_channel($realFixture, $realChannel, 3)) > 0) $bootstrappedChannels++;
        else check(se_channel_handle($realChannel) !== '', 'channel has neither catalogue videos nor a YouTube handle: ' . se_channel_key($realChannel));
    }
    check($bootstrappedChannels >= 17, 'too many real channels cannot be bootstrapped from catalogue videos');
}

check(se_catalog_category(['channel' => 'Geopop', 'title' => 'Il sapore della scienza', 'category' => 'Cucina']) === 'Divulgazione', 'known channel did not override a stale category');
check(se_catalog_category(['channelHandle' => '@ruhicenetdocs', 'title' => 'Inside the hidden city', 'category' => 'Musica']) === 'Documentari', 'documentary channel remained in a stale category');
check(se_catalog_category(['channel' => 'Nicolò Balini', 'title' => 'Nuovo viaggio', 'category' => 'Musica']) === 'Viaggi', 'travel channel remained in a stale category');
check(se_catalog_category(['channel' => 'VANZAI CUCINANDO', 'title' => 'Una nuova ricetta']) === 'Cucina', 'food channel was not categorized');
check(se_catalog_category(['channel' => 'Canale nuovo', 'title' => 'Gameplay Nintendo Switch']) === 'Gaming', 'strong title fallback was not applied');

$strictData = ['settings' => ['contentLanguage' => 'it', 'playbackCountry' => 'CH'], 'botSettings' => ['requireVerifiedItalianAudio' => true]];
$foreignBase = ['videoId' => 'foreign_audio', 'title' => 'Foreign production', 'durationSeconds' => 1200, 'language' => 'en', 'privacyStatus' => 'public', 'embeddable' => true];
check(!se_is_playable($foreignBase + ['italianVerified' => true, 'italianAudioMode' => 'multi_track', 'italianAudioTrackId' => 'it.3', 'availableLanguages' => [['code' => 'it', 'audioTrackId' => 'it.3']]], null, $strictData), 'unconfirmed embedded multi-audio video was scheduled');
check(se_is_playable($foreignBase + ['italianVideoId' => 'Italian0001'], null, $strictData), 'separate Italian video version was rejected');
check(!se_is_playable($foreignBase + ['captions' => [['code' => 'it']]], null, $strictData), 'Italian subtitles were incorrectly accepted as Italian audio');
check(!se_is_playable($foreignBase + ['trustedItalianChannel' => true], null, $strictData), 'channel trust incorrectly bypassed Italian audio verification');
check(!se_is_playable($foreignBase + ['availableLanguages' => [['code' => 'it']], 'regionRestriction' => ['blocked' => ['CH']]], null, $strictData), 'video blocked in Switzerland was scheduled');
check(!se_is_playable($foreignBase + ['availableLanguages' => [['code' => 'it']], 'regionRestriction' => ['allowed' => ['IT']]], null, $strictData), 'allow-list excluding Switzerland was ignored');
check(!se_is_playable(array_merge($foreignBase, ['availableLanguages' => [['code' => 'it']], 'embeddable' => false]), null, $strictData), 'non-embeddable video was scheduled');

$profile = se_bot_profile(['botV3Settings' => ['freshnessWeight' => 250, 'repeatCooldownDays' => -5, 'minDurationMinutes' => 12, 'maxDurationMinutes' => 10, 'allowedCategories' => ['Gaming', 'Categoria inventata']]]);
check($profile['freshnessWeight'] === 200 && $profile['repeatCooldownDays'] === 0, 'slider profile limits are not enforced');
check($profile['minDurationMinutes'] === 12 && $profile['maxDurationMinutes'] === 17, 'duration profile is not normalized safely');
check($profile['allowedCategories'] === ['Gaming'], 'unknown category filter was accepted');
check(se_bot_profile(['botV3Settings' => ['requireVerifiedItalianAudio' => false]])['requireVerifiedItalianAudio'] === true, 'mandatory Italian audio verification can be disabled');
$durationProfileData = ['botV3Settings' => ['minDurationMinutes' => 10, 'maxDurationMinutes' => 20], 'settings' => ['playbackCountry' => 'CH']];
$italianVideo = ['videoId' => 'duration_ok', 'title' => 'Italiano', 'durationSeconds' => 900, 'defaultAudioLanguage' => 'it', 'privacyStatus' => 'public', 'embeddable' => true];
check(se_is_playable($italianVideo, null, $durationProfileData), 'video inside custom duration filter was rejected');
check(!se_is_playable(array_merge($italianVideo, ['durationSeconds' => 300]), null, $durationProfileData), 'video below custom duration filter was accepted');

$availabilityData = [
    'settings' => ['playbackCountry' => 'CH'],
    'videos' => [['videoId' => 'AbCdEfGhI12', 'title' => 'Disponibilita', 'durationSeconds' => 900, 'defaultAudioLanguage' => 'it', 'privacyStatus' => 'public', 'embeddable' => true]],
    'videoLibrary' => ['test' => [['videoId' => 'AbCdEfGhI12', 'title' => 'Disponibilita', 'durationSeconds' => 900, 'defaultAudioLanguage' => 'it', 'privacyStatus' => 'public', 'embeddable' => true]]],
];
check(se_set_video_availability($availabilityData, 'AbCdEfGhI12', false, 'private_or_deleted', $now), 'unavailable transition was not recorded');
check(($availabilityData['videoAvailability']['AbCdEfGhI12']['status'] ?? '') === 'unavailable', 'availability registry did not quarantine the video');
check(!empty($availabilityData['videos'][0]['hiddenByAvailabilityBot']), 'catalog video was not hidden');
check(!se_is_playable($availabilityData['videos'][0], null, $availabilityData), 'quarantined video remained schedulable');
check(se_set_video_availability($availabilityData, 'AbCdEfGhI12', true, 'ok', $now + 1800), 'restored transition was not recorded');
check(($availabilityData['videos'][0]['availabilityStatus'] ?? '') === 'available' && empty($availabilityData['videos'][0]['hiddenByAvailabilityBot']), 'restored video remained hidden');
check(se_is_playable($availabilityData['videos'][0], null, $availabilityData), 'restored video did not become schedulable');
check(se_set_video_localized_title($availabilityData, 'AbCdEfGhI12', 'Original English Title', 'Titolo italiano ufficiale'), 'official Italian title was not applied');
check(($availabilityData['videos'][0]['title'] ?? '') === 'Titolo italiano ufficiale' && ($availabilityData['videos'][0]['originalTitle'] ?? '') === 'Original English Title', 'original/localized title pair was not preserved');

$audioData = ['settings' => ['playbackCountry' => 'CH'], 'videos' => [[
    'videoId' => 'MultiAudio01', 'title' => 'Doppiato', 'durationSeconds' => 1200,
    'language' => 'en', 'privacyStatus' => 'public', 'embeddable' => true,
]]];
check(se_set_video_audio_verification($audioData, 'MultiAudio01', ['status' => 'verified', 'mode' => 'multi_track', 'reason' => 'italian_audio_track_verified', 'audioLanguages' => [['code' => 'it', 'label' => 'Italiano', 'audioTrackId' => 'it.4']], 'italianAudioTrackId' => 'it.4'], $now), 'Italian multi-audio verification was not saved');
check(($audioData['videos'][0]['italianAudioTrackId'] ?? '') === 'it.4' && !empty($audioData['videos'][0]['italianVerified']), 'Italian track id was lost');
check(empty($audioData['videos'][0]['italianPlaybackGuaranteed']) && !se_is_playable($audioData['videos'][0], null, $audioData), 'multi-audio availability was incorrectly treated as guaranteed playback');
se_set_video_audio_verification($audioData, 'MultiAudio01', ['status' => 'verified', 'mode' => 'default', 'reason' => 'youtube_default_audio_it', 'audioLanguages' => [['code' => 'it', 'label' => 'Italiano']]], $now + 30);
check(!empty($audioData['videos'][0]['italianPlaybackGuaranteed']) && se_is_playable($audioData['videos'][0], null, $audioData), 'default Italian audio was not accepted as guaranteed');
se_set_video_audio_verification($audioData, 'MultiAudio01', ['status' => 'rejected', 'reason' => 'italian_audio_not_found'], $now + 60);
check(!se_is_playable($audioData['videos'][0], null, $audioData), 'video without verified Italian audio remained schedulable');

$unsafeLockData = [
    'settings' => ['timezone' => 'Europe/Zurich', 'playbackCountry' => 'CH'],
    'channels' => [['id' => 'safe_channel', 'name' => 'Canale sicuro']],
    'slots' => [['id' => 'all', 'start' => '00:00', 'end' => '24:00', 'channelIds' => ['safe_channel']]],
    'videos' => [
        ['videoId' => 'UnsafeMulti1', 'title' => 'English multi audio', 'channelId' => 'safe_channel', 'durationSeconds' => 1200, 'language' => 'en', 'italianVerified' => true, 'italianAudioMode' => 'multi_track', 'italianAudioTrackId' => 'it.3', 'privacyStatus' => 'public', 'embeddable' => true],
        ['videoId' => 'SafeItalian1', 'title' => 'Italiano sicuro', 'channelId' => 'safe_channel', 'durationSeconds' => 1200, 'defaultAudioLanguage' => 'it', 'privacyStatus' => 'public', 'embeddable' => true],
    ],
    'schedule' => [['videoId' => 'UnsafeMulti1', 'title' => 'English multi audio', 'channelId' => 'safe_channel', 'durationSeconds' => 1200, 'language' => 'en', 'italianVerified' => true, 'italianAudioMode' => 'multi_track', 'italianAudioTrackId' => 'it.3', 'startDateTime' => se_iso($now - 60), 'endDateTime' => se_iso($now + 1140)]],
];
$safeReplacement = se_build_future_schedule($unsafeLockData, $now, 24);
check(!in_array('UnsafeMulti1', array_column($safeReplacement, 'videoId'), true), 'unsafe multi-audio item remained locked in the official Live queue');
check(($safeReplacement[0]['videoId'] ?? '') === 'SafeItalian1', 'unsafe current item was not replaced by guaranteed Italian audio');

$futureVideos = [];
for ($i = 1; $i <= 8; $i++) {
    $futureVideos[] = ['videoId' => 'future_' . str_pad((string)$i, 4, '0', STR_PAD_LEFT), 'title' => 'Futuro ' . $i, 'channelId' => 'future_channel', 'durationSeconds' => 1800, 'publishedAt' => se_iso($now - $i * 86400), 'defaultAudioLanguage' => 'it', 'privacyStatus' => 'public', 'embeddable' => true];
}
$futureData = [
    'settings' => ['timezone' => 'Europe/Zurich', 'playbackCountry' => 'CH'],
    'channels' => [['id' => 'future_channel', 'name' => 'Canale futuro', 'rating' => 8]],
    'slots' => [['id' => 'all_day', 'name' => 'Tutto il giorno', 'start' => '00:00', 'end' => '24:00', 'channelIds' => ['future_channel']]],
    'videos' => $futureVideos,
];
se_ensure_daily_schedule($futureData, $now, true);
check(se_ensure_future_schedule($futureData, $now, 72), '72-hour forecast was not generated');
check(($futureData['futureScheduleMeta']['horizonHours'] ?? 0) === 72, 'forecast horizon is not 72 hours');
check(count($futureData['futureSchedule'] ?? []) > 100, '72-hour forecast is unexpectedly short');
$lockedBefore = array_map(static fn($item): string => se_video_id($item) . '|' . (string)($item['startDateTime'] ?? ''), array_slice($futureData['futureSchedule'], 0, 3));
check(count(array_filter(array_slice($futureData['futureSchedule'], 0, 3), static fn($item): bool => !empty($item['forecastLocked']))) === 3, 'current and next two forecast items are not locked');
$futureLastEnd = max(array_map(static fn($item): int => se_ts((string)($item['endDateTime'] ?? '')), $futureData['futureSchedule']));
check($futureLastEnd >= $now + 71 * 3600, 'forecast does not cover the requested horizon');
check(!se_ensure_future_schedule($futureData, $now + 60, 72), 'unchanged forecast was rebuilt inside the same hour');
$futureData['videos'][] = ['videoId' => 'future_new01', 'title' => 'Novita appena caricata', 'channelId' => 'future_channel', 'durationSeconds' => 1800, 'publishedAt' => se_iso($now + 60), 'defaultAudioLanguage' => 'it', 'privacyStatus' => 'public', 'embeddable' => true];
check(se_ensure_future_schedule($futureData, $now + 60, 72), 'new source video did not revise the future forecast');
check(in_array('future_new01', array_column($futureData['futureSchedule'], 'videoId'), true), 'new source video did not replace a future rotation/replica');
$lockedAfter = array_map(static fn($item): string => se_video_id($item) . '|' . (string)($item['startDateTime'] ?? ''), array_slice($futureData['futureSchedule'], 0, 3));
check($lockedAfter === $lockedBefore, 'catalog update changed one of the three published live commitments');
check(array_search('future_new01', array_column($futureData['futureSchedule'], 'videoId'), true) >= 3, 'new video entered inside the three locked live commitments');
$newFutureItem = array_values(array_filter($futureData['futureSchedule'], static fn($item): bool => se_video_id($item) === 'future_new01'))[0] ?? [];
check(!empty($newFutureItem['forecastSubstitute']), 'new verified content was not marked as a dynamic substitute');
check(($futureData['futureScheduleMeta']['revisionReason'] ?? '') === 'catalog_update', 'adaptive forecast did not record the catalog update');
se_publish_future_schedule($futureData, $now + 60);
check(($futureData['scheduleMeta']['rollingSource'] ?? '') === 'futureSchedule', 'daily public schedule is not sourced from the rolling forecast');

echo "schedule-engine-tests PASS\n";
