<?php
declare(strict_types=1);

require_once __DIR__ . '/schedule-engine.php';
require_once __DIR__ . '/multilive-engine.php';

if (!function_exists('v3_tick')) {
    function v3_compact_item(array $item): array {
        $id = se_video_id($item);
        return [
            'id' => (string)($item['id'] ?? ('v3_' . $id)),
            'videoId' => $id,
            'title' => (string)($item['title'] ?? 'Video'),
            'originalTitle' => (string)($item['originalTitle'] ?? $item['title'] ?? 'Video'),
            'italianTitle' => (string)($item['italianTitle'] ?? ''),
            'titleTranslationStatus' => (string)($item['titleTranslationStatus'] ?? 'original'),
            'channel' => (string)($item['channel'] ?? $item['channelTitle'] ?? ''),
            'channelId' => (string)($item['channelId'] ?? $item['sourceChannelId'] ?? ''),
            'category' => (string)($item['category'] ?? 'Generale'),
            'thumbnail' => (string)($item['thumbnail'] ?? ''),
            'durationSeconds' => se_duration($item),
            'startDateTime' => (string)($item['startDateTime'] ?? ''),
            'actualStartDateTime' => (string)($item['startDateTime'] ?? ''),
            'endDateTime' => (string)($item['endDateTime'] ?? ''),
            'slotId' => (string)($item['slotId'] ?? ''),
            'slotName' => (string)($item['slotName'] ?? ''),
            'strategy' => (string)($item['strategy'] ?? ''),
            'reason' => (string)($item['reason'] ?? ''),
            'finalScore' => (float)($item['finalScore'] ?? 0),
            'publishedAt' => (string)($item['publishedAt'] ?? ''),
            'isReplica' => !empty($item['isReplica']),
            'language' => (string)($item['language'] ?? ''),
            'defaultLanguage' => (string)($item['defaultLanguage'] ?? ''),
            'defaultAudioLanguage' => (string)($item['defaultAudioLanguage'] ?? ''),
            'availableLanguages' => is_array($item['availableLanguages'] ?? null) ? $item['availableLanguages'] : [],
            'italianVerified' => !empty($item['italianVerified']),
            'italianAudioStatus' => (string)($item['italianAudioStatus'] ?? ''),
            'italianAudioMode' => (string)($item['italianAudioMode'] ?? ''),
            'italianAudioTrackId' => (string)($item['italianAudioTrackId'] ?? ''),
            'italianAudioCheckedAt' => (string)($item['italianAudioCheckedAt'] ?? ''),
            'italianAudioReason' => (string)($item['italianAudioReason'] ?? ''),
            'italianPlaybackGuaranteed' => !empty($item['italianPlaybackGuaranteed']),
            'italianVideoId' => (string)($item['italianVideoId'] ?? ''),
            'forecastLocked' => !empty($item['forecastLocked']),
            'liveCommitmentPosition' => (int)($item['liveCommitmentPosition'] ?? 0),
            'forecastSubstitute' => !empty($item['forecastSubstitute']),
            'forecastSubstitutedAt' => (string)($item['forecastSubstitutedAt'] ?? ''),
        ];
    }

    function v3_find_current(array $schedule, int $now): array {
        foreach ($schedule as $index => $item) {
            if (!is_array($item)) continue;
            $start = se_ts((string)($item['startDateTime'] ?? ''));
            $end = se_ts((string)($item['endDateTime'] ?? ''));
            if ($start > 0 && $end > $start && $start <= $now && $now < $end) return [$index, $item, 'exact'];
        }
        foreach ($schedule as $index => $item) {
            if (!is_array($item)) continue;
            $start = se_ts((string)($item['startDateTime'] ?? ''));
            if ($start >= $now) return [$index, $item, 'next'];
        }
        return [-1, [], 'missing'];
    }

    function v3_supply_analytics(array $data, int $now): array {
        $cutoff = $now - 30 * 86400;
        $channels = se_active_channels($data);
        $videos = se_collect_videos($data);
        $day = se_day_context($data, $now);
        $windows = se_day_windows($data, $day);
        $sources = [];

        foreach ($channels as $channel) {
            $key = se_channel_key($channel);
            if ($key === '') continue;
            $matched = array_values(array_filter($videos, static fn($video) => is_array($video) && se_video_matches_channel($video, $channel)));
            $eligible = array_values(array_filter($matched, static fn($video) => se_is_playable($video, $channel, $data)));
            $recent = array_values(array_filter($matched, static function ($video) use ($cutoff): bool {
                $published = se_ts((string)($video['publishedAt'] ?? $video['published'] ?? $video['datePublished'] ?? $video['addedAt'] ?? ''));
                return $published >= $cutoff;
            }));
            $recentEligible = array_values(array_filter($recent, static fn($video) => se_is_playable($video, $channel, $data)));
            $durationPool = $recentEligible ?: $eligible;
            $durationSeconds = array_sum(array_map('se_duration', $durationPool));
            $averageSeconds = $durationPool ? (int)round($durationSeconds / count($durationPool)) : 1800;
            $recentMinutes = array_sum(array_map('se_duration', $recentEligible)) / 60;
            $sources[$key] = [
                'id' => $key,
                'name' => (string)($channel['name'] ?? $channel['title'] ?? $channel['handle'] ?? 'Fonte'),
                'handle' => (string)($channel['handle'] ?? ''),
                'uploads30d' => count($recent),
                'eligibleUploads30d' => count($recentEligible),
                'uploadsPerDay' => round(count($recentEligible) / 30, 2),
                'freshMinutesPerDay' => round($recentMinutes / 30, 1),
                'averageDurationMinutes' => round($averageSeconds / 60, 1),
                'eligibleLibrary' => count($eligible),
                'assignedMinutesPerDay' => 0.0,
                'slots' => [],
            ];
        }

        $slotRows = [];
        foreach ($windows as $window) {
            $slot = is_array($window['slot'] ?? null) ? $window['slot'] : [];
            $slotId = (string)($slot['id'] ?? $slot['name'] ?? 'automatic');
            $minutes = max(0, ((int)$window['end'] - (int)$window['start']) / 60);
            if ($minutes <= 0) continue;
            $slotChannels = se_channels_for_slot($channels, $slot);
            $sourceKeys = array_values(array_filter(array_map('se_channel_key', $slotChannels), static fn($id) => $id !== '' && isset($sources[$id])));
            if (!isset($slotRows[$slotId])) $slotRows[$slotId] = [
                'id' => $slotId,
                'name' => (string)($slot['name'] ?? 'Programmazione automatica'),
                'dailyMinutes' => 0.0,
                'sourceIds' => [],
                'sourceDemand' => [],
            ];
            $slotRows[$slotId]['dailyMinutes'] += $minutes;
            $slotRows[$slotId]['sourceIds'] = array_values(array_unique(array_merge($slotRows[$slotId]['sourceIds'], $sourceKeys)));
            $share = $sourceKeys ? $minutes / count($sourceKeys) : 0;
            foreach ($sourceKeys as $sourceKey) {
                $sources[$sourceKey]['assignedMinutesPerDay'] += $share;
                $sources[$sourceKey]['slots'][$slotId] = (string)($slot['name'] ?? $slotId);
                $slotRows[$slotId]['sourceDemand'][$sourceKey] = (float)($slotRows[$slotId]['sourceDemand'][$sourceKey] ?? 0) + $share;
            }
        }

        foreach ($sources as &$source) {
            $demand30 = $source['assignedMinutesPerDay'] * 30;
            $supply30 = $source['freshMinutesPerDay'] * 30;
            $deficit = max(0, $demand30 - $supply30);
            $coverage = $source['assignedMinutesPerDay'] > 0 ? min(999, ($source['freshMinutesPerDay'] / $source['assignedMinutesPerDay']) * 100) : 100;
            $source['assignedMinutesPerDay'] = round($source['assignedMinutesPerDay'], 1);
            $source['coveragePercent'] = round($coverage);
            $source['deficitMinutes30d'] = round($deficit);
            $source['expectedRepeatPlays30d'] = $deficit > 0 ? (int)ceil($deficit / max(1, $source['averageDurationMinutes'])) : 0;
            $source['risk'] = $coverage >= 100 ? 'ok' : ($coverage >= 70 ? 'warning' : 'critical');
            $source['slots'] = array_values($source['slots']);
        }
        unset($source);

        foreach ($slotRows as &$slotRow) {
            $slotSources = array_values(array_filter(array_map(static fn($id) => $sources[$id] ?? null, $slotRow['sourceIds'])));
            $freshMinutes = 0.0;
            $uploadsPerDay = 0.0;
            foreach ($slotSources as $slotSource) {
                $sourceId = (string)$slotSource['id'];
                $allocation = $slotSource['assignedMinutesPerDay'] > 0
                    ? (float)($slotRow['sourceDemand'][$sourceId] ?? 0) / $slotSource['assignedMinutesPerDay']
                    : 0;
                $freshMinutes += $slotSource['freshMinutesPerDay'] * $allocation;
                $uploadsPerDay += $slotSource['uploadsPerDay'] * $allocation;
            }
            $averageDuration = $slotSources ? array_sum(array_column($slotSources, 'averageDurationMinutes')) / count($slotSources) : 30;
            $deficitDaily = max(0, $slotRow['dailyMinutes'] - $freshMinutes);
            $coverage = $slotRow['dailyMinutes'] > 0 ? min(999, ($freshMinutes / $slotRow['dailyMinutes']) * 100) : 100;
            $slotRow['sourceCount'] = count($slotSources);
            $slotRow['sourceNames'] = array_values(array_column($slotSources, 'name'));
            $slotRow['uploads30d'] = array_sum(array_column($slotSources, 'eligibleUploads30d'));
            $slotRow['uploadsPerDay'] = round($uploadsPerDay, 2);
            $slotRow['freshMinutesPerDay'] = round($freshMinutes, 1);
            $slotRow['coveragePercent'] = round($coverage);
            $slotRow['missingMinutesPerDay'] = round($deficitDaily, 1);
            $slotRow['requiredUploadsPerDay'] = round($slotRow['dailyMinutes'] / max(1, $averageDuration), 2);
            $slotRow['missingUploadsPerDay'] = round($deficitDaily / max(1, $averageDuration), 2);
            $slotRow['expectedRepeatPlays30d'] = $deficitDaily > 0 ? (int)ceil(($deficitDaily * 30) / max(1, $averageDuration)) : 0;
            $slotRow['risk'] = $coverage >= 100 ? 'ok' : ($coverage >= 70 ? 'warning' : 'critical');
            unset($slotRow['sourceIds']);
            unset($slotRow['sourceDemand']);
        }
        unset($slotRow);

        $replicaMap = [];
        foreach (is_array($data['futureSchedule'] ?? null) ? $data['futureSchedule'] : [] as $item) {
            if (!is_array($item)) continue;
            $id = se_video_id($item);
            if ($id === '') continue;
            if (!isset($replicaMap[$id])) $replicaMap[$id] = [
                'videoId' => $id,
                'title' => (string)($item['title'] ?? 'Video'),
                'channel' => (string)($item['channel'] ?? ''),
                'airings' => 0,
                'replicas' => 0,
                'historicalReplica' => false,
                'times' => [],
            ];
            $replicaMap[$id]['airings']++;
            if (!empty($item['isReplica'])) $replicaMap[$id]['historicalReplica'] = true;
            $replicaMap[$id]['times'][] = (string)($item['startDateTime'] ?? '');
        }
        $replicaVideos = [];
        foreach ($replicaMap as $row) if ($row['airings'] > 1 || $row['historicalReplica']) {
            $row['replicas'] = max($row['airings'] - 1, $row['historicalReplica'] ? 1 : 0);
            $replicaVideos[] = $row;
        }
        usort($replicaVideos, static fn($a, $b) => $b['replicas'] <=> $a['replicas']);

        $sourceRows = array_values($sources);
        usort($sourceRows, static function ($a, $b): int {
            $order = ['critical' => 0, 'warning' => 1, 'ok' => 2];
            return ($order[$a['risk']] <=> $order[$b['risk']]) ?: ($a['coveragePercent'] <=> $b['coveragePercent']);
        });
        $slotRows = array_values($slotRows);
        usort($slotRows, static function ($a, $b): int {
            $order = ['critical' => 0, 'warning' => 1, 'ok' => 2];
            return ($order[$a['risk']] <=> $order[$b['risk']]) ?: ($a['coveragePercent'] <=> $b['coveragePercent']);
        });
        $totalDemand = array_sum(array_column($slotRows, 'dailyMinutes'));
        $totalFresh = array_sum(array_column($sourceRows, 'freshMinutesPerDay'));
        $overallCoverage = $totalDemand > 0 ? min(999, ($totalFresh / $totalDemand) * 100) : 100;

        return [
            'windowDays' => 30,
            'generatedAt' => se_iso($now),
            'sourceCount' => count($sourceRows),
            'criticalSources' => count(array_filter($sourceRows, static fn($row) => $row['risk'] === 'critical')),
            'criticalSlots' => count(array_filter($slotRows, static fn($row) => $row['risk'] === 'critical')),
            'dailyDemandMinutes' => round($totalDemand, 1),
            'freshMinutesPerDay' => round($totalFresh, 1),
            'overallCoveragePercent' => round($overallCoverage),
            'missingMinutesPerDay' => round(max(0, $totalDemand - $totalFresh), 1),
            'expectedRepeatPlays30d' => array_sum(array_column($slotRows, 'expectedRepeatPlays30d')),
            'sources' => $sourceRows,
            'slots' => $slotRows,
            'replicaVideos' => array_slice($replicaVideos, 0, 50),
        ];
    }

    function v3_tick(array &$data, int $now, string $trigger = 'manual'): array {
        $data['botV3'] = is_array($data['botV3'] ?? null) ? $data['botV3'] : [];
        $data['botV3Decisions'] = is_array($data['botV3Decisions'] ?? null) ? $data['botV3Decisions'] : [];
        $state = $data['botV3'];
        $started = microtime(true);
        // Catalogo, palinsesto e stato live appartengono allo stesso motore.
        // Il controllo intervallo dentro schedule-engine evita richieste YouTube
        // ad ogni minuto di cron.
        $forceCatalogSync = $trigger === 'sync_sources';
        $catalogSync = $trigger === 'test'
            ? ['changed' => false, 'skipped' => 'test']
            : se_sync_youtube_catalog($data, $now, $forceCatalogSync);
        $audioVerification = $trigger === 'test'
            ? ['changed' => false, 'skipped' => 'test']
            : se_refresh_italian_audio_verification($data, $now);
        // Il controllo catalogo puÃ² aggiornare cursore e diagnostica disponibilitÃ :
        // conservarli quando a fine tick viene pubblicato lo stato del motore.
        $state = array_merge($state, is_array($data['botV3'] ?? null) ? $data['botV3'] : []);
        $hasCurrentSource = false;
        foreach (['futureSchedule', 'schedule'] as $sourceKey) foreach (is_array($data[$sourceKey] ?? null) ? $data[$sourceKey] : [] as $sourceItem) {
            if (!is_array($sourceItem)) continue;
            $sourceStart = se_ts((string)($sourceItem['startDateTime'] ?? ''));
            $sourceEnd = se_ts((string)($sourceItem['endDateTime'] ?? ''));
            if ($sourceStart <= $now && $sourceEnd > $now) { $hasCurrentSource = true; break 2; }
        }
        $bootstrapped = !$hasCurrentSource ? se_ensure_daily_schedule($data, $now, true) : false;
        $futureRebuilt = se_ensure_future_schedule($data, $now, 72, in_array($trigger, ['sync_sources', 'force_rebuild_queue'], true));
        se_publish_future_schedule($data, $now);
        $rebuilt = $bootstrapped || $futureRebuilt;
        $schedule = is_array($data['futureSchedule'] ?? null) ? $data['futureSchedule'] : [];
        [$index, $current, $match] = v3_find_current($schedule, $now);
        if ($index < 0) {
            se_ensure_daily_schedule($data, $now, true);
            $futureRebuilt = se_ensure_future_schedule($data, $now, 72, true) || $futureRebuilt;
            se_publish_future_schedule($data, $now);
            $rebuilt = true;
            $schedule = is_array($data['futureSchedule'] ?? null) ? $data['futureSchedule'] : [];
            [$index, $current, $match] = v3_find_current($schedule, $now);
        }

        $previousId = (string)($state['currentVideoId'] ?? '');
        $currentId = se_video_id($current);
        $changed = $currentId !== '' && $currentId !== $previousId;
        $queue = [];
        if ($index >= 0) {
            foreach (array_slice($schedule, $index, 3) as $item) if (is_array($item)) $queue[] = v3_compact_item($item);
        }
        $start = se_ts((string)($current['startDateTime'] ?? ''));
        $end = se_ts((string)($current['endDateTime'] ?? ''));
        $offset = $start > 0 ? max(0, min(se_duration($current) - 1, $now - $start)) : 0;

        if ($currentId !== '') {
            $data['publicLiveSchedule'] = is_array($data['publicLiveSchedule'] ?? null) ? $data['publicLiveSchedule'] : [];
            $data['publicLiveSchedule']['liveQueue'] = $queue;
            $data['liveQueue'] = $queue;
            $data['liveState'] = array_merge(is_array($data['liveState'] ?? null) ? $data['liveState'] : [], [
                'phase' => 'content', 'status' => 'playing', 'type' => 'video',
                'currentVideoId' => $currentId,
                'currentTitle' => (string)($current['title'] ?? ''),
                'currentItalianVideoId' => (string)($current['italianVideoId'] ?? ''),
                'currentItalianAudioTrackId' => (string)($current['italianAudioTrackId'] ?? ''),
                'currentItalianAudioMode' => (string)($current['italianAudioMode'] ?? ''),
                'currentItalianAudioStatus' => (string)($current['italianAudioStatus'] ?? ''),
                'currentStartedAt' => (string)($current['startDateTime'] ?? ''),
                'actualStartDateTime' => (string)($current['startDateTime'] ?? ''),
                'currentEndsAt' => (string)($current['endDateTime'] ?? ''),
                'currentDurationSeconds' => se_duration($current),
                'offset' => $offset, 'currentVideoOffset' => $offset,
                'currentChangedBy' => 'bot-v3', 'currentChangeReason' => $changed ? 'wall_clock_projection' : 'state_refresh',
                'lastAuthority' => ['requestedBy' => 'bot-v3', 'reason' => $changed ? 'wall_clock_projection' : 'state_refresh', 'at' => se_iso($now)],
                'serverNowAtPublish' => se_iso($now),
            ]);
        }

        if ($changed) {
            array_unshift($data['botV3Decisions'], [
                'id' => 'v3d_' . $now . '_' . substr(hash('sha256', $currentId), 0, 8),
                'at' => se_iso($now), 'trigger' => $trigger, 'videoId' => $currentId,
                'title' => (string)($current['title'] ?? ''), 'channel' => (string)($current['channel'] ?? ''),
                'slotName' => (string)($current['slotName'] ?? ''), 'category' => (string)($current['category'] ?? ''),
                'strategy' => (string)($current['strategy'] ?? ''), 'reason' => (string)($current['reason'] ?? 'Voce prevista dal palinsesto giornaliero'),
                'score' => (float)($current['finalScore'] ?? 0), 'publishedAt' => (string)($current['publishedAt'] ?? ''),
                'scheduledStart' => (string)($current['startDateTime'] ?? ''), 'scheduledEnd' => (string)($current['endDateTime'] ?? ''),
                'alternatives' => array_values(array_map(static function ($item): array {
                    return ['videoId' => se_video_id($item), 'title' => (string)($item['title'] ?? ''), 'channel' => (string)($item['channel'] ?? ''), 'strategy' => (string)($item['strategy'] ?? '')];
                }, array_filter(array_slice($schedule, $index + 1, 3), 'is_array'))),
            ]);
            $data['botV3Decisions'] = array_slice($data['botV3Decisions'], 0, 200);
        }

        $lastTickTs = se_ts((string)($state['lastTickAt'] ?? ''));
        $lag = $lastTickTs > 0 ? max(0, $now - $lastTickTs) : 0;
        $state = array_merge($state, [
            'engineVersion' => 3, 'enabled' => ($state['enabled'] ?? true) !== false,
            'status' => $currentId !== '' ? 'RUNNING' : 'NO_PROGRAMME',
            'lastTickAt' => se_iso($now), 'lastTrigger' => $trigger,
            'lastTickDurationMs' => (int)round((microtime(true) - $started) * 1000),
            'tickSequence' => (int)($state['tickSequence'] ?? 0) + 1,
            'currentVideoId' => $currentId, 'currentTitle' => (string)($current['title'] ?? ''),
            'currentStartedAt' => (string)($current['startDateTime'] ?? ''), 'currentEndsAt' => (string)($current['endDateTime'] ?? ''),
            'currentOffsetSeconds' => $offset, 'scheduleMatch' => $match,
            'scheduleDate' => (string)($data['scheduleMeta']['date'] ?? ''), 'scheduleItems' => count($schedule),
            'queueLength' => count($queue), 'lastScheduleRebuilt' => $rebuilt,
            'futureScheduleRebuilt' => $futureRebuilt,
            'futureScheduleItems' => (int)($data['futureScheduleMeta']['items'] ?? 0),
            'futureScheduleReplicas' => (int)($data['futureScheduleMeta']['replicas'] ?? 0),
            'futureScheduleFreshItems' => (int)($data['futureScheduleMeta']['freshItems'] ?? 0),
            'futureScheduleSubstitutes' => (int)($data['futureScheduleMeta']['substituteItems'] ?? 0),
            'futureReplicasReplaced' => (int)($data['futureScheduleMeta']['replicasReplacedLastRun'] ?? 0),
            'lastLagSeconds' => $lag, 'recoveryCount' => (int)($state['recoveryCount'] ?? 0) + ($lag > 120 ? 1 : 0),
            'lastError' => $currentId === '' ? 'Nessun programma valido per l’orario corrente' : '',
            'nextWakeAt' => $end > 0 ? se_iso(min($end + 1, $now + 60)) : se_iso($now + 60),
        ]);
        if ($trigger === 'cron') $state['lastCronAt'] = se_iso($now);
        if ($changed) $state['lastDecisionAt'] = se_iso($now);
        $data['botV3'] = $state;
        // Pubblica i canali lineari secondari dopo aver congelato la terna
        // ufficiale della Live principale, mantenendo i due motori isolati.
        $webLiveChannels = ml_tick_all($data, $now);
        $data['version'] = (string)((int)round(microtime(true) * 1000));
        $data['lastBotPublishAt'] = se_iso($now);
        return ['ok' => $currentId !== '', 'changed' => $changed, 'rebuilt' => $rebuilt, 'futureRebuilt' => $futureRebuilt, 'catalogSync' => $catalogSync, 'audioVerification' => $audioVerification, 'webLiveChannels' => $webLiveChannels, 'state' => $state, 'current' => $currentId !== '' ? v3_compact_item($current) : null, 'queue' => $queue];
    }

    function v3_status(array $data, int $now): array {
        $state = is_array($data['botV3'] ?? null) ? $data['botV3'] : [];
        $lastCron = se_ts((string)($state['lastCronAt'] ?? ''));
        $cronAge = $lastCron > 0 ? max(0, $now - $lastCron) : null;
        return [
            'ok' => true, 'engineVersion' => 3, 'enabled' => ($state['enabled'] ?? false) === true,
            'status' => (string)($state['status'] ?? 'NOT_STARTED'), 'state' => $state,
            'cronActive' => $cronAge !== null && $cronAge <= 130, 'cronAgeSeconds' => $cronAge,
            'current' => is_array($data['publicLiveSchedule']['liveQueue'][0] ?? null) ? $data['publicLiveSchedule']['liveQueue'][0] : null,
            'upcoming' => array_slice(is_array($data['publicLiveSchedule']['liveQueue'] ?? null) ? $data['publicLiveSchedule']['liveQueue'] : [], 1, 2),
            'futureSchedule' => array_values(array_map('v3_compact_item', array_filter(is_array($data['futureSchedule'] ?? null) ? $data['futureSchedule'] : [], 'is_array'))),
            'futureScheduleMeta' => is_array($data['futureScheduleMeta'] ?? null) ? $data['futureScheduleMeta'] : [],
            'supplyAnalytics' => v3_supply_analytics($data, $now),
            'decisions' => array_slice(is_array($data['botV3Decisions'] ?? null) ? $data['botV3Decisions'] : [], 0, 50),
            'settings' => se_bot_profile($data),
            'webLiveChannels' => is_array($data['webLiveChannels'] ?? null) ? $data['webLiveChannels'] : [],
            'serverNow' => se_iso($now),
        ];
    }
}
