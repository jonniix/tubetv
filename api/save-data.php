<?php
/**
 * TubeTV - api/save-data.php
 * Writes tubetv-data.json with atomic replace and JSON error responses.
 */

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-TubeTV-Admin');

function fail_json($message, $code = 500) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'error' => (string)$message,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function get_header_ci(array $headers, string $name): string {
    foreach ($headers as $k => $v) {
        if (strcasecmp((string)$k, $name) === 0) {
            return is_string($v) ? trim($v) : '';
        }
    }
    return '';
}

function get_admin_token(): string {
    $configPath = dirname(__DIR__) . '/private/config.php';
    if (is_file($configPath)) {
        $cfg = include $configPath;
        if (is_array($cfg)) {
            if (!empty($cfg['TUBETV_ADMIN_TOKEN']) && is_string($cfg['TUBETV_ADMIN_TOKEN'])) {
                return trim($cfg['TUBETV_ADMIN_TOKEN']);
            }
            if (!empty($cfg['ADMIN_TOKEN']) && is_string($cfg['ADMIN_TOKEN'])) {
                return trim($cfg['ADMIN_TOKEN']);
            }
        }
    }

    $envA = getenv('TUBETV_ADMIN_TOKEN');
    if (is_string($envA) && trim($envA) !== '') return trim($envA);
    $envB = getenv('ADMIN_TOKEN');
    if (is_string($envB) && trim($envB) !== '') return trim($envB);
    return '';
}

function requires_admin_auth(array $incoming): bool {
    $publicOnlyKeys = ['liveSkipRequest'];
    return !empty(array_diff(array_keys($incoming), $publicOnlyKeys));
}

function is_list_array($arr): bool {
    if (!is_array($arr)) return false;
    if ($arr === []) return true;
    return array_keys($arr) === range(0, count($arr) - 1);
}

function deep_merge_replace_lists($old, $new) {
    if (!is_array($old) || !is_array($new)) {
        return $new;
    }

    if (is_list_array($old) || is_list_array($new)) {
        return $new;
    }

    foreach ($new as $key => $value) {
        if (array_key_exists($key, $old)) {
            $old[$key] = deep_merge_replace_lists($old[$key], $value);
        } else {
            $old[$key] = $value;
        }
    }

    return $old;
}

function normalize_live_queues(array &$data): void {
    if (isset($data['publicLiveSchedule']['liveQueue']) && is_array($data['publicLiveSchedule']['liveQueue'])) {
        $q = array_slice(array_values($data['publicLiveSchedule']['liveQueue']), 0, 3);

        $data['publicLiveSchedule']['liveQueue'] = $q;
        $data['publicLiveSchedule']['current'] = $q[0] ?? null;
        $data['publicLiveSchedule']['next'] = $q[1] ?? null;
        $data['publicLiveSchedule']['afterNext'] = $q[2] ?? null;
        $data['liveQueue'] = $q;
    }

    if (isset($data['liveQueue']) && is_array($data['liveQueue'])) {
        $q = array_slice(array_values($data['liveQueue']), 0, 3);
        $data['liveQueue'] = $q;

        if (!isset($data['publicLiveSchedule']) || !is_array($data['publicLiveSchedule'])) {
            $data['publicLiveSchedule'] = [];
        }

        if (!isset($data['publicLiveSchedule']['liveQueue']) || !is_array($data['publicLiveSchedule']['liveQueue'])) {
            $data['publicLiveSchedule']['liveQueue'] = $q;
            $data['publicLiveSchedule']['current'] = $q[0] ?? null;
            $data['publicLiveSchedule']['next'] = $q[1] ?? null;
            $data['publicLiveSchedule']['afterNext'] = $q[2] ?? null;
        }
    }
}

function strip_secrets(array &$data): void {
    if (isset($data['settings']) && is_array($data['settings'])) {
        unset(
            $data['settings']['apiKey'],
            $data['settings']['youtubeApiKey'],
            $data['settings']['ytApiKey'],
            $data['settings']['groqApiKey']
        );
    }

    if (isset($data['channels']) && is_array($data['channels'])) {
        foreach ($data['channels'] as &$ch) {
            if (is_array($ch)) {
                unset($ch['apiKey'], $ch['youtubeApiKey']);
            }
        }
        unset($ch);
    }
}

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    $root = dirname(__DIR__);
    $dataDir = $root . '/data';
    if (!is_dir($dataDir)) {
        @mkdir($dataDir, 0775, true);
    }
    $dataFile = $dataDir . '/tubetv-data.json';
    $tmp = $dataFile . '.tmp';
    error_log('[save-data.php] dataFile=' . $dataFile);

    if ($method === 'GET') {
        echo json_encode([
            'ok' => true,
            'mode' => 'php',
            'file' => basename($dataFile),
            'dataFile' => $dataFile,
            'dataFileWritable' => is_file($dataFile) ? is_writable($dataFile) : null,
            'dataDirWritable' => is_writable(dirname($dataFile)),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($method !== 'POST') {
        fail_json('METHOD_NOT_ALLOWED', 405);
    }

    $raw = file_get_contents('php://input');
    if (!$raw) {
        fail_json('EMPTY_BODY', 400);
    }

    $input = json_decode($raw, true);
    if (!is_array($input)) {
        fail_json('INVALID_JSON_BODY: ' . json_last_error_msg(), 400);
    }

    $filename = basename((string)($input['filename'] ?? 'tubetv-data.json'));
    if ($filename !== 'tubetv-data.json') {
        fail_json('FILENAME_NOT_ALLOWED: ' . $filename, 403);
    }

    $patch = $input['data'] ?? null;
    if (!is_array($patch)) {
        fail_json('MISSING_DATA_OBJECT', 400);
    }

    $adminToken = get_admin_token();
    if ($adminToken !== '' && requires_admin_auth($patch)) {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $provided = get_header_ci(is_array($headers) ? $headers : [], 'X-TubeTV-Admin');
        if ($provided === '' || !hash_equals($adminToken, $provided)) {
            fail_json('UNAUTHORIZED', 401);
        }
    }

    if (!file_exists($dataFile)) {
        $empty = [
            'version' => time(),
            'videos' => [],
            'channels' => [],
            'slots' => [],
            'publicLiveSchedule' => ['liveQueue' => []],
            'liveQueue' => [],
            'liveState' => [],
            'botState' => [
                'serverPublishStatus' => 'ERRORE',
                'serverPublishMessage' => 'DATA_FILE_CREATED_EMPTY_BY_SAVE_DATA'
            ],
            'botHistory' => []
        ];
        @file_put_contents($dataFile, json_encode($empty, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    if (!is_readable($dataFile)) {
        fail_json('DATA_FILE_NOT_READABLE: ' . $dataFile, 500);
    }

    if (!is_writable($dataFile)) {
        fail_json('DATA_FILE_NOT_WRITABLE: ' . $dataFile, 500);
    }

    if (!is_writable(dirname($dataFile))) {
        fail_json('DATA_DIR_NOT_WRITABLE: ' . dirname($dataFile), 500);
    }

    $merge = !empty($input['merge']);
    $preserveRuntime = !empty($input['preserveRuntime']);
    $existing = [];
    if (file_exists($dataFile)) {
        $existingRaw = file_get_contents($dataFile);
        if ($existingRaw !== false && trim($existingRaw) !== '') {
            $decoded = json_decode($existingRaw, true);
            if (is_array($decoded)) {
                $existing = $decoded;
            }
        }
    }

    $data = $merge ? deep_merge_replace_lists($existing, $patch) : $patch;

    if ($preserveRuntime && is_array($existing)) {
        $runtimeKeys = [
            'schedule', 'palinsesto', 'scheduleMeta', 'scheduleArchive',
            'internalSlotSchedule', 'publicLiveSchedule', 'liveQueue', 'liveState',
            'botHistory', 'airedLast30Days', 'lastBotPublishAt'
        ];
        foreach ($runtimeKeys as $key) {
            if (array_key_exists($key, $existing)) $data[$key] = $existing[$key];
        }
        if (isset($existing['botState']) && is_array($existing['botState'])) {
            $data['botState'] = $existing['botState'];
        }
    }

    $catalogKeys = ['videos', 'catalog', 'channels', 'series', 'seriesEpisodes', 'kids', 'videoLibrary'];
    $livePatchKeys = ['publicLiveSchedule', 'liveQueue', 'liveState', 'botState', 'botHistory', 'lastBotPublishAt', 'version'];
    $incomingKeys = array_keys($patch);
    $isLivePatchOnly = empty(array_diff($incomingKeys, $livePatchKeys));
    if ($isLivePatchOnly && is_array($existing)) {
        foreach ($catalogKeys as $k) {
            if (array_key_exists($k, $existing) && !array_key_exists($k, $data)) {
                $data[$k] = $existing[$k];
            }
        }
    }

    normalize_live_queues($data);
    strip_secrets($data);

    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        fail_json('JSON_ENCODE_FAILED: ' . json_last_error_msg());
    }

    $written = file_put_contents($tmp, $json, LOCK_EX);
    if ($written === false) {
        fail_json('TMP_WRITE_FAILED: ' . $tmp);
    }

    if (!rename($tmp, $dataFile)) {
        @unlink($tmp);
        fail_json('RENAME_FAILED_FROM_TMP_TO_DATA: ' . $tmp . ' -> ' . $dataFile, 500);
    }

    echo json_encode([
        'ok' => true,
        'bytes' => strlen($json),
        'file' => basename($dataFile),
        'dataFile' => $dataFile,
        'updatedAt' => date('c'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
} catch (Throwable $e) {
    error_log('[save-data.php] ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    fail_json($e->getMessage(), 500);
}
