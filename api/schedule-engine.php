<?php
/**
 * TubeTV daily schedule engine.
 *
 * Pure data functions used by the official Bot V3 engine. The generated `schedule` is the
 * public, replayable programme guide; `publicLiveSchedule.liveQueue` remains
 * the three-item runtime window used by the live player.
 */

if (!function_exists('se_iso')) {
    function se_iso(int $ts): string {
        return gmdate('Y-m-d\TH:i:s', $ts) . '.000Z';
    }

    function se_ts($value): int {
        if (!is_string($value) || trim($value) === '') return 0;
        $ts = strtotime($value);
        return $ts === false ? 0 : $ts;
    }

    function se_timezone(array $data): DateTimeZone {
        $name = trim((string)($data['settings']['timezone'] ?? 'Europe/Zurich'));
        try { return new DateTimeZone($name !== '' ? $name : 'Europe/Zurich'); }
        catch (Throwable $e) { return new DateTimeZone('Europe/Zurich'); }
    }

    function se_day_context(array $data, int $now): array {
        $tz = se_timezone($data);
        $local = (new DateTimeImmutable('@' . $now))->setTimezone($tz);
        $start = $local->setTime(0, 0, 0);
        $end = $start->modify('+1 day');
        return [
            'date' => $start->format('Y-m-d'),
            'timezone' => $tz->getName(),
            'start' => $start->getTimestamp(),
            'end' => $end->getTimestamp(),
        ];
    }

    function se_video_id(array $video): string {
        return trim((string)($video['videoId'] ?? $video['id'] ?? ''));
    }

    function se_channel_key(array $channel): string {
        return trim((string)($channel['id'] ?? $channel['channelId'] ?? $channel['handle'] ?? $channel['url'] ?? $channel['name'] ?? $channel['title'] ?? ''));
    }

    function se_channel_refs(array $channel): array {
        $out = [];
        foreach (['id', 'channelId', 'handle', 'url', 'name', 'title'] as $key) {
            $value = trim((string)($channel[$key] ?? ''));
            if ($value !== '') $out[$value] = true;
        }
        return array_keys($out);
    }

    function se_video_channel_refs(array $video): array {
        $out = [];
        foreach (['sourceChannelId', 'channelId', 'channelHandle', 'handle', 'channel', 'channelTitle', 'sourceChannelTitle'] as $key) {
            $value = trim((string)($video[$key] ?? ''));
            if ($value !== '') $out[$value] = true;
        }
        return array_keys($out);
    }

    function se_duration(array $video): int {
        $seconds = (int)($video['durationSeconds'] ?? $video['durationSecs'] ?? 0);
        if ($seconds <= 0 && !empty($video['durationMin'])) $seconds = (int)$video['durationMin'] * 60;
        return $seconds > 0 ? $seconds : 1800;
    }

    function se_collect_videos(array $data): array {
        $all = is_array($data['videos'] ?? null) ? $data['videos'] : [];
        foreach (($data['videoLibrary'] ?? []) as $items) {
            if (!is_array($items)) continue;
            foreach ($items as $video) if (is_array($video)) $all[] = $video;
        }
        $unique = [];
        foreach ($all as $video) {
            if (!is_array($video)) continue;
            $id = se_video_id($video);
            if ($id === '') continue;
            if (!isset($unique[$id])) $unique[$id] = $video;
            else $unique[$id] = array_merge($unique[$id], $video);
        }
        return array_values($unique);
    }

    function se_bot_profile(array $data): array {
        $raw = is_array($data['botV3Settings'] ?? null) ? $data['botV3Settings'] : [];
        $knownCategories = ['Generale', 'Intrattenimento', 'Divulgazione', 'Documentari', 'Tecnologia', 'Gaming', 'Cucina', 'Viaggi', 'Sport', 'Cinema', 'Musica', 'News', 'Arte', 'Podcast', 'Fai da te'];
        $allowed = array_values(array_unique(array_intersect($knownCategories, array_map('strval', is_array($raw['allowedCategories'] ?? null) ? $raw['allowedCategories'] : []))));
        $minMinutes = max(1, min(60, (int)($raw['minDurationMinutes'] ?? 5)));
        $maxMinutes = max(10, min(240, (int)($raw['maxDurationMinutes'] ?? 90)));
        if ($maxMinutes <= $minMinutes) $maxMinutes = min(240, $minMinutes + 5);
        return [
            'freshnessWeight' => max(0, min(200, (int)($raw['freshnessWeight'] ?? 100))),
            'channelVarietyWeight' => max(0, min(200, (int)($raw['channelVarietyWeight'] ?? 100))),
            'categoryVarietyWeight' => max(0, min(200, (int)($raw['categoryVarietyWeight'] ?? 100))),
            'repeatCooldownDays' => max(0, min(90, (int)($raw['repeatCooldownDays'] ?? 30))),
            'minDurationMinutes' => $minMinutes,
            'maxDurationMinutes' => $maxMinutes,
            // La Live Web e sempre italiana: questo requisito non puo essere disattivato.
            'requireVerifiedItalianAudio' => true,
            'allowEmergencyReplicas' => ($raw['allowEmergencyReplicas'] ?? true) !== false,
            'allowedCategories' => $allowed,
        ];
    }

    function se_catalog_category(array $video): string {
        $channelText = strtolower(implode(' ', array_filter([
            (string)($video['channelHandle'] ?? ''), (string)($video['handle'] ?? ''),
            (string)($video['channel'] ?? ''), (string)($video['channelTitle'] ?? ''),
            (string)($video['sourceChannelTitle'] ?? ''),
        ])));
        $channelRules = [
            'Gaming' => ['cicciogamer89'],
            'Cucina' => ['vanzaicucinando', 'chefandrealarossa'],
            'Documentari' => ['ruhicenet', 'progetto happiness', 'progettohappiness'],
            'Viaggi' => ['safariumano', 'nicolo balini', 'nicolò balini', 'nicolajiang', 'nicola jiang'],
            'Divulgazione' => ['geopop', 'novalectio', 'nova lectio'],
            'Tecnologia' => ['jakidale', 'moltenimichele', 'michele molteni'],
            'Intrattenimento' => ['xmurry', 'murrypwnz', 'aledellagiusta', 'dieffebros', 'dieffe studios', 'dieffestudios', 'mrbeast', 'valerio.mazzei', 'valerio mazzei', 'leobonni'],
        ];
        foreach ($channelRules as $category => $signals) {
            foreach ($signals as $signal) if (strpos($channelText, $signal) !== false) return $category;
        }

        $aliases = [
            'documentario' => 'Documentari', 'documentary' => 'Documentari', 'documentari' => 'Documentari',
            'science' => 'Divulgazione', 'scienza' => 'Divulgazione', 'divulgazione' => 'Divulgazione',
            'tech' => 'Tecnologia', 'technology' => 'Tecnologia', 'tecnologia' => 'Tecnologia',
            'food' => 'Cucina', 'cucina' => 'Cucina', 'gaming' => 'Gaming', 'game' => 'Gaming',
            'travel' => 'Viaggi', 'viaggi' => 'Viaggi', 'sport' => 'Sport', 'sports' => 'Sport',
            'music' => 'Musica', 'musica' => 'Musica', 'news' => 'News', 'arte' => 'Arte',
            'cinema' => 'Cinema', 'film' => 'Cinema', 'podcast' => 'Podcast',
            'entertainment' => 'Intrattenimento', 'intrattenimento' => 'Intrattenimento',
            'general' => 'Generale', 'generale' => 'Generale', 'fai da te' => 'Fai da te',
        ];
        $stored = strtolower(trim((string)($video['category'] ?? $video['topic'] ?? '')));
        if ($stored !== '' && isset($aliases[$stored])) return $aliases[$stored];

        $text = strtolower(implode(' ', array_filter([(string)($video['title'] ?? ''), $channelText])));
        $rules = [
            'Cucina' => '/\b(cucina|ricett[ae]|chef|ristorante|pizza|pasta|street food)\b/u',
            'Gaming' => '/\b(gaming|gameplay|videogioc[oa]|playstation|xbox|nintendo|minecraft|fortnite)\b/u',
            'Tecnologia' => '/\b(tecnologia|smartphone|iphone|android|computer|gadget|robot|software|intelligenza artificiale)\b/u',
            'Viaggi' => '/\b(viaggi[oa]?|travel|vacanza|camper|hotel|aereo|esplor[oa])\b/u',
            'Sport' => '/\b(calcio|partita|campionato|atleta|tennis|basket|formula 1|motogp|workout)\b/u',
            'Cinema' => '/\b(cinema|trailer|movie|serie tv|netflix)\b/u',
            'Musica' => '/\b(musica|music|concerto|remix|cantante|album|band|live session)\b/u',
            'News' => '/\b(news|attualita|politica|notizia|cronaca)\b/u',
            'Divulgazione' => '/\b(scienza|storia|geopolitica|economia|fisica|chimica|spazio|universo|come funziona)\b/u',
        ];
        foreach ($rules as $category => $pattern) if (preg_match($pattern, $text)) return $category;
        return 'Generale';
    }

    function se_normalize_catalog_categories(array &$data): int {
        $changed = 0;
        $channelsByRef = [];
        $data['channels'] = is_array($data['channels'] ?? null) ? $data['channels'] : [];
        foreach ($data['channels'] as $index => &$channel) {
            if (!is_array($channel)) continue;
            $category = se_catalog_category($channel);
            if (($channel['category'] ?? '') !== $category) {
                $channel['category'] = $category;
                $changed++;
            }
            foreach (se_channel_refs($channel) as $ref) $channelsByRef[$ref] = $channel;
        }
        unset($channel);

        $normalizeVideo = function (array $video) use ($channelsByRef, &$changed): array {
            $context = $video;
            foreach (se_video_channel_refs($video) as $ref) {
                if (isset($channelsByRef[$ref])) {
                    $context = array_merge($video, $channelsByRef[$ref], [
                        'title' => (string)($video['title'] ?? ''),
                        'channel' => (string)($video['channel'] ?? $channelsByRef[$ref]['name'] ?? $channelsByRef[$ref]['title'] ?? ''),
                        'channelHandle' => (string)($video['channelHandle'] ?? $channelsByRef[$ref]['handle'] ?? ''),
                    ]);
                    break;
                }
            }
            $category = se_catalog_category($context);
            if (($video['category'] ?? '') !== $category) {
                $video['category'] = $category;
                $changed++;
            }
            return $video;
        };

        foreach ($data['videos'] as &$video) if (is_array($video)) $video = $normalizeVideo($video);
        unset($video);
        $data['videoLibrary'] = is_array($data['videoLibrary'] ?? null) ? $data['videoLibrary'] : [];
        foreach ($data['videoLibrary'] as &$items) {
            if (!is_array($items)) continue;
            foreach ($items as &$video) if (is_array($video)) $video = $normalizeVideo($video);
            unset($video);
        }
        unset($items);
        return $changed;
    }

    function se_youtube_api_key(): string {
        $keys = ['YOUTUBE_API_KEY', 'YT_API_KEY'];
        foreach ($keys as $key) {
            $value = getenv($key);
            if (is_string($value) && trim($value) !== '') return trim($value);
        }
        $root = defined('TUBE_ROOT') ? TUBE_ROOT : dirname(__DIR__);
        $path = $root . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'config.php';
        if (is_file($path)) {
            $config = include $path;
            if (is_array($config)) foreach ($keys as $key) {
                if (!empty($config[$key]) && is_string($config[$key])) return trim($config[$key]);
            }
        }
        return '';
    }

    function se_http_json(string $url): ?array {
        $GLOBALS['SE_HTTP_LAST_ERROR'] = '';
        $raw = false;
        $status = 0;
        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT => 18,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT => 'TubeTV-Scheduler/2.0',
            ]);
            $raw = curl_exec($curl);
            $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $curlError = curl_error($curl);
            curl_close($curl);
            if ($raw === false && $curlError !== '') $GLOBALS['SE_HTTP_LAST_ERROR'] = 'network_' . preg_replace('/[^a-z0-9]+/i', '_', strtolower($curlError));
        } else {
            $context = stream_context_create(['http' => [
                'timeout' => 18,
                'ignore_errors' => true,
                'header' => "User-Agent: TubeTV-Scheduler/2.0\r\n",
            ]]);
            $raw = @file_get_contents($url, false, $context);
            if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', (string)$http_response_header[0], $m)) $status = (int)$m[1];

            // PHP portabile su Windows spesso non include OpenSSL/cURL. Usa il
            // client di sistema senza passare da una shell e senza loggare l'URL.
            if ((!is_string($raw) || $raw === '') && function_exists('proc_open')) {
                $binary = PHP_OS_FAMILY === 'Windows' ? 'curl.exe' : 'curl';
                $pipes = [];
                $process = @proc_open([
                    $binary, '-sS', '-L', '--connect-timeout', '8', '--max-time', '18',
                    '-A', 'TubeTV-Scheduler/2.0', '-w', "\n%{http_code}", $url,
                ], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
                if (is_resource($process)) {
                    $response = stream_get_contents($pipes[1]);
                    $error = stream_get_contents($pipes[2]);
                    fclose($pipes[1]);
                    fclose($pipes[2]);
                    $exitCode = proc_close($process);
                    if ($exitCode === 0 && preg_match('/\n(\d{3})$/', (string)$response, $match)) {
                        $status = (int)$match[1];
                        $raw = substr((string)$response, 0, -4);
                    } elseif ($error !== '') {
                        $GLOBALS['SE_HTTP_LAST_ERROR'] = 'network_' . preg_replace('/[^a-z0-9]+/i', '_', strtolower(trim($error)));
                    }
                }
            }
        }
        if (!is_string($raw) || $raw === '') {
            if ($GLOBALS['SE_HTTP_LAST_ERROR'] === '') $GLOBALS['SE_HTTP_LAST_ERROR'] = $status > 0 ? 'http_' . $status : 'empty_response';
            return null;
        }
        $decoded = json_decode($raw, true);
        if ($status < 200 || $status >= 300) {
            $reason = (string)($decoded['error']['errors'][0]['reason'] ?? $decoded['error']['status'] ?? ('http_' . $status));
            $GLOBALS['SE_HTTP_LAST_ERROR'] = preg_replace('/[^a-z0-9_-]+/i', '_', strtolower($reason));
            return null;
        }
        if (!is_array($decoded)) {
            $GLOBALS['SE_HTTP_LAST_ERROR'] = 'invalid_json';
            return null;
        }
        return $decoded;
    }

    function se_http_last_error(): string {
        return trim((string)($GLOBALS['SE_HTTP_LAST_ERROR'] ?? '')) ?: 'unreachable';
    }

    function se_set_video_availability(array &$data, string $videoId, bool $available, string $reason, int $now): bool {
        if ($videoId === '') return false;
        $data['videoAvailability'] = is_array($data['videoAvailability'] ?? null) ? $data['videoAvailability'] : [];
        $previous = is_array($data['videoAvailability'][$videoId] ?? null) ? $data['videoAvailability'][$videoId] : [];
        $next = [
            'status' => $available ? 'available' : 'unavailable',
            'reason' => $available ? 'ok' : ($reason ?: 'youtube_unavailable'),
            'checkedAt' => se_iso($now),
            'retryAfter' => se_iso($now + ($available ? 21600 : 1800)),
        ];
        $changed = ($previous['status'] ?? '') !== $next['status'] || ($previous['reason'] ?? '') !== $next['reason'];
        $data['videoAvailability'][$videoId] = $next;

        $apply = static function (array $item) use ($videoId, $available, $reason, $now, &$changed): array {
            if (se_video_id($item) !== $videoId) return $item;
            $before = $item;
            $item['availabilityStatus'] = $available ? 'available' : 'unavailable';
            $item['availabilityCheckedAt'] = se_iso($now);
            if ($available) {
                unset($item['unavailable'], $item['isUnavailable'], $item['hiddenByAvailabilityBot'], $item['unavailableReason'], $item['unavailableUntil'], $item['broken'], $item['isBroken'], $item['regionBlocked']);
            } else {
                $item['unavailable'] = true;
                $item['isUnavailable'] = true;
                $item['hiddenByAvailabilityBot'] = true;
                $item['unavailableReason'] = $reason ?: 'youtube_unavailable';
                $item['unavailableUntil'] = se_iso($now + 1800);
            }
            if ($item != $before) $changed = true;
            return $item;
        };

        foreach (['videos', 'topContent', 'events', 'manualEvents'] as $key) {
            if (!is_array($data[$key] ?? null)) continue;
            foreach ($data[$key] as &$item) if (is_array($item)) $item = $apply($item);
            unset($item);
        }
        foreach (['videoLibrary', 'seriesEpisodes'] as $groupKey) {
            if (!is_array($data[$groupKey] ?? null)) continue;
            if (array_is_list($data[$groupKey])) {
                foreach ($data[$groupKey] as &$item) if (is_array($item)) $item = $apply($item);
                unset($item);
                continue;
            }
            foreach ($data[$groupKey] as &$items) {
                if (!is_array($items)) continue;
                foreach ($items as &$item) if (is_array($item)) $item = $apply($item);
                unset($item);
            }
            unset($items);
        }
        return $changed;
    }

    function se_set_video_localized_title(array &$data, string $videoId, string $originalTitle, string $italianTitle): bool {
        $originalTitle = trim($originalTitle);
        $italianTitle = trim($italianTitle);
        if ($videoId === '' || $originalTitle === '' || $italianTitle === '' || strcasecmp($originalTitle, $italianTitle) === 0) return false;
        $changed = false;
        $apply = static function (array $item) use ($videoId, $originalTitle, $italianTitle, &$changed): array {
            if (se_video_id($item) !== $videoId) return $item;
            $before = $item;
            $item['originalTitle'] = $originalTitle;
            $item['italianTitle'] = $italianTitle;
            $item['title'] = $italianTitle;
            $item['titleTranslationStatus'] = 'youtube_official_it';
            if ($item != $before) $changed = true;
            return $item;
        };
        foreach (['videos', 'topContent', 'events', 'manualEvents', 'schedule', 'palinsesto', 'internalSlotSchedule', 'futureSchedule'] as $key) {
            if (!is_array($data[$key] ?? null)) continue;
            foreach ($data[$key] as &$item) if (is_array($item)) $item = $apply($item);
            unset($item);
        }
        foreach (['videoLibrary', 'seriesEpisodes'] as $groupKey) {
            if (!is_array($data[$groupKey] ?? null)) continue;
            if (array_is_list($data[$groupKey])) {
                foreach ($data[$groupKey] as &$item) if (is_array($item)) $item = $apply($item);
                unset($item); continue;
            }
            foreach ($data[$groupKey] as &$items) {
                if (!is_array($items)) continue;
                foreach ($items as &$item) if (is_array($item)) $item = $apply($item);
                unset($item);
            }
            unset($items);
        }
        return $changed;
    }

    function se_refresh_video_availability(array &$data, string $apiKey, int $now, bool $force = false): array {
        $data['botV3'] = is_array($data['botV3'] ?? null) ? $data['botV3'] : [];
        $last = se_ts((string)($data['botV3']['lastAvailabilitySweepAt'] ?? ''));
        if (!$force && $last > 0 && ($now - $last) < 1800) return ['checked' => 0, 'hidden' => 0, 'restored' => 0, 'skipped' => 'interval'];

        $ids = [];
        foreach (se_collect_videos($data) as $video) {
            $id = se_video_id($video);
            if ($id !== '' && preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) $ids[$id] = true;
        }
        foreach (['topContent', 'events', 'manualEvents'] as $key) foreach (is_array($data[$key] ?? null) ? $data[$key] : [] as $video) {
            if (!is_array($video)) continue; $id = se_video_id($video);
            if ($id !== '' && preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) $ids[$id] = true;
        }
        foreach (is_array($data['seriesEpisodes'] ?? null) ? $data['seriesEpisodes'] : [] as $episodes) foreach (is_array($episodes) ? $episodes : [] as $video) {
            if (!is_array($video)) continue; $id = se_video_id($video);
            if ($id !== '' && preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) $ids[$id] = true;
        }
        $ids = array_keys($ids);
        if (!$ids) return ['checked' => 0, 'hidden' => 0, 'restored' => 0, 'skipped' => 'empty'];
        sort($ids, SORT_STRING);
        $cursor = max(0, (int)($data['botV3']['availabilityCursor'] ?? 0)) % count($ids);
        $batch = [];
        for ($i = 0; $i < min(50, count($ids)); $i++) $batch[] = $ids[($cursor + $i) % count($ids)];
        $response = se_http_json('https://www.googleapis.com/youtube/v3/videos?' . http_build_query([
            'part' => 'snippet,contentDetails,status', 'id' => implode(',', $batch), 'hl' => 'it', 'key' => $apiKey,
        ]));
        if ($response === null) return ['checked' => 0, 'hidden' => 0, 'restored' => 0, 'error' => 'youtube_api_' . se_http_last_error()];
        $returned = [];
        foreach (is_array($response['items'] ?? null) ? $response['items'] : [] as $detail) if (is_array($detail) && !empty($detail['id'])) $returned[(string)$detail['id']] = $detail;
        $country = strtoupper(trim((string)($data['settings']['playbackCountry'] ?? $data['settings']['countryCode'] ?? 'CH'))) ?: 'CH';
        $hidden = 0; $restored = 0;
        foreach ($batch as $id) {
            $before = (string)($data['videoAvailability'][$id]['status'] ?? '');
            $detail = $returned[$id] ?? null;
            $available = false; $reason = 'deleted_private_or_missing';
            if (is_array($detail)) {
                $status = is_array($detail['status'] ?? null) ? $detail['status'] : [];
                $content = is_array($detail['contentDetails'] ?? null) ? $detail['contentDetails'] : [];
                $snippet = is_array($detail['snippet'] ?? null) ? $detail['snippet'] : [];
                $privacy = strtolower((string)($status['privacyStatus'] ?? ''));
                $embeddable = ($status['embeddable'] ?? true) !== false;
                $regionOk = se_region_allowed(['regionRestriction' => $content['regionRestriction'] ?? []], $country);
                $broadcast = strtolower((string)($snippet['liveBroadcastContent'] ?? 'none'));
                se_set_video_localized_title($data, $id, (string)($snippet['title'] ?? ''), (string)($snippet['localized']['title'] ?? ''));
                $available = $privacy === 'public' && $embeddable && $regionOk && ($broadcast === '' || $broadcast === 'none');
                $reason = $privacy !== 'public' ? 'private_or_deleted' : (!$embeddable ? 'embedding_disabled' : (!$regionOk ? 'blocked_in_' . strtolower($country) : 'live_or_upcoming'));
            }
            se_set_video_availability($data, $id, $available, $available ? 'ok' : $reason, $now);
            if ($available && $before === 'unavailable') $restored++;
            if (!$available && $before !== 'unavailable') $hidden++;
        }
        $data['botV3']['lastAvailabilitySweepAt'] = se_iso($now);
        $data['botV3']['availabilityCursor'] = ($cursor + count($batch)) % count($ids);
        $data['botV3']['availabilityChecked'] = count($batch);
        $data['botV3']['availabilityHiddenLastRun'] = $hidden;
        $data['botV3']['availabilityRestoredLastRun'] = $restored;
        $data['botV3']['availabilityUnavailableTotal'] = count(array_filter($data['videoAvailability'], static fn($entry) => is_array($entry) && ($entry['status'] ?? '') === 'unavailable'));
        if ($hidden > 0) {
            $blocked = array_keys(array_filter($data['videoAvailability'], static fn($entry) => is_array($entry) && ($entry['status'] ?? '') === 'unavailable'));
            foreach (['schedule', 'palinsesto', 'internalSlotSchedule'] as $key) if (is_array($data[$key] ?? null)) {
                $data[$key] = array_values(array_filter($data[$key], static function ($item) use ($blocked, $now): bool {
                    if (!is_array($item) || !in_array(se_video_id($item), $blocked, true)) return true;
                    return se_ts((string)($item['endDateTime'] ?? '')) <= $now;
                }));
            }
        }
        return ['checked' => count($batch), 'hidden' => $hidden, 'restored' => $restored, 'unavailableTotal' => $data['botV3']['availabilityUnavailableTotal']];
    }

    function se_audio_language_codes(array $video): array {
        $codes = [];
        foreach (['availableLanguages', 'audioTracks', 'languageVariants', 'audioVersions'] as $key) {
            foreach (is_array($video[$key] ?? null) ? $video[$key] : [] as $track) {
                if (!is_array($track)) continue;
                $code = strtolower(trim((string)($track['code'] ?? $track['languageCode'] ?? $track['language'] ?? $track['id'] ?? '')));
                if ($code !== '') $codes[preg_replace('/[._].*$/', '', $code)] = true;
            }
        }
        return array_keys($codes);
    }

    function se_region_allowed(array $video, string $country = 'CH'): bool {
        $country = strtoupper(trim($country)) ?: 'CH';
        $restriction = is_array($video['regionRestriction'] ?? null) ? $video['regionRestriction'] : [];
        if (array_key_exists('allowed', $restriction)) {
            $allowed = array_map('strtoupper', is_array($restriction['allowed']) ? $restriction['allowed'] : []);
            return in_array($country, $allowed, true);
        }
        $blocked = array_map('strtoupper', is_array($restriction['blocked'] ?? null) ? $restriction['blocked'] : []);
        return !in_array($country, $blocked, true);
    }

    function se_youtube_watch_probe(string $videoId, string $country = 'CH'): array {
        static $cache = null, $dirty = false, $networkChecks = 0, $registered = false;
        $root = defined('TUBE_ROOT') ? TUBE_ROOT : dirname(__DIR__);
        $cachePath = $root . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'youtube-playability-cache.json';
        if ($cache === null) {
            $decoded = is_file($cachePath) ? json_decode((string)@file_get_contents($cachePath), true) : [];
            $cache = is_array($decoded) ? $decoded : [];
        }
        if (!$registered) {
            $registered = true;
            register_shutdown_function(function () use (&$cache, &$dirty, $cachePath): void {
                if (!$dirty || !is_array($cache)) return;
                @file_put_contents($cachePath . '.tmp', json_encode($cache, JSON_UNESCAPED_SLASHES), LOCK_EX);
                @rename($cachePath . '.tmp', $cachePath); @chmod($cachePath, 0600);
            });
        }
        $key = 'v5:' . strtoupper($country) . ':' . $videoId;
        $stored = is_array($cache[$key] ?? null) ? $cache[$key] : [];
        $ttl = !empty($stored['checked']) ? 604800 : 21600;
        if ((int)($stored['checkedAt'] ?? 0) > time() - $ttl) return $stored;
        if ($networkChecks >= 8) return ['checked' => false, 'reason' => 'probe_deferred'];
        $networkChecks++;
        $body = '';
        if (function_exists('curl_init')) {
            $curl = curl_init('https://www.youtube.com/watch?v=' . rawurlencode($videoId) . '&hl=it&gl=' . rawurlencode(strtoupper($country)));
            curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 20, CURLOPT_ENCODING => '', CURLOPT_USERAGENT => 'Mozilla/5.0 TubeTV-Scheduler/2.1', CURLOPT_HTTPHEADER => ['Accept-Language: it-IT,it;q=0.9']]);
            $raw = curl_exec($curl); $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl);
            if (is_string($raw) && $status >= 200 && $status < 300) $body = $raw;
        } elseif ((bool)ini_get('allow_url_fopen')) {
            $context = stream_context_create(['http' => ['timeout' => 20, 'follow_location' => 1, 'ignore_errors' => true, 'header' => "User-Agent: Mozilla/5.0 TubeTV-Scheduler/2.1\r\nAccept-Language: it-IT,it;q=0.9\r\nAccept-Encoding: identity\r\n"]]);
            $raw = @file_get_contents('https://www.youtube.com/watch?v=' . rawurlencode($videoId) . '&hl=it&gl=' . rawurlencode(strtoupper($country)), false, $context);
            if (is_string($raw)) $body = $raw;
        }
        if ($body === '' && function_exists('proc_open') && preg_match('/^[A-Za-z0-9_-]{6,20}$/', $videoId)) {
            $pipes = [];
            $process = @proc_open(['curl', '-L', '--compressed', '-sS', '--max-time', '20', '-A', 'Mozilla/5.0 TubeTV-Scheduler/2.1', '-H', 'Accept-Language: it-IT,it;q=0.9', 'https://www.youtube.com/watch?v=' . $videoId . '&hl=it&gl=' . strtoupper($country)], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
            if (is_resource($process)) {
                @fclose($pipes[0]); $raw = stream_get_contents($pipes[1]); @fclose($pipes[1]); @fclose($pipes[2]); $exit = proc_close($process);
                if ($exit === 0 && is_string($raw)) $body = $raw;
            }
        }
        $languages = [];
        $scanBody = str_replace(['\\u0026', '\\"'], ['&', '"'], html_entity_decode($body, ENT_QUOTES | ENT_HTML5));
        if ($scanBody !== '' && preg_match_all('/"audioTrack"\s*:\s*\{.{0,1800}?"id"\s*:\s*"([^"]+)"/s', $scanBody, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $id = strtolower(trim((string)($match[1] ?? '')));
                $code = preg_replace('/[._].*$/', '', $id);
                if ($code === '' || strlen($code) > 12) continue;
                $languages[$code] = ['code' => $code, 'label' => strtoupper($code), 'audioTrackId' => $id];
            }
        }
        $embedOk = $scanBody !== ''
            && preg_match('/"playabilityStatus"\s*:\s*\{\s*"status"\s*:\s*"OK"/', $scanBody) === 1
            && preg_match('/"playableInEmbed"\s*:\s*false/', $scanBody) !== 1;
        $result = ['checked' => $body !== '', 'checkedAt' => time(), 'playableInCH' => $embedOk, 'audioLanguages' => array_values($languages), 'italianAudio' => isset($languages['it']), 'reason' => $body === '' ? 'probe_unreachable' : ($embedOk ? 'ok' : 'youtube_not_playable')];
        $cache[$key] = $result; $dirty = true;
        return $result;
    }

    function se_set_video_audio_verification(array &$data, string $videoId, array $result, int $now): bool {
        if ($videoId === '') return false;
        $status = (string)($result['status'] ?? 'deferred');
        $verified = $status === 'verified';
        $languages = is_array($result['audioLanguages'] ?? null) ? $result['audioLanguages'] : [];
        $trackId = trim((string)($result['italianAudioTrackId'] ?? ''));
        $mode = (string)($result['mode'] ?? ($trackId !== '' ? 'multi_track' : 'default'));
        $changed = false;
        $apply = static function (array $item) use ($videoId, $status, $verified, $languages, $trackId, $mode, $result, $now, &$changed): array {
            if (se_video_id($item) !== $videoId) return $item;
            $before = $item;
            $item['italianAudioStatus'] = $status;
            $item['italianAudioCheckedAt'] = se_iso($now);
            $item['italianAudioReason'] = (string)($result['reason'] ?? $status);
            if ($languages) $item['availableLanguages'] = $languages;
            if ($verified) {
                $item['italianVerified'] = true;
                $item['italianAudioMode'] = $mode;
                $item['italianPlaybackGuaranteed'] = $mode === 'default' || !empty($item['italianVideoId']);
                $item['languageStatus'] = $item['italianPlaybackGuaranteed'] ? 'it_verified' : 'it_track_available_not_guaranteed';
                if ($trackId !== '') $item['italianAudioTrackId'] = $trackId;
                elseif ($mode === 'default') unset($item['italianAudioTrackId']);
                unset($item['audioRejected'], $item['audioVerificationDeferred']);
            } elseif ($status === 'rejected') {
                $item['italianVerified'] = false;
                $item['languageStatus'] = 'no_it_audio';
                $item['audioRejected'] = true;
                $item['availableLanguages'] = array_values(array_filter(is_array($item['availableLanguages'] ?? null) ? $item['availableLanguages'] : [], static function ($language): bool {
                    if (!is_array($language)) return true;
                    $code = strtolower((string)($language['code'] ?? $language['languageCode'] ?? $language['language'] ?? ''));
                    return $code !== 'it' && !str_starts_with($code, 'it-');
                }));
                unset($item['italianAudioTrackId'], $item['italianAudioMode'], $item['italianPlaybackGuaranteed'], $item['audioVerificationDeferred']);
            } else {
                $item['audioVerificationDeferred'] = true;
            }
            if ($item != $before) $changed = true;
            return $item;
        };
        foreach (['videos', 'topContent', 'events', 'manualEvents', 'schedule', 'palinsesto', 'internalSlotSchedule', 'futureSchedule'] as $key) {
            if (!is_array($data[$key] ?? null)) continue;
            foreach ($data[$key] as &$item) if (is_array($item)) $item = $apply($item);
            unset($item);
        }
        foreach (['videoLibrary', 'seriesEpisodes'] as $groupKey) {
            if (!is_array($data[$groupKey] ?? null)) continue;
            if (array_is_list($data[$groupKey])) {
                foreach ($data[$groupKey] as &$item) if (is_array($item)) $item = $apply($item);
                unset($item); continue;
            }
            foreach ($data[$groupKey] as &$items) {
                if (!is_array($items)) continue;
                foreach ($items as &$item) if (is_array($item)) $item = $apply($item);
                unset($item);
            }
            unset($items);
        }
        return $changed;
    }

    function se_refresh_italian_audio_verification(array &$data, int $now): array {
        $data['botV3'] = is_array($data['botV3'] ?? null) ? $data['botV3'] : [];
        $candidates = [];
        $add = static function ($video, int $priority) use (&$candidates, $now): void {
            if (!is_array($video)) return;
            $id = se_video_id($video);
            if (!preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) return;
            $checked = se_ts((string)($video['italianAudioCheckedAt'] ?? ''));
            $status = (string)($video['italianAudioStatus'] ?? '');
            if ($status === 'verified' || !empty($video['italianVerified']) || !empty($video['italianVideoId']) || !empty($video['forceItalian'])) return;
            if ($status === 'rejected' && $checked > $now - 86400) return;
            $published = se_ts((string)($video['publishedAt'] ?? $video['createdAt'] ?? ''));
            $candidates[$id] = ['video' => $video, 'priority' => min($priority, (int)($candidates[$id]['priority'] ?? 99)), 'published' => $published];
        };
        foreach (is_array($data['schedule'] ?? null) ? $data['schedule'] : [] as $video) {
            if (is_array($video) && se_ts((string)($video['endDateTime'] ?? '')) > $now) $add($video, -1);
        }
        foreach (is_array($data['futureSchedule'] ?? null) ? $data['futureSchedule'] : [] as $video) $add($video, 0);
        foreach (se_collect_videos($data) as $video) $add($video, 1);
        uasort($candidates, static fn($a, $b): int => $a['priority'] === $b['priority'] ? $b['published'] <=> $a['published'] : $a['priority'] <=> $b['priority']);

        $checked = 0; $processed = 0; $verified = 0; $rejected = 0; $deferred = 0; $changed = false;
        $country = strtoupper(trim((string)($data['settings']['playbackCountry'] ?? $data['settings']['countryCode'] ?? 'CH'))) ?: 'CH';
        foreach ($candidates as $id => $candidate) {
            if ($checked >= 8 || $processed >= 24) break;
            $processed++;
            $video = $candidate['video'];
            $default = strtolower(trim((string)($video['defaultAudioLanguage'] ?? $video['audioLanguage'] ?? $video['language'] ?? '')));
            $knownItalian = null;
            foreach (is_array($video['availableLanguages'] ?? null) ? $video['availableLanguages'] : [] as $language) {
                if (!is_array($language)) continue;
                $code = strtolower((string)($language['code'] ?? $language['languageCode'] ?? $language['language'] ?? ''));
                if (($code === 'it' || str_starts_with($code, 'it-')) && !empty($language['audioTrackId'])) { $knownItalian = $language; break; }
            }
            if ($default === 'it' || str_starts_with($default, 'it-')) {
                $result = ['status' => 'verified', 'mode' => 'default', 'reason' => 'youtube_default_audio_it', 'audioLanguages' => [['code' => 'it', 'label' => 'Italiano']]];
            } elseif ($knownItalian) {
                $result = ['status' => 'verified', 'mode' => 'multi_track', 'reason' => 'italian_audio_track_verified', 'audioLanguages' => $video['availableLanguages'], 'italianAudioTrackId' => (string)$knownItalian['audioTrackId']];
            } else {
                $checked++;
                $probe = se_youtube_watch_probe((string)$id, $country);
                if (empty($probe['checked'])) {
                    $result = ['status' => 'deferred', 'reason' => (string)($probe['reason'] ?? 'probe_deferred')];
                } else {
                    $italian = null;
                    foreach (is_array($probe['audioLanguages'] ?? null) ? $probe['audioLanguages'] : [] as $language) {
                        if (!is_array($language)) continue;
                        $code = strtolower((string)($language['code'] ?? ''));
                        if ($code === 'it' || str_starts_with($code, 'it-')) { $italian = $language; break; }
                    }
                    $result = $italian
                        ? ['status' => 'verified', 'mode' => 'multi_track', 'reason' => 'italian_audio_track_verified', 'audioLanguages' => $probe['audioLanguages'], 'italianAudioTrackId' => (string)($italian['audioTrackId'] ?? '')]
                        : ['status' => 'rejected', 'reason' => 'italian_audio_not_found', 'audioLanguages' => $probe['audioLanguages'] ?? []];
                }
            }
            if (($result['status'] ?? '') === 'verified') $verified++;
            elseif (($result['status'] ?? '') === 'rejected') $rejected++;
            else $deferred++;
            $changed = se_set_video_audio_verification($data, (string)$id, $result, $now) || $changed;
        }
        if ($rejected > 0) {
            foreach (['schedule', 'palinsesto', 'internalSlotSchedule', 'futureSchedule'] as $key) if (is_array($data[$key] ?? null)) {
                $data[$key] = array_values(array_filter($data[$key], static function ($item) use ($now): bool {
                    if (!is_array($item) || empty($item['audioRejected'])) return true;
                    return se_ts((string)($item['endDateTime'] ?? '')) <= $now;
                }));
            }
        }
        $data['botV3']['lastItalianAudioSweepAt'] = se_iso($now);
        $data['botV3']['italianAudioCheckedLastRun'] = $checked;
        $data['botV3']['italianAudioVerifiedLastRun'] = $verified;
        $data['botV3']['italianAudioRejectedLastRun'] = $rejected;
        $data['botV3']['italianAudioDeferredLastRun'] = $deferred;
        return compact('changed', 'checked', 'verified', 'rejected', 'deferred');
    }

    function se_channel_handle(array $channel): string {
        $handle = trim((string)($channel['handle'] ?? ''));
        if ($handle !== '') return ltrim($handle, '@');
        $url = (string)($channel['url'] ?? '');
        if (preg_match('~youtube\.com/@([^/?&]+)~i', $url, $m)) return ltrim($m[1], '@');
        return '';
    }

    function se_parse_iso_duration(string $iso): int {
        if ($iso === '') return 0;
        try {
            $interval = new DateInterval($iso);
            return $interval->d * 86400 + $interval->h * 3600 + $interval->i * 60 + $interval->s;
        } catch (Throwable $e) { return 0; }
    }

    function se_existing_video_ids_for_channel(array $data, array $channel, int $limit = 10): array {
        $ids = [];
        foreach (se_collect_videos($data) as $video) {
            if (!se_video_matches_channel($video, $channel)) continue;
            $id = se_video_id($video);
            if ($id !== '') $ids[$id] = true;
            if (count($ids) >= $limit) break;
        }
        return array_keys($ids);
    }

    function se_resolve_youtube_channel_id(array $data, array $channel, string $apiKey): string {
        $known = trim((string)($channel['youtubeChannelId'] ?? ''));
        if ($known !== '') return $known;
        $legacy = trim((string)($channel['channelId'] ?? ''));
        if (preg_match('/^UC[A-Za-z0-9_-]{20,}$/', $legacy)) return $legacy;

        // Existing catalogue entries are the most reliable bootstrap: videos.list
        // returns the canonical UC... id even when a channel changed its handle.
        $sampleVideoIds = se_existing_video_ids_for_channel($data, $channel);
        if (!$sampleVideoIds) return '';
        $lookup = se_http_json('https://www.googleapis.com/youtube/v3/videos?' . http_build_query([
            'part' => 'snippet', 'id' => implode(',', $sampleVideoIds), 'key' => $apiKey,
        ]));
        return trim((string)($lookup['items'][0]['snippet']['channelId'] ?? ''));
    }

    function se_sync_youtube_channel(array &$data, array &$channel, string $apiKey, int $now): array {
        $handle = se_channel_handle($channel);
        $youtubeId = se_resolve_youtube_channel_id($data, $channel, $apiKey);
        $uploads = trim((string)($channel['uploadsPlaylistId'] ?? ''));
        if ($uploads === '') {
            $params = ['part' => 'contentDetails,snippet', 'key' => $apiKey];
            if ($youtubeId !== '') $params['id'] = $youtubeId;
            elseif ($handle !== '') $params['forHandle'] = '@' . $handle;
            else return ['imported' => 0, 'error' => 'channel_reference_missing'];

            $info = se_http_json('https://www.googleapis.com/youtube/v3/channels?' . http_build_query($params));
            if ($info === null) return ['imported' => 0, 'error' => 'youtube_api_' . se_http_last_error()];
            $item = $info['items'][0] ?? null;
            if (!is_array($item)) return ['imported' => 0, 'error' => 'channel_not_found'];
            $youtubeId = (string)($item['id'] ?? $youtubeId);
            $uploads = (string)($item['contentDetails']['relatedPlaylists']['uploads'] ?? '');
            if ($uploads === '') return ['imported' => 0, 'error' => 'uploads_playlist_missing'];
            $channel['youtubeChannelId'] = $youtubeId;
            $channel['uploadsPlaylistId'] = $uploads;
            if ($handle === '' && !empty($item['snippet']['customUrl'])) $channel['handle'] = ltrim((string)$item['snippet']['customUrl'], '@');
            if (empty($channel['name']) && !empty($item['snippet']['title'])) $channel['name'] = (string)$item['snippet']['title'];
        }

        $playlist = se_http_json('https://www.googleapis.com/youtube/v3/playlistItems?' . http_build_query([
            'part' => 'snippet,contentDetails', 'playlistId' => $uploads,
            'maxResults' => 50, 'key' => $apiKey,
        ]));
        if ($playlist === null) return ['imported' => 0, 'error' => 'youtube_playlist_' . se_http_last_error()];
        $playlistItems = is_array($playlist['items'] ?? null) ? $playlist['items'] : [];
        $ids = [];
        $byId = [];
        foreach ($playlistItems as $upload) {
            if (!is_array($upload)) continue;
            $id = trim((string)($upload['contentDetails']['videoId'] ?? $upload['snippet']['resourceId']['videoId'] ?? ''));
            if ($id === '') continue;
            $ids[] = $id;
            $byId[$id] = $upload;
        }
        if (!$ids) return ['imported' => 0, 'error' => 'no_uploads'];
        $details = se_http_json('https://www.googleapis.com/youtube/v3/videos?' . http_build_query([
            'part' => 'snippet,contentDetails,status', 'id' => implode(',', array_slice($ids, 0, 50)), 'hl' => 'it', 'key' => $apiKey,
        ]));
        if ($details === null) return ['imported' => 0, 'error' => 'youtube_videos_' . se_http_last_error()];
        $returnedIds = [];
        foreach (is_array($details['items'] ?? null) ? $details['items'] : [] as $detail) {
            if (is_array($detail) && trim((string)($detail['id'] ?? '')) !== '') $returnedIds[trim((string)$detail['id'])] = true;
        }
        foreach (array_slice($ids, 0, 50) as $requestedId) {
            if (!isset($returnedIds[$requestedId])) se_set_video_availability($data, $requestedId, false, 'deleted_private_or_missing', $now);
        }
        $existing = [];
        foreach (se_collect_videos($data) as $video) $existing[se_video_id($video)] = true;
        $internalChannelId = se_channel_key($channel);
        $libraryKey = se_channel_handle($channel) ?: $youtubeId;
        $data['videoLibrary'] = is_array($data['videoLibrary'] ?? null) ? $data['videoLibrary'] : [];
        $data['videoLibrary'][$libraryKey] = is_array($data['videoLibrary'][$libraryKey] ?? null) ? $data['videoLibrary'][$libraryKey] : [];
        $imported = 0;
        $updated = 0;
        foreach (is_array($details['items'] ?? null) ? $details['items'] : [] as $detail) {
            if (!is_array($detail)) continue;
            $id = trim((string)($detail['id'] ?? ''));
            if ($id === '') continue;
            $snippet = is_array($detail['snippet'] ?? null) ? $detail['snippet'] : [];
            $status = is_array($detail['status'] ?? null) ? $detail['status'] : [];
            $duration = se_parse_iso_duration((string)($detail['contentDetails']['duration'] ?? ''));
            $contentDetails = is_array($detail['contentDetails'] ?? null) ? $detail['contentDetails'] : [];
            $originalTitle = trim((string)($byId[$id]['snippet']['title'] ?? $snippet['title'] ?? 'Video'));
            $italianTitle = trim((string)($snippet['localized']['title'] ?? ''));
            $hasOfficialItalianTitle = $italianTitle !== '' && strcasecmp($italianTitle, $originalTitle) !== 0;
            $video = [
                'id' => $id, 'videoId' => $id,
                'url' => 'https://www.youtube.com/watch?v=' . $id,
                'title' => $hasOfficialItalianTitle ? $italianTitle : $originalTitle,
                'originalTitle' => $originalTitle,
                'italianTitle' => $hasOfficialItalianTitle ? $italianTitle : '',
                'titleTranslationStatus' => $hasOfficialItalianTitle ? 'youtube_official_it' : 'original',
                'description' => (string)($snippet['description'] ?? ''),
                'thumbnail' => (string)($snippet['thumbnails']['high']['url'] ?? $snippet['thumbnails']['medium']['url'] ?? ('https://img.youtube.com/vi/' . $id . '/hqdefault.jpg')),
                'publishedAt' => (string)($snippet['publishedAt'] ?? $byId[$id]['snippet']['publishedAt'] ?? ''),
                'durationSeconds' => $duration,
                'durationSecs' => $duration,
                'sourceChannelId' => $internalChannelId,
                'channelId' => $internalChannelId,
                'youtubeChannelId' => $youtubeId,
                'channel' => (string)($channel['name'] ?? $channel['title'] ?? $snippet['channelTitle'] ?? ''),
                'channelTitle' => (string)($snippet['channelTitle'] ?? $channel['name'] ?? $channel['title'] ?? ''),
                'channelHandle' => $libraryKey,
                'category' => se_catalog_category(array_merge($channel, [
                    'channelHandle' => $libraryKey,
                    'channel' => (string)($channel['name'] ?? $channel['title'] ?? $snippet['channelTitle'] ?? ''),
                    'title' => (string)($snippet['title'] ?? $byId[$id]['snippet']['title'] ?? 'Video'),
                ])),
                'defaultLanguage' => (string)($snippet['defaultLanguage'] ?? ''),
                'defaultAudioLanguage' => (string)($snippet['defaultAudioLanguage'] ?? ''),
                'language' => (string)($snippet['defaultAudioLanguage'] ?? ''),
                'liveBroadcastContent' => (string)($snippet['liveBroadcastContent'] ?? 'none'),
                'privacyStatus' => (string)($status['privacyStatus'] ?? ''),
                'embeddable' => ($status['embeddable'] ?? true) !== false,
                'regionRestriction' => is_array($contentDetails['regionRestriction'] ?? null) ? $contentDetails['regionRestriction'] : [],
                'trustedItalianChannel' => !empty($channel['trustedItalianChannel']),
                'isKids' => !empty($channel['isKids']),
                'isTv' => !empty($channel['isTv']),
                'syncedAt' => se_iso($now),
            ];
            $country = strtoupper(trim((string)($data['settings']['playbackCountry'] ?? $data['settings']['countryCode'] ?? 'CH'))) ?: 'CH';
            $youtubeAvailable = strtolower((string)$video['privacyStatus']) === 'public'
                && !empty($video['embeddable'])
                && se_region_allowed($video, $country)
                && strtolower((string)$video['liveBroadcastContent']) === 'none';
            if (!$youtubeAvailable) {
                se_set_video_availability($data, $id, false, 'youtube_unavailable', $now);
                continue;
            }
            $audioSignals = array_filter([(string)$video['defaultAudioLanguage'], (string)$video['language']], fn($lang) => preg_match('/^it(?:-|$)/i', $lang));
            if (!$audioSignals && se_region_allowed($video, $country) && $video['embeddable']) {
                $probe = se_youtube_watch_probe($id, $country);
                if (!empty($probe['checked'])) {
                    $video['youtubePlayableInCH'] = !empty($probe['playableInCH']);
                    $video['availableLanguages'] = is_array($probe['audioLanguages'] ?? null) ? $probe['audioLanguages'] : [];
                    $video['audioTrackVerifiedAt'] = se_iso((int)($probe['checkedAt'] ?? $now));
                    foreach ($video['availableLanguages'] as $audioLanguage) {
                        if (!is_array($audioLanguage)) continue;
                        $audioCode = strtolower((string)($audioLanguage['code'] ?? ''));
                        if ($audioCode === 'it' || str_starts_with($audioCode, 'it-')) {
                            $video['italianVerified'] = true;
                            $video['italianAudioStatus'] = 'verified';
                            $video['italianAudioMode'] = 'multi_track';
                            $video['italianAudioTrackId'] = (string)($audioLanguage['audioTrackId'] ?? '');
                            $video['italianPlaybackGuaranteed'] = false;
                            $video['languageStatus'] = 'it_track_available_not_guaranteed';
                            break;
                        }
                    }
                }
            }
            if (array_key_exists('youtubePlayableInCH', $video) && !$video['youtubePlayableInCH']) {
                se_set_video_availability($data, $id, false, 'youtube_not_playable', $now);
                continue;
            }
            se_set_video_availability($data, $id, true, 'ok', $now);
            $catalogOnlyMultiAudio = ($video['italianAudioMode'] ?? '') === 'multi_track' && !empty($video['italianAudioTrackId']);
            if (!se_is_playable($video, $channel, $data) && !$catalogOnlyMultiAudio) continue;
            if (isset($existing[$id])) {
                $refresh = $video;
                foreach (['defaultLanguage', 'defaultAudioLanguage', 'language'] as $optionalKey) {
                    if (trim((string)($refresh[$optionalKey] ?? '')) === '') unset($refresh[$optionalKey]);
                }
                foreach (['trustedItalianChannel', 'isKids', 'isTv'] as $curatedKey) {
                    if (empty($refresh[$curatedKey])) unset($refresh[$curatedKey]);
                }
                unset($refresh['syncedAt']);
                $recordChanged = false;
                $mergeRefresh = function (array $stored) use ($refresh, $id, $now, &$recordChanged): array {
                    $merged = array_merge($stored, $refresh, ['id' => ($stored['id'] ?? $id), 'videoId' => $id]);
                    if ($merged != $stored) {
                        $merged['syncedAt'] = se_iso($now);
                        $recordChanged = true;
                    }
                    return $merged;
                };
                foreach ($data['videos'] as &$stored) {
                    if (is_array($stored) && se_video_id($stored) === $id) $stored = $mergeRefresh($stored);
                }
                unset($stored);
                foreach ($data['videoLibrary'] as &$libraryItems) {
                    if (!is_array($libraryItems)) continue;
                    foreach ($libraryItems as &$stored) {
                        if (is_array($stored) && se_video_id($stored) === $id) $stored = $mergeRefresh($stored);
                    }
                    unset($stored);
                }
                unset($libraryItems);
                if ($recordChanged) $updated++;
                continue;
            }
            $data['videos'][] = $video;
            $data['videoLibrary'][$libraryKey][] = $video;
            $existing[$id] = true;
            $imported++;
        }
        $channel['lastCatalogSyncAt'] = se_iso($now);
        $channel['lastCatalogImported'] = $imported;
        $channel['catalogSyncStatus'] = 'OK';
        return ['imported' => $imported, 'updated' => $updated, 'error' => ''];
    }

    function se_sync_youtube_catalog(array &$data, int $now, bool $force = false): array {
        $data['channels'] = is_array($data['channels'] ?? null) ? $data['channels'] : [];
        $data['videos'] = is_array($data['videos'] ?? null) ? $data['videos'] : [];
        $data['botState'] = is_array($data['botState'] ?? null) ? $data['botState'] : [];
        $normalized = se_normalize_catalog_categories($data);
        $apiKey = se_youtube_api_key();
        $availability = $apiKey !== ''
            ? se_refresh_video_availability($data, $apiKey, $now, $force)
            : ['checked' => 0, 'hidden' => 0, 'restored' => 0, 'skipped' => 'missing_key'];
        $availabilityChanged = (int)($availability['hidden'] ?? 0) + (int)($availability['restored'] ?? 0);
        $lastAttempt = se_ts((string)($data['botState']['lastServerCatalogAttemptAt'] ?? $data['botState']['lastServerCatalogSyncAt'] ?? ''));
        $previousOk = (($data['botState']['catalogSyncStatus'] ?? '') === 'OK');
        $retryAfter = $previousOk ? 1800 : 300;
        if (!$force && $lastAttempt > 0 && ($now - $lastAttempt) < $retryAfter) return ['changed' => ($normalized + $availabilityChanged) > 0, 'normalized' => $normalized, 'availability' => $availability, 'skipped' => 'interval'];
        $data['botState']['lastServerCatalogAttemptAt'] = se_iso($now);
        if ($apiKey === '') {
            $data['botState']['catalogSyncStatus'] = 'MISSING_YOUTUBE_API_KEY';
            return ['changed' => $normalized > 0, 'normalized' => $normalized, 'availability' => $availability, 'skipped' => 'missing_key'];
        }
        $imported = 0;
        $updated = 0;
        $errors = [];
        $checked = 0;
        foreach ($data['channels'] as &$channel) {
            if (!is_array($channel) || (($channel['active'] ?? true) === false) || (($channel['enabled'] ?? true) === false)) continue;
            $checked++;
            $result = se_sync_youtube_channel($data, $channel, $apiKey, $now);
            $imported += (int)($result['imported'] ?? 0);
            $updated += (int)($result['updated'] ?? 0);
            if (!empty($result['error'])) {
                $channel['catalogSyncStatus'] = 'ERROR';
                $channel['catalogSyncError'] = (string)$result['error'];
                $errors[] = se_channel_key($channel) . ':' . $result['error'];
            } else {
                unset($channel['catalogSyncError']);
            }
        }
        unset($channel);
        $data['botState']['lastServerCatalogSyncAt'] = se_iso($now);
        $data['botState']['lastServerCatalogImported'] = $imported;
        $data['botState']['lastServerCatalogUpdated'] = $updated;
        $data['botState']['lastServerCatalogChannelsChecked'] = $checked;
        $data['botState']['catalogSyncStatus'] = $errors ? 'PARTIAL' : 'OK';
        $data['botState']['catalogSyncErrors'] = array_slice($errors, 0, 30);
        if (!$errors) $data['botState']['lastServerCatalogSuccessAt'] = se_iso($now);
        $normalized += se_normalize_catalog_categories($data);
        return ['changed' => ($imported + $updated + $normalized + $availabilityChanged) > 0, 'imported' => $imported, 'updated' => $updated, 'normalized' => $normalized, 'availability' => $availability, 'errors' => $errors];
    }

    function se_active_channels(array $data): array {
        return array_values(array_filter(is_array($data['channels'] ?? null) ? $data['channels'] : [], function ($channel) {
            return is_array($channel)
                && (($channel['active'] ?? true) !== false)
                && (($channel['enabled'] ?? true) !== false);
        }));
    }

    function se_video_matches_channel(array $video, array $channel): bool {
        return count(array_intersect(se_video_channel_refs($video), se_channel_refs($channel))) > 0;
    }

    function se_video_language_allowed(array $video, ?array $channel, array $data): bool {
        $signals = [];
        foreach (['defaultAudioLanguage', 'audioLanguage', 'language'] as $key) {
            $value = strtolower(trim((string)($video[$key] ?? '')));
            if ($value !== '') $signals[] = $value;
        }
        foreach ($signals as $lang) if ($lang === 'it' || strpos($lang, 'it-') === 0) return true;
        if (!empty($video['italianVideoId']) || !empty($video['italianPlaybackGuaranteed'])) return true;
        if (($video['italianAudioMode'] ?? '') === 'default' && !empty($video['italianVerified'])) return true;
        // YouTube conferma l'esistenza delle tracce multilingua, ma l'IFrame
        // incorporato non garantisce che quella italiana diventi la traccia attiva.
        if (($video['italianAudioMode'] ?? '') === 'multi_track' || !empty($video['italianAudioTrackId'])) return false;
        foreach ($signals as $lang) {
            if ($lang === 'en' || strpos($lang, 'en-') === 0 || $lang === 'de' || strpos($lang, 'de-') === 0 || $lang === 'fr' || strpos($lang, 'fr-') === 0) return false;
        }
        return !empty($video['italianVerified']) || !empty($video['forceItalian']);
    }

    function se_is_playable(array $video, ?array $channel, array $data): bool {
        if (se_video_id($video) === '') return false;
        if (!empty($video['hiddenByAvailabilityBot']) || !empty($video['unavailable']) || !empty($video['isUnavailable']) || (($video['availabilityStatus'] ?? '') === 'unavailable')) return false;
        $duration = se_duration($video);
        $title = strtolower((string)($video['title'] ?? ''));
        $profile = se_bot_profile($data);
        if ($duration < $profile['minDurationMinutes'] * 60 || $duration > $profile['maxDurationMinutes'] * 60) return false;
        if (strpos($title, '#shorts') !== false || strpos($title, 'shorts') !== false) return false;
        if (!empty($video['isLive']) || !empty($video['isShort']) || !empty($video['isPrivate']) || !empty($video['isUnavailable']) || !empty($video['regionBlocked']) || !empty($video['broken']) || !empty($video['isBroken'])) return false;
        if (isset($video['embeddable']) && $video['embeddable'] === false) return false;
        if (isset($video['youtubePlayableInCH']) && $video['youtubePlayableInCH'] === false) return false;
        $country = strtoupper(trim((string)($data['settings']['playbackCountry'] ?? $data['settings']['countryCode'] ?? 'CH'))) ?: 'CH';
        if (!se_region_allowed($video, $country)) return false;
        $privacy = strtolower((string)($video['privacyStatus'] ?? ''));
        if ($privacy !== '' && $privacy !== 'public') return false;
        $broadcast = strtolower((string)($video['liveBroadcastContent'] ?? 'none'));
        if ($broadcast !== '' && $broadcast !== 'none') return false;
        return se_video_language_allowed($video, $channel, $data);
    }

    function se_minutes(string $value, int $fallback): int {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($value), $m)) return $fallback;
        $hour = max(0, min(24, (int)$m[1]));
        $minute = max(0, min(59, (int)$m[2]));
        if ($hour === 24) $minute = 0;
        return $hour * 60 + $minute;
    }

    function se_day_windows(array $data, array $day): array {
        $raw = is_array($data['slots'] ?? null) ? $data['slots'] : [];
        $segments = [];
        foreach ($raw as $index => $slot) {
            if (!is_array($slot)) continue;
            $start = se_minutes((string)($slot['start'] ?? $slot['startTime'] ?? '00:00'), 0);
            $end = se_minutes((string)($slot['end'] ?? $slot['endTime'] ?? '24:00'), 1440);
            if ($end <= $start) $end += 1440;
            foreach ([0, -1440] as $shift) {
                $a = max(0, $start + $shift);
                $b = min(1440, $end + $shift);
                if ($b <= $a) continue;
                $segments[] = ['from' => $a, 'to' => $b, 'slot' => $slot, 'index' => $index];
            }
        }

        $bounds = [0 => true, 1440 => true];
        foreach ($segments as $segment) {
            $bounds[$segment['from']] = true;
            $bounds[$segment['to']] = true;
        }
        $points = array_keys($bounds);
        sort($points, SORT_NUMERIC);
        $windows = [];
        for ($i = 0; $i < count($points) - 1; $i++) {
            $from = (int)$points[$i];
            $to = (int)$points[$i + 1];
            if ($to <= $from) continue;
            $covering = array_values(array_filter($segments, function ($segment) use ($from, $to) {
                return $segment['from'] <= $from && $segment['to'] >= $to;
            }));
            usort($covering, function ($a, $b) {
                $pa = (int)($a['slot']['priority'] ?? 99);
                $pb = (int)($b['slot']['priority'] ?? 99);
                return $pa === $pb ? $a['index'] <=> $b['index'] : $pa <=> $pb;
            });
            $slot = $covering[0]['slot'] ?? [
                'id' => 'automatic_gap', 'name' => 'Programmazione automatica',
                'channelIds' => [], 'priority' => 99,
            ];
            $windows[] = [
                'start' => $day['start'] + $from * 60,
                'end' => $day['start'] + $to * 60,
                'slot' => $slot,
            ];
        }
        return $windows;
    }

    function se_channels_for_slot(array $channels, array $slot): array {
        $allowed = [];
        foreach (['channelIds', 'channels'] as $key) {
            foreach (is_array($slot[$key] ?? null) ? $slot[$key] : [] as $id) {
                $value = trim((string)$id);
                if ($value !== '') $allowed[$value] = true;
            }
        }
        if (!$allowed) return $channels;
        $matched = array_values(array_filter($channels, function ($channel) use ($allowed) {
            foreach (se_channel_refs($channel) as $ref) if (isset($allowed[$ref])) return true;
            return false;
        }));
        return $matched ?: $channels;
    }

    function se_history_latest(array $data): array {
        $latest = [];
        foreach (is_array($data['botHistory'] ?? null) ? $data['botHistory'] : [] as $entry) {
            if (!is_array($entry)) continue;
            $id = trim((string)($entry['videoId'] ?? $entry['id'] ?? ''));
            $at = se_ts((string)($entry['airedAt'] ?? $entry['startDateTime'] ?? $entry['createdAt'] ?? ''));
            if ($id !== '' && $at > (int)($latest[$id] ?? 0)) $latest[$id] = $at;
        }
        return $latest;
    }

    function se_catalog_signature(array $data): string {
        $parts = [];
        foreach (se_collect_videos($data) as $video) {
            $parts[] = implode('|', [se_video_id($video), se_duration($video), (string)($video['publishedAt'] ?? ''), (string)($video['title'] ?? ''), (string)($video['italianTitle'] ?? ''), (string)($video['defaultAudioLanguage'] ?? $video['audioLanguage'] ?? ''), json_encode($video['availableLanguages'] ?? []), (string)($video['italianAudioStatus'] ?? ''), (string)($video['italianAudioTrackId'] ?? ''), !empty($video['italianPlaybackGuaranteed']) ? 'it-guaranteed' : 'it-not-guaranteed', json_encode($video['regionRestriction'] ?? []), (($video['embeddable'] ?? true) === false ? 'noembed' : 'embed'), (string)($video['availabilityStatus'] ?? '')]);
        }
        foreach (se_active_channels($data) as $channel) {
            $parts[] = 'c|' . se_channel_key($channel) . '|' . (string)($channel['rating'] ?? $channel['stars'] ?? 0) . '|' . implode(',', (array)($channel['slots'] ?? []));
        }
        foreach ((array)($data['slots'] ?? []) as $slot) if (is_array($slot)) $parts[] = 's|' . json_encode($slot);
        $parts[] = 'bot-v3-settings|' . json_encode(se_bot_profile($data));
        sort($parts, SORT_STRING);
        return hash('sha256', implode("\n", $parts));
    }

    function se_category(array $video): string {
        return se_catalog_category($video);
    }

    function se_pick(array $pool, array $channels, array $history, array $used, array $channelUses, array $recentChannels, array $recentCategories, int $cursor, string $dayDate, string $slotId, int $remaining, array $settings): ?array {
        $channelByRef = [];
        foreach ($channels as $channel) foreach (se_channel_refs($channel) as $ref) $channelByRef[$ref] = $channel;
        $candidates = [];
        foreach ($pool as $video) {
            $channel = null;
            foreach (se_video_channel_refs($video) as $ref) if (isset($channelByRef[$ref])) { $channel = $channelByRef[$ref]; break; }
            if (!$channel) continue;
            $id = se_video_id($video);
            $last = (int)($history[$id] ?? 0);
            $ageDays = $last > 0 ? max(0, ($cursor - $last) / 86400) : 9999;
            $todayUses = (int)($used[$id] ?? 0);
            $published = se_ts((string)($video['publishedAt'] ?? $video['createdAt'] ?? ''));
            $publicationAge = $published > 0 ? max(0, $cursor - $published) : PHP_INT_MAX;
            $cooldown = (int)$settings['repeatCooldownDays'];
            if ($todayUses === 0 && $ageDays >= $cooldown) {
                $tier = 0;
                if ($publicationAge <= 48 * 3600) $strategy = 'fresh_48h';
                elseif ($publicationAge <= 7 * 86400) $strategy = 'recent_7d';
                elseif ($publicationAge <= 30 * 86400) $strategy = 'recent_30d';
                else $strategy = 'rotation_30';
            }
            elseif ($todayUses === 0 && $ageDays >= 7) { $tier = 1; $strategy = 'rotation_relaxed_30'; }
            elseif ($todayUses === 0 && $ageDays >= 1) { $tier = 2; $strategy = 'rotation_relaxed_7'; }
            elseif ($todayUses === 0) { $tier = 3; $strategy = 'rotation_relaxed_1'; }
            else {
                if (empty($settings['allowEmergencyReplicas'])) continue;
                $tier = 4 + $todayUses; $strategy = 'replica_emergency';
            }

            $channelId = se_channel_key($channel);
            if ($recentChannels && end($recentChannels) === $channelId) $tier += 2;
            $duration = se_duration($video);
            $rating = (float)($channel['rating'] ?? $channel['stars'] ?? $channel['score'] ?? 5);
            $freshHours = $published > 0 ? max(0, ($cursor - $published) / 3600) : 99999;
            $category = se_category($video);
            $score = $rating * 120;
            $freshnessScale = ((int)$settings['freshnessWeight']) / 100;
            $channelVarietyScale = ((int)$settings['channelVarietyWeight']) / 100;
            $categoryVarietyScale = ((int)$settings['categoryVarietyWeight']) / 100;
            if ($freshHours <= 48) $score += (4200 - $freshHours * 20) * $freshnessScale;
            elseif ($freshHours <= 168) $score += (1900 - ($freshHours - 48) * 5) * $freshnessScale;
            elseif ($freshHours <= 720) $score += (900 - ($freshHours - 168) * 1.2) * $freshnessScale;
            if ($last === 0) $score += 650;
            else $score += min(500, $ageDays * 14);
            if (!empty($video['italianVerified']) || !empty($video['italianVideoId']) || !empty($video['forceItalian'])) $score += 180;
            $score -= (int)($channelUses[$channelId] ?? 0) * 85 * $channelVarietyScale;
            $score -= $todayUses * 1200;
            if (in_array($channelId, array_slice($recentChannels, -2), true)) $score -= 550 * $channelVarietyScale;
            if (in_array($category, array_slice($recentCategories, -2), true)) $score -= 160 * $categoryVarietyScale;
            if ($remaining > 0 && $duration > $remaining + 900) $score -= min(900, ($duration - $remaining) / 5);
            $score += (crc32($dayDate . '|' . $slotId . '|' . $id) % 1000) / 1000;
            $candidates[] = compact('video', 'channel', 'channelId', 'category', 'duration', 'tier', 'strategy', 'score', 'ageDays', 'freshHours');
        }
        if (!$candidates) return null;
        usort($candidates, function ($a, $b) {
            if ($a['tier'] !== $b['tier']) return $a['tier'] <=> $b['tier'];
            if ($a['score'] === $b['score']) return strcmp(se_video_id($a['video']), se_video_id($b['video']));
            return $a['score'] < $b['score'] ? 1 : -1;
        });
        return $candidates[0];
    }

    function se_make_item(array $pick, array $slot, int $start, string $date, int $ordinal): array {
        $video = $pick['video'];
        $channel = $pick['channel'];
        $id = se_video_id($video);
        $duration = (int)$pick['duration'];
        $end = $start + $duration;
        $strategy = (string)$pick['strategy'];
        $reason = $strategy === 'fresh_48h'
            ? 'Novita delle ultime 48 ore, canale preferito e video non trasmesso di recente'
            : ($strategy === 'recent_7d'
                ? 'Video recente degli ultimi 7 giorni, prioritario nella fascia e non trasmesso di recente'
                : ($strategy === 'recent_30d'
                    ? 'Video pubblicato negli ultimi 30 giorni, scelto prima del catalogo storico'
            : ($strategy === 'rotation_30'
                ? 'Rotazione editoriale della fascia, priorita al canale e nessuna replica negli ultimi 30 giorni'
                : ($strategy === 'replica_emergency'
                    ? 'Replica di emergenza: catalogo disponibile insufficiente per coprire la fascia senza ripetizioni'
                    : 'Rotazione ampliata progressivamente per garantire la continuita H24'))));
        return [
            'id' => 'daily_' . str_replace('-', '', $date) . '_' . str_pad((string)$ordinal, 3, '0', STR_PAD_LEFT) . '_' . substr(hash('sha1', $id), 0, 8),
            'videoId' => $id,
            'url' => (string)($video['url'] ?? ('https://www.youtube.com/watch?v=' . $id)),
            'title' => (string)($video['title'] ?? 'Video'),
            'originalTitle' => (string)($video['originalTitle'] ?? $video['title'] ?? 'Video'),
            'italianTitle' => (string)($video['italianTitle'] ?? ''),
            'titleTranslationStatus' => (string)($video['titleTranslationStatus'] ?? 'original'),
            'channel' => (string)($channel['name'] ?? $channel['title'] ?? $video['channel'] ?? $video['channelTitle'] ?? ''),
            'channelId' => (string)$pick['channelId'],
            'sourceChannelId' => (string)$pick['channelId'],
            'thumbnail' => (string)($video['thumbnail'] ?? $video['thumb'] ?? ('https://img.youtube.com/vi/' . $id . '/hqdefault.jpg')),
            'publishedAt' => (string)($video['publishedAt'] ?? $video['createdAt'] ?? $video['date'] ?? ''),
            'durationSeconds' => $duration,
            'slotId' => (string)($slot['id'] ?? $slot['slotId'] ?? ''),
            'slotName' => (string)($slot['name'] ?? $slot['title'] ?? 'Programmazione automatica'),
            'strategy' => $strategy,
            'reason' => $reason,
            'finalScore' => round((float)$pick['score'], 2),
            'isReplica' => $strategy === 'replica_emergency',
            'category' => se_category($video),
            'language' => (string)($video['language'] ?? $video['defaultAudioLanguage'] ?? ''),
            'defaultLanguage' => (string)($video['defaultLanguage'] ?? ''),
            'defaultAudioLanguage' => (string)($video['defaultAudioLanguage'] ?? ''),
            'availableLanguages' => is_array($video['availableLanguages'] ?? null) ? $video['availableLanguages'] : [],
            'italianVideoId' => (string)($video['italianVideoId'] ?? ''),
            'italianVerified' => !empty($video['italianVerified']),
            'italianAudioStatus' => (string)($video['italianAudioStatus'] ?? (!empty($video['italianVerified']) ? 'verified' : '')),
            'italianAudioMode' => (string)($video['italianAudioMode'] ?? ''),
            'italianAudioTrackId' => (string)($video['italianAudioTrackId'] ?? ''),
            'italianPlaybackGuaranteed' => !empty($video['italianPlaybackGuaranteed']) || (($video['italianAudioMode'] ?? '') === 'default') || preg_match('/^it(?:-|$)/i', (string)($video['defaultAudioLanguage'] ?? $video['language'] ?? '')) === 1,
            'italianAudioCheckedAt' => (string)($video['italianAudioCheckedAt'] ?? ''),
            'italianAudioReason' => (string)($video['italianAudioReason'] ?? ''),
            'embeddable' => ($video['embeddable'] ?? true) !== false,
            'youtubePlayableInCH' => ($video['youtubePlayableInCH'] ?? true) !== false,
            'regionRestriction' => is_array($video['regionRestriction'] ?? null) ? $video['regionRestriction'] : [],
            'scheduleApproved' => !empty($video['italianPlaybackGuaranteed']) || !empty($video['italianVideoId']) || (($video['italianAudioMode'] ?? '') === 'default') || preg_match('/^it(?:-|$)/i', (string)($video['defaultAudioLanguage'] ?? $video['language'] ?? '')) === 1,
            'languageStatus' => (string)($video['languageStatus'] ?? 'it_verified'),
            'isKids' => !empty($video['isKids']) || !empty($channel['isKids']),
            'scheduledStartDateTime' => se_iso($start),
            'scheduledEndDateTime' => se_iso($end),
            'startDateTime' => se_iso($start),
            'endDateTime' => se_iso($end),
            'time' => gmdate('H:i', $start),
            'type' => 'content',
            'status' => 'scheduled',
        ];
    }

    function se_build_schedule(array $data, int $fromTs, array $prefix = []): array {
        $day = se_day_context($data, $fromTs);
        $channels = se_active_channels($data);
        $videos = se_collect_videos($data);
        $history = se_history_latest($data);
        $used = [];
        $channelUses = [];
        $recentChannels = [];
        $recentCategories = [];
        $plan = [];
        $settings = se_bot_profile($data);
        foreach ($prefix as $item) {
            if (!is_array($item) || se_video_id($item) === '') continue;
            $plan[] = $item;
            $id = se_video_id($item);
            $channelId = (string)($item['channelId'] ?? $item['sourceChannelId'] ?? $item['channel'] ?? '');
            $used[$id] = (int)($used[$id] ?? 0) + 1;
            $channelUses[$channelId] = (int)($channelUses[$channelId] ?? 0) + 1;
            $recentChannels[] = $channelId;
            $recentCategories[] = (string)($item['category'] ?? 'general');
        }
        $cursor = max($fromTs, $day['start']);
        if ($plan) {
            $lastEnd = se_ts((string)(end($plan)['endDateTime'] ?? ''));
            if ($lastEnd > $cursor) $cursor = $lastEnd;
        }
        $ordinal = count($plan);
        foreach (se_day_windows($data, $day) as $window) {
            if ($window['end'] <= $cursor) continue;
            if ($cursor < $window['start']) $cursor = $window['start'];
            $slot = $window['slot'];
            $slotChannels = se_channels_for_slot($channels, $slot);
            $pool = array_values(array_filter($videos, function ($video) use ($slotChannels, $data, $settings) {
                $allowedCategories = $settings['allowedCategories'];
                if ($allowedCategories && !in_array(se_category($video), $allowedCategories, true)) return false;
                foreach ($slotChannels as $channel) {
                    if (se_video_matches_channel($video, $channel) && se_is_playable($video, $channel, $data)) return true;
                }
                return false;
            }));
            while ($cursor < $window['end'] && $ordinal < 1000) {
                $remaining = $window['end'] - $cursor;
                $slotId = (string)($slot['id'] ?? $slot['name'] ?? 'automatic');
                $pick = se_pick($pool, $slotChannels, $history, $used, $channelUses, $recentChannels, $recentCategories, $cursor, $day['date'], $slotId, $remaining, $settings);
                if (!$pick) break;
                $item = se_make_item($pick, $slot, $cursor, $day['date'], ++$ordinal);
                $plan[] = $item;
                $id = se_video_id($item);
                $channelId = (string)$item['channelId'];
                $used[$id] = (int)($used[$id] ?? 0) + 1;
                $channelUses[$channelId] = (int)($channelUses[$channelId] ?? 0) + 1;
                $recentChannels[] = $channelId;
                $recentCategories[] = (string)$item['category'];
                $recentChannels = array_slice($recentChannels, -5);
                $recentCategories = array_slice($recentCategories, -5);
                $cursor = se_ts($item['endDateTime']);
            }
        }
        return $plan;
    }

    function se_archive_old_schedule(array &$data, string $oldDate, array $schedule): void {
        if ($oldDate === '' || !$schedule) return;
        $data['scheduleArchive'] = is_array($data['scheduleArchive'] ?? null) ? $data['scheduleArchive'] : [];
        $data['scheduleArchive'][$oldDate] = array_values($schedule);
        ksort($data['scheduleArchive']);
        while (count($data['scheduleArchive']) > 14) array_shift($data['scheduleArchive']);
    }

    function se_ensure_daily_schedule(array &$data, int $now, bool $force = false): bool {
        $day = se_day_context($data, $now);
        $meta = is_array($data['scheduleMeta'] ?? null) ? $data['scheduleMeta'] : [];
        $existing = is_array($data['schedule'] ?? null) ? $data['schedule'] : [];
        $oldDate = (string)($meta['date'] ?? '');
        $signature = se_catalog_signature($data);
        $sameDay = $oldDate === $day['date'];
        $sameCatalog = hash_equals((string)($meta['catalogSignature'] ?? ''), $signature);
        if (!$force && $sameDay && $sameCatalog && $existing) {
            $data['palinsesto'] = $existing;
            return false;
        }
        if (!$sameDay && $existing) se_archive_old_schedule($data, $oldDate, $existing);

        $prefix = [];
        if ($sameDay && $existing) {
            foreach ($existing as $item) {
                if (!is_array($item)) continue;
                $start = se_ts((string)($item['startDateTime'] ?? ''));
                if ($start > 0 && $start <= $now) $prefix[] = $item;
            }
        }
        $from = $prefix ? max($now, se_ts((string)(end($prefix)['endDateTime'] ?? ''))) : max($now, $day['start']);
        $schedule = se_build_schedule($data, $from, $prefix);
        $data['schedule'] = $schedule;
        $data['palinsesto'] = $schedule;
        $data['internalSlotSchedule'] = $schedule;
        $data['scheduleMeta'] = [
            'date' => $day['date'],
            'timezone' => $day['timezone'],
            'generatedAt' => se_iso($now),
            'catalogSignature' => $signature,
            'items' => count($schedule),
            'replicas' => count(array_filter($schedule, function ($item) { return !empty($item['isReplica']); })),
            'engineVersion' => 2,
        ];
        return true;
    }

    function se_locked_live_items(array $data, int $now, int $limit = 3): array {
        $catalog = [];
        foreach (se_collect_videos($data) as $video) if (is_array($video) && se_video_id($video) !== '') $catalog[se_video_id($video)] = $video;
        $sources = [
            is_array($data['publicLiveSchedule']['liveQueue'] ?? null) ? $data['publicLiveSchedule']['liveQueue'] : [],
            is_array($data['liveQueue'] ?? null) ? $data['liveQueue'] : [],
            is_array($data['futureSchedule'] ?? null) ? $data['futureSchedule'] : [],
            is_array($data['schedule'] ?? null) ? $data['schedule'] : [],
        ];
        $unique = [];
        foreach ($sources as $items) foreach ($items as $item) {
            if (!is_array($item) || se_video_id($item) === '') continue;
            $start = se_ts((string)($item['startDateTime'] ?? ''));
            $end = se_ts((string)($item['endDateTime'] ?? ''));
            if ($start <= 0 || $end <= $now || $end <= $start) continue;
            // Le sorgenti sono ordinate per autorita: la coda gia pubblicata viene
            // prima della previsione. A parita di orario conserva il suo impegno.
            $key = (string)$start;
            if (isset($unique[$key])) continue;
            $candidate = array_merge($catalog[se_video_id($item)] ?? [], $item);
            if (!se_is_playable($candidate, null, $data)) continue;
            $unique[$key] = $candidate;
        }
        $items = array_values($unique);
        usort($items, static function ($a, $b): int {
            $byStart = se_ts((string)($a['startDateTime'] ?? '')) <=> se_ts((string)($b['startDateTime'] ?? ''));
            return $byStart !== 0 ? $byStart : strcmp(se_video_id($a), se_video_id($b));
        });
        $currentIndex = null;
        foreach ($items as $index => $item) {
            $start = se_ts((string)($item['startDateTime'] ?? ''));
            $end = se_ts((string)($item['endDateTime'] ?? ''));
            if ($start <= $now && $end > $now) { $currentIndex = $index; break; }
        }
        if ($currentIndex === null) return [];
        $locked = [];
        foreach (array_slice($items, $currentIndex, max(1, $limit)) as $index => $item) {
            $item['forecastLocked'] = true;
            $item['liveCommitmentPosition'] = $index + 1;
            $locked[] = $item;
        }
        return $locked;
    }

    function se_build_future_schedule(array $data, int $now, int $hours = 72): array {
        $hours = max(24, min(168, $hours));
        $horizonEnd = $now + $hours * 3600;
        // In onda + prossimi due sono impegni pubblicati e non cambiano piu.
        // Il resto della finestra rimane adattivo.
        $plan = se_locked_live_items($data, $now, 3);

        $cursor = $plan ? max($now, se_ts((string)(end($plan)['endDateTime'] ?? ''))) : $now;
        $guard = 0;
        while ($cursor < $horizonEnd && $guard++ < 8) {
            $day = se_day_context($data, $cursor);
            $before = count($plan);
            $expanded = se_build_schedule($data, $cursor, $plan);
            if (count($expanded) > $before) {
                $plan = $expanded;
                $lastEnd = se_ts((string)(end($plan)['endDateTime'] ?? ''));
                $cursor = $lastEnd > $cursor ? $lastEnd : $day['end'];
            } else {
                $cursor = $day['end'];
            }
            if ($cursor < $day['end']) $cursor = $day['end'];
        }

        return array_values(array_filter($plan, static function ($item) use ($now, $horizonEnd): bool {
            if (!is_array($item)) return false;
            $start = se_ts((string)($item['startDateTime'] ?? ''));
            $end = se_ts((string)($item['endDateTime'] ?? ''));
            return $start < $horizonEnd && $end > $now;
        }));
    }

    function se_ensure_future_schedule(array &$data, int $now, int $hours = 72, bool $force = false): bool {
        $hours = max(24, min(168, $hours));
        $catalogSignature = se_catalog_signature($data);
        $hourAnchor = intdiv($now, 3600) * 3600;
        $lockedItems = se_locked_live_items($data, $now, 3);
        $lockKey = implode(',', array_map(static fn($item): string => se_video_id($item) . '@' . (string)($item['endDateTime'] ?? ''), $lockedItems));
        $signature = hash('sha256', 'official-live-lock-3-v1|' . $catalogSignature . '|' . $hourAnchor . '|' . $lockKey . '|' . $hours);
        $meta = is_array($data['futureScheduleMeta'] ?? null) ? $data['futureScheduleMeta'] : [];
        $existing = is_array($data['futureSchedule'] ?? null) ? $data['futureSchedule'] : [];
        if (!$force && $existing && hash_equals((string)($meta['signature'] ?? ''), $signature)) return false;

        $oldReplicas = count(array_filter($existing, static function ($item) use ($now): bool {
            return is_array($item) && !empty($item['isReplica']) && se_ts((string)($item['startDateTime'] ?? '')) > $now;
        }));
        $catalogChanged = (string)($meta['catalogSignature'] ?? '') !== ''
            && !hash_equals((string)$meta['catalogSignature'], $catalogSignature);
        $future = se_build_future_schedule($data, $now, $hours);
        $oldById = [];
        foreach ($existing as $oldItem) if (is_array($oldItem) && se_video_id($oldItem) !== '') $oldById[se_video_id($oldItem)] = $oldItem;
        foreach ($future as $index => &$futureItem) {
            $id = se_video_id($futureItem);
            $oldItem = $oldById[$id] ?? null;
            if (is_array($oldItem) && !empty($oldItem['forecastSubstitute'])) {
                $futureItem['forecastSubstitute'] = true;
                $futureItem['forecastSubstitutedAt'] = (string)($oldItem['forecastSubstitutedAt'] ?? '');
                $futureItem['forecastSubstituteReason'] = (string)($oldItem['forecastSubstituteReason'] ?? 'catalog_update');
            } elseif ($catalogChanged && $index >= 3 && !isset($oldById[$id])) {
                $futureItem['forecastSubstitute'] = true;
                $futureItem['forecastSubstitutedAt'] = se_iso($now);
                $futureItem['forecastSubstituteReason'] = 'new_verified_content';
            }
        }
        unset($futureItem);
        $newReplicas = count(array_filter($future, static fn($item): bool => is_array($item) && !empty($item['isReplica'])));
        $fresh = count(array_filter($future, static fn($item): bool => is_array($item) && in_array((string)($item['strategy'] ?? ''), ['fresh_48h', 'recent_7d'], true)));
        $substitutes = count(array_filter($future, static fn($item): bool => is_array($item) && !empty($item['forecastSubstitute'])));

        $data['futureSchedule'] = $future;
        $data['futureScheduleMeta'] = [
            'generatedAt' => se_iso($now),
            'horizonStart' => se_iso($now),
            'horizonEnd' => se_iso($now + $hours * 3600),
            'horizonHours' => $hours,
            'signature' => $signature,
            'catalogSignature' => $catalogSignature,
            'items' => count($future),
            'replicas' => $newReplicas,
            'freshItems' => $fresh,
            'substituteItems' => $substitutes,
            'replicasReplacedLastRun' => $catalogChanged ? max(0, $oldReplicas - $newReplicas) : 0,
            'adaptive' => true,
            'lockedItems' => min(3, count($future)),
            'officialLiveSource' => true,
            'revisionReason' => $force ? 'forced' : ($catalogChanged ? 'catalog_update' : 'hourly_rollover'),
            'engineVersion' => 3,
        ];
        return true;
    }

    function se_publish_future_schedule(array &$data, int $now): void {
        $future = is_array($data['futureSchedule'] ?? null) ? $data['futureSchedule'] : [];
        if (!$future) return;
        $day = se_day_context($data, $now);
        $oldMeta = is_array($data['scheduleMeta'] ?? null) ? $data['scheduleMeta'] : [];
        $oldSchedule = is_array($data['schedule'] ?? null) ? $data['schedule'] : [];
        $oldDate = (string)($oldMeta['date'] ?? '');
        if ($oldDate !== '' && $oldDate !== $day['date']) se_archive_old_schedule($data, $oldDate, $oldSchedule);

        $past = array_values(array_filter($oldSchedule, static function ($item) use ($day, $now): bool {
            if (!is_array($item)) return false;
            $start = se_ts((string)($item['startDateTime'] ?? ''));
            $end = se_ts((string)($item['endDateTime'] ?? ''));
            return $start >= $day['start'] && $end <= $now;
        }));
        $todayFuture = array_values(array_filter($future, static function ($item) use ($day, $now): bool {
            if (!is_array($item)) return false;
            $start = se_ts((string)($item['startDateTime'] ?? ''));
            $end = se_ts((string)($item['endDateTime'] ?? ''));
            return $end > $now && $start < $day['end'];
        }));
        $official = array_merge($past, $todayFuture);
        $data['schedule'] = $official;
        $data['palinsesto'] = $official;
        $data['internalSlotSchedule'] = $official;
        $data['scheduleMeta'] = [
            'date' => $day['date'], 'timezone' => $day['timezone'], 'generatedAt' => se_iso($now),
            'catalogSignature' => se_catalog_signature($data), 'items' => count($official),
            'replicas' => count(array_filter($official, static fn($item): bool => is_array($item) && !empty($item['isReplica']))),
            'engineVersion' => 3, 'rollingSource' => 'futureSchedule', 'lockedLiveItems' => min(3, count($future)),
        ];
    }

    function se_mark_aired(array &$data, string $videoId, int $now): void {
        foreach (['schedule', 'palinsesto', 'internalSlotSchedule'] as $key) {
            if (!is_array($data[$key] ?? null)) continue;
            $bestIndex = null;
            $bestStart = 0;
            foreach ($data[$key] as $index => $item) {
                if (!is_array($item) || se_video_id($item) !== $videoId || (($item['status'] ?? '') === 'aired')) continue;
                $start = se_ts((string)($item['startDateTime'] ?? ''));
                if ($start <= $now && $start >= $bestStart) {
                    $bestIndex = $index;
                    $bestStart = $start;
                }
            }
            if ($bestIndex !== null) {
                $data[$key][$bestIndex]['status'] = 'aired';
                $data[$key][$bestIndex]['airedAt'] = se_iso($now);
            }
        }
    }
}
