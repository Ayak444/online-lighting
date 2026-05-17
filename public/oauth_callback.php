<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/frontend.php';
require_once __DIR__ . '/../config/external_auth.php';

start_app_session();

$provider = trim((string) ($_GET['provider'] ?? ''));
if (!auth_provider_is_supported($provider)) {
    http_response_code(404);
    exit('Unsupported provider.');
}

if (isset($_GET['error'])) {
    set_flash(auth_provider_label($provider) . ' 授權已取消，請重新嘗試。');
    redirect('login.php');
}

$stateRow = oauth_state_consume($provider, trim((string) ($_GET['state'] ?? '')));
if ($stateRow === null) {
    set_flash('登入驗證已過期，請重新發起授權。');
    redirect('login.php');
}

$code = trim((string) ($_GET['code'] ?? ''));
if ($code === '') {
    set_flash(auth_provider_label($provider) . ' 沒有回傳授權碼。');
    redirect((string) $stateRow['redirect_path']);
}

try {
    $profile = oauth_exchange_profile($provider, $code);
    $providerUserId = trim((string) ($profile['provider_user_id'] ?? ''));
    if ($providerUserId === '') {
        throw new RuntimeException('外部登入身分缺少唯一識別碼。');
    }

    if ($stateRow['flow_mode'] === 'link') {
        $currentUser = current_user();
        if ($currentUser === null || (int) $currentUser['user_id'] !== (int) $stateRow['user_id']) {
            throw new RuntimeException('登入狀態已改變，請重新綁定。');
        }

        auth_identity_link((int) $currentUser['user_id'], $provider, $profile);
        audit_log((int) $currentUser['user_id'], 'member_oauth_link', 'auth_identities', null, null, [
            'provider' => $provider,
            'provider_user_id' => $providerUserId,
        ]);
        set_flash(auth_provider_label($provider) . ' 帳號已完成綁定。');
        redirect('profile.php');
    }

    $identity = auth_identity_find($provider, $providerUserId);
    if ($identity !== null) {
        if ((int) $identity['is_active'] !== 1) {
            throw new RuntimeException('會員帳號已停用。');
        }

        $stmt = db()->prepare('UPDATE auth_identities SET last_login_at = NOW() WHERE identity_id = ?');
        $stmt->execute([(int) $identity['identity_id']]);

        login_user($identity);
        audit_log((int) $identity['user_id'], 'member_oauth_login', 'auth_identities', (string) $identity['identity_id'], null, [
            'provider' => $provider,
        ]);
        redirect('profile.php');
    }

    $email = trim((string) ($profile['email'] ?? ''));
    if ($email !== '') {
        $stmt = db()->prepare('SELECT user_id FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            set_flash('此 Email 已經有會員帳號，請先用原本方式登入，再到會員中心綁定 ' . auth_provider_label($provider) . '。');
            redirect('login.php');
        }
    }

    $newUser = auth_external_user_create($provider, $profile);
    login_user($newUser);
    audit_log((int) $newUser['user_id'], 'member_oauth_register', 'auth_identities', null, null, [
        'provider' => $provider,
        'provider_user_id' => $providerUserId,
    ]);
    redirect('profile.php');
} catch (Throwable $exception) {
    set_flash(auth_provider_label($provider) . ' 登入失敗：' . $exception->getMessage());
    redirect((string) ($stateRow['redirect_path'] ?? 'login.php'));
}
