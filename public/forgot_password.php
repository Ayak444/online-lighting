<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/frontend.php';
require_once __DIR__ . '/../config/notification_delivery.php';

start_app_session();

if (current_user() !== null) {
    redirect('profile.php');
}

$errors = [];
$flash = flash_message();
$email = '';

function password_reset_public_base_url(): string
{
    global $integrationConfig;

    return rtrim((string) ($integrationConfig['app']['public_base_url'] ?? ''), '/');
}

function create_password_reset_notification(int $userId, string $email, string $resetUrl): int
{
    $subject = '線上點燈系統密碼重設';
    $content = "您好：\n\n請在 30 分鐘內開啟以下連結重設密碼：\n" . $resetUrl . "\n\n如果不是您本人操作，請忽略此信。";

    $stmt = db()->prepare(
        'INSERT INTO notifications (user_id, channel, subject, content, status)
         VALUES (?, "email", ?, ?, "pending")'
    );
    $stmt->execute([$userId, $subject, $content]);

    return (int) db()->lastInsertId();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors[] = '表單驗證失敗，請重新操作。';
    }

    $email = trim((string) ($_POST['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = '請輸入正確的 Email。';
    }

    if ($errors === []) {
        $stmt = db()->prepare(
            'SELECT user_id, email
             FROM users
             WHERE email = ?
               AND auth_provider = "local"
               AND is_active = 1
             LIMIT 1'
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            $token = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);
            $expiresAt = date('Y-m-d H:i:s', time() + 30 * 60);

            $pdo = db();
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare(
                    'UPDATE password_resets
                     SET used_at = COALESCE(used_at, NOW())
                     WHERE user_id = ?
                       AND used_at IS NULL'
                );
                $stmt->execute([(int) $user['user_id']]);

                $stmt = $pdo->prepare(
                    'INSERT INTO password_resets (user_id, token_hash, expires_at, requested_ip)
                     VALUES (?, ?, ?, ?)'
                );
                $stmt->execute([
                    (int) $user['user_id'],
                    $tokenHash,
                    $expiresAt,
                    $_SERVER['REMOTE_ADDR'] ?? null,
                ]);
                $resetId = (int) $pdo->lastInsertId();

                $resetUrl = password_reset_public_base_url() . '/reset_password.php?token=' . rawurlencode($token);
                $notifyId = create_password_reset_notification((int) $user['user_id'], (string) $user['email'], $resetUrl);
                audit_log((int) $user['user_id'], 'member_password_reset_request', 'password_resets', (string) $resetId, null, [
                    'email' => $user['email'],
                    'expires_at' => $expiresAt,
                    'notify_id' => $notifyId,
                ]);

                $pdo->commit();

                notification_process_by_id($notifyId, true);
            } catch (Throwable $throwable) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            }
        }

        set_flash('如果此 Email 有註冊帳號，系統已寄出密碼重設信。');
        redirect('forgot_password.php');
    }
}
?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>忘記密碼</title>
    <link rel="stylesheet" href="../assets/css/main.css">
</head>
<body>
    <main class="page narrow">
        <h1>忘記密碼</h1>

        <?php if ($flash): ?>
            <div class="alert success"><p><?= e($flash) ?></p></div>
        <?php endif; ?>

        <?php if ($errors !== []): ?>
            <div class="alert error">
                <?php foreach ($errors as $error): ?>
                    <p><?= e($error) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form class="form" method="post" action="forgot_password.php">
            <?= csrf_field() ?>
            <label>
                註冊 Email
                <input type="email" name="email" value="<?= e($email) ?>" required>
            </label>
            <button class="button" type="submit">寄出重設密碼信</button>
        </form>

        <p class="helper-text"><a href="login.php">回會員登入</a></p>
    </main>
</body>
</html>
