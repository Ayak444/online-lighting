<?php
declare(strict_types=1);

require_once __DIR__ . '/frontend.php';
require_once __DIR__ . '/notification_delivery.php';

function renewal_reminder_run_at(string $blessingEndDate): string
{
    try {
        $endDate = new DateTimeImmutable($blessingEndDate . ' 09:00:00');
        $runAt = $endDate->modify('-30 days');
    } catch (Throwable) {
        $runAt = new DateTimeImmutable('+1 day 09:00:00');
    }

    return $runAt->format('Y-m-d H:i:s');
}

function renewal_reminder_subject(array $period): string
{
    return ((string) $period['service_year']) . ' 年度點燈續點提醒';
}

function ensure_renewal_reminder_job_for_period(array $period, ?int $actorId = null): ?int
{
    $periodId = (int) ($period['period_id'] ?? 0);
    $endDate = (string) ($period['blessing_end_date'] ?? '');

    if ($periodId <= 0 || $endDate === '') {
        return null;
    }

    $subject = renewal_reminder_subject($period);
    $nextRunAt = renewal_reminder_run_at($endDate);
    $jobName = '年度點燈續點提醒-' . (string) $period['service_year'];
    global $integrationConfig;
    $baseUrl = rtrim((string) ($integrationConfig['app']['public_base_url'] ?? ''), '/');
    $renewalUrl = $baseUrl === '' ? 'renewal_advisor.php' : $baseUrl . '/renewal_advisor.php';
    $content = '您本年度的點燈服務將於 ' . $endDate . ' 到期，敬請登入系統使用智慧續點功能，確認祈福對象資料後辦理續點：' . $renewalUrl;

    $stmt = db()->prepare(
        'SELECT *
         FROM scheduled_jobs
         WHERE job_type = "expiration_reminder"
           AND target_channel = "email"
           AND subject = ?
         ORDER BY job_id DESC
         LIMIT 1'
    );
    $stmt->execute([$subject]);
    $before = $stmt->fetch();

    if ($before) {
        $stmt = db()->prepare(
            'UPDATE scheduled_jobs
             SET job_name = ?,
                 content = ?,
                 status = CASE WHEN status = "finished" THEN "active" ELSE status END,
                 next_run_at = ?
             WHERE job_id = ?'
        );
        $stmt->execute([$jobName, $content, $nextRunAt, (int) $before['job_id']]);

        $after = load_scheduled_job_for_renewal((int) $before['job_id']);
        audit_log($actorId, 'admin_scheduled_job_save', 'scheduled_jobs', (string) $before['job_id'], $before, $after);

        return (int) $before['job_id'];
    }

    $stmt = db()->prepare(
        'INSERT INTO scheduled_jobs (
            created_by,
            job_name,
            job_type,
            trigger_time,
            cron_expression,
            target_channel,
            subject,
            content,
            status,
            next_run_at
         ) VALUES (?, ?, "expiration_reminder", ?, ?, "email", ?, ?, "active", ?)'
    );
    $stmt->execute([
        $actorId,
        $jobName,
        $nextRunAt,
        'annual-renewal-reminder',
        $subject,
        $content,
        $nextRunAt,
    ]);

    $jobId = (int) db()->lastInsertId();
    audit_log($actorId, 'admin_scheduled_job_save', 'scheduled_jobs', (string) $jobId, null, load_scheduled_job_for_renewal($jobId));

    return $jobId;
}

function load_scheduled_job_for_renewal(int $jobId): ?array
{
    $stmt = db()->prepare('SELECT * FROM scheduled_jobs WHERE job_id = ? LIMIT 1');
    $stmt->execute([$jobId]);
    $job = $stmt->fetch();

    return $job ?: null;
}

function renewal_recipient_rows(?array $period = null): array
{
    $params = [];
    $where = [
        'o.payment_status = "paid"',
        'o.review_status = "approved"',
        'oi.item_status <> "cancelled"',
        'oi.blessing_end_date IS NOT NULL',
        'u.is_active = 1',
    ];

    if ($period !== null && !empty($period['period_id'])) {
        $where[] = 'o.service_period_id = ?';
        $params[] = (int) $period['period_id'];
    }

    $stmt = db()->prepare(
        'SELECT u.user_id, u.name, u.email,
                MIN(oi.blessing_end_date) AS nearest_end_date,
                COUNT(*) AS lamp_count,
                GROUP_CONCAT(DISTINCT lt.name ORDER BY lt.sort_order, lt.type_id SEPARATOR "、") AS lantern_names
         FROM orders o
         INNER JOIN users u ON u.user_id = o.user_id
         INNER JOIN order_items oi ON oi.order_id = o.order_id
         INNER JOIN lantern_types lt ON lt.type_id = oi.type_id
         WHERE ' . implode(' AND ', $where) . '
         GROUP BY u.user_id, u.name, u.email
         ORDER BY nearest_end_date ASC, u.user_id ASC'
    );
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function create_renewal_notification_for_user(array $row, ?array $period = null): int
{
    global $integrationConfig;

    $baseUrl = rtrim((string) ($integrationConfig['app']['public_base_url'] ?? ''), '/');
    $renewalUrl = $baseUrl === '' ? 'renewal_advisor.php' : $baseUrl . '/renewal_advisor.php';
    $endDate = (string) ($row['nearest_end_date'] ?? ($period['blessing_end_date'] ?? ''));
    $lampCount = (int) ($row['lamp_count'] ?? 0);
    $lanternNames = (string) ($row['lantern_names'] ?? '點燈服務');
    $subject = '點燈即將到期，邀請您辦理續點';
    $content = '您好，' . (string) ($row['name'] ?? '') . "：\n\n"
        . '您目前共有 ' . $lampCount . ' 筆點燈服務即將於 ' . $endDate . " 到期。\n"
        . '燈種：' . $lanternNames . "\n\n"
        . '請登入系統使用智慧續點功能，確認祈福對象資料後選擇適合的燈種：' . "\n"
        . $renewalUrl . "\n\n"
        . '若您已完成續點，請忽略此信。';

    $stmt = db()->prepare(
        'INSERT INTO notifications (user_id, channel, subject, content, status)
         VALUES (?, "email", ?, ?, "pending")'
    );
    $stmt->execute([(int) $row['user_id'], $subject, $content]);

    return (int) db()->lastInsertId();
}

function send_renewal_notifications_now(?array $period = null, ?int $actorId = null): array
{
    $rows = renewal_recipient_rows($period);
    $results = [
        'recipient_count' => count($rows),
        'sent_count' => 0,
        'failed_count' => 0,
        'messages' => [],
    ];

    foreach ($rows as $row) {
        $notifyId = create_renewal_notification_for_user($row, $period);
        $message = notification_process_by_id($notifyId, true, $actorId);
        $delivery = notification_latest_delivery($notifyId);
        $status = (string) ($delivery['status'] ?? 'unknown');

        if ($status === 'sent') {
            $results['sent_count']++;
        } else {
            $results['failed_count']++;
        }

        $results['messages'][] = [
            'user_id' => (int) $row['user_id'],
            'email' => (string) ($row['email'] ?? ''),
            'notify_id' => $notifyId,
            'status' => $status,
            'message' => $message,
        ];
    }

    audit_log($actorId, 'admin_renewal_notification_send', 'notifications', null, null, $results);

    return $results;
}
