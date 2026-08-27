<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
$data = request_data();
$action = (string)($data['action'] ?? '');
$code = valid_code($data['code'] ?? '');
if ($action === 'join') enforce_join_rate_limit();

$result = with_session($code, function (array &$session) use ($action, $data): array {
    if ($action === 'info') {
        return ['ok' => true, 'hostRegistered' => !empty($session['hostRegistered']), 'challenge' => (string)($session['challenge'] ?? '')];
    }
    if ($action === 'register_host') {
        if (!safe_equal($session['token'], $data['token'] ?? '')) json_response(['message' => 'Autorizzazione host non valida'], 403);
        $session['hostRegistered'] = true; $session['hostSeenAt'] = time();
        return ['ok' => true];
    }
    if ($action === 'join') {
        if (empty($session['hostRegistered'])) json_response(['message' => 'Il PC non è ancora pronto'], 409);
        $viewerId = bin2hex(random_bytes(12));
        $session['viewers'][$viewerId] = ['lastSeen' => time()];
        $session['messages'][$viewerId] = [];
        $proof = strtolower((string)($data['proof'] ?? ''));
        if ($proof !== '' && !preg_match('/^[a-f0-9]{64}$/', $proof)) json_response(['message' => 'Prova di accesso non valida'], 400);
        queue_message($session, 'host', ['type' => 'viewer_join', 'viewerId' => $viewerId, 'viewers' => count($session['viewers']), 'proof' => $proof, 'challenge' => (string)($session['challenge'] ?? '')]);
        return ['viewerId' => $viewerId, 'challenge' => (string)($session['challenge'] ?? '')];
    }
    $role = (string)($data['role'] ?? '');
    $recipient = '';
    if ($role === 'host') {
        if (!safe_equal($session['token'], $data['token'] ?? '')) json_response(['message' => 'Host non autorizzato'], 403);
        $session['hostSeenAt'] = time(); $recipient = 'host';
    } elseif ($role === 'viewer') {
        $viewerId = (string)($data['viewerId'] ?? '');
        if (!isset($session['viewers'][$viewerId])) json_response(['message' => 'Display non autorizzato'], 403);
        $session['viewers'][$viewerId]['lastSeen'] = time(); $recipient = $viewerId;
    } else json_response(['message' => 'Ruolo non valido'], 400);

    if ($action === 'poll') {
        $messages = $session['messages'][$recipient] ?? [];
        $session['messages'][$recipient] = [];
        return ['messages' => $messages];
    }
    if ($action === 'send') {
        if ($role === 'host') {
            $target = (string)($data['to'] ?? '');
            if (!isset($session['viewers'][$target])) json_response(['message' => 'Display non trovato'], 404);
            queue_message($session, $target, ['type' => 'signal', 'from' => 'host', 'data' => $data['data'] ?? null]);
        } else queue_message($session, 'host', ['type' => 'signal', 'from' => $recipient, 'data' => $data['data'] ?? null]);
        return ['ok' => true];
    }
    if ($action === 'leave') {
        if ($role === 'host') { $session['hostRegistered'] = false; }
        else {
            unset($session['viewers'][$recipient], $session['messages'][$recipient]);
            queue_message($session, 'host', ['type' => 'viewer_left', 'viewerId' => $recipient, 'viewers' => count($session['viewers'])]);
        }
        return ['ok' => true];
    }
    json_response(['message' => 'Azione non valida'], 400);
});
json_response($result);
