<?php
declare(strict_types=1);
require __DIR__ . '/api/iptv-lib.php';
function iptv_check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$sample = "#EXTM3U\n#EXTINF:-1 tvg-id=\"rai1\" tvg-logo=\"https://images.example.com/rai.png\" group-title=\"Italia\",Rai 1 HD\nhttps://stream.example.com/live/rai1/index.m3u8\n#EXTINF:-1 group-title=\"News\",News 24\nhttps://stream.example.com/live/news/index.m3u8\n#EXTINF:-1,Locale\nfile:///tmp/private.ts\n";
$channels = iptv_parse_m3u($sample);
iptv_check(count($channels) === 2, 'M3U safe channel count');
iptv_check($channels[0]['name'] === 'Rai 1 HD' && $channels[0]['group'] === 'Italia' && $channels[0]['tvgId'] === 'rai1', 'M3U metadata');
$largePath = tempnam(sys_get_temp_dir(), 'tubetv_iptv_test_');
iptv_check(is_string($largePath) && $largePath !== '', 'Large M3U temporary file');
if (is_string($largePath) && $largePath !== '') {
    $largeHandle = fopen($largePath, 'wb');
    iptv_check(is_resource($largeHandle), 'Large M3U temporary file opened');
    if (is_resource($largeHandle)) {
        fwrite($largeHandle, "#EXTM3U\n");
        for ($i = 0; $i < 20000; $i++) {
            fwrite($largeHandle, '#EXTINF:-1 group-title="Test",Canale ' . $i . "\n");
            fwrite($largeHandle, 'https://stream.example.com/channel-' . $i . ".m3u8\n");
        }
        fclose($largeHandle);
        $largeChannels = iptv_parse_m3u_file($largePath, 50000);
        iptv_check(count($largeChannels) === 20000, 'Large M3U incremental parsing');
    }
    @unlink($largePath);
}
iptv_check(!iptv_url_allowed('http://127.0.0.1/private.m3u8'), 'loopback accepted');
iptv_check(!iptv_url_allowed('file:///tmp/private.m3u8'), 'file URL accepted');
iptv_check(iptv_url_allowed('https://stream.example.com/live.m3u8'), 'HTTPS rejected');
iptv_check(iptv_resolve_url('https://stream.example.com/path/master.m3u8', 'segment/1.ts') === 'https://stream.example.com/path/segment/1.ts', 'relative URL resolution');
iptv_check(iptv_stream_format('https://stream.example.com/live/123.ts') === 'mpegts', 'MPEG-TS live detection');
iptv_check(iptv_stream_format('https://stream.example.com/movie/123.mp4') === 'native', 'Native VOD detection');
iptv_check(iptv_stream_format('https://stream.example.com/live/123.m3u8') === 'hls', 'HLS live detection');
iptv_check(iptv_normalize_remote_url('https://images.example.com/base/list.m3u', '/logos/rai.png') === 'https://images.example.com/logos/rai.png', 'Relative logo URL resolution');
iptv_check(iptv_normalize_remote_url('https://images.example.com/list.m3u', '//cdn.example.com/logo.png') === 'https://cdn.example.com/logo.png', 'Protocol-relative logo URL resolution');
$epgM3uPath = tempnam(sys_get_temp_dir(), 'tubetv_epg_test_');
if (is_string($epgM3uPath)) { file_put_contents($epgM3uPath, '#EXTM3U x-tvg-url="https://guide.example.com/epg.xml.gz"' . "\n"); iptv_check(iptv_m3u_epg_url_file($epgM3uPath, 'https://provider.example/list.m3u') === 'https://guide.example.com/epg.xml.gz', 'M3U embedded EPG discovery'); @unlink($epgM3uPath); }
$config = iptv_default_config(); $config['accessPinHash'] = password_hash('6594', PASSWORD_DEFAULT);
iptv_check(iptv_verify_pin($config, '6594') && !iptv_verify_pin($config, '0000'), 'PIN verification');
$deviceTestPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tubetv-device-test-' . bin2hex(random_bytes(4)) . '.json';
putenv('TUBETV_IPTV_DEVICES_PATH=' . $deviceTestPath);
$deviceRecord = iptv_register_device('user_test', str_repeat('a', 32), 'Browser test');
iptv_check(($deviceRecord['status'] ?? '') === 'pending', 'New IPTV device starts pending');
$testDevices = iptv_read_devices(); $testDevices[0]['status'] = 'approved'; iptv_save_devices($testDevices);
iptv_check(iptv_device_approved((string)$deviceRecord['id'], 'user_test'), 'Approved IPTV device is accepted');
@unlink($deviceTestPath); putenv('TUBETV_IPTV_DEVICES_PATH');
$public = (string)file_get_contents(__DIR__ . '/data/tubetv-data.json');
iptv_check(stripos($public, 'iptv-config') === false, 'private config leaked');
echo "iptv-tests PASS\n";
