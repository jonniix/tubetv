<?php
declare(strict_types=1);

require_once __DIR__ . '/schedule-engine.php';

/**
 * TubeTV secondary linear channels.
 *
 * The official V3 cron owns every channel. Secondary channels do not use time
 * slots: they rotate recent videos from explicitly assigned YouTube sources.
 */

if (!function_exists('ml_definitions')) {
    function ml_definitions(): array {
        return [
            'live2' => ['id' => 'live2', 'name' => 'Live Web 2', 'shortName' => 'Live Web 2'],
            'kids'  => ['id' => 'kids',  'name' => 'Live Kids', 'shortName' => 'Live Kids'],
            'crime' => ['id' => 'crime', 'name' => 'Live Crime', 'shortName' => 'Live Crime'],
            'docu'  => ['id' => 'docu',  'name' => 'Live Docu & Lifestyle', 'shortName' => 'Docu & Lifestyle'],
            'cucina' => ['id' => 'cucina', 'name' => 'Live Cucina', 'shortName' => 'Live Cucina'],
            'girl' => ['id' => 'girl', 'name' => 'Live Girl', 'shortName' => 'Live Girl'],
            'rewind24' => ['id' => 'rewind24', 'name' => 'Rewind 24h', 'shortName' => 'Rewind 24h', 'type' => 'rewind', 'windowHours' => 24],
            'rewind7' => ['id' => 'rewind7', 'name' => 'Rewind 7', 'shortName' => 'Rewind 7 giorni', 'type' => 'rewind', 'windowHours' => 168],
            'rewind30' => ['id' => 'rewind30', 'name' => 'Rewind 30', 'shortName' => 'Rewind 30 giorni', 'type' => 'rewind', 'windowHours' => 720],
        ];
    }

    function ml_is_rewind(array $definition): bool {
        return (string)($definition['type'] ?? '') === 'rewind';
    }

    function ml_channel_assigned(array $channel, string $stationId): bool {
        $ids = is_array($channel['webLiveIds'] ?? null) ? $channel['webLiveIds'] : [];
        return in_array($stationId, array_map('strval', $ids), true);
    }

    function ml_assigned_channels(array $data, string $stationId): array {
        $channels = array_values(array_filter(se_active_channels($data), static fn($channel): bool =>
            is_array($channel) && ml_channel_assigned($channel, $stationId)
        ));
        $unique = [];
        foreach ($channels as $channel) {
            $key = se_channel_key($channel);
            if ($key !== '' && !isset($unique[$key])) $unique[$key] = $channel;
        }
        $channels = array_values($unique);
        usort($channels, static fn(array $a, array $b): int =>
            strcmp(se_channel_key($a), se_channel_key($b))
        );
        return $channels;
    }

    function ml_recent_pool(array $data, array $channel, int $now): array {
        $minimumPublishedAt = $now - (30 * 86400);
        $pool = []; $seen = [];
        foreach (se_collect_videos($data) as $video) {
            if (!is_array($video) || !se_video_matches_channel($video, $channel)) continue;
            if (!se_is_playable($video, $channel, $data)) continue;
            $videoId = se_video_id($video);
            if ($videoId === '' || isset($seen[$videoId])) continue;
            $published = se_ts((string)($video['publishedAt'] ?? $video['createdAt'] ?? ''));
            if ($published <= 0 || $published < $minimumPublishedAt || $published > $now + 300) continue;
            $seen[$videoId] = true;
            $video['_mlPublishedTs'] = $published;
            $pool[] = $video;
        }
        usort($pool, static fn(array $a, array $b): int =>
            (int)($b['_mlPublishedTs'] ?? 0) <=> (int)($a['_mlPublishedTs'] ?? 0)
        );
        return $pool;
    }

    function ml_rewind_pool(array $data, int $windowHours, int $now): array {
        $minimumPublishedAt = $now - max(1, $windowHours) * 3600;
        $pool = []; $seen = [];
        foreach (se_active_channels($data) as $channel) {
            if (!is_array($channel)) continue;
            foreach (se_collect_videos($data) as $video) {
                if (!is_array($video) || !se_video_matches_channel($video, $channel)) continue;
                if (!se_is_playable($video, $channel, $data)) continue;
                $videoId = se_video_id($video);
                if ($videoId === '' || isset($seen[$videoId])) continue;
                $published = se_ts((string)($video['publishedAt'] ?? $video['createdAt'] ?? ''));
                if ($published <= 0 || $published < $minimumPublishedAt || $published > $now + 300) continue;
                $seen[$videoId] = true;
                $video['_mlPublishedTs'] = $published;
                $video['_mlChannel'] = $channel;
                $pool[] = $video;
            }
        }
        usort($pool, static fn(array $a, array $b): int =>
            ((int)($b['_mlPublishedTs'] ?? 0) <=> (int)($a['_mlPublishedTs'] ?? 0))
            ?: strcmp(se_video_id($a), se_video_id($b))
        );
        return $pool;
    }

    function ml_history_stats(array $history, string $videoId): array {
        $count = 0; $last = 0;
        foreach ($history as $entry) {
            if (!is_array($entry) || (string)($entry['videoId'] ?? '') !== $videoId) continue;
            $count++;
            $last = max($last, se_ts((string)($entry['airedAt'] ?? '')));
        }
        return ['count' => $count, 'last' => $last];
    }

    function ml_pick_video(array $pool, array $history, array $queuedIds, int $now): ?array {
        if (!$pool) return null;
        $ranked = [];
        foreach ($pool as $video) {
            $id = se_video_id($video);
            if ($id === '') continue;
            $stats = ml_history_stats($history, $id);
            $published = (int)($video['_mlPublishedTs'] ?? 0);
            $age = max(0, $now - $published);
            $queuedCount = count(array_filter($queuedIds, static fn(string $queuedId): bool => $queuedId === $id));
            $promotion = $age <= 3 * 86400 && ($stats['count'] + $queuedCount) < 3;
            // New uploads receive up to three promoted airings during 72 hours.
            // Afterwards the normal loop selects the least recently aired item.
            $score = ($promotion
                ? 2000000000000 + $published - ($stats['count'] * 100000000)
                : 1000000000000 - ($stats['last'] ?: 0) + (int)floor($published / 1000))
                - ($queuedCount * 4000000000000);
            $ranked[] = ['video' => $video, 'score' => $score, 'promotion' => $promotion, 'stats' => $stats];
        }
        usort($ranked, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
        return $ranked[0] ?? null;
    }

    function ml_compact_video(array $video, array $channel, string $stationId, int $start, array $pick): array {
        $duration = max(60, se_duration($video));
        $id = se_video_id($video);
        $clean = $video;
        unset($clean['_mlPublishedTs']);
        return array_merge($clean, [
            'id' => 'ml_' . $stationId . '_' . $start . '_' . $id,
            'videoId' => $id,
            'channel' => (string)($channel['name'] ?? $channel['title'] ?? $video['channel'] ?? ''),
            'channelId' => se_channel_key($channel),
            'sourceChannelId' => se_channel_key($channel),
            'durationSeconds' => $duration,
            'startDateTime' => se_iso($start),
            'actualStartDateTime' => se_iso($start),
            'endDateTime' => se_iso($start + $duration),
            'webLiveId' => $stationId,
            'strategy' => !empty($pick['promotion']) ? 'novita_72h' : 'rotazione_30g',
            'reason' => !empty($pick['promotion'])
                ? 'Novita con passaggio extra consentito nelle prime 72 ore'
                : 'Rotazione del contenuto recente meno trasmesso',
            'isReplica' => (int)($pick['stats']['count'] ?? 0) > 0,
            'newReleasePromotion' => !empty($pick['promotion']),
            'publishedAt' => (string)($video['publishedAt'] ?? $video['createdAt'] ?? ''),
        ]);
    }

    function ml_tick_rewind_station(array $data, array $definition, array $existing, int $now): array {
        $stationId = (string)$definition['id'];
        $windowHours = max(1, (int)($definition['windowHours'] ?? 24));
        $history = array_values(array_filter(is_array($existing['history'] ?? null) ? $existing['history'] : [], 'is_array'));
        $schedule = array_values(array_filter(is_array($existing['schedule'] ?? null) ? $existing['schedule'] : [], 'is_array'));
        $recorded = array_fill_keys(array_map(static fn($h): string => (string)($h['scheduleId'] ?? ''), $history), true);
        foreach ($schedule as $item) {
            $end = se_ts((string)($item['endDateTime'] ?? ''));
            $scheduleId = (string)($item['id'] ?? '');
            if ($end > 0 && $end <= $now && $scheduleId !== '' && empty($recorded[$scheduleId])) {
                array_unshift($history, ['scheduleId'=>$scheduleId,'videoId'=>se_video_id($item),'channelId'=>(string)($item['channelId']??''),'airedAt'=>(string)($item['startDateTime']??se_iso($end-se_duration($item)))]);
                $recorded[$scheduleId] = true;
            }
        }
        $history = array_slice($history, 0, 1200);
        $schedule = array_values(array_filter($schedule, static fn(array $item): bool => se_ts((string)($item['endDateTime'] ?? '')) > $now));
        usort($schedule, static fn(array $a, array $b): int => se_ts((string)($a['startDateTime'] ?? '')) <=> se_ts((string)($b['startDateTime'] ?? '')));

        $pool = ml_rewind_pool($data, $windowHours, $now);
        $poolSignature = hash('sha256', implode('|', array_map(static fn(array $video): string => se_video_id($video) . ':' . (string)($video['_mlPublishedTs'] ?? 0), $pool)));
        $poolChanged = (int)($existing['engineVersion'] ?? 0) < 3 || (string)($existing['poolSignature'] ?? '') !== $poolSignature;
        if ($poolChanged && $schedule) {
            $onAir = [];
            foreach ($schedule as $item) {
                $start = se_ts((string)($item['startDateTime'] ?? '')); $end = se_ts((string)($item['endDateTime'] ?? ''));
                if ($start <= $now && $now < $end) { $onAir = [$item]; break; }
            }
            $schedule = $onAir;
        }

        $cursor = $schedule ? se_ts((string)(end($schedule)['endDateTime'] ?? '')) : $now;
        if ($cursor < $now) $cursor = $now;
        $rewindCursor = $poolChanged ? 0 : max(0, (int)($existing['rewindCursor'] ?? 0));
        $lastVideoId = $schedule ? se_video_id(end($schedule)) : '';
        $horizon = $now + 24 * 3600; $guard = 0;
        while ($pool && $cursor < $horizon && count($schedule) < 720 && $guard++ < 3000) {
            $poolIndex = $rewindCursor % count($pool); $video = $pool[$poolIndex]; $rewindCursor++;
            if (count($pool) > 1 && se_video_id($video) === $lastVideoId) continue;
            $channel = is_array($video['_mlChannel'] ?? null) ? $video['_mlChannel'] : [];
            $stats = ml_history_stats($history, se_video_id($video));
            $item = ml_compact_video($video, $channel, $stationId, $cursor, ['promotion'=>false,'stats'=>$stats]);
            $item['strategy'] = 'rewind_' . $windowHours . 'h';
            $item['reason'] = 'Rotazione continua dei video pubblicati nelle ultime ' . $windowHours . ' ore';
            $item['rewindWindowHours'] = $windowHours;
            $item['isReplica'] = $stats['count'] > 0 || $rewindCursor > count($pool);
            $schedule[] = $item; $lastVideoId = se_video_id($item); $cursor = se_ts((string)$item['endDateTime']);
        }

        $currentIndex = null;
        foreach ($schedule as $index => $item) {
            $start = se_ts((string)($item['startDateTime'] ?? '')); $end = se_ts((string)($item['endDateTime'] ?? ''));
            if ($start <= $now && $now < $end) { $currentIndex = $index; break; }
        }
        $queue = $currentIndex === null ? [] : array_slice($schedule, $currentIndex, 3);
        $current = $queue[0] ?? []; $start = se_ts((string)($current['startDateTime'] ?? '')); $duration = $current ? se_duration($current) : 0;
        $state = $current ? [
            'stationId'=>$stationId,'phase'=>'content','status'=>'playing','type'=>'video','mode'=>'REWIND',
            'currentVideoId'=>se_video_id($current),'currentTitle'=>(string)($current['title']??''),'currentChannel'=>(string)($current['channel']??''),
            'currentStartedAt'=>(string)($current['startDateTime']??''),'actualStartDateTime'=>(string)($current['startDateTime']??''),'currentEndsAt'=>(string)($current['endDateTime']??''),
            'currentDurationSeconds'=>$duration,'offset'=>max(0,min(max(0,$duration-1),$now-$start)),'currentVideoOffset'=>max(0,min(max(0,$duration-1),$now-$start)),
            'serverNowAtPublish'=>se_iso($now),'currentChangedBy'=>'bot-v3-rewind','currentChangeReason'=>$poolChanged?'rewind_pool_updated':'rewind_linear_projection',
            'rewindWindowHours'=>$windowHours,
        ] : ['stationId'=>$stationId,'status'=>$pool?'WAITING':'NO_RECENT_CONTENT','mode'=>'REWIND','rewindWindowHours'=>$windowHours,'serverNowAtPublish'=>se_iso($now)];
        $channelIds = [];
        foreach ($pool as $video) { $channel = is_array($video['_mlChannel'] ?? null) ? $video['_mlChannel'] : []; $id=se_channel_key($channel); if($id!=='')$channelIds[$id]=true; }
        return array_merge($definition, [
            'enabled'=>true,'assignedChannelIds'=>array_keys($channelIds),'sourceCount'=>count($channelIds),'eligibleVideoCount'=>count($pool),
            'schedule'=>$schedule,'liveQueue'=>$queue,'liveState'=>$state,'history'=>$history,'rewindCursor'=>$rewindCursor,
            'engineVersion'=>3,'poolSignature'=>$poolSignature,'updatedAt'=>se_iso($now),
            'rules'=>['rewind'=>true,'windowHours'=>$windowHours,'newUploadsBecomeNext'=>true,'personalSkip'=>true,'loop'=>true],
        ]);
    }

    function ml_tick_station(array $data, array $definition, array $existing, int $now): array {
        if (ml_is_rewind($definition)) return ml_tick_rewind_station($data, $definition, $existing, $now);
        $stationId = (string)$definition['id'];
        $channels = ml_assigned_channels($data, $stationId);
        $history = array_values(array_filter(is_array($existing['history'] ?? null) ? $existing['history'] : [], 'is_array'));
        $schedule = array_values(array_filter(is_array($existing['schedule'] ?? null) ? $existing['schedule'] : [], 'is_array'));

        // Record completed entries exactly once, then retain only current/future timeline.
        $recorded = array_fill_keys(array_map(static fn($h): string => (string)($h['scheduleId'] ?? ''), $history), true);
        foreach ($schedule as $item) {
            $end = se_ts((string)($item['endDateTime'] ?? ''));
            $scheduleId = (string)($item['id'] ?? '');
            if ($end > 0 && $end <= $now && $scheduleId !== '' && empty($recorded[$scheduleId])) {
                array_unshift($history, [
                    'scheduleId' => $scheduleId,
                    'videoId' => se_video_id($item),
                    'channelId' => (string)($item['channelId'] ?? ''),
                    'airedAt' => (string)($item['startDateTime'] ?? se_iso($end - se_duration($item))),
                ]);
                $recorded[$scheduleId] = true;
            }
        }
        $history = array_slice($history, 0, 600);
        $schedule = array_values(array_filter($schedule, static fn(array $item): bool =>
            se_ts((string)($item['endDateTime'] ?? '')) > $now
        ));
        usort($schedule, static fn(array $a, array $b): int =>
            se_ts((string)($a['startDateTime'] ?? '')) <=> se_ts((string)($b['startDateTime'] ?? ''))
        );

        $pools = [];
        foreach ($channels as $channel) $pools[se_channel_key($channel)] = ml_recent_pool($data, $channel, $now);
        $channels = array_values(array_filter($channels, static fn(array $channel): bool => !empty($pools[se_channel_key($channel)])));
        $sourceSignature = implode('|', array_map('se_channel_key', $channels));

        // Older projections and changed source sets may contain hours of stale
        // repetitions. Keep only the programme already on air and regenerate
        // the future from its exact end, using the current source rotation.
        $projectionChanged = (int)($existing['engineVersion'] ?? 0) < 2
            || (string)($existing['sourceSignature'] ?? '') !== $sourceSignature;
        if ($projectionChanged && $schedule) {
            $onAir = [];
            foreach ($schedule as $item) {
                $start = se_ts((string)($item['startDateTime'] ?? ''));
                $end = se_ts((string)($item['endDateTime'] ?? ''));
                if ($start <= $now && $now < $end) { $onAir = [$item]; break; }
            }
            $schedule = $onAir;
        }
        $cursor = $schedule ? se_ts((string)(end($schedule)['endDateTime'] ?? '')) : $now;
        if ($cursor < $now) $cursor = $now;
        $channelCursor = max(0, (int)($existing['channelCursor'] ?? 0));
        if ($schedule && count($channels) > 1) {
            $lastChannelId = (string)(end($schedule)['channelId'] ?? end($schedule)['sourceChannelId'] ?? '');
            foreach ($channels as $index => $channel) {
                if (se_channel_key($channel) === $lastChannelId) {
                    $channelCursor = ($index + 1) % count($channels);
                    break;
                }
            }
        }
        $queuedIds = array_values(array_filter(array_map('se_video_id', $schedule)));
        $horizon = $now + 24 * 3600;

        // On an empty station, let the source with the freshest upload start.
        if (!$schedule && count($channels) > 1) {
            $freshestIndex = 0; $freshest = 0;
            foreach ($channels as $index => $channel) {
                $candidate = $pools[se_channel_key($channel)][0] ?? [];
                $published = (int)($candidate['_mlPublishedTs'] ?? 0);
                if ($published > $freshest) { $freshest = $published; $freshestIndex = $index; }
            }
            $channelCursor = $freshestIndex;
        }

        $guard = 0;
        while ($channels && $cursor < $horizon && count($schedule) < 360 && $guard++ < 1000) {
            $channelIndex = $channelCursor % count($channels);
            $channel = $channels[$channelIndex];
            $channelCursor = ($channelIndex + 1) % count($channels);
            $pick = ml_pick_video($pools[se_channel_key($channel)] ?? [], $history, $queuedIds, $now);
            if (!$pick) continue;
            $item = ml_compact_video($pick['video'], $channel, $stationId, $cursor, $pick);
            $schedule[] = $item;
            $queuedIds[] = se_video_id($item);
            $cursor = se_ts((string)$item['endDateTime']);
        }

        $currentIndex = null;
        foreach ($schedule as $index => $item) {
            $start = se_ts((string)($item['startDateTime'] ?? ''));
            $end = se_ts((string)($item['endDateTime'] ?? ''));
            if ($start <= $now && $now < $end) { $currentIndex = $index; break; }
        }
        $queue = $currentIndex === null ? [] : array_slice($schedule, $currentIndex, 3);
        $current = $queue[0] ?? [];
        $start = se_ts((string)($current['startDateTime'] ?? ''));
        $duration = $current ? se_duration($current) : 0;
        $state = $current ? [
            'stationId' => $stationId,
            'phase' => 'content', 'status' => 'playing', 'type' => 'video', 'mode' => 'LIVE',
            'currentVideoId' => se_video_id($current),
            'currentTitle' => (string)($current['title'] ?? ''),
            'currentChannel' => (string)($current['channel'] ?? ''),
            'currentStartedAt' => (string)($current['startDateTime'] ?? ''),
            'actualStartDateTime' => (string)($current['startDateTime'] ?? ''),
            'currentEndsAt' => (string)($current['endDateTime'] ?? ''),
            'currentDurationSeconds' => $duration,
            'offset' => max(0, min(max(0, $duration - 1), $now - $start)),
            'currentVideoOffset' => max(0, min(max(0, $duration - 1), $now - $start)),
            'serverNowAtPublish' => se_iso($now),
            'currentChangedBy' => 'bot-v3-multilive',
            'currentChangeReason' => 'secondary_linear_projection',
        ] : ['stationId' => $stationId, 'status' => $channels ? 'WAITING' : 'NO_SOURCES', 'serverNowAtPublish' => se_iso($now)];

        return array_merge($definition, [
            'enabled' => true,
            'assignedChannelIds' => array_values(array_map('se_channel_key', $channels)),
            'sourceCount' => count($channels),
            'schedule' => $schedule,
            'liveQueue' => $queue,
            'liveState' => $state,
            'history' => $history,
            'channelCursor' => $channelCursor,
            'engineVersion' => 2,
            'sourceSignature' => $sourceSignature,
            'updatedAt' => se_iso($now),
            'rules' => ['maxAgeDays' => 30, 'newReleaseWindowHours' => 72, 'newReleasePromotedAirings' => 3, 'strictSourceRotation' => true, 'exhaustVideoPoolBeforeRepeat' => true],
        ]);
    }

    function ml_force_rebuild_station(array $data, array $definition, array $existing, int $now): array {
        $schedule = array_values(array_filter(is_array($existing['schedule'] ?? null) ? $existing['schedule'] : [], 'is_array'));
        $onAir = [];
        foreach ($schedule as $item) {
            $start = se_ts((string)($item['startDateTime'] ?? ''));
            $end = se_ts((string)($item['endDateTime'] ?? ''));
            if ($start <= $now && $now < $end) { $onAir = [$item]; break; }
        }
        $seed = $existing;
        $seed['schedule'] = $onAir;
        $seed['engineVersion'] = 0;
        $seed['sourceSignature'] = '__manual_rebuild__';
        $rebuilt = ml_tick_station($data, $definition, $seed, $now);
        $rebuilt['manuallyRebuiltAt'] = se_iso($now);
        return $rebuilt;
    }

    function ml_tick_all(array &$data, int $now): array {
        $existing = is_array($data['webLiveChannels'] ?? null) ? $data['webLiveChannels'] : [];
        $result = [];
        foreach (ml_definitions() as $id => $definition) {
            $result[$id] = ml_tick_station($data, $definition, is_array($existing[$id] ?? null) ? $existing[$id] : [], $now);
        }
        $data['webLiveChannels'] = $result;
        return $result;
    }
}
