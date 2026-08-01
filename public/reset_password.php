<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/frontend.php';

start_app_session();

if (current_user() !== null) {
    redirect('profile.php');
}

$errors = [];
$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$tokenHash = $token === '' ? '' : hash('sha256', $token);
$reset = null;

if ($tokenHash !== '') {
    $stmt = db()->prepare(
        'SELECT pr.*, u.email, u.name
         FROM password_resets pr
         INNER JOIN users u ON u.user_id = pr.user_id
         WHERE pr.token_hash = ?
           AND pr.used_at IS NULL
           AND pr.expires_at > NOW()
           AND u.is_active = 1
         LIMIT 1'
    );
    $stmt->execute([$tokenHash]);
    $reset = $stmt->fetch() ?: null;
}

if ($token === '' || $reset === null) {
    $errors[] = '密碼重設連結已失效，請重新申請。';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errors === []) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors[] = '表單驗證失敗，請重新操作。';
    }

    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

    if (strlen($password) < 8) {
        $errors[] = '新密碼至少需要 8 個字元。';
    }

    if ($password !== $passwordConfirm) {
        $errors[] = '兩次輸入的密碼不一致。';
    }

    if ($errors === []) {
        $pdo = db();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'UPDATE users
                 SET password_hash = ?, updated_at = NOW()
                 WHERE user_id = ?'
            );
            $stmt->execute([
                password_hash($password, PASSWORD_DEFAULT),
                (int) $reset['user_id'],
            ]);

            $stmt = $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE reset_id = ?');
            $stmt->execute([(int) $reset['reset_id']]);

            audit_log((int) $reset['user_id'], 'member_password_reset_complete', 'users', (string) $reset['user_id'], null, [
                'reset_id' => (int) $reset['reset_id'],
            ]);

            $pdo->commit();
            set_flash('密碼已更新，請使用新密碼登入。');
            redirect('login.php');
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = '密碼更新失敗，請稍後再試。';
        }
    }
}
?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>重設密碼</title>
    <link rel="stylesheet" href="../assets/css/main.css">
</head>
<body>
    <main class="page narrow">
        <h1>重設密碼</h1>

        <?php if ($errors !== []): ?>
            <div class="alert error">
                <?php foreach ($errors as $error): ?>
                    <p><?= e($error) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($reset !== null): ?>
            <form class="form" method="post" action="reset_password.php">
                <?= csrf_field() ?>
                <input type="hidden" name="token" value="<?= e($token) ?>">

                <p class="helper-text">帳號：<?= e((string) $reset['email']) ?></p>

                <label>
                    新密碼
                    <input type="password" name="password" minlength="8" required>
                </label>

                <label>
                    確認新密碼
                    <input type="password" name="password_confirm" minlength="8" required>
                </label>

                <button class="button" type="submit">更新密碼</button>
            </form>
        <?php endif; ?>

        <p class="helper-text"><a href="forgot_password.php">重新申請重設信</a></p>
    </main>
</body>
</html>
