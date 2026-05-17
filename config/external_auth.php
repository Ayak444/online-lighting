<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/integrations.php';
require_once __DIR__ . '/birth_profile.php';

function auth_provider_is_supported(string $provider): bool
{
    return in_array($provider, ['google', 'line'], true);
}

function auth_provider_label(string $provider): string
{
    return [
        'google' => 'Google',
        'line' => 'LINE',
    ][$provider] ?? $provider;
}

function auth_provider_config(string $provider): array
{
    global $integrationConfig;

    return is_array($integrationConfig[$provider] ?? null) ? $integrationConfig[$provider] : [];
}

function auth_public_base_url(): string
{
    global $integrationConfig;

    return rtrim((string) ($integrationConfig['app']['public_base_url'] ?? ''), '/');
}

function auth_provider_redirect_uri(string $provider): string
{
    $config = auth_provider_config($provider);
    $customRedirect = trim((string) ($config['redirect_uri'] ?? ''));
    if ($customRedirect !== '') {
        return $customRedirect;
    }

    return auth_public_base_url() . '/oauth_callback.php?provider=' . rawurlencode($provider);
}

function auth_provider_is_configured(string $provider): bool
{
    $config = auth_provider_config($provider);

    if ($provider === 'google') {
        return trim((string) ($config['client_id'] ?? '')) !== ''
            && trim((string) ($config['client_secret'] ?? '')) !== '';
    }

    if ($provider === 'line') {
        return trim((string) ($config['channel_id'] ?? '')) !== ''
            && trim((string) ($config['channel_secret'] ?? '')) !== '';
    }

    return false;
}

function auth_phone_demo_code_preview_enabled(): bool
{
    global $integrationConfig;

    return (bool) ($integrationConfig['phone_login']['demo_code_preview'] ?? false);
}

function oauth_state_create(string $provider, string $flowMode, ?int $userId, string $redirectPath): string
{
    if (!auth_provider_is_supported($provider)) {
        throw new InvalidArgumentException('Unsupported OAuth provider.');
    }

    if (!in_array($flowMode, ['login', 'link'], true)) {
        throw new InvalidArgumentException('Unsupported OAuth flow.');
    }

    $state = bin2hex(random_bytes(32));
    $stmt = db()->prepare(
        'INSERT INTO oauth_states (
             state_hash,
             provider,
             flow_mode,
             user_id,
             redirect_path,
             expires_at
         ) VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))'
    );
    $stmt->execute([
        hash('sha256', $state),
        $provider,
        $flowMode,
        $userId,
        $redirectPath,
    ]);

    return $state;
}

function oauth_state_consume(string $provider, string $state): ?array
{
    if (!auth_provider_is_supported($provider) || trim($state) === '') {
        return null;
    }

    $stateHash = hash('sha256', $state);
    $pdo = db();
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare(
            'SELECT *
             FROM oauth_states
             WHERE state_hash = ?
               AND provider = ?
               AND consumed_at IS NULL
               AND expires_at >= NOW()
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute([$stateHash, $provider]);
        $row = $stmt->fetch();

        if (!$row) {
            $pdo->rollBack();
            return null;
        }

        $stmt = $pdo->prepare('UPDATE oauth_states SET consumed_at = NOW() WHERE state_hash = ?');
        $stmt->execute([$stateHash]);
        $pdo->commit();

        return $row;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}

function oauth_build_authorize_url(string $provider, string $state): string
{
    $config = auth_provider_config($provider);
    $redirectUri = auth_provider_redirect_uri($provider);

    if ($provider === 'google') {
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id' => (string) ($config['client_id'] ?? ''),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'access_type' => 'online',
            'prompt' => 'select_account',
        ]);
    }

    if ($provider === 'line') {
        return 'https://access.line.me/oauth2/v2.1/authorize?' . http_build_query([
            'response_type' => 'code',
            'client_id' => (string) ($config['channel_id'] ?? ''),
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'scope' => 'profile openid email',
            'bot_prompt' => (string) ($config['bot_prompt'] ?? 'normal'),
        ]);
    }

    throw new InvalidArgumentException('Unsupported OAuth provider.');
}

function oauth_http_json(string $url, string $method = 'GET', array $formData = [], array $headers = []): array
{
    $headerLines = array_merge(['Accept: application/json'], $headers);
    $options = [
        'http' => [
            'method' => strtoupper($method),
            'header' => implode("\r\n", $headerLines),
            'ignore_errors' => true,
            'timeout' => 20,
        ],
    ];

    if ($formData !== []) {
        $options['http']['header'] .= "\r\nContent-Type: application/x-www-form-urlencoded";
        $options['http']['content'] = http_build_query($formData);
    }

    $response = @file_get_contents($url, false, stream_context_create($options));
    if ($response === false) {
        throw new RuntimeException('OAuth provider request failed.');
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('OAuth provider returned invalid JSON.');
    }

    return $decoded;
}

function oauth_exchange_profile(string $provider, string $code): array
{
    $config = auth_provider_config($provider);
    $redirectUri = auth_provider_redirect_uri($provider);

    if ($provider === 'google') {
        $token = oauth_http_json('https://oauth2.googleapis.com/token', 'POST', [
            'code' => $code,
            'client_id' => (string) ($config['client_id'] ?? ''),
            'client_secret' => (string) ($config['client_secret'] ?? ''),
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ]);

        $accessToken = trim((string) ($token['access_token'] ?? ''));
        if ($accessToken === '') {
            throw new RuntimeException('Google access token missing.');
        }

        $profile = oauth_http_json(
            'https://openidconnect.googleapis.com/v1/userinfo',
            'GET',
            [],
            ['Authorization: Bearer ' . $accessToken]
        );

        return [
            'provider_user_id' => trim((string) ($profile['sub'] ?? '')),
            'email' => trim((string) ($profile['email'] ?? '')) ?: null,
            'display_name' => trim((string) ($profile['name'] ?? 'Google 使用者')),
            'profile' => $profile,
        ];
    }

    if ($provider === 'line') {
        $token = oauth_http_json('https://api.line.me/oauth2/v2.1/token', 'POST', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => (string) ($config['channel_id'] ?? ''),
            'client_secret' => (string) ($config['channel_secret'] ?? ''),
        ]);

        $accessToken = trim((string) ($token['access_token'] ?? ''));
        if ($accessToken === '') {
            throw new RuntimeException('LINE access token missing.');
        }

        $profile = oauth_http_json(
            'https://api.line.me/v2/profile',
            'GET',
            [],
            ['Authorization: Bearer ' . $accessToken]
        );

        return [
            'provider_user_id' => trim((string) ($profile['userId'] ?? '')),
            'email' => null,
            'display_name' => trim((string) ($profile['displayName'] ?? 'LINE 使用者')),
            'profile' => $profile,
        ];
    }

    throw new InvalidArgumentException('Unsupported OAuth provider.');
}

function auth_identity_find(string $provider, string $providerUserId): ?array
{
    $stmt = db()->prepare(
        'SELECT ai.*, u.user_id, u.email, u.name, u.is_active
         FROM auth_identities ai
         INNER JOIN users u ON u.user_id = ai.user_id
         WHERE ai.provider = ?
           AND ai.provider_user_id = ?
         LIMIT 1'
    );
    $stmt->execute([$provider, $providerUserId]);
    $identity = $stmt->fetch();

    return $identity ?: null;
}

function auth_identity_list_for_user(int $userId): array
{
    $stmt = db()->prepare(
        'SELECT provider, provider_email, display_name, linked_at, last_login_at
         FROM auth_identities
         WHERE user_id = ?
         ORDER BY provider'
    );
    $stmt->execute([$userId]);

    return $stmt->fetchAll();
}

function auth_identity_link(int $userId, string $provider, array $profile): void
{
    $providerUserId = trim((string) ($profile['provider_user_id'] ?? ''));
    if ($providerUserId === '') {
        throw new RuntimeException('Provider user id missing.');
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare(
            'SELECT identity_id, user_id
             FROM auth_identities
             WHERE provider = ?
               AND provider_user_id = ?
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute([$provider, $providerUserId]);
        $existing = $stmt->fetch();

        if ($existing && (int) $existing['user_id'] !== $userId) {
            throw new RuntimeException('This external identity is already linked to another member.');
        }

        $stmt = $pdo->prepare(
            'SELECT identity_id
             FROM auth_identities
             WHERE user_id = ?
               AND provider = ?
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute([$userId, $provider]);
        $sameProvider = $stmt->fetch();

        if ($sameProvider && !$existing) {
            throw new RuntimeException('This account already has the provider linked.');
        }

        if ($existing) {
            $stmt = $pdo->prepare(
                'UPDATE auth_identities
                 SET provider_email = ?, display_name = ?, profile_json = ?, updated_at = NOW()
                 WHERE identity_id = ?'
            );
            $stmt->execute([
                $profile['email'] ?? null,
                $profile['display_name'] ?? null,
                json_encode($profile['profile'] ?? [], JSON_UNESCAPED_UNICODE),
                (int) $existing['identity_id'],
            ]);
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO auth_identities (
                     user_id,
                     provider,
                     provider_user_id,
                     provider_email,
                     display_name,
                     profile_json
                 ) VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $userId,
                $provider,
                $providerUserId,
                $profile['email'] ?? null,
                $profile['display_name'] ?? null,
                json_encode($profile['profile'] ?? [], JSON_UNESCAPED_UNICODE),
            ]);
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}

function auth_external_user_create(string $provider, array $profile): array
{
    $providerUserId = trim((string) ($profile['provider_user_id'] ?? ''));
    if ($providerUserId === '') {
        throw new RuntimeException('Provider user id missing.');
    }

    $email = trim((string) ($profile['email'] ?? ''));
    if ($email === '') {
        $email = $provider . '_' . substr(hash('sha256', $providerUserId), 0, 24) . '@oauth.local';
    }

    $name = trim((string) ($profile['display_name'] ?? ''));
    if ($name === '') {
        $name = auth_provider_label($provider) . ' 使用者';
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare('SELECT user_id FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            throw new RuntimeException('Existing member email requires manual linking.');
        }

        $stmt = $pdo->prepare(
            'INSERT INTO users (
                 email,
                 password_hash,
                 name,
                 auth_provider,
                 provider_id
             ) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $email,
            password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
            $name,
            $provider,
            $providerUserId,
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
            'name' => $name,
            'gender' => 'unspecified',
            'phone' => null,
            'birthday' => null,
            'birth_clock_time' => null,
        ]);

        $stmt = $pdo->prepare(
            'INSERT INTO auth_identities (
                 user_id,
                 provider,
                 provider_user_id,
                 provider_email,
                 display_name,
                 profile_json,
                 last_login_at
             ) VALUES (?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $userId,
            $provider,
            $providerUserId,
            $profile['email'] ?? null,
            $profile['display_name'] ?? null,
            json_encode($profile['profile'] ?? [], JSON_UNESCAPED_UNICODE),
        ]);

        $pdo->commit();

        return [
            'user_id' => $userId,
            'email' => $email,
            'name' => $name,
        ];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}
