<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

date_default_timezone_set('Asia/Taipei');

function start_app_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name('online_lighting_session');
    session_set_cookie_params(0, '/', '', false, true);
    session_start();
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function csrf_token(): string
{
    start_app_session();

    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['_csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf_token(?string $token): bool
{
    start_app_session();

    if (!is_string($token) || $token === '') {
        return false;
    }

    return hash_equals((string) ($_SESSION['_csrf_token'] ?? ''), $token);
}

function get_user_roles(int $userId): array
{
    $stmt = db()->prepare(
        'SELECT r.role_name
         FROM user_roles ur
         INNER JOIN roles r ON r.role_id = ur.role_id
         WHERE ur.user_id = ?
         ORDER BY r.role_name'
    );
    $stmt->execute([$userId]);

    return array_map(static function (array $row): string {
        return $row['role_name'];
    }, $stmt->fetchAll());
}

function find_user_by_id(int $userId): ?array
{
    $stmt = db()->prepare(
        'SELECT user_id, email, name, gender, phone, address, birthday, birth_clock_time, lunar_birthday, zodiac, annual_reminder,
                auth_provider, provider_id, is_active
         FROM users
         WHERE user_id = ? AND is_active = 1
         LIMIT 1'
    );
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    return $user ?: null;
}

function current_user(): ?array
{
    start_app_session();

    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $user = find_user_by_id((int) $_SESSION['user_id']);
    if ($user === null) {
        logout_user();
        return null;
    }

    $user['roles'] = get_user_roles((int) $user['user_id']);
    $_SESSION['roles'] = $user['roles'];

    return $user;
}

function current_user_has_role(string $roleName): bool
{
    start_app_session();

    if (empty($_SESSION['user_id'])) {
        return false;
    }

    $roles = $_SESSION['roles'] ?? get_user_roles((int) $_SESSION['user_id']);
    $_SESSION['roles'] = $roles;

    return in_array($roleName, $roles, true);
}

function authenticate_user(string $email, string $password): ?array
{
    $stmt = db()->prepare(
        'SELECT user_id, email, password_hash, name, is_active
         FROM users
         WHERE email = ? AND auth_provider = "local"
         LIMIT 1'
    );
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || (int) $user['is_active'] !== 1) {
        return null;
    }

    if (!password_verify($password, $user['password_hash'])) {
        return null;
    }

    return $user;
}

function find_active_users_by_phone(string $phone): array
{
    $stmt = db()->prepare(
        'SELECT user_id, email, name, phone, is_active
         FROM users
         WHERE phone = ?
           AND is_active = 1
         ORDER BY user_id ASC
         LIMIT 2'
    );
    $stmt->execute([$phone]);

    return $stmt->fetchAll();
}

function login_user(array $user): void
{
    start_app_session();
    session_regenerate_id(true);

    $userId = (int) $user['user_id'];
    $_SESSION['user_id'] = $userId;
    $_SESSION['user_name'] = (string) $user['name'];
    $_SESSION['roles'] = get_user_roles($userId);
}

function logout_user(): void
{
    start_app_session();
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
    }

    session_destroy();
}

function require_login(string $redirectPath = 'login.php'): array
{
    $user = current_user();
    if ($user === null) {
        redirect($redirectPath);
    }

    return $user;
}

function require_admin(string $redirectPath = 'login.php'): array
{
    $user = require_login($redirectPath);
    if (!in_array('admin', $user['roles'], true)) {
        http_response_code(403);
        exit('Forbidden');
    }

    return $user;
}

function audit_log(?int $userId, string $action, ?string $tableName = null, ?string $recordId = null, ?array $beforeData = null, ?array $afterData = null): void
{
    $stmt = db()->prepare(
        'INSERT INTO audit_logs (user_id, action, table_name, record_id, before_data, after_data, ip_address, user_agent)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $stmt->execute([
        $userId,
        $action,
        $tableName,
        $recordId,
        $beforeData === null ? null : json_encode($beforeData, JSON_UNESCAPED_UNICODE),
        $afterData === null ? null : json_encode($afterData, JSON_UNESCAPED_UNICODE),
        $_SERVER['REMOTE_ADDR'] ?? null,
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
    ]);
}
