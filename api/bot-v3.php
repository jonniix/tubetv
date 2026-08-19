<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

define('V3_ROOT', dirname(__DIR__));
define('V3_DATA', V3_ROOT . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'tubetv-data.json');
define('V3_LOCK', V3_ROOT . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . '.bot-v3.lock');
require_once __DIR__ . '/bot-v3-engine.php';

function v3_config(): array { $p = V3_ROOT . '/private/config.php'; $c = is_file($p) ? include $p : []; return is_array($c) ? $c : []; }
function v3_auth(): bool {
    $c = v3_config(); $token = trim((string)($c['TUBETV_ADMIN_TOKEN'] ?? $c['ADMIN_TOKEN'] ?? ''));
    if ($token === '') return false;
    $given = trim((string)($_SERVER['HTTP_X_TUBETV_ADMIN'] ?? $_GET['token'] ?? ''));
    return $given !== '' && hash_equals($token, $given);
}
function v3_read(): array { $d = is_file(V3_DATA) ? json_decode((string)file_get_contents(V3_DATA), true) : []; return is_array($d) ? $d : []; }
function v3_write(array $data): bool {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false; $tmp = V3_DATA . '.v3.tmp';
    return file_put_contents($tmp, $json, LOCK_EX) !== false && rename($tmp, V3_DATA);
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$body = json_decode((string)file_get_contents('php://input'), true); if (!is_array($body)) $body = [];
$cliAction = PHP_SAPI === 'cli' ? (string)($argv[1] ?? '') : '';
$action = strtolower(trim((string)($cliAction !== '' ? $cliAction : ($body['action'] ?? $_GET['action'] ?? 'status'))));
$isCron = !empty($_GET['cron']); if ($isCron) $action = 'tick';
if ($method === 'GET' && $action === 'status' && !$isCron) { echo json_encode(v3_status(v3_read(), time()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
if (PHP_SAPI !== 'cli' && !v3_auth()) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'UNAUTHORIZED']); exit; }

$lock = fopen(V3_LOCK, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { http_response_code(409); echo json_encode(['ok' => false, 'error' => 'TICK_LOCKED']); exit; }
$data = v3_read(); $now = time();
if ($action === 'reset') { $data['botV3'] = ['enabled' => true, 'engineVersion' => 3, 'resetAt' => se_iso($now), 'tickSequence' => 0, 'recoveryCount' => 0]; $data['botV3Decisions'] = []; }
elseif ($action === 'enable') { $data['botV3'] = is_array($data['botV3'] ?? null) ? $data['botV3'] : []; $data['botV3']['enabled'] = true; $data['botV3']['enabledAt'] = se_iso($now); }
elseif ($action === 'disable') { $data['botV3'] = is_array($data['botV3'] ?? null) ? $data['botV3'] : []; $data['botV3']['enabled'] = false; $data['botV3']['disabledAt'] = se_iso($now); }
elseif ($action === 'save_settings') {
    $candidate = is_array($body['settings'] ?? null) ? $body['settings'] : [];
    $data['botV3Settings'] = se_bot_profile(['botV3Settings' => $candidate]);
}
elseif ($action === 'reset_settings') { $data['botV3Settings'] = se_bot_profile([]); }
elseif (!in_array($action, ['tick', 'sync_sources', 'force_rebuild_queue'], true)) { flock($lock, LOCK_UN); fclose($lock); http_response_code(400); echo json_encode(['ok' => false, 'error' => 'UNSUPPORTED_ACTION']); exit; }

$result = null;
if ($action !== 'disable') {
    $tickTrigger = in_array($action, ['save_settings', 'reset_settings'], true) ? 'force_rebuild_queue' : ($isCron ? 'cron' : $action);
    $result = v3_tick($data, $now, $tickTrigger);
}
$saved = v3_write($data); flock($lock, LOCK_UN); fclose($lock);
echo json_encode(array_merge(v3_status($data, $now), ['action' => $action, 'saved' => $saved, 'result' => $result]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
