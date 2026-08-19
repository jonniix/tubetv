<?php
declare(strict_types=1);
require __DIR__ . '/iptv-lib.php';

$token = trim((string)($_GET['session'] ?? ''));
$channelId = trim((string)($_GET['channel'] ?? ''));
$session = iptv_load_session($token);
$url = $session ? (string)($session['channels'][$channelId] ?? '') : '';
if (!$session) { http_response_code(401); exit('IPTV_SESSION_EXPIRED'); }
if ($channelId === '' || $url === '' || !iptv_url_allowed($url)) { http_response_code(404); exit('IPTV_STREAM_NOT_FOUND'); }
if (!function_exists('proc_open')) { http_response_code(503); exit('IPTV_TRANSCODER_UNAVAILABLE'); }

$configuredFfmpeg = trim((string)(getenv('IPTV_FFMPEG_PATH') ?: ''));
$candidates = array_values(array_unique(array_filter([
    $configuredFfmpeg,
    PHP_OS_FAMILY === 'Windows' ? 'C:\\ffmpeg\\ffmpeg-7.0.1\\bin\\ffmpeg.exe' : '/usr/bin/ffmpeg',
    PHP_OS_FAMILY === 'Windows' ? 'C:\\ffmpeg\\bin\\ffmpeg.exe' : '/usr/local/bin/ffmpeg',
    'ffmpeg',
])));
$ffmpeg = 'ffmpeg';
foreach ($candidates as $candidate) {
    if ($candidate === 'ffmpeg' || (is_file($candidate) && is_executable($candidate))) { $ffmpeg = $candidate; break; }
}
$command = [
    $ffmpeg, '-nostdin', '-hide_banner', '-loglevel', 'error',
    '-user_agent', 'Mozilla/5.0 TubeTV/1.0',
    '-rw_timeout', '15000000', '-reconnect', '1', '-reconnect_streamed', '1', '-reconnect_delay_max', '5',
    '-i', $url,
    '-map', '0:v:0', '-map', '0:a:0?',
    '-sn', '-dn',
    '-vf', "scale=w='trunc(min(1920,iw)/2)*2':h=-2",
    '-c:v', 'libx264', '-preset', 'veryfast', '-tune', 'zerolatency',
    '-pix_fmt', 'yuv420p', '-profile:v', 'main', '-level', '4.1',
    '-c:a', 'aac', '-ac', '2', '-b:a', '128k',
    '-max_muxing_queue_size', '2048',
    '-movflags', 'frag_keyframe+empty_moov+default_base_moof',
    '-f', 'mp4', 'pipe:1',
];
$pipes = [];
$process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
if (!is_resource($process)) { http_response_code(503); exit('IPTV_TRANSCODER_START_FAILED'); }
@fclose($pipes[0]);
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

// Do not answer 200/video for an FFmpeg process that immediately failed: an
// empty MP4 makes browsers show only the crossed-out player control.
$firstChunk = '';
$stderr = '';
$startupDeadline = microtime(true) + 20.0;
while ($firstChunk === '' && microtime(true) < $startupDeadline && !connection_aborted()) {
    $read = [$pipes[1], $pipes[2]]; $write = null; $except = null;
    $ready = @stream_select($read, $write, $except, 0, 250000);
    if ($ready !== false && $ready > 0) {
        foreach ($read as $stream) {
            $chunk = (string)@fread($stream, 262144);
            if ($stream === $pipes[1]) $firstChunk .= $chunk;
            else $stderr .= $chunk;
        }
    }
    $status = proc_get_status($process);
    if (!$status['running'] && feof($pipes[1])) break;
}
if ($firstChunk === '') {
    @proc_terminate($process); @fclose($pipes[1]); @fclose($pipes[2]); @proc_close($process);
    http_response_code(502);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    $reason = strtolower($stderr);
    if (str_contains($reason, 'not found') || str_contains($reason, 'no such file')) exit('IPTV_SOURCE_NOT_FOUND');
    if (str_contains($reason, '401') || str_contains($reason, '403')) exit('IPTV_SOURCE_DENIED');
    exit('IPTV_TRANSCODE_FAILED');
}
stream_set_blocking($pipes[1], true);

@set_time_limit(0);
while (ob_get_level() > 0) @ob_end_flush();
header('Content-Type: video/mp4');
header('Content-Disposition: inline; filename="tubetv-film.mp4"');
header('Cache-Control: no-store');
header('X-Accel-Buffering: no');
header('X-Content-Type-Options: nosniff');
echo $firstChunk; flush();

while (!feof($pipes[1]) && !connection_aborted()) {
    $chunk = fread($pipes[1], 262144);
    if ($chunk === false) break;
    if ($chunk !== '') { echo $chunk; flush(); }
    @fread($pipes[2], 8192);
}
@fclose($pipes[1]);
@fclose($pipes[2]);
if (connection_aborted()) @proc_terminate($process);
@proc_close($process);
