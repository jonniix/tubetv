<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/api/schedule-engine.php';

function yt_check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$source = __DIR__ . '/tubetv-data (23).json';
$data = json_decode((string)file_get_contents($source), true);
yt_check(is_array($data), 'JSON sorgente non leggibile');
yt_check(!empty($data['channels']) && !empty($data['videos']), 'Il JSON non contiene fonti e video');

$apiKey = se_youtube_api_key();
yt_check($apiKey !== '', 'YOUTUBE_API_KEY mancante sul server');

// Lavora esclusivamente in memoria: il JSON dell'utente non viene scritto.
$channel = $data['channels'][0];
$before = se_collect_videos($data);
$beforeIds = array_fill_keys(array_map('se_video_id', $before), true);
$result = se_sync_youtube_channel($data, $channel, $apiKey, time());
yt_check(empty($result['error']), 'Sincronizzazione fallita: ' . (string)($result['error'] ?? 'errore sconosciuto'));
yt_check(!empty($channel['youtubeChannelId']), 'ID YouTube canonico non risolto');
yt_check(!empty($channel['uploadsPlaylistId']), 'Playlist upload non risolta');

$after = se_collect_videos($data);
$newDates = [];
foreach ($after as $video) {
    $id = se_video_id($video);
    if ($id !== '' && !isset($beforeIds[$id])) $newDates[] = (string)($video['publishedAt'] ?? '');
}
rsort($newDates, SORT_STRING);

echo json_encode([
    'ok' => true,
    'sourceFile' => basename($source),
    'channel' => (string)($channel['name'] ?? $channel['title'] ?? ''),
    'canonicalChannelResolved' => true,
    'uploadsPlaylistResolved' => true,
    'imported' => (int)($result['imported'] ?? 0),
    'updated' => (int)($result['updated'] ?? 0),
    'newestImportedAt' => $newDates[0] ?? null,
    'sourceFileModified' => false,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

