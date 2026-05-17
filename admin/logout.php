<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';

$user = current_user();
if ($user !== null) {
    audit_log((int) $user['user_id'], 'admin_logout', 'users', (string) $user['user_id']);
}

logout_user();
redirect('login.php');
