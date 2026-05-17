<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/birth_profile.php';
require_once __DIR__ . '/../config/external_auth.php';

start_app_session();

if (current_user() !== null) {
    redirect('profile.php');
}

$errors = [];
$old = [
    'name' => '',
    'email' => '',
    'gender' => 'unspecified',
    'phone' => '',
    'address' => '',
    'birthday' => '',
    'birth_clock_time' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors[] = '表單已過期，請重新送出。';
    }

    $old['name'] = trim((string) ($_POST['name'] ?? ''));
    $old['email'] = trim((string) ($_POST['email'] ?? ''));
    $old['gender'] = (string) ($_POST['gender'] ?? 'unspecified');
    $old['phone'] = trim((string) ($_POST['phone'] ?? ''));
    $old['address'] = trim((string) ($_POST['address'] ?? ''));
    $old['birthday'] = trim((string) ($_POST['birthday'] ?? ''));
    $old['birth_clock_time'] = trim((string) ($_POST['birth_clock_time'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

    if ($old['name'] === '') {
        $errors[] = '請輸入姓名。';
    }

    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = '請輸入正確的 email。';
    }

    if (!in_array($old['gender'], ['male', 'female', 'other', 'unspecified'], true)) {
        $errors[] = '性別欄位不正確。';
    }

    if (strlen($password) < 8) {
        $errors[] = '密碼至少需要 8 個字元。';
    }

    if ($password !== $passwordConfirm) {
        $errors[] = '兩次輸入的密碼不一致。';
    }

    if ($old['birthday'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $old['birthday'])) {
        $errors[] = '生日格式不正確。';
    }

    if ($old['birth_clock_time'] !== '' && birth_profile_hour_branch($old['birth_clock_time']) === null) {
        $errors[] = '出生時間格式不正確。';
    }

    if ($errors === []) {
        try {
            $pdo = db();
            $pdo->beginTransaction();
            $birthDerived = birth_profile_derive(
                $old['birthday'] === '' ? null : $old['birthday'],
                $old['birth_clock_time'] === '' ? null : $old['birth_clock_time']
            );

            $stmt = $pdo->prepare(
                'INSERT INTO users (
                    email,
                    password_hash,
                    name,
                    gender,
                    phone,
                    address,
                    birthday,
                    birth_clock_time,
                    lunar_birthday,
                    zodiac,
                    auth_provider
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "local")'
            );
            $stmt->execute([
                $old['email'],
                password_hash($password, PASSWORD_DEFAULT),
                $old['name'],
                $old['gender'],
                $old['phone'] === '' ? null : $old['phone'],
                $old['address'] === '' ? null : $old['address'],
                $old['birthday'] === '' ? null : $old['birthday'],
                $old['birth_clock_time'] === '' ? null : $old['birth_clock_time'],
                $birthDerived['lunar_birthday'],
                $birthDerived['zodiac'],
            ]);

            $userId = (int) $pdo->lastInsertId();

            $stmt = $pdo->prepare(
                'INSERT INTO user_roles (user_id, role_id)
                 SELECT ?, role_id FROM roles WHERE role_name = "member"'
            );
            $stmt->execute([$userId]);

            $stmt = $pdo->prepare('INSERT INTO carts (user_id) VALUES (?)');
            $stmt->execute([$userId]);

            sync_self_dependent($userId, [
                'name' => $old['name'],
                'gender' => $old['gender'],
                'phone' => $old['phone'] === '' ? null : $old['phone'],
                'birthday' => $old['birthday'] === '' ? null : $old['birthday'],
                'birth_clock_time' => $old['birth_clock_time'] === '' ? null : $old['birth_clock_time'],
            ]);

            audit_log($userId, 'member_register', 'users', (string) $userId, null, [
                'email' => $old['email'],
                'name' => $old['name'],
                'role' => 'member',
            ]);

            $pdo->commit();

            login_user([
                'user_id' => $userId,
                'name' => $old['name'],
            ]);

            redirect('profile.php');
        } catch (PDOException $exception) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($exception->getCode() === '23000') {
                $errors[] = '這個 email 已經註冊過。';
            } else {
                $errors[] = '註冊失敗，請稍後再試。';
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
    <title>會員註冊</title>
    <link rel="stylesheet" href="../assets/css/main.css">
</head>
<body>
    <main class="page narrow">
        <h1>會員註冊</h1>

        <?php if ($errors !== []): ?>
            <div class="alert error">
                <?php foreach ($errors as $error): ?>
                    <p><?= e($error) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <section class="panel section-gap">
            <h2>快速註冊</h2>
            <?php if (auth_provider_is_configured('google')): ?>
                <p class="helper-text">第一次使用 Google 會自動建立會員帳號，之後可直接用同一個 Google 帳號登入。</p>
                <a class="button" href="oauth_start.php?provider=google">使用 Google 一鍵註冊</a>
            <?php else: ?>
                <p class="helper-text">Google 一鍵註冊功能已完成程式串接，尚未填入 Google OAuth Client ID / Secret。</p>
            <?php endif; ?>
        </section>

        <form class="form" method="post" action="register.php">
            <?= csrf_field() ?>

            <label>
                姓名
                <input type="text" name="name" value="<?= e($old['name']) ?>" required>
            </label>

            <label>
                Email
                <input type="email" name="email" value="<?= e($old['email']) ?>" required>
            </label>

            <label>
                密碼
                <input type="password" name="password" required minlength="8">
            </label>

            <label>
                確認密碼
                <input type="password" name="password_confirm" required minlength="8">
            </label>

            <label>
                性別
                <select name="gender">
                    <option value="unspecified" <?= $old['gender'] === 'unspecified' ? 'selected' : '' ?>>未指定</option>
                    <option value="male" <?= $old['gender'] === 'male' ? 'selected' : '' ?>>男</option>
                    <option value="female" <?= $old['gender'] === 'female' ? 'selected' : '' ?>>女</option>
                    <option value="other" <?= $old['gender'] === 'other' ? 'selected' : '' ?>>其他</option>
                </select>
            </label>

            <label>
                手機電話
                <input type="tel" name="phone" value="<?= e($old['phone']) ?>">
            </label>

            <label>
                地址
                <input type="text" name="address" value="<?= e($old['address']) ?>">
            </label>

            <label>
                國曆生日
                <input type="date" name="birthday" value="<?= e($old['birthday']) ?>">
            </label>

            <label>
                出生時間
                <input type="time" name="birth_clock_time" value="<?= e($old['birth_clock_time']) ?>">
            </label>

            <button class="button" type="submit">建立會員帳戶</button>
        </form>

        <p class="helper-text">已經有帳號？<a href="login.php">前往登入</a></p>
    </main>
</body>
</html>
