<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/frontend.php';
require_once __DIR__ . '/../config/renewal_notifications.php';

$admin = require_admin('login.php');
$adminId = (int) $admin['user_id'];
$errors = [];
$flash = flash_message();
$editJobId = (int) ($_GET['edit'] ?? 0);

function admin_nav(): string
{
    $links = [
        ['dashboard.php', '儀表板'],
        ['lantern_types.php', '燈種'],
        ['lamp_positions.php', '燈位'],
        ['orders.php', '訂單'],
        ['service_periods.php', '年度燈期'],
        ['annual_flow_rules.php', '流年規則'],
        ['reports.php', '統計報表'],
        ['scheduled_jobs.php', '排程任務'],
        ['notifications.php', '通知中心'],
        ['users.php', '會員權限'],
        ['articles.php', '內容管理'],
        ['feedbacks.php', '問題回饋'],
        ['logs.php', '操作軌跡'],
        ['logout.php', '登出'],
    ];

    $html = '<nav>';
    foreach ($links as [$href, $label]) {
        $html .= '<a href="' . e($href) . '">' . e($label) . '</a>';
    }
    $html .= '</nav>';

    return $html;
}

function datetime_local_to_sql(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    $value = str_replace('T', ' ', $value);
    $date = DateTimeImmutable::createFromFormat('Y-m-d H:i', $value);
    if (!$date) {
        return null;
    }

    return $date->format('Y-m-d H:i:s');
}

function load_job(int $jobId): ?array
{
    $stmt = db()->prepare('SELECT * FROM scheduled_jobs WHERE job_id = ? LIMIT 1');
    $stmt->execute([$jobId]);
    $job = $stmt->fetch();

    return $job ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors[] = '表單驗證失敗，請重新送出。';
    }

    $action = trim((string) ($_POST['action'] ?? 'save_job'));
    $jobId = (int) ($_POST['job_id'] ?? 0);

    if ($errors === [] && $action === 'save_job') {
        $jobName = trim((string) ($_POST['job_name'] ?? ''));
        $jobType = trim((string) ($_POST['job_type'] ?? 'expiration_reminder'));
        $triggerTime = datetime_local_to_sql($_POST['trigger_time'] ?? null);
        $cronExpression = trim((string) ($_POST['cron_expression'] ?? '')) ?: null;
        $targetChannel = trim((string) ($_POST['target_channel'] ?? 'email'));
        $subject = trim((string) ($_POST['subject'] ?? '')) ?: null;
        $content = trim((string) ($_POST['content'] ?? '')) ?: null;
        $status = trim((string) ($_POST['status'] ?? 'active'));
        $nextRunAt = datetime_local_to_sql($_POST['next_run_at'] ?? null);

        if ($jobName === '') {
            $errors[] = '任務名稱不可空白。';
        }

        if (!in_array($jobType, ['expiration_reminder', 'event_broadcast', 'custom'], true)) {
            $errors[] = '任務類型不正確。';
        }

        if (!in_array($targetChannel, ['email', 'sms', 'line', 'system'], true)) {
            $errors[] = '通知渠道不正確。';
        }

        if (!in_array($status, ['active', 'paused', 'finished'], true)) {
            $errors[] = '排程狀態不正確。';
        }

        if ($nextRunAt === null) {
            $errors[] = '下次執行時間必填。';
        }

        if ($errors === []) {
            $pdo = db();
            $before = null;

            if ($jobId > 0) {
                $stmt = $pdo->prepare('SELECT * FROM scheduled_jobs WHERE job_id = ? LIMIT 1');
                $stmt->execute([$jobId]);
                $before = $stmt->fetch();

                if (!$before) {
                    $errors[] = '找不到要更新的排程任務。';
                }
            }

            if ($errors === []) {
                if ($jobId > 0) {
                    $stmt = $pdo->prepare(
                        'UPDATE scheduled_jobs
                         SET job_name = ?,
                             job_type = ?,
                             trigger_time = ?,
                             cron_expression = ?,
                             target_channel = ?,
                             subject = ?,
                             content = ?,
                             status = ?,
                             next_run_at = ?
                         WHERE job_id = ?'
                    );
                    $stmt->execute([
                        $jobName,
                        $jobType,
                        $triggerTime,
                        $cronExpression,
                        $targetChannel,
                        $subject,
                        $content,
                        $status,
                        $nextRunAt,
                        $jobId,
                    ]);
                } else {
                    $stmt = $pdo->prepare(
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
                         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    $stmt->execute([
                        $adminId,
                        $jobName,
                        $jobType,
                        $triggerTime,
                        $cronExpression,
                        $targetChannel,
                        $subject,
                        $content,
                        $status,
                        $nextRunAt,
                    ]);
                    $jobId = (int) $pdo->lastInsertId();
                }

                $after = load_job($jobId);
                audit_log($adminId, 'admin_scheduled_job_save', 'scheduled_jobs', (string) $jobId, $before ?: null, $after);
                set_flash('排程任務已儲存。');
                redirect('scheduled_jobs.php?edit=' . $jobId);
            }
        }
    }

    if ($errors === [] && $action === 'send_renewal_now') {
        $period = active_lamp_service_period();
        if ($period === null) {
            $errors[] = '目前沒有啟用中的年度燈期，無法發送續點通知。';
        } else {
            $result = send_renewal_notifications_now($period, $adminId);
            set_flash(
                '續點通知已發送。對象 ' . (int) $result['recipient_count']
                . ' 位，成功 ' . (int) $result['sent_count']
                . ' 位，失敗 ' . (int) $result['failed_count'] . ' 位。'
            );
            redirect('scheduled_jobs.php');
        }
    }

    if ($errors === [] && $action === 'update_status') {
        $nextStatus = trim((string) ($_POST['next_status'] ?? 'paused'));
        if (!in_array($nextStatus, ['active', 'paused', 'finished'], true)) {
            $errors[] = '要更新的狀態不正確。';
        }

        if ($errors === []) {
            $stmt = db()->prepare('SELECT * FROM scheduled_jobs WHERE job_id = ? LIMIT 1');
            $stmt->execute([$jobId]);
            $before = $stmt->fetch();

            if (!$before) {
                $errors[] = '找不到排程任務。';
            } else {
                $stmt = db()->prepare('UPDATE scheduled_jobs SET status = ? WHERE job_id = ?');
                $stmt->execute([$nextStatus, $jobId]);
                $after = load_job($jobId);
                audit_log($adminId, 'admin_scheduled_job_status', 'scheduled_jobs', (string) $jobId, $before, $after);
                set_flash('排程狀態已更新。');
                redirect('scheduled_jobs.php');
            }
        }
    }

    if ($errors === [] && $action === 'delete_job') {
        $before = load_job($jobId);
        if ($before === null) {
            $errors[] = '找不到要刪除的排程。';
        } else {
            $stmt = db()->prepare('DELETE FROM scheduled_jobs WHERE job_id = ?');
            $stmt->execute([$jobId]);
            audit_log($adminId, 'admin_scheduled_job_delete', 'scheduled_jobs', (string) $jobId, $before, null);
            set_flash('排程已刪除。');
            redirect('scheduled_jobs.php');
        }
    }
}

$jobs = db()->query(
    'SELECT sj.*, u.name AS creator_name
     FROM scheduled_jobs sj
     LEFT JOIN users u ON u.user_id = sj.created_by
     ORDER BY sj.created_at DESC, sj.job_id DESC'
)->fetchAll();

$editJob = $editJobId > 0 ? load_job($editJobId) : null;
$formJob = $editJob ?: [
    'job_id' => 0,
    'job_name' => '',
    'job_type' => 'expiration_reminder',
    'trigger_time' => null,
    'cron_expression' => '',
    'target_channel' => 'email',
    'subject' => '',
    'content' => '',
    'status' => 'active',
    'next_run_at' => date('Y-m-d H:i:s', strtotime('+1 day')),
];
?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>排程任務</title>
    <link rel="stylesheet" href="../assets/css/main.css">
</head>
<body>
    <header class="site-header">
        <h1>後台管理</h1>
        <?= admin_nav() ?>
    </header>

    <main class="page">
        <h1>排程任務</h1>

        <?php if ($flash): ?>
            <div class="alert success"><p><?= e($flash) ?></p></div>
        <?php endif; ?>

        <?php if ($errors !== []): ?>
            <div class="alert error">
                <?php foreach ($errors as $error): ?>
                    <p><?= e($error) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <section class="panel">
            <div class="section-heading">
                <div>
                    <h2>續點通知</h2>
                    <p class="helper-text">立即寄送 Email 給目前年度已付款且已審核通過的點燈會員，提醒年度燈期到期並前往智慧續點。</p>
                </div>
                <form method="post" action="scheduled_jobs.php" onsubmit="return confirm('確定要立即發送續點通知 Email？');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="send_renewal_now">
                    <button class="button" type="submit">一鍵發送續點通知</button>
                </form>
            </div>
        </section>

        <section class="panel">
            <div class="section-heading">
                <div>
                    <h2><?= (int) $formJob['job_id'] > 0 ? '編輯排程任務' : '新增排程任務' ?></h2>
                    <p class="helper-text">年度點燈提醒與活動廣播都會先建成排程，再由系統任務執行。</p>
                </div>
            </div>

            <form class="compact-form grid-form" method="post" action="scheduled_jobs.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_job">
                <input type="hidden" name="job_id" value="<?= e((string) $formJob['job_id']) ?>">

                <label>
                    任務名稱
                    <input type="text" name="job_name" value="<?= e((string) $formJob['job_name']) ?>" required>
                </label>

                <label>
                    任務類型
                    <select name="job_type" required>
                        <?php foreach (['expiration_reminder', 'event_broadcast', 'custom'] as $jobType): ?>
                            <option value="<?= e($jobType) ?>" <?= $formJob['job_type'] === $jobType ? 'selected' : '' ?>>
                                <?= e(scheduled_job_type_label($jobType)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    通知渠道
                    <select name="target_channel" required>
                        <?php foreach (['email', 'sms', 'line', 'system'] as $channel): ?>
                            <option value="<?= e($channel) ?>" <?= $formJob['target_channel'] === $channel ? 'selected' : '' ?>>
                                <?= e(notification_channel_label($channel)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    狀態
                    <select name="status" required>
                        <?php foreach (['active', 'paused', 'finished'] as $status): ?>
                            <option value="<?= e($status) ?>" <?= $formJob['status'] === $status ? 'selected' : '' ?>>
                                <?= e(scheduled_job_status_label($status)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    觸發時間
                    <input type="datetime-local" name="trigger_time" value="<?= e($formJob['trigger_time'] ? date('Y-m-d\TH:i', strtotime((string) $formJob['trigger_time'])) : '') ?>">
                </label>

                <label>
                    下次執行時間
                    <input type="datetime-local" name="next_run_at" value="<?= e($formJob['next_run_at'] ? date('Y-m-d\TH:i', strtotime((string) $formJob['next_run_at'])) : '') ?>" required>
                </label>

                <label class="full-span">
                    Cron 表示式
                    <input type="text" name="cron_expression" value="<?= e((string) ($formJob['cron_expression'] ?? '')) ?>" placeholder="例如 0 9 * * *">
                </label>

                <label class="full-span">
                    通知標題
                    <input type="text" name="subject" value="<?= e((string) ($formJob['subject'] ?? '')) ?>">
                </label>

                <label class="full-span">
                    通知內容
                    <textarea name="content" rows="4"><?= e((string) ($formJob['content'] ?? '')) ?></textarea>
                </label>

                <div class="full-span admin-actions">
                    <button class="button" type="submit">儲存排程</button>
                    <?php if ((int) $formJob['job_id'] > 0): ?>
                        <a class="button secondary" href="scheduled_jobs.php">新增另一筆</a>
                    <?php endif; ?>
                </div>
            </form>
        </section>

        <section class="panel section-gap">
            <div class="section-heading">
                <div>
                    <h2>排程列表</h2>
                </div>
            </div>

            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>名稱</th>
                            <th>類型</th>
                            <th>渠道</th>
                            <th>狀態</th>
                            <th>下次執行</th>
                            <th>上次執行</th>
                            <th>建立者</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($jobs as $job): ?>
                            <tr>
                                <td><?= e($job['job_name']) ?></td>
                                <td><?= e(scheduled_job_type_label($job['job_type'])) ?></td>
                                <td><?= e(notification_channel_label($job['target_channel'])) ?></td>
                                <td><?= e(scheduled_job_status_label($job['status'])) ?></td>
                                <td><?= e((string) ($job['next_run_at'] ?? '未設定')) ?></td>
                                <td><?= e((string) ($job['last_run_at'] ?? '尚未執行')) ?></td>
                                <td><?= e((string) ($job['creator_name'] ?? '系統')) ?></td>
                                <td>
                                    <div class="table-actions">
                                        <a href="scheduled_jobs.php?edit=<?= e((string) $job['job_id']) ?>">編輯</a>
                                        <form method="post" action="scheduled_jobs.php">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="job_id" value="<?= e((string) $job['job_id']) ?>">
                                            <input type="hidden" name="next_status" value="<?= $job['status'] === 'active' ? 'paused' : 'active' ?>">
                                            <button class="link-button" type="submit"><?= $job['status'] === 'active' ? '暫停' : '啟用' ?></button>
                                        </form>
                                        <form method="post" action="scheduled_jobs.php" onsubmit="return confirm('確定要刪除此排程？');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete_job">
                                            <input type="hidden" name="job_id" value="<?= e((string) $job['job_id']) ?>">
                                            <button class="link-button danger" type="submit">刪除</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</body>
</html>
