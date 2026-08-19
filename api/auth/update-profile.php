<?php
declare(strict_types=1);

require __DIR__ . '/_lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    auth_json_response(['ok' => false, 'error' => 'Metodo non consentito.'], 405);
}

$input = auth_request_data();
$users = auth_read_users();
$currentUser = auth_require_user($users);
$index = auth_find_user_index_by_id($users, (string) $currentUser['id']);
if ($index < 0) {
    auth_json_response(['ok' => false, 'error' => 'Utente non trovato.'], 404);
}

$user = $users[$index];
$changedEmail = false;

if (array_key_exists('name', $input)) {
    $name = auth_trim_string($input['name']);
    if ($name === '' || mb_strlen($name) < 2) {
        auth_json_response(['ok' => false, 'error' => 'Inserisci un nome valido.'], 422);
    }
    $user['name'] = $name;
}

if (array_key_exists('email', $input)) {
    $email = auth_normalize_email($input['email']);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        auth_json_response(['ok' => false, 'error' => 'Inserisci una email valida.'], 422);
    }
    $otherIndex = auth_find_user_index_by_email($users, $email);
    if ($otherIndex !== -1 && $otherIndex !== $index) {
        auth_json_response(['ok' => false, 'error' => 'Questa email è già in uso.'], 409);
    }
    $user['email'] = $email;
    $changedEmail = true;
}

if (array_key_exists('language', $input)) {
    $language = auth_trim_string($input['language']);
    $user['language'] = $language !== '' ? $language : 'it';
}

$preferences = is_array($user['preferences'] ?? null) ? $user['preferences'] : auth_default_preferences();
if (array_key_exists('subtitlesDefault', $input)) {
    $preferences['subtitlesDefault'] = filter_var($input['subtitlesDefault'], FILTER_VALIDATE_BOOLEAN);
}
if (array_key_exists('autoplay', $input)) {
    $preferences['autoplay'] = filter_var($input['autoplay'], FILTER_VALIDATE_BOOLEAN);
}
if (array_key_exists('theme', $input)) {
    $theme = auth_trim_string($input['theme']);
    if ($theme !== '') {
        $preferences['theme'] = $theme;
    }
}
if (array_key_exists('preferences', $input) && is_array($input['preferences'])) {
    foreach (['language', 'subtitlesDefault', 'autoplay', 'theme'] as $key) {
        if (array_key_exists($key, $input['preferences'])) {
            $preferences[$key] = $input['preferences'][$key];
        }
    }
}
$user['preferences'] = array_replace(auth_default_preferences(), $preferences);

$subscription = is_array($user['subscription'] ?? null) ? $user['subscription'] : auth_default_subscription();
if (array_key_exists('subscription', $input) && is_array($input['subscription'])) {
    foreach (['plan', 'adsDisabled', 'startedAt', 'expiresAt'] as $key) {
        if (array_key_exists($key, $input['subscription'])) {
            $subscription[$key] = $input['subscription'][$key];
        }
    }
}
$user['subscription'] = array_replace(auth_default_subscription(), $subscription);

$user['updatedAt'] = gmdate('c');
$users[$index] = $user;
auth_save_users($users);

if ($changedEmail) {
    $_SESSION['user_id'] = $user['id'];
}

auth_json_response(['ok' => true, 'user' => auth_public_user($user)]);
