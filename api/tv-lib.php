<?php
declare(strict_types=1);
require_once __DIR__ . '/auth/_lib.php';

function tv_pairings_path(): string { $custom = trim((string)getenv('TUBETV_TV_PAIRINGS_PATH')); return $custom !== '' ? $custom : auth_private_dir() . DIRECTORY_SEPARATOR . 'tv-pairings.json'; }
function tv_devices_path(): string { $custom = trim((string)getenv('TUBETV_TV_DEVICES_PATH')); return $custom !== '' ? $custom : auth_private_dir() . DIRECTORY_SEPARATOR . 'tv-devices.json'; }
function tv_devices_lock() {
    $path = tv_devices_path() . '.lock'; $dir = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $handle = @fopen($path, 'c');
    if (!$handle || !@flock($handle, LOCK_EX)) auth_json_response(['ok' => false, 'error' => 'TV_STORAGE_BUSY'], 503);
    return $handle;
}
function tv_devices_unlock($handle): void { if (is_resource($handle)) { @flock($handle, LOCK_UN); @fclose($handle); } }
function tv_read_file(string $path): array { $data = is_file($path) ? json_decode((string)@file_get_contents($path), true) : []; return is_array($data) ? $data : []; }
function tv_write_file(string $path, array $data): void { $dir = dirname($path); if (!is_dir($dir)) @mkdir($dir, 0700, true); $tmp = $path . '.tmp'; @file_put_contents($tmp, json_encode(array_values($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX); @rename($tmp, $path); @chmod($path, 0600); }
function tv_random_hex(int $bytes): string { return bin2hex(random_bytes($bytes)); }
function tv_clean_id(string $value): string { $value = strtolower(trim($value)); return preg_match('/^[a-z0-9_-]{24,96}$/', $value) ? $value : ''; }
function tv_clean_name(string $value): string { $value = trim(preg_replace('/[^\pL\pN ._()\/-]+/u', '', $value) ?? ''); return substr($value !== '' ? $value : 'TubeTV', 0, 100); }
function tv_active_pairings(): array { $now = time(); return array_values(array_filter(tv_read_file(tv_pairings_path()), fn($item) => (int)($item['expiresAt'] ?? 0) > $now)); }
function tv_find_pair(array $items, string $id): int { foreach ($items as $i => $item) if ((string)($item['id'] ?? '') === $id) return (int)$i; return -1; }
function tv_find_device(array $items, string $id): int { foreach ($items as $i => $item) if ((string)($item['id'] ?? '') === $id) return (int)$i; return -1; }
function tv_device_trusted(array $device): bool { return (string)($device['userId'] ?? '') !== '' && (string)($device['status'] ?? '') === 'active'; }
function tv_public_device(array $device): array { return ['id' => (string)$device['id'], 'name' => (string)$device['name'], 'status' => (string)$device['status'], 'createdAt' => (string)$device['createdAt'], 'lastSeenAt' => (string)($device['lastSeenAt'] ?? ''), 'online' => (int)($device['lastSeenUnix'] ?? 0) > time() - 15]; }
