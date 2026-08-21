<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode([
    'ok' => true,
    'service' => 'TubeTV Host',
    'version' => 1,
    'time' => gmdate('c'),
], JSON_UNESCAPED_SLASHES);

