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
            login_user($user);
            audit_log((int) $user['user_id'], 'member_login', 'users', (string) $user['user_id']);
            redirect('profile.php');
        }
    }
}
?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>會員登入</title>
    <link rel="stylesheet" href="../assets/css/main.css">
</head>
<body>
    <main class="page narrow">
        <h1>會員登入</h1>

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

            <button class="button" type="submit">登入</button>
        </form>

        <section class="panel section-gap">
            <h2>其他登入方式</h2>
            <div class="stack-list">
                <?php foreach (['google', 'line'] as $provider): ?>
                    <article class="list-item">
                        <h3><?= e(auth_provider_label($provider)) ?> 登入</h3>
                        <?php if (auth_provider_is_configured($provider)): ?>
                            <?php if ($provider === 'google'): ?>
                                <p class="helper-text">第一次使用 Google 會自動建立會員帳號。</p>
                            <?php endif; ?>
                            <a class="button" href="oauth_start.php?provider=<?= e($provider) ?>">使用 <?= e(auth_provider_label($provider)) ?> 繼續</a>
                        <?php else: ?>
                            <p class="helper-text"><?= e(auth_provider_label($provider)) ?> 登入尚未完成金鑰設定。</p>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>

                <article class="list-item">
                    <h3>手機驗證碼登入</h3>
                    <p class="helper-text">以會員資料中綁定的手機號碼收取一次性驗證碼登入。</p>
                    <a class="button secondary" href="phone_login.php">使用手機登入</a>
                </article>
            </div>
        </section>

        <p class="helper-text">還沒有帳號？<a href="register.php">建立會員帳戶</a></p>
        <p class="helper-text"><a href="../admin/login.php">後台管理登入</a></p>
    </main>
</body>
</html>
