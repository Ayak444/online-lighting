<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';

start_app_session();

if (current_user_has_role('admin')) {
    redirect('dashboard.php');
}

$errors = [];
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors[] = '表單已過期，請重新送出。';
    }

    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = '請輸入正確的 email。';
    }

    if ($password === '') {
        $errors[] = '請輸入密碼。';
    }

    if ($errors === []) {
        $user = authenticate_user($email, $password);

        if ($user === null) {
            $errors[] = '帳號或密碼錯誤。';
        } else {
            $roles = get_user_roles((int) $user['user_id']);

            if (!in_array('admin', $roles, true)) {
                $errors[] = '此帳號沒有後台管理權限。';
                audit_log((int) $user['user_id'], 'admin_login_denied', 'users', (string) $user['user_id']);
            } else {
                login_user($user);
                audit_log((int) $user['user_id'], 'admin_login', 'users', (string) $user['user_id']);
                redirect('dashboard.php');
            }
        }
    }
}
?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>後台管理登入</title>
    <link rel="stylesheet" href="../assets/css/main.css">
</head>
<body>
    <main class="page narrow">
        <h1>後台管理登入</h1>

        <?php if ($errors !== []): ?>
            <div class="alert error">
                <?php foreach ($errors as $error): ?>
                    <p><?= e($error) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form class="form" method="post" action="login.php">
            <?= csrf_field() ?>

            <label>
                Email
                <input type="email" name="email" value="<?= e($email) ?>" required>
            </label>

            <label>
                密碼
                <input type="password" name="password" required>
            </label>

            <button class="button" type="submit">進入後台</button>
        </form>

        <p class="helper-text"><a href="../public/login.php">回會員登入</a></p>
    </main>
</body>
</html>
