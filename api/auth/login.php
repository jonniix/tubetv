<?php
declare(strict_types=1);

require __DIR__ . '/_lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    auth_json_response(['ok' => false, 'error' => 'Metodo non consentito.'], 405);
}

$input = auth_request_data();
$email = auth_normalize_email($input['email'] ?? '');
$password = (string) ($input['password'] ?? '');
if (!auth_rate_limit('login')) auth_json_response(['ok' => false, 'error' => 'Troppi tentativi. Riprova più tardi.'], 429);

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    auth_json_response(['ok' => false, 'error' => 'Email non valida.'], 422);
}

if ($password === '') {
    auth_json_response(['ok' => false, 'error' => 'Inserisci la password.'], 422);
}

$users = auth_read_users();
$index = auth_find_user_index_by_email($users, $email);
if ($index < 0) {
    auth_rate_limit('login', false);
    auth_json_response(['ok' => false, 'error' => 'Credenziali non valide.'], 401);
}

$user = $users[$index];
if (!password_verify($password, (string) ($user['passwordHash'] ?? ''))) {
    auth_rate_limit('login', false);
    auth_json_response(['ok' => false, 'error' => 'Credenziali non valide.'], 401);
}

$users[$index]['updatedAt'] = gmdate('c');
if (password_needs_rehash((string)($user['passwordHash'] ?? ''), PASSWORD_DEFAULT)) $users[$index]['passwordHash'] = password_hash($password, PASSWORD_DEFAULT);
auth_save_users($users);
auth_rate_limit('login', true);

session_regenerate_id(true);
$_SESSION['user_id'] = $user['id'];

$user['updatedAt'] = $users[$index]['updatedAt'];
auth_json_response(['ok' => true, 'user' => auth_public_user($user)]);
