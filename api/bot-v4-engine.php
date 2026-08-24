<?php
declare(strict_types=1);

require_once __DIR__ . '/schedule-engine.php';

/**
 * Bot V4 shadow engine.
 *
 * It only writes botV4* fields. The official V3 schedule and live queue are never
 * changed, so the new policy can be audited before it is promoted on air.
 */
if (!function_exists('v4_shadow_tick')) {
    function v4_publication_ts(array $video): int {
        foreach (['publishedAt', 'published', 'uploadDate', 'publishedDate'] as $key) {
            $ts = se_ts((string)($video[$key] ?? ''));
            if ($ts > 0) return $ts;
        }
        return 0;
    }

    function v4_history_sources(array $data): array {
        $out = [];
        foreach (['botV4History', 'botHistory', 'scheduleArchive'] as $key) {
            $value = $data[$key] ?? [];
            if (!is_array($value)) continue;
            foreach ($value as $group) {
                if (!is_array($group)) continue;
                if (se_video_id($group) !== '') $out[] = $group;
                else foreach ($group as $item) if (is_array($item)) $out[] = $item;
            }
        }
        foreach (['schedule', 'futureSchedule'] as $key) {
            foreach (is_array($data[$key] ?? null) ? $data[$key] : [] as $item) {
                if (is_array($item)) $out[] = $item;
            }
        }
        return $out;
    }

    function v4_rebuild_history(array &$data, int $now): array {
        $catalog = [];
        foreach (se_collect_videos($data) as $video) $catalog[se_video_id($video)] = $video;
        $cutoff = $now - 2 * 366 * 86400;
        $unique = [];
        foreach (v4_history_sources($data) as $entry) {
            $id = se_video_id($entry);
            $aired = se_ts((string)($entry['airedAt'] ?? $entry['startDateTime'] ?? $entry['createdAt'] ?? ''));
            $end = se_ts((string)($entry['endDateTime'] ?? ''));
            if ($id === '' || $aired <= 0 || $aired > $now || ($end > 0 && $end > $now) || $aired < $cutoff) continue;
            $video = array_merge($catalog[$id] ?? [], $entry);
            $key = $id . '@' . $aired;
            $unique[$key] = [
                'videoId' => $id,
                'title' => (string)($video['title'] ?? 'Video'),
                'channel' => (string)($video['channel'] ?? $video['channelTitle'] ?? $video['sourceChannelTitle'] ?? ''),
                'channelId' => (string)($video['channelId'] ?? $video['sourceChannelId'] ?? ''),
                'publishedAt' => (string)($video['publishedAt'] ?? ''),
                'airedAt' => se_iso($aired),
                'classification' => (string)($entry['classification'] ?? ($entry['isReplica'] ?? false ? 'REPLICA' : 'STORICO')),
                'finalScore' => (float)($entry['finalScore'] ?? 0),
            ];
        }
        $history = array_values($unique);
        usort($history, static fn($a, $b): int => se_ts($a['airedAt']) <=> se_ts($b['airedAt']));
        if (count($history) > 10000) $history = array_slice($history, -10000);
        $data['botV4History'] = $history;
        return $history;
    }

    function v4_history_stats(array $history): array {
        $stats = [];
        foreach ($history as $entry) {
            $id = se_video_id($entry);
            if ($id === '') continue;
            $aired = se_ts((string)($entry['airedAt'] ?? ''));
            if (!isset($stats[$id])) $stats[$id] = ['count' => 0, 'latest' => 0, 'airings' => []];
            $stats[$id]['count']++;
            $stats[$id]['latest'] = max($stats[$id]['latest'], $aired);
            $stats[$id]['airings'][] = $aired;
        }
        return $stats;
    }

    function v4_channel_map(array $channels): array {
        $map = [];
        foreach ($channels as $channel) foreach (se_channel_refs($channel) as $ref) $map[$ref] = $channel;
        return $map;
    }

    function v4_video_channel(array $video, array $channelMap): ?array {
        foreach (se_video_channel_refs($video) as $ref) if (isset($channelMap[$ref])) return $channelMap[$ref];
        return null;
    }

    function v4_score(array $video, ?array $channel, int $now, int $uses, bool $premiere): float {
        $published = v4_publication_ts($video);
        $ageHours = $published > 0 ? max(0, ($now - $published) / 3600) : 9999;
        $freshness = max(0, 72 - $ageHours) * 50;
        $rating = (float)($channel['rating'] ?? $channel['stars'] ?? 3);
        return round(($premiere ? 5000 : 2500) + $freshness + $rating * 120 - $uses * 1800, 2);
    }

    function v4_make_item(array $video, int $start, string $classification, float $score, array $slot, string $reason): array {
        $duration = max(60, se_duration($video));
        $id = se_video_id($video);
        return array_merge($video, [
            'id' => 'v4_' . strtolower($classification) . '_' . $start . '_' . $id,
            'videoId' => $id,
            'durationSeconds' => $duration,
            'startDateTime' => se_iso($start),
            'endDateTime' => se_iso($start + $duration),
            'slotId' => (string)($slot['id'] ?? 'automatic_gap'),
            'slotName' => (string)($slot['name'] ?? 'Programmazione automatica'),
            'classification' => $classification,
            'displayBadge' => $classification === 'NOVITA' ? 'NOVITÀ' : $classification,
            'badgeDurationSeconds' => 15,
            'strategy' => 'v4_' . strtolower($classification),
            'reason' => $reason,
            'finalScore' => $score,
            'shadow' => true,
            'isReplica' => $classification === 'REPLICA',
        ]);
    }

    function v4_window_at(array $data, int $cursor): array {
        $day = se_day_context($data, $cursor);
        foreach (se_day_windows($data, $day) as $window) {
            if ($cursor >= $window['start'] && $cursor < $window['end']) return $window;
        }
        return ['start' => $cursor, 'end' => $day['end'], 'slot' => ['id' => 'automatic_gap', 'name' => 'Programmazione automatica', 'channelIds' => []]];
    }

    function v4_week_context(array $data, int $ts): array {
        $tz = se_timezone($data);
        $local = (new DateTimeImmutable('@' . $ts))->setTimezone($tz);
        $monday = $local->modify('monday this week')->setTime(0, 0, 0);
        $sunday = $monday->modify('+6 days')->setTime(18, 0, 0);
        return ['monday' => $monday->getTimestamp(), 'replayStart' => $sunday->getTimestamp()];
    }

    function v4_pick_fresh(array $pool, array $allowedChannels, array $historyStats, array $simUses, int $now, array $data): ?array {
        $channelMap = v4_channel_map($allowedChannels);
        $candidates = [];
        foreach ($pool as $video) {
            $id = se_video_id($video);
            $published = v4_publication_ts($video);
            $channel = v4_video_channel($video, $channelMap);
            if ($id === '' || !$channel || $published <= 0 || $published > $now || $published < $now - 72 * 3600 || !se_is_playable($video, $channel, $data)) continue;
            $actual = (int)($historyStats[$id]['count'] ?? 0);
            $used = (int)($simUses[$id] ?? 0);
            if ($actual + $used >= 3) continue;
            $premiere = $actual + $used === 0;
            $score = v4_score($video, $channel, $now, $used, $premiere);
            $candidates[] = ['video' => $video, 'channel' => $channel, 'premiere' => $premiere, 'score' => $score, 'published' => $published];
        }
        usort($candidates, static function ($a, $b): int {
            if ($a['premiere'] !== $b['premiere']) return $a['premiere'] ? -1 : 1;
            $byScore = $b['score'] <=> $a['score'];
            if ($byScore !== 0) return $byScore;
            $byPublished = $b['published'] <=> $a['published'];
            return $byPublished !== 0 ? $byPublished : strcmp(se_video_id($a['video']), se_video_id($b['video']));
        });
        return $candidates[0] ?? null;
    }

    function v4_archive_pool(array $pool, int $now): array {
        $min = $now - 2 * 366 * 86400;
        $max = $now - 72 * 3600;
        $out = array_values(array_filter($pool, static function ($video) use ($min, $max): bool {
            $published = v4_publication_ts($video);
            return $published >= $min && $published < $max;
        }));
        usort($out, static function ($a, $b): int {
            $byDate = v4_publication_ts($a) <=> v4_publication_ts($b);
            return $byDate !== 0 ? $byDate : strcmp(se_video_id($a), se_video_id($b));
        });
        return $out;
    }

    function v4_pick_archive(array $archive, array $allowedChannels, array $data, int &$cursor, int &$cycle, array &$used): ?array {
        $count = count($archive);
        if ($count === 0) return null;
        $channelMap = v4_channel_map($allowedChannels);
        $start = (($cursor % $count) + $count) % $count;
        for ($step = 0; $step < $count; $step++) {
            $index = ($start + $step) % $count;
            $video = $archive[$index];
            $channel = v4_video_channel($video, $channelMap);
            if (!$channel || isset($used[se_video_id($video)]) || !se_is_playable($video, $channel, $data)) continue;
            $cursor = $index + 1;
            if ($cursor >= $count) $cursor = 0;
            return ['video' => $video, 'channel' => $channel, 'score' => 1000 - $step];
        }
        // If every compatible archive item has already been used, begin a new
        // rotation rather than leaving a hole in the linear schedule.
        if ($used) {
            $used = [];
            $cycle++;
            for ($step = 0; $step < $count; $step++) {
                $index = ($start + $step) % $count;
                $video = $archive[$index];
                $channel = v4_video_channel($video, $channelMap);
                if (!$channel || !se_is_playable($video, $channel, $data)) continue;
                $cursor = ($index + 1) % $count;
                return ['video' => $video, 'channel' => $channel, 'score' => 900 - $step];
            }
        }
        return null;
    }

    function v4_replay_candidates(array $history, array $schedule, int $weekStart, int $replayStart): array {
        $best = [];
        foreach (array_merge($history, $schedule) as $item) {
            if (!is_array($item)) continue;
            $id = se_video_id($item);
            $aired = se_ts((string)($item['airedAt'] ?? $item['startDateTime'] ?? ''));
            $published = v4_publication_ts($item);
            if ($id === '' || $aired < $weekStart || $aired >= $replayStart || $published < $weekStart || $published >= $replayStart) continue;
            $candidate = $item;
            $candidate['_replayScore'] = (float)($item['finalScore'] ?? 0);
            if (!isset($best[$id]) || $candidate['_replayScore'] > $best[$id]['_replayScore']) $best[$id] = $candidate;
        }
        $out = array_values($best);
        usort($out, static fn($a, $b): int => ($b['_replayScore'] <=> $a['_replayScore']) ?: strcmp(se_video_id($a), se_video_id($b)));
        return $out;
    }

    function v4_build_shadow_schedule(array $data, int $now, array $history): array {
        $horizon = $now + 72 * 3600;
        $pool = se_collect_videos($data);
        $channels = se_active_channels($data);
        $historyStats = v4_history_stats($history);
        $archive = v4_archive_pool($pool, $now);
        $cursorStart = max(0, (int)($data['botV4ArchiveCursor'] ?? 0));
        $archiveCursor = $archive ? $cursorStart % count($archive) : 0;
        $archiveCycle = max(0, (int)($data['botV4ArchiveCycle'] ?? 0));
        $schedule = [];
        $simUses = [];
        $archiveUsed = [];

        $locked = se_locked_live_items($data, $now, 3);
        $clock = $now;
        foreach ($locked as $item) {
            $copy = $item;
            $copy['classification'] = 'BLOCCATO';
            $copy['displayBadge'] = 'BLOCCATO';
            $copy['badgeDurationSeconds'] = 15;
            $copy['shadow'] = true;
            $schedule[] = $copy;
            $clock = max($clock, se_ts((string)($copy['endDateTime'] ?? '')));
            $simUses[se_video_id($copy)] = ($simUses[se_video_id($copy)] ?? 0) + 1;
        }

        $week = v4_week_context($data, $clock);
        $replayDone = $clock > $week['replayStart'];
        $guard = 0;
        while ($clock < $horizon && $guard++ < 1000) {
            $week = v4_week_context($data, $clock);
            if (!$replayDone && $clock >= $week['replayStart']) {
                $schedule[] = [
                    'id' => 'v4_replay_intro_' . $clock, 'type' => 'bumper', 'videoId' => '',
                    'title' => 'Momento Replica', 'durationSeconds' => 7,
                    'startDateTime' => se_iso($clock), 'endDateTime' => se_iso($clock + 7),
                    'classification' => 'REPLICA_INTRO', 'displayBadge' => 'MOMENTO REPLICA',
                    'badgeDurationSeconds' => 7, 'strategy' => 'v4_replica_intro',
                    'reason' => 'Apertura del blocco domenicale dopo la fine naturale del programma', 'shadow' => true,
                ];
                $clock += 7;
                foreach (v4_replay_candidates($history, $schedule, $week['monday'], $week['replayStart']) as $video) {
                    $item = v4_make_item($video, $clock, 'REPLICA', (float)($video['_replayScore'] ?? 0), ['id' => 'sunday_replay', 'name' => 'Repliche della settimana'], 'Uscita della settimana già trasmessa su TubeTV, ordinata per punteggio');
                    $schedule[] = $item;
                    $clock = se_ts($item['endDateTime']);
                }
                $replayDone = true;
                continue;
            }

            $window = v4_window_at($data, $clock);
            $slot = $window['slot'];
            $allowedChannels = se_channels_for_slot($channels, $slot);
            $fresh = v4_pick_fresh($pool, $allowedChannels, $historyStats, $simUses, $clock, $data);
            if ($fresh) {
                $id = se_video_id($fresh['video']);
                $classification = $fresh['premiere'] ? 'PREMIERE' : 'NOVITA';
                $reason = $fresh['premiere'] ? 'Primo passaggio TubeTV di una nuova uscita' : 'Nuovo passaggio entro 72 ore, autorizzato dal punteggio';
                $item = v4_make_item($fresh['video'], $clock, $classification, $fresh['score'], $slot, $reason);
                $simUses[$id] = ($simUses[$id] ?? 0) + 1;
            } else {
                $picked = v4_pick_archive($archive, $allowedChannels, $data, $archiveCursor, $archiveCycle, $archiveUsed);
                if (!$picked) { $clock = max($clock + 60, (int)$window['end']); continue; }
                $id = se_video_id($picked['video']);
                $archiveUsed[$id] = true;
                $item = v4_make_item($picked['video'], $clock, 'ARCHIVIO', $picked['score'], $slot, 'Archivio degli ultimi due anni, dal più vecchio al più recente');
            }
            $schedule[] = $item;
            $clock = se_ts($item['endDateTime']);
        }
        return ['schedule' => $schedule, 'cursorStart' => $cursorStart, 'cursorEnd' => $archiveCursor, 'archiveCycle' => $archiveCycle, 'archiveSize' => count($archive)];
    }

    function v4_shadow_tick(array &$data, int $now, string $trigger = 'cron'): array {
        $history = v4_rebuild_history($data, $now);
        $catalogSignature = se_catalog_signature($data);
        $hourKey = gmdate('Y-m-d-H', $now);
        $signature = hash('sha256', $catalogSignature . '|' . $hourKey . '|' . count($history));
        $state = is_array($data['botV4'] ?? null) ? $data['botV4'] : [];
        $existing = is_array($data['botV4ShadowSchedule'] ?? null) ? $data['botV4ShadowSchedule'] : [];
        $lastEnd = $existing ? se_ts((string)($existing[count($existing) - 1]['endDateTime'] ?? '')) : 0;
        $rebuild = $trigger === 'force_rebuild_queue' || ($state['signature'] ?? '') !== $signature || $lastEnd < $now + 60 * 3600;
        if ($rebuild) {
            $built = v4_build_shadow_schedule($data, $now, $history);
            $schedule = $built['schedule'];
            $data['botV4ShadowSchedule'] = $schedule;
            $data['botV4ArchiveCursor'] = $built['cursorEnd'];
            $data['botV4ArchiveCycle'] = $built['archiveCycle'];
            $counts = ['PREMIERE' => 0, 'NOVITA' => 0, 'ARCHIVIO' => 0, 'REPLICA' => 0, 'REPLICA_INTRO' => 0, 'BLOCCATO' => 0];
            $unique = [];
            foreach ($schedule as $item) {
                $class = (string)($item['classification'] ?? '');
                if (isset($counts[$class])) $counts[$class]++;
                if (se_video_id($item) !== '') $unique[se_video_id($item)] = true;
            }
            $meta = [
                'mode' => 'SHADOW', 'official' => false, 'generatedAt' => se_iso($now),
                'horizonHours' => 72, 'items' => count($schedule), 'uniqueVideos' => count($unique),
                'premieres' => $counts['PREMIERE'], 'novita' => $counts['NOVITA'],
                'archive' => $counts['ARCHIVIO'], 'replicas' => $counts['REPLICA'],
                'replayIntros' => $counts['REPLICA_INTRO'], 'locked' => $counts['BLOCCATO'],
                'historyItems' => count($history), 'archiveSize' => $built['archiveSize'],
                'archiveCursorStart' => $built['cursorStart'], 'archiveCursorEnd' => $built['cursorEnd'],
                'archiveCycle' => $built['archiveCycle'], 'maxFreshAirings' => 3,
                'freshWindowHours' => 72, 'archiveLookbackYears' => 2,
                'badgeDurationSeconds' => 15, 'sundayReplayStart' => '18:00',
            ];
            $data['botV4Meta'] = $meta;
        } else {
            $schedule = $existing;
            $meta = is_array($data['botV4Meta'] ?? null) ? $data['botV4Meta'] : [];
        }
        $data['botV4'] = array_merge($state, [
            'engineVersion' => 4, 'mode' => 'SHADOW', 'enabled' => true,
            'status' => $schedule ? 'SIMULATING' : 'NO_PROGRAMME',
            'lastTickAt' => se_iso($now), 'lastTrigger' => $trigger,
            'lastRebuilt' => $rebuild, 'signature' => $signature,
            'notice' => 'Simulazione isolata: il Bot V4 non controlla ancora la diretta.',
        ]);
        return ['ok' => !empty($schedule), 'rebuilt' => $rebuild, 'state' => $data['botV4'], 'meta' => $meta];
    }

    function v4_shadow_status(array $data, int $now): array {
        return [
            'ok' => true, 'engineVersion' => 4, 'mode' => 'SHADOW', 'official' => false,
            'state' => is_array($data['botV4'] ?? null) ? $data['botV4'] : [],
            'meta' => is_array($data['botV4Meta'] ?? null) ? $data['botV4Meta'] : [],
            'schedule' => is_array($data['botV4ShadowSchedule'] ?? null) ? $data['botV4ShadowSchedule'] : [],
            'historyCount' => count(is_array($data['botV4History'] ?? null) ? $data['botV4History'] : []),
            'serverNow' => se_iso($now),
            'notice' => 'Il V4 è in simulazione e non modifica il palinsesto ufficiale V3.',
        ];
    }
}
