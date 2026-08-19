<?php
declare(strict_types=1);
require __DIR__ . '/_lib.php';

$users = auth_read_users(); $current = auth_require_user($users);
$index = auth_find_user_index_by_id($users, (string)$current['id']);
if ($index < 0) auth_json_response(['ok' => false, 'error' => 'Account non trovato.'], 404);
$preferences = array_replace(auth_default_preferences(), is_array($users[$index]['preferences'] ?? null) ? $users[$index]['preferences'] : []);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    auth_json_response(['ok' => true, 'favorites' => array_values((array)$preferences['iptvFavorites']), 'recent' => array_values((array)$preferences['iptvRecent'])]);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') auth_json_response(['ok' => false, 'error' => 'Metodo non consentito.'], 405);
$input = auth_request_data();
$clean = function($items, int $limit): array {
    if (!is_array($items)) return [];
    $out = []; foreach ($items as $item) { $id = preg_replace('/[^0-9]/', '', (string)$item); if ($id !== '' && !in_array($id, $out, true)) $out[] = $id; if (count($out) >= $limit) break; }
    return $out;
};
$preferences['iptvFavorites'] = $clean($input['favorites'] ?? [], 500);
$preferences['iptvRecent'] = $clean($input['recent'] ?? [], 50);
$users[$index]['preferences'] = $preferences; $users[$index]['updatedAt'] = gmdate('c'); auth_save_users($users);
auth_json_response(['ok' => true, 'favorites' => $preferences['iptvFavorites'], 'recent' => $preferences['iptvRecent']]);
