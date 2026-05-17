<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/frontend.php';

$admin = require_admin('login.php');
$adminId = (int) $admin['user_id'];
$errors = [];
$flash = flash_message();
$statusFilter = trim((string) ($_GET['status'] ?? ''));
$channelFilter = trim((string) ($_GET['channel'] ?? ''));
$query = trim((string) ($_GET['q'] ?? ''));

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors[] = '表單驗證失敗，請重新送出。';
    }

    $notifyId = (int) ($_POST['notify_id'] ?? 0);
    $nextStatus = trim((string) ($_POST['next_status'] ?? ''));

    if (!in_array($nextStatus, ['pending', 'sent', 'failed', 'read'], true)) {
        $errors[] = '通知狀態不正確。';
    }

    if ($errors === []) {
        $stmt = db()->prepare('SELECT * FROM notifications WHERE notify_id = ? LIMIT 1');
        $stmt->execute([$notifyId]);
        $before = $stmt->fetch();

        if (!$before) {
            $errors[] = '找不到通知紀錄。';
        } else {
            $sentAt = $nextStatus === 'sent' ? date('Y-m-d H:i:s') : $before['sent_at'];
            $stmt = db()->prepare(
                'UPDATE notifications
                 SET status = ?, sent_at = ?
                 WHERE notify_id = ?'
            );
            $stmt->execute([$nextStatus, $sentAt, $notifyId]);

            $stmt = db()->prepare('SELECT * FROM notifications WHERE notify_id = ? LIMIT 1');
            $stmt->execute([$notifyId]);
            $after = $stmt->fetch();

            audit_log($adminId, 'admin_notification_status', 'notifications', (string) $notifyId, $before, $after ?: null);
            set_flash('通知狀態已更新。');
            redirect('notifications.php');
        }
    }
}

$where = [];
$params = [];

if ($statusFilter !== '' && in_array($statusFilter, ['pending', 'sent', 'failed', 'read'], true)) {
    $where[] = 'n.status = ?';
    $params[] = $statusFilter;
}

if ($channelFilter !== '' && in_array($channelFilter, ['email', 'sms', 'line', 'system'], true)) {
    $where[] = 'n.channel = ?';
    $params[] = $channelFilter;
}

if ($query !== '') {
    $where[] = '(n.subject LIKE ? OR n.content LIKE ? OR u.email LIKE ? OR o.order_number LIKE ?)';
    $like = '%' . $query . '%';
    array_push($params, $like, $like, $like, $like);
}

$sql =
    'SELECT n.*, u.name AS user_name, u.email, o.order_number, sj.job_name,
            (
                SELECT nd.status
                FROM notification_deliveries nd
                WHERE nd.notify_id = n.notify_id
                ORDER BY nd.attempt_no DESC, nd.delivery_id DESC
                LIMIT 1
            ) AS delivery_status,
            (
                SELECT nd.provider
                FROM notification_deliveries nd
                WHERE nd.notify_id = n.notify_id
                ORDER BY nd.attempt_no DESC, nd.delivery_id DESC
                LIMIT 1
            ) AS delivery_provider,
            (
                SELECT nd.error_message
                FROM notification_deliveries nd
                WHERE nd.notify_id = n.notify_id
                ORDER BY nd.attempt_no DESC, nd.delivery_id DESC
                LIMIT 1
            ) AS delivery_error,
            (
                SELECT COUNT(*)
                FROM notification_deliveries nd
                WHERE nd.notify_id = n.notify_id
            ) AS delivery_attempt_count
     FROM notifications n
     INNER JOIN users u ON u.user_id = n.user_id
     LEFT JOIN orders o ON o.order_id = n.order_id
     LEFT JOIN scheduled_jobs sj ON sj.job_id = n.job_id';

if ($where !== []) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= ' ORDER BY n.created_at DESC, n.notify_id DESC LIMIT 200';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$notifications = $stmt->fetchAll();
?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>通知中心</title>
    <link rel="stylesheet" href="../assets/css/main.css">
</head>
<body>
    <header class="site-header">
        <h1>後台管理</h1>
        <?= admin_nav() ?>
    </header>

    <main class="page">
        <h1>通知中心</h1>

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
            <form class="filter-form log-filter-form" method="get" action="notifications.php">
                <input type="search" name="q" value="<?= e($query) ?>" placeholder="搜尋主旨、內容、會員或訂單">
                <select name="channel">
                    <option value="">全部渠道</option>
                    <?php foreach (['email', 'sms', 'line', 'system'] as $channel): ?>
                        <option value="<?= e($channel) ?>" <?= $channelFilter === $channel ? 'selected' : '' ?>>
                            <?= e(notification_channel_label($channel)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select name="status">
                    <option value="">全部狀態</option>
                    <?php foreach (['pending', 'sent', 'failed', 'read'] as $status): ?>
                        <option value="<?= e($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>>
                            <?= e(notification_status_label($status)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button class="button" type="submit">查詢</button>
            </form>
        </section>

        <section class="panel section-gap">
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>建立時間</th>
                            <th>會員</th>
                            <th>渠道</th>
                            <th>主旨</th>
                            <th>來源</th>
                            <th>狀態</th>
                            <th>投遞</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($notifications as $notification): ?>
                            <tr>
                                <td><?= e($notification['created_at']) ?></td>
                                <td>
                                    <?= e($notification['user_name']) ?><br>
                                    <span class="helper-text"><?= e($notification['email']) ?></span>
                                </td>
                                <td><?= e(notification_channel_label($notification['channel'])) ?></td>
                                <td>
                                    <strong><?= e($notification['subject']) ?></strong>
                                    <p class="helper-text"><?= e($notification['content']) ?></p>
                                </td>
                                <td>
                                    <?= e((string) ($notification['job_name'] ?? '即時通知')) ?>
                                    <?php if ($notification['order_number']): ?>
                                        <p class="helper-text">訂單 <?= e($notification['order_number']) ?></p>
                                    <?php endif; ?>
                                </td>
                                <td><?= e(notification_status_label($notification['status'])) ?></td>
                                <td>
                                    <?= e(notification_delivery_status_label($notification['delivery_status'] ?? null)) ?>
                                    <p class="helper-text">
                                        <?= e((string) ($notification['delivery_provider'] ?? '-')) ?>
                                        / 第 <?= e((string) ($notification['delivery_attempt_count'] ?? 0)) ?> 次
                                    </p>
                                    <?php if (!empty($notification['delivery_error'])): ?>
                                        <p class="helper-text"><?= e((string) $notification['delivery_error']) ?></p>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form class="inline-form" method="post" action="notifications.php">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="notify_id" value="<?= e((string) $notification['notify_id']) ?>">
                                        <select name="next_status">
                                            <?php foreach (['pending', 'sent', 'failed', 'read'] as $status): ?>
                                                <option value="<?= e($status) ?>" <?= $notification['status'] === $status ? 'selected' : '' ?>>
                                                    <?= e(notification_status_label($status)) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button class="button secondary" type="submit">更新</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($notifications === []): ?>
                            <tr>
                                <td colspan="8">目前沒有符合條件的通知。</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</body>
</html>
