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

$mode = trim((string) ($_GET['mode'] ?? 'login'));
$currentUser = current_user();
if ($mode !== 'link' || $currentUser === null) {
    $mode = 'login';
}

if (!auth_provider_is_configured($provider)) {
    set_flash(auth_provider_label($provider) . ' 登入尚未完成金鑰設定。');
    redirect($mode === 'link' ? 'profile.php' : 'login.php');
}

$state = oauth_state_create(
    $provider,
    $mode,
    $mode === 'link' && $currentUser !== null ? (int) $currentUser['user_id'] : null,
    'profile.php'
);

redirect(oauth_build_authorize_url($provider, $state));
