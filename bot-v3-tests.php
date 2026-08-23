<?php
declare(strict_types=1);

require_once __DIR__ . '/api/bot-v3-engine.php';
function v3_check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }

$path = getenv('TUBETV_TEST_DATA') ?: (__DIR__ . '/data/tubetv-data.json');
$data = json_decode((string)file_get_contents($path), true);
v3_check(is_array($data), 'JSON principale non leggibile');
$now = time();
$data['botV3'] = ['enabled' => true, 'lastTickAt' => se_iso($now - 600), 'tickSequence' => 0, 'recoveryCount' => 0];
$data['botV3Decisions'] = [];

$first = v3_tick($data, $now, 'test');
v3_check(!empty($first['ok']), 'Bot V3 non trova il programma corrente');
v3_check(count($first['queue'] ?? []) >= 1 && count($first['queue'] ?? []) <= 3, 'Micro-coda V3 non valida');
v3_check(($data['liveState']['currentChangedBy'] ?? '') === 'bot-v3', 'Autorità live non assegnata a V3');
v3_check((int)($data['liveState']['offset'] ?? -1) >= 0, 'Offset live negativo');
v3_check((int)($data['botV3']['recoveryCount'] ?? 0) === 1, 'Tick dopo dieci minuti non registrato come recupero');

$id = (string)($data['botV3']['currentVideoId'] ?? '');
$decisions = count($data['botV3Decisions'] ?? []);
$second = v3_tick($data, $now + 5, 'test');
v3_check(($data['botV3']['currentVideoId'] ?? '') === $id, 'Tick idempotente ha cambiato programma prima della scadenza');
v3_check(count($data['botV3Decisions'] ?? []) === $decisions, 'Tick idempotente ha duplicato la decisione');
v3_check((int)($data['botV3']['tickSequence'] ?? 0) === 2, 'Sequenza tick errata');

$status = v3_status($data, $now + 5);
v3_check(($status['engineVersion'] ?? 0) === 3 && !empty($status['enabled']), 'Status V3 incompleto');
v3_check(array_key_exists('cronActive', $status), 'Diagnostica cron assente');
v3_check(($status['futureScheduleMeta']['horizonHours'] ?? 0) === 72 && count($status['futureSchedule'] ?? []) > 0, 'Previsione dinamica 72 ore assente');
v3_check(($status['current']['videoId'] ?? '') === ($status['futureSchedule'][0]['videoId'] ?? ''), 'Live corrente diversa dalla previsione ufficiale');
v3_check(array_column($status['upcoming'] ?? [], 'videoId') === array_column(array_slice($status['futureSchedule'], 1, 2), 'videoId'), 'Prossimi due Live diversi dalla previsione ufficiale');
v3_check(count(array_filter(array_slice($status['futureSchedule'], 0, 3), static fn($item): bool => !empty($item['forecastLocked']))) === 3, 'Terna Live non bloccata nella previsione');
v3_check(($status['settings']['freshnessWeight'] ?? 0) === 100, 'Profilo attuale non caricato come default');
v3_check(($status['settings']['repeatCooldownDays'] ?? 0) === 30, 'Anti-replica default non corrisponde alla modalita attuale');
v3_check(($status['settings']['minDurationMinutes'] ?? 0) === 5 && ($status['settings']['maxDurationMinutes'] ?? 0) === 90, 'Durate default cambiate');
v3_check(($status['settings']['requireVerifiedItalianAudio'] ?? false) === true, 'Vincolo audio italiano non attivo');
v3_check(($status['supplyAnalytics']['windowDays'] ?? 0) === 30, 'Analisi editoriale non usa gli ultimi 30 giorni');
v3_check(array_key_exists('overallCoveragePercent', $status['supplyAnalytics'] ?? []), 'Copertura editoriale complessiva assente');
v3_check(is_array($status['supplyAnalytics']['sources'] ?? null) && is_array($status['supplyAnalytics']['slots'] ?? null), 'Analisi fonti o fasce assente');
v3_check(is_array($status['supplyAnalytics']['replicaVideos'] ?? null), 'Elenco video replicati assente');
v3_tick($data, $now + 10, 'cron');
$cronStatus = v3_status($data, $now + 10);
v3_check(!empty($cronStatus['cronActive']) && ($cronStatus['cronAgeSeconds'] ?? -1) === 0, 'Heartbeat cron V3 non rilevato');
$committedIds = array_column(array_slice($cronStatus['futureSchedule'], 0, 3), 'videoId');
$transitionAt = se_ts((string)($cronStatus['futureSchedule'][0]['endDateTime'] ?? '')) + 1;
v3_tick($data, $transitionAt, 'test');
$afterTransition = v3_status($data, $transitionAt);
$promotedIds = array_column(array_slice($afterTransition['futureSchedule'], 0, 3), 'videoId');
v3_check(($promotedIds[0] ?? '') === ($committedIds[1] ?? '') && ($promotedIds[1] ?? '') === ($committedIds[2] ?? ''), 'Passaggio Live non ha conservato i due impegni gia pubblicati');

$admin = (string)file_get_contents(__DIR__ . '/admin.html');
v3_check(strpos($admin, 'panel-bot-v3') !== false && strpos($admin, 'Bot Palinsesto ufficiale') !== false && strpos($admin, 'Come ha scelto') !== false, 'Dashboard del bot ufficiale assente');
v3_check(strpos($admin, 'Regole di creazione palinsesto') !== false && strpos($admin, 'saveBotV3Settings()') !== false && strpos($admin, 'bot-setting-categories') !== false, 'Controlli facili del profilo palinsesto assenti');
v3_check(strpos($admin, 'bot-v3-availability-status') !== false && strpos($admin, 'Video nascosti') !== false, 'Diagnostica disponibilita video assente');
v3_check(strpos($admin, 'bot-v3-future') !== false && strpos($admin, 'Previsione dinamica delle prossime 72 ore') !== false, 'Interfaccia previsione futura assente');
v3_check(strpos($admin, 'Intelligenza editoriale · copertura e repliche') !== false && strpos($admin, 'renderBotV3SupplyAnalytics') !== false, 'Console sostenibilita editoriale assente');
v3_check(strpos($admin, 'Rendimento delle fonti · ultimi 30 giorni') !== false && strpos($admin, 'Video che verranno replicati nelle 72 ore') !== false, 'Dettaglio fonti o repliche assente');
v3_check(strpos($admin, 'Solo audio italiano verificato (obbligatorio)') !== false, 'Admin non mostra il vincolo audio italiano');
v3_check(strpos($admin, 'TITOLO EN - AUDIO IT GARANTITO') !== false && strpos($admin, 'TRACCIA IT NON GARANTITA - ESCLUSO') !== false && strpos($admin, 'SUBENTRATO') !== false && strpos($admin, 'TITOLO IT UFFICIALE') !== false, 'Badge editoriali della previsione incompleti');
v3_check(strpos($admin, 'api/bot-tick.php') === false && strpos($admin, "location.href = 'bot.html") === false, 'Admin collegato a un bot legacy');
v3_check(!is_file(__DIR__ . '/api/bot-tick.php') && !is_file(__DIR__ . '/bot.html') && !is_file(__DIR__ . '/prova.html'), 'File runtime dei bot legacy ancora presenti');
$index = (string)file_get_contents(__DIR__ . '/index.html');
v3_check(strpos($index, 'api/bot-v3.php?action=tick') !== false && strpos($index, 'api/bot-tick.php') === false, 'Live Web non usa esclusivamente il bot ufficiale');
$endpoint = (string)file_get_contents(__DIR__ . '/api/bot-v3.php');
v3_check(strpos($endpoint, "\$action === 'save_settings'") !== false && strpos($endpoint, "\$action === 'reset_settings'") !== false, 'Endpoint impostazioni bot incompleto');
$scheduleEngine = (string)file_get_contents(__DIR__ . '/api/schedule-engine.php');
v3_check(strpos($scheduleEngine, 'se_refresh_video_availability') !== false && strpos($scheduleEngine, 'hiddenByAvailabilityBot') !== false, 'Controllo automatico dei video eliminati assente');
v3_check(strpos($scheduleEngine, 'se_refresh_italian_audio_verification') !== false && strpos($scheduleEngine, 'italianAudioTrackId') !== false, 'Verifica automatica audio italiano assente');

echo "bot-v3-tests PASS\n";
