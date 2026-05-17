<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/frontend.php';
require_once __DIR__ . '/../config/external_auth.php';

start_app_session();

if (current_user() !== null) {
    redirect('profile.php');
}

$errors = [];
$flash = flash_message();
$phone = trim((string) ($_POST['phone'] ?? $_SESSION['_phone_login_phone'] ?? ''));
$demoCode = $_SESSION['_phone_login_demo_code'] ?? null;
$demoPhone = $_SESSION['_phone_login_demo_phone'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors[] = '表單驗證失敗，請重新送出。';
    }

    $action = trim((string) ($_POST['action'] ?? ''));

    if ($errors === [] && $action === 'request_code') {
        if ($phone === '') {
            $errors[] = '請輸入手機號碼。';
        }

        $users = $phone === '' ? [] : find_active_users_by_phone($phone);
        if ($errors === [] && count($users) !== 1) {
            $errors[] = '找不到唯一對應的會員手機，請改用 Email 登入或先確認會員資料。';
        }

        if ($errors === []) {
            $code = (string) random_int(100000, 999999);
            $stmt = db()->prepare(
                'INSERT INTO phone_login_codes (
                     user_id,
                     phone,
                     code_hash,
                     expires_at,
                     requested_ip
                 ) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 5 MINUTE), ?)'
            );
            $stmt->execute([
                (int) $users[0]['user_id'],
                $phone,
                password_hash($code, PASSWORD_DEFAULT),
                $_SERVER['REMOTE_ADDR'] ?? null,
            ]);

            $_SESSION['_phone_login_phone'] = $phone;
            if (auth_phone_demo_code_preview_enabled()) {
                $_SESSION['_phone_login_demo_code'] = $code;
                $_SESSION['_phone_login_demo_phone'] = $phone;
            }

            audit_log((int) $users[0]['user_id'], 'member_phone_login_code_request', 'phone_login_codes', (string) db()->lastInsertId(), null, [
                'phone' => $phone,
            ]);

            set_flash('手機登入驗證碼已建立，請輸入收到的 6 位數驗證碼。');
            redirect('phone_login.php');
        }
    }

    if ($errors === [] && $action === 'verify_code') {
        $code = trim((string) ($_POST['code'] ?? ''));
        if ($phone === '' || !preg_match('/^\d{6}$/', $code)) {
            $errors[] = '請輸入正確的手機與 6 位數驗證碼。';
        }

        if ($errors === []) {
            $stmt = db()->prepare(
                'SELECT plc.*, u.user_id, u.email, u.name, u.is_active
                 FROM phone_login_codes plc
                 INNER JOIN users u ON u.user_id = plc.user_id
                 WHERE plc.phone = ?
                   AND plc.verified_at IS NULL
                   AND plc.expires_at >= NOW()
                 ORDER BY plc.code_id DESC
                 LIMIT 1'
            );
            $stmt->execute([$phone]);
            $loginCode = $stmt->fetch();

            if (!$loginCode || (int) $loginCode['is_active'] !== 1) {
                $errors[] = '驗證碼已過期，請重新申請。';
            } elseif ((int) $loginCode['attempt_count'] >= 5) {
                $errors[] = '驗證次數已達上限，請重新申請驗證碼。';
            } elseif (!password_verify($code, (string) $loginCode['code_hash'])) {
                $stmt = db()->prepare('UPDATE phone_login_codes SET attempt_count = attempt_count + 1 WHERE code_id = ?');
                $stmt->execute([(int) $loginCode['code_id']]);
                $errors[] = '驗證碼不正確。';
            } else {
                $stmt = db()->prepare('UPDATE phone_login_codes SET verified_at = NOW() WHERE code_id = ?');
                $stmt->execute([(int) $loginCode['code_id']]);

                login_user($loginCode);
                audit_log((int) $loginCode['user_id'], 'member_phone_login', 'phone_login_codes', (string) $loginCode['code_id']);

                unset(
                    $_SESSION['_phone_login_phone'],
                    $_SESSION['_phone_login_demo_code'],
                    $_SESSION['_phone_login_demo_phone']
                );

                redirect('profile.php');
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
    <title>手機登入</title>
    <link rel="stylesheet" href="../assets/css/main.css">
</head>
<body>
    <main class="page narrow">
        <h1>手機登入</h1>

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

        <?php if (auth_phone_demo_code_preview_enabled() && is_string($demoCode) && $demoPhone === $phone): ?>
            <div class="alert">
                <p>本機展示模式驗證碼：<?= e($demoCode) ?></p>
            </div>
        <?php endif; ?>

        <section class="panel">
            <h2>索取驗證碼</h2>
            <form class="form" method="post" action="phone_login.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="request_code">
                <label>
                    手機號碼
                    <input type="tel" name="phone" value="<?= e($phone) ?>" required>
                </label>
                <button class="button" type="submit">取得驗證碼</button>
            </form>
        </section>

        <section class="panel section-gap">
            <h2>驗證登入</h2>
            <form class="form" method="post" action="phone_login.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="verify_code">
                <label>
                    手機號碼
                    <input type="tel" name="phone" value="<?= e($phone) ?>" required>
                </label>
                <label>
                    6 位數驗證碼
                    <input type="text" name="code" inputmode="numeric" maxlength="6" pattern="\d{6}" required>
                </label>
                <button class="button" type="submit">驗證並登入</button>
            </form>
        </section>

        <p class="helper-text"><a href="login.php">回 Email 登入</a></p>
    </main>
</body>
</html>
