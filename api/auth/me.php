<?php
declare(strict_types=1);

require __DIR__ . '/_lib.php';

$user = auth_current_user();
auth_json_response(['ok' => true, 'user' => $user]);
