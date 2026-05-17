<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';

header('Content-Type: application/json; charset=utf-8');

$user = current_user();

echo json_encode([
    'authenticated' => $user !== null,
    'user' => $user === null ? null : [
        'user_id' => (int) $user['user_id'],
        'email' => $user['email'],
        'name' => $user['name'],
        'roles' => $user['roles'],
    ],
], JSON_UNESCAPED_UNICODE);
