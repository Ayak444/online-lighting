<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/integrations.php';

function notification_preferences_defaults(): array
{
    return [
        'email_enabled' => 1,
        'sms_enabled' => 0,
        'line_enabled' => 0,
        'system_enabled' => 1,
    ];
}

function notification_preferences_for_user(int $userId): array
{
    $stmt = db()->prepare(
        'SELECT user_id, email_enabled, sms_enabled, line_enabled, system_enabled
         FROM user_notification_preferences
         WHERE user_id = ?
         LIMIT 1'
    );
    $stmt->execute([$userId]);
    $preferences = $stmt->fetch();

    if ($preferences) {
        return $preferences;
    }

    $defaults = notification_preferences_defaults();
    $stmt = db()->prepare(
        'INSERT INTO user_notification_preferences (
            user_id,
            email_enabled,
            sms_enabled,
            line_enabled,
            system_enabled
         ) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $userId,
        $defaults['email_enabled'],
        $defaults['sms_enabled'],
        $defaults['line_enabled'],
        $defaults['system_enabled'],
    ]);

    return array_merge(['user_id' => $userId], $defaults);
}

function save_notification_preferences(int $userId, array $preferences): array
{
    $payload = [
        'email_enabled' => !empty($preferences['email_enabled']) ? 1 : 0,
        'sms_enabled' => !empty($preferences['sms_enabled']) ? 1 : 0,
        'line_enabled' => !empty($preferences['line_enabled']) ? 1 : 0,
        'system_enabled' => !empty($preferences['system_enabled']) ? 1 : 0,
    ];

    $stmt = db()->prepare(
        'INSERT INTO user_notification_preferences (
            user_id,
            email_enabled,
            sms_enabled,
            line_enabled,
            system_enabled
         ) VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            email_enabled = VALUES(email_enabled),
            sms_enabled = VALUES(sms_enabled),
            line_enabled = VALUES(line_enabled),
            system_enabled = VALUES(system_enabled)'
    );
    $stmt->execute([
        $userId,
        $payload['email_enabled'],
        $payload['sms_enabled'],
        $payload['line_enabled'],
        $payload['system_enabled'],
    ]);

    return array_merge(['user_id' => $userId], $payload);
}

function notification_channel_is_enabled(array $preferences, string $channel): bool
{
    $field = $channel . '_enabled';
    return !empty($preferences[$field]);
}

function notification_delivery_config(string $channel): array
{
    global $integrationConfig;

    return $integrationConfig['notifications'][$channel] ?? [];
}

function notification_delivery_runtime(): array
{
    global $integrationConfig;

    return [
        'max_attempts' => max(1, (int) ($integrationConfig['notifications']['max_attempts'] ?? 3)),
        'retry_minutes' => max(1, (int) ($integrationConfig['notifications']['retry_minutes'] ?? 15)),
    ];
}

function notification_latest_delivery(int $notifyId): ?array
{
    $stmt = db()->prepare(
        'SELECT *
         FROM notification_deliveries
         WHERE notify_id = ?
         ORDER BY attempt_no DESC, delivery_id DESC
         LIMIT 1'
    );
    $stmt->execute([$notifyId]);
    $delivery = $stmt->fetch();

    return $delivery ?: null;
}

function notification_delivery_attempt_count(int $notifyId): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM notification_deliveries WHERE notify_id = ?');
    $stmt->execute([$notifyId]);

    return (int) $stmt->fetchColumn();
}

function notification_line_user_id(int $userId): ?string
{
    $stmt = db()->prepare(
        'SELECT provider_user_id
         FROM auth_identities
         WHERE user_id = ?
           AND provider = "line"
         ORDER BY identity_id DESC
         LIMIT 1'
    );
    $stmt->execute([$userId]);
    $userIdValue = $stmt->fetchColumn();

    return $userIdValue === false ? null : (string) $userIdValue;
}

function notification_recipient_for_channel(array $notification): array
{
    $userId = (int) $notification['user_id'];
    $channel = (string) $notification['channel'];

    $stmt = db()->prepare(
        'SELECT email, phone
         FROM users
         WHERE user_id = ?
           AND is_active = 1
         LIMIT 1'
    );
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        return [null, '會員不存在或已停用。'];
    }

    if ($channel === 'email') {
        $email = trim((string) ($user['email'] ?? ''));
        return $email === '' ? [null, '會員尚未設定 Email。'] : [$email, null];
    }

    if ($channel === 'sms') {
        $phone = trim((string) ($user['phone'] ?? ''));
        return $phone === '' ? [null, '會員尚未設定手機號碼。'] : [$phone, null];
    }

    if ($channel === 'line') {
        $lineUserId = notification_line_user_id($userId);
        return $lineUserId === null || $lineUserId === ''
            ? [null, '會員尚未綁定 LINE 帳號。']
            : [$lineUserId, null];
    }

    return ['system:' . $userId, null];
}

function notification_http_request(string $url, string $method, array $headers, string $body): array
{
    $headerText = implode("\r\n", $headers);
    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => $headerText,
            'content' => $body,
            'ignore_errors' => true,
            'timeout' => 20,
        ],
    ]);

    $responseBody = @file_get_contents($url, false, $context);
    $responseHeaders = $http_response_header ?? [];
    $statusCode = 0;

    if (isset($responseHeaders[0]) && preg_match('/\s(\d{3})\s/', (string) $responseHeaders[0], $matches) === 1) {
        $statusCode = (int) $matches[1];
    }

    return [
        'status_code' => $statusCode,
        'body' => $responseBody === false ? '' : $responseBody,
    ];
}

function notification_content_preview(string $content, int $length = 120): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($content, 0, $length);
    }

    return substr($content, 0, $length);
}

function notification_mock_result(string $channel, string $recipient, string $subject, string $content): array
{
    return [
        'status' => 'sent',
        'provider' => 'mock_' . $channel,
        'provider_message_id' => 'mock-' . $channel . '-' . bin2hex(random_bytes(8)),
        'provider_response' => json_encode([
            'recipient' => $recipient,
            'subject' => $subject,
            'content_preview' => notification_content_preview($content),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'error_message' => null,
    ];
}

function notification_send_email(string $recipient, string $subject, string $content): array
{
    $config = notification_delivery_config('email');
    $driver = (string) ($config['driver'] ?? 'mock');

    if ($driver === 'mock') {
        return notification_mock_result('email', $recipient, $subject, $content);
    }

    if ($driver !== 'mail') {
        return [
            'status' => 'failed',
            'provider' => $driver,
            'provider_message_id' => null,
            'provider_response' => null,
            'error_message' => 'Email driver 不支援。',
        ];
    }

    $fromAddress = trim((string) ($config['from_address'] ?? ''));
    $fromName = trim((string) ($config['from_name'] ?? '線上點燈系統'));
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
    ];

    if ($fromAddress !== '') {
        $headers[] = 'From: ' . $fromName . ' <' . $fromAddress . '>';
    }

    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $sent = @mail($recipient, $encodedSubject, $content, implode("\r\n", $headers));

    return [
        'status' => $sent ? 'sent' : 'failed',
        'provider' => 'php_mail',
        'provider_message_id' => null,
        'provider_response' => $sent ? 'mail() accepted message.' : null,
        'error_message' => $sent ? null : 'mail() 發送失敗。',
    ];
}

function notification_send_sms(string $recipient, string $subject, string $content): array
{
    $config = notification_delivery_config('sms');
    $driver = (string) ($config['driver'] ?? 'mock');
    $message = trim($subject . "\n" . $content);

    if ($driver === 'mock') {
        return notification_mock_result('sms', $recipient, $subject, $content);
    }

    if ($driver !== 'twilio') {
        return [
            'status' => 'failed',
            'provider' => $driver,
            'provider_message_id' => null,
            'provider_response' => null,
            'error_message' => 'SMS driver 不支援。',
        ];
    }

    $accountSid = trim((string) ($config['twilio_account_sid'] ?? ''));
    $authToken = trim((string) ($config['twilio_auth_token'] ?? ''));
    $from = trim((string) ($config['twilio_from'] ?? ''));

    if ($accountSid === '' || $authToken === '' || $from === '') {
        return [
            'status' => 'failed',
            'provider' => 'twilio',
            'provider_message_id' => null,
            'provider_response' => null,
            'error_message' => 'Twilio 設定不完整。',
        ];
    }

    $response = notification_http_request(
        'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($accountSid) . '/Messages.json',
        'POST',
        [
            'Authorization: Basic ' . base64_encode($accountSid . ':' . $authToken),
            'Content-Type: application/x-www-form-urlencoded',
        ],
        http_build_query([
            'To' => $recipient,
            'From' => $from,
            'Body' => $message,
        ])
    );

    $decoded = json_decode((string) $response['body'], true);
    $sid = is_array($decoded) ? (string) ($decoded['sid'] ?? '') : '';
    $sent = in_array((int) $response['status_code'], [200, 201], true);

    return [
        'status' => $sent ? 'sent' : 'failed',
        'provider' => 'twilio',
        'provider_message_id' => $sid === '' ? null : $sid,
        'provider_response' => (string) $response['body'],
        'error_message' => $sent ? null : 'Twilio API 回傳錯誤。',
    ];
}

function notification_send_line(string $recipient, string $subject, string $content): array
{
    $config = notification_delivery_config('line');
    $driver = (string) ($config['driver'] ?? 'mock');
    $message = trim($subject . "\n" . $content);

    if ($driver === 'mock') {
        return notification_mock_result('line', $recipient, $subject, $content);
    }

    if ($driver !== 'messaging_api') {
        return [
            'status' => 'failed',
            'provider' => $driver,
            'provider_message_id' => null,
            'provider_response' => null,
            'error_message' => 'LINE driver 不支援。',
        ];
    }

    $channelAccessToken = trim((string) ($config['channel_access_token'] ?? ''));
    if ($channelAccessToken === '') {
        return [
            'status' => 'failed',
            'provider' => 'line_messaging_api',
            'provider_message_id' => null,
            'provider_response' => null,
            'error_message' => 'LINE Messaging API access token 尚未設定。',
        ];
    }

    $response = notification_http_request(
        'https://api.line.me/v2/bot/message/push',
        'POST',
        [
            'Authorization: Bearer ' . $channelAccessToken,
            'Content-Type: application/json',
        ],
        (string) json_encode([
            'to' => $recipient,
            'messages' => [
                [
                    'type' => 'text',
                    'text' => $message,
                ],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    $sent = (int) $response['status_code'] === 200;

    return [
        'status' => $sent ? 'sent' : 'failed',
        'provider' => 'line_messaging_api',
        'provider_message_id' => null,
        'provider_response' => (string) $response['body'],
        'error_message' => $sent ? null : 'LINE Messaging API 回傳錯誤。',
    ];
}

function notification_send_system(string $recipient, string $subject, string $content): array
{
    return [
        'status' => 'sent',
        'provider' => 'system',
        'provider_message_id' => null,
        'provider_response' => json_encode([
            'recipient' => $recipient,
            'subject' => $subject,
            'content_preview' => notification_content_preview($content),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'error_message' => null,
    ];
}

function notification_send_via_channel(array $notification, string $recipient): array
{
    $channel = (string) $notification['channel'];
    $subject = (string) $notification['subject'];
    $content = (string) $notification['content'];

    if ($channel === 'email') {
        return notification_send_email($recipient, $subject, $content);
    }

    if ($channel === 'sms') {
        return notification_send_sms($recipient, $subject, $content);
    }

    if ($channel === 'line') {
        return notification_send_line($recipient, $subject, $content);
    }

    return notification_send_system($recipient, $subject, $content);
}

function notification_record_delivery(array $notification, int $attemptNo, ?string $recipient, array $result): array
{
    $runtime = notification_delivery_runtime();
    $status = (string) ($result['status'] ?? 'failed');
    $nextRetryAt = null;
    $sentAt = null;

    if ($status === 'sent') {
        $sentAt = date('Y-m-d H:i:s');
    } elseif ($status === 'failed' && $attemptNo < $runtime['max_attempts']) {
        $nextRetryAt = date('Y-m-d H:i:s', time() + ($runtime['retry_minutes'] * 60));
    }

    $stmt = db()->prepare(
        'INSERT INTO notification_deliveries (
            notify_id,
            channel,
            recipient,
            provider,
            attempt_no,
            status,
            provider_message_id,
            provider_response,
            error_message,
            next_retry_at,
            sent_at
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        (int) $notification['notify_id'],
        (string) $notification['channel'],
        $recipient,
        (string) ($result['provider'] ?? 'unknown'),
        $attemptNo,
        $status,
        $result['provider_message_id'] ?? null,
        $result['provider_response'] ?? null,
        $result['error_message'] ?? null,
        $nextRetryAt,
        $sentAt,
    ]);

    $deliveryId = (int) db()->lastInsertId();
    $notificationStatus = $status === 'sent' ? 'sent' : 'failed';
    $stmt = db()->prepare(
        'UPDATE notifications
         SET status = ?,
             sent_at = CASE WHEN ? = "sent" THEN NOW() ELSE sent_at END
         WHERE notify_id = ?'
    );
    $stmt->execute([
        $notificationStatus,
        $status,
        (int) $notification['notify_id'],
    ]);

    $stmt = db()->prepare('SELECT * FROM notification_deliveries WHERE delivery_id = ? LIMIT 1');
    $stmt->execute([$deliveryId]);

    return $stmt->fetch() ?: [];
}

function notification_process_pending(int $limit = 50): array
{
    $runtime = notification_delivery_runtime();
    $stmt = db()->prepare(
        'SELECT *
         FROM notifications
         WHERE status IN ("pending", "failed")
         ORDER BY created_at ASC, notify_id ASC
         LIMIT ?'
    );
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $notifications = $stmt->fetchAll();
    $results = [];

    foreach ($notifications as $notification) {
        $notifyId = (int) $notification['notify_id'];
        $attemptCount = notification_delivery_attempt_count($notifyId);
        $latestDelivery = notification_latest_delivery($notifyId);
        $status = (string) $notification['status'];

        if ($attemptCount >= $runtime['max_attempts'] && $status !== 'pending') {
            $results[] = 'Notification #' . $notifyId . ' skipped: retry limit reached.';
            continue;
        }

        if (
            $status === 'failed'
            && $latestDelivery
            && $latestDelivery['status'] === 'skipped'
        ) {
            $results[] = 'Notification #' . $notifyId . ' skipped: waiting for manual requeue.';
            continue;
        }

        if (
            $status === 'failed'
            && $latestDelivery
            && $latestDelivery['status'] === 'failed'
            && !empty($latestDelivery['next_retry_at'])
            && strtotime((string) $latestDelivery['next_retry_at']) > time()
        ) {
            $results[] = 'Notification #' . $notifyId . ' skipped: retry window not reached.';
            continue;
        }

        $preferences = notification_preferences_for_user((int) $notification['user_id']);
        if (!notification_channel_is_enabled($preferences, (string) $notification['channel'])) {
            $delivery = notification_record_delivery($notification, $attemptCount + 1, null, [
                'status' => 'skipped',
                'provider' => 'preference',
                'provider_message_id' => null,
                'provider_response' => null,
                'error_message' => '會員已關閉此通知渠道。',
            ]);

            audit_log(null, 'system_notification_delivery', 'notification_deliveries', (string) ($delivery['delivery_id'] ?? ''), null, $delivery ?: null);
            $results[] = 'Notification #' . $notifyId . ' skipped: channel disabled.';
            continue;
        }

        [$recipient, $recipientError] = notification_recipient_for_channel($notification);
        if ($recipient === null) {
            $delivery = notification_record_delivery($notification, $attemptCount + 1, null, [
                'status' => 'skipped',
                'provider' => 'recipient',
                'provider_message_id' => null,
                'provider_response' => null,
                'error_message' => $recipientError,
            ]);

            audit_log(null, 'system_notification_delivery', 'notification_deliveries', (string) ($delivery['delivery_id'] ?? ''), null, $delivery ?: null);
            $results[] = 'Notification #' . $notifyId . ' skipped: recipient unavailable.';
            continue;
        }

        $result = notification_send_via_channel($notification, $recipient);
        $delivery = notification_record_delivery($notification, $attemptCount + 1, $recipient, $result);

        audit_log(null, 'system_notification_delivery', 'notification_deliveries', (string) ($delivery['delivery_id'] ?? ''), null, $delivery ?: null);
        $results[] = 'Notification #' . $notifyId . ' delivery status: ' . (string) ($delivery['status'] ?? 'unknown') . '.';
    }

    return $results;
}
