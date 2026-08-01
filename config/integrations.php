<?php
declare(strict_types=1);

$integrationConfig = [
    'app' => [
        'public_base_url' => getenv('APP_PUBLIC_BASE_URL') ?: 'http://127.0.0.1:8088/public',
    ],
    'google' => [
        'client_id' => getenv('GOOGLE_CLIENT_ID') ?: '',
        'client_secret' => getenv('GOOGLE_CLIENT_SECRET') ?: '',
        'redirect_uri' => getenv('GOOGLE_REDIRECT_URI') ?: '',
    ],
    'line' => [
        'channel_id' => getenv('LINE_CHANNEL_ID') ?: '',
        'channel_secret' => getenv('LINE_CHANNEL_SECRET') ?: '',
        'redirect_uri' => getenv('LINE_REDIRECT_URI') ?: '',
        'bot_prompt' => getenv('LINE_BOT_PROMPT') ?: 'normal',
    ],
    'phone_login' => [
        'demo_code_preview' => filter_var(getenv('PHONE_LOGIN_DEMO_CODE_PREVIEW') ?: '1', FILTER_VALIDATE_BOOLEAN),
    ],
    'notifications' => [
        'max_attempts' => max(1, (int) (getenv('NOTIFICATION_MAX_ATTEMPTS') ?: 3)),
        'retry_minutes' => max(1, (int) (getenv('NOTIFICATION_RETRY_MINUTES') ?: 15)),
        'email' => [
            'driver' => getenv('NOTIFICATION_EMAIL_DRIVER') ?: 'mock',
            'from_address' => getenv('NOTIFICATION_EMAIL_FROM_ADDRESS') ?: 'no-reply@example.test',
            'from_name' => getenv('NOTIFICATION_EMAIL_FROM_NAME') ?: '線上點燈系統',
            'smtp_host' => getenv('SMTP_HOST') ?: '',
            'smtp_port' => (int) (getenv('SMTP_PORT') ?: 587),
            'smtp_username' => getenv('SMTP_USERNAME') ?: '',
            'smtp_password' => getenv('SMTP_PASSWORD') ?: '',
            'smtp_encryption' => getenv('SMTP_ENCRYPTION') ?: 'tls',
        ],
        'sms' => [
            'driver' => getenv('NOTIFICATION_SMS_DRIVER') ?: 'mock',
            'twilio_account_sid' => getenv('TWILIO_ACCOUNT_SID') ?: '',
            'twilio_auth_token' => getenv('TWILIO_AUTH_TOKEN') ?: '',
            'twilio_from' => getenv('TWILIO_FROM_NUMBER') ?: '',
        ],
        'line' => [
            'driver' => getenv('NOTIFICATION_LINE_DRIVER') ?: 'mock',
            'channel_access_token' => getenv('LINE_MESSAGING_CHANNEL_ACCESS_TOKEN') ?: '',
        ],
    ],
];

$integrationLocalPath = __DIR__ . '/integrations.local.php';
if (is_file($integrationLocalPath)) {
    $localConfig = require $integrationLocalPath;
    if (is_array($localConfig)) {
        $integrationConfig = array_replace_recursive($integrationConfig, $localConfig);
    }
}
