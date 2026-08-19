<?php
declare(strict_types=1);

require __DIR__ . '/_lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    auth_json_response(['ok' => false, 'error' => 'Metodo non consentito.'], 405);
}

$input = auth_request_data();
$name = auth_trim_string($input['name'] ?? '');
$email = auth_normalize_email($input['email'] ?? '');
$password = (string) ($input['password'] ?? '');
$confirmPassword = (string) ($input['confirmPassword'] ?? $input['passwordConfirm'] ?? '');
$consent = filter_var($input['consent'] ?? $input['privacyConsent'] ?? false, FILTER_VALIDATE_BOOLEAN);

if (!auth_rate_limit('register')) auth_json_response(['ok' => false, 'error' => 'Troppi tentativi. Riprova più tardi.'], 429);
auth_rate_limit('register', false);

if ($name === '' || auth_string_length($name) < 2) {
    auth_json_response(['ok' => false, 'error' => 'Inserisci un nome valido.'], 422);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    auth_json_response(['ok' => false, 'error' => 'Inserisci una email valida.'], 422);
}

if (auth_string_length($password) < 8) {
    auth_json_response(['ok' => false, 'error' => 'La password deve contenere almeno 8 caratteri.'], 422);
}

if ($password !== $confirmPassword) {
    auth_json_response(['ok' => false, 'error' => 'Le password non coincidono.'], 422);
}

if (!$consent) {
    auth_json_response(['ok' => false, 'error' => 'Devi accettare termini e privacy.'], 422);
}

$users = auth_read_users();
if (auth_find_user_index_by_email($users, $email) !== -1) {
    auth_json_response(['ok' => false, 'error' => 'Esiste già un account con questa email.'], 409);
}

$user = auth_build_user_record([
    'name' => $name,
    'email' => $email,
    'passwordHash' => password_hash($password, PASSWORD_DEFAULT),
    'language' => $input['language'] ?? 'it',
    'preferences' => [
        'language' => auth_trim_string($input['language'] ?? 'it') ?: 'it'
    ]
]);

$users[] = $user;
auth_save_users($users);
auth_rate_limit('register', true);

session_regenerate_id(true);
$_SESSION['user_id'] = $user['id'];

auth_json_response(['ok' => true, 'user' => auth_public_user($user)]);
