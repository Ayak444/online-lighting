<?php
declare(strict_types=1);

return [
    'app' => [
        'public_base_url' => 'http://127.0.0.1:8088/public',
    ],
    'google' => [
        'client_id' => '',
        'client_secret' => '',
        'redirect_uri' => '',
    ],
    'line' => [
        'channel_id' => '',
        'channel_secret' => '',
        'redirect_uri' => '',
        'bot_prompt' => 'normal',
    ],
    'phone_login' => [
        'demo_code_preview' => true,
    ],
    'notifications' => [
        'max_attempts' => 3,
        'retry_minutes' => 15,
        'email' => [
            'driver' => 'mock',
            'from_address' => 'no-reply@example.test',
            'from_name' => '線上點燈系統',
        ],
        'sms' => [
            'driver' => 'mock',
            'twilio_account_sid' => '',
            'twilio_auth_token' => '',
            'twilio_from' => '',
        ],
        'line' => [
            'driver' => 'mock',
            'channel_access_token' => '',
        ],
    ],
];
