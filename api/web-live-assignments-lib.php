<?php
declare(strict_types=1);

if (!function_exists('wla_read_assignments')) {
    function wla_assignments_path(): string {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'web-live-assignments.json';
    }

    function wla_lock_path(): string {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . '.web-live-assignments.lock';
    }

    function wla_read_assignments(): array {
        $path = wla_assignments_path();
        $decoded = is_file($path) ? json_decode((string)@file_get_contents($path), true) : [];
        $assignments = is_array($decoded['assignments'] ?? null) ? $decoded['assignments'] : [];
        $clean = [];
        $allowed = ['live2', 'kids', 'crime', 'docu', 'cucina'];
        foreach ($assignments as $channelId => $stationIds) {
            if (!is_string($channelId) || $channelId === '' || !is_array($stationIds)) continue;
            $clean[$channelId] = array_values(array_unique(array_intersect($allowed, array_map('strval', $stationIds))));
        }
        return $clean;
    }

    function wla_write_assignment(string $channelId, array $stationIds): array {
        $lock = @fopen(wla_lock_path(), 'c');
        if (!$lock || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) fclose($lock);
            throw new RuntimeException('ASSIGNMENT_LOCK_FAILED');
        }
        try {
            $assignments = wla_read_assignments();
            $allowed = ['live2', 'kids', 'crime', 'docu', 'cucina'];
            $assignments[$channelId] = array_values(array_unique(array_intersect($allowed, array_map('strval', $stationIds))));
            $payload = ['version' => 1, 'updatedAt' => gmdate('c'), 'assignments' => $assignments];
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) throw new RuntimeException('ASSIGNMENT_JSON_FAILED');
            $path = wla_assignments_path();
            $temporary = $path . '.tmp';
            if (file_put_contents($temporary, $json, LOCK_EX) === false || !rename($temporary, $path)) {
                @unlink($temporary);
                throw new RuntimeException('ASSIGNMENT_WRITE_FAILED');
            }
            @chmod($path, 0600);
            flock($lock, LOCK_UN); fclose($lock);
            return $assignments[$channelId];
        } catch (Throwable $error) {
            flock($lock, LOCK_UN); fclose($lock);
            throw $error;
        }
    }

    function wla_apply_assignments(array &$data): int {
        $assignments = wla_read_assignments();
        if (!$assignments || !is_array($data['channels'] ?? null)) return 0;
        $changed = 0;
        foreach ($data['channels'] as &$channel) {
            if (!is_array($channel)) continue;
            $channelId = (string)($channel['id'] ?? '');
            if ($channelId === '' || !array_key_exists($channelId, $assignments)) continue;
            if (($channel['webLiveIds'] ?? []) !== $assignments[$channelId]) $changed++;
            $channel['webLiveIds'] = $assignments[$channelId];
        }
        unset($channel);
        return $changed;
    }
}
