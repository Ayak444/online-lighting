<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/frontend.php';
require_once __DIR__ . '/../config/integrations.php';
require_once __DIR__ . '/../config/notification_delivery.php';

function notification_already_exists(int $jobId, int $userId, string $subject): bool
{
    $stmt = db()->prepare(
        'SELECT 1
         FROM notifications
         WHERE job_id = ?
           AND user_id = ?
           AND subject = ?
         LIMIT 1'
    );
    $stmt->execute([$jobId, $userId, $subject]);

    return (bool) $stmt->fetchColumn();
}

function create_job_notification(array $job, int $userId, string $subject, string $content): ?int
{
    if (notification_already_exists((int) $job['job_id'], $userId, $subject)) {
        return null;
    }

    $stmt = db()->prepare(
        'INSERT INTO notifications (
            user_id,
            job_id,
            channel,
            subject,
            content,
            status
         ) VALUES (?, ?, ?, ?, ?, "pending")'
    );
    $stmt->execute([
        $userId,
        (int) $job['job_id'],
        $job['target_channel'],
        $subject,
        $content,
    ]);

    return (int) db()->lastInsertId();
}

function expiration_reminder_recipients(array $period): array
{
    $stmt = db()->prepare(
        'SELECT DISTINCT o.user_id
         FROM orders o
         INNER JOIN order_items oi ON oi.order_id = o.order_id
         WHERE o.service_period_id = ?
           AND o.payment_status = "paid"
           AND o.review_status = "approved"
           AND oi.item_status <> "cancelled"
         ORDER BY o.user_id'
    );
    $stmt->execute([(int) $period['period_id']]);

    return array_map(static function (array $row): int {
        return (int) $row['user_id'];
    }, $stmt->fetchAll());
}

function broadcast_recipients(): array
{
    $stmt = db()->query(
        'SELECT user_id
         FROM users
         WHERE is_active = 1
         ORDER BY user_id'
    );

    return array_map(static function (array $row): int {
        return (int) $row['user_id'];
    }, $stmt->fetchAll());
}

$stmt = db()->query(
    'SELECT *
     FROM scheduled_jobs
     WHERE status = "active"
       AND next_run_at IS NOT NULL
       AND next_run_at <= NOW()
     ORDER BY next_run_at ASC, job_id ASC'
);
$jobs = $stmt->fetchAll();
$results = [];

foreach ($jobs as $job) {
    $createdCount = 0;
    $sentCount = 0;
    $failedCount = 0;
    $subject = trim((string) ($job['subject'] ?? ''));
    $content = trim((string) ($job['content'] ?? ''));

    if ($job['job_type'] === 'expiration_reminder') {
        $period = active_lamp_service_period();
        if ($period === null) {
            $results[] = 'Job #' . $job['job_id'] . ' skipped: no active lamp service period.';
            continue;
        }

        if ($subject === '') {
            $subject = '年度點燈續點提醒';
        }

        if ($content === '') {
            global $integrationConfig;
            $baseUrl = rtrim((string) ($integrationConfig['app']['public_base_url'] ?? ''), '/');
            $renewalUrl = $baseUrl === '' ? 'renewal_advisor.php' : $baseUrl . '/renewal_advisor.php';
            $content = '您的 ' . $period['service_year'] . ' 年度點燈服務將於 ' . $period['blessing_end_date'] . ' 到期。請登入系統使用智慧續點功能，確認祈福對象資料後辦理續點：' . $renewalUrl;
        } else {
            $content .= ' 本年度點燈到期日為 ' . $period['blessing_end_date'] . '。';
        }

        foreach (expiration_reminder_recipients($period) as $userId) {
            $notificationId = create_job_notification($job, $userId, $subject, $content);
            if ($notificationId !== null) {
                $createdCount++;
                notification_process_by_id($notificationId, true, null);
                $delivery = notification_latest_delivery($notificationId);
                if (($delivery['delivery_status'] ?? '') === 'sent') {
                    $sentCount++;
                } else {
                    $failedCount++;
                }
            }
        }
    } else {
        if ($subject === '') {
            $subject = '系統通知';
        }

        if ($content === '') {
            $content = '後台建立了一筆通知排程，請登入查看最新訊息。';
        }

        foreach (broadcast_recipients() as $userId) {
            $notificationId = create_job_notification($job, $userId, $subject, $content);
            if ($notificationId !== null) {
                $createdCount++;
                notification_process_by_id($notificationId, true, null);
                $delivery = notification_latest_delivery($notificationId);
                if (($delivery['delivery_status'] ?? '') === 'sent') {
                    $sentCount++;
                } else {
                    $failedCount++;
                }
            }
        }
    }

    $before = $job;
    $stmt = db()->prepare(
        'UPDATE scheduled_jobs
         SET last_run_at = NOW(),
             next_run_at = NULL,
             status = "finished"
         WHERE job_id = ?'
    );
    $stmt->execute([(int) $job['job_id']]);

    audit_log(null, 'system_scheduled_job_run', 'scheduled_jobs', (string) $job['job_id'], $before, [
        'created_notifications' => $createdCount,
        'sent_notifications' => $sentCount,
        'failed_notifications' => $failedCount,
        'job_type' => $job['job_type'],
    ]);

    $results[] = 'Job #' . $job['job_id'] . ' completed, notifications created: ' . $createdCount . ', sent: ' . $sentCount . ', failed: ' . $failedCount . '.';
}

if ($results === []) {
    $results[] = 'No due scheduled jobs.';
}

header('Content-Type: text/plain; charset=UTF-8');
echo implode(PHP_EOL, $results) . PHP_EOL;
