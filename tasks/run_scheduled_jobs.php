<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/frontend.php';

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

function create_job_notification(array $job, int $userId, string $subject, string $content): bool
{
    if (notification_already_exists((int) $job['job_id'], $userId, $subject)) {
        return false;
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

    return true;
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
    $subject = trim((string) ($job['subject'] ?? ''));
    $content = trim((string) ($job['content'] ?? ''));

    if ($job['job_type'] === 'expiration_reminder') {
        $period = active_lamp_service_period();
        if ($period === null) {
            $results[] = 'Job #' . $job['job_id'] . ' skipped: no active lamp service period.';
            continue;
        }

        if ($subject === '') {
            $subject = '年度點燈即將謝燈';
        }

        if ($content === '') {
            $content = '您的 ' . $period['service_year'] . ' 年度點燈服務將於 ' . $period['blessing_end_date'] . ' 謝燈，敬請留意後續公告。';
        } else {
            $content .= ' 本年度謝燈日為 ' . $period['blessing_end_date'] . '。';
        }

        foreach (expiration_reminder_recipients($period) as $userId) {
            if (create_job_notification($job, $userId, $subject, $content)) {
                $createdCount++;
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
            if (create_job_notification($job, $userId, $subject, $content)) {
                $createdCount++;
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
        'job_type' => $job['job_type'],
    ]);

    $results[] = 'Job #' . $job['job_id'] . ' completed, notifications created: ' . $createdCount . '.';
}

if ($results === []) {
    $results[] = 'No due scheduled jobs.';
}

header('Content-Type: text/plain; charset=UTF-8');
echo implode(PHP_EOL, $results) . PHP_EOL;
