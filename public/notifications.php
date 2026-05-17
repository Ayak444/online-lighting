<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/frontend.php';

$user = require_login('login.php');
$userId = (int) $user['user_id'];
$errors = [];
$flash = flash_message();
$statusFilter = trim((string) ($_GET['status'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors[] = '表單驗證失敗，請重新送出。';
    }

    $notifyId = (int) ($_POST['notify_id'] ?? 0);

    if ($errors === []) {
        $stmt = db()->prepare(
            'SELECT *
             FROM notifications
             WHERE notify_id = ? AND user_id = ?
             LIMIT 1'
        );
        $stmt->execute([$notifyId, $userId]);
        $before = $stmt->fetch();

        if (!$before) {
            $errors[] = '找不到要更新的通知。';
        } else {
            $stmt = db()->prepare(
                'UPDATE notifications
                 SET status = "read"
                 WHERE notify_id = ? AND user_id = ?'
            );
            $stmt->execute([$notifyId, $userId]);

            $stmt = db()->prepare('SELECT * FROM notifications WHERE notify_id = ? LIMIT 1');
            $stmt->execute([$notifyId]);
            $after = $stmt->fetch();

            audit_log($userId, 'member_notification_read', 'notifications', (string) $notifyId, $before, $after ?: null);
            set_flash('通知已標記為已讀。');
            redirect('notifications.php');
        }
    }
}

$where = ['user_id = ?'];
$params = [$userId];

if ($statusFilter !== '' && in_array($statusFilter, ['pending', 'sent', 'failed', 'read'], true)) {
    $where[] = 'status = ?';
    $params[] = $statusFilter;
}

$stmt = db()->prepare(
    'SELECT notify_id, channel, subject, content, status, sent_at, created_at,
            (
                SELECT nd.status
                FROM notification_deliveries nd
                WHERE nd.notify_id = notifications.notify_id
                ORDER BY nd.attempt_no DESC, nd.delivery_id DESC
                LIMIT 1
            ) AS delivery_status,
            (
                SELECT nd.provider
                FROM notification_deliveries nd
                WHERE nd.notify_id = notifications.notify_id
                ORDER BY nd.attempt_no DESC, nd.delivery_id DESC
                LIMIT 1
            ) AS delivery_provider,
            (
                SELECT nd.error_message
                FROM notification_deliveries nd
                WHERE nd.notify_id = notifications.notify_id
                ORDER BY nd.attempt_no DESC, nd.delivery_id DESC
                LIMIT 1
            ) AS delivery_error
     FROM notifications
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY created_at DESC, notify_id DESC'
);
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
        <h1>通知中心</h1>
        <?= public_nav($user) ?>
    </header>

    <main class="page">
        <h1>我的通知</h1>

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
            <form class="filter-form" method="get" action="notifications.php">
                <select name="status">
                    <option value="">全部通知</option>
                    <?php foreach (['pending', 'sent', 'failed', 'read'] as $status): ?>
                        <option value="<?= e($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>>
                            <?= e(notification_status_label($status)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button class="button" type="submit">篩選</button>
            </form>
        </section>

        <section class="panel section-gap">
            <?php if ($notifications === []): ?>
                <p class="helper-text">目前沒有通知。</p>
            <?php else: ?>
                <div class="stack-list">
                    <?php foreach ($notifications as $notification): ?>
                        <article class="list-item">
                            <div class="section-heading">
                                <div>
                                    <h2><?= e($notification['subject']) ?></h2>
                                    <p class="helper-text">
                                        <?= e(notification_channel_label($notification['channel'])) ?> /
                                        <?= e(notification_status_label($notification['status'])) ?> /
                                        <?= e(notification_delivery_status_label($notification['delivery_status'] ?? null)) ?> /
                                        <?= e($notification['created_at']) ?>
                                    </p>
                                </div>
                                <?php if ($notification['status'] !== 'read'): ?>
                                    <form method="post" action="notifications.php">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="notify_id" value="<?= e((string) $notification['notify_id']) ?>">
                                        <button class="button secondary" type="submit">標記已讀</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                            <p><?= e($notification['content']) ?></p>
                            <?php if ($notification['sent_at']): ?>
                                <p class="helper-text">發送時間：<?= e($notification['sent_at']) ?></p>
                            <?php endif; ?>
                            <?php if (!empty($notification['delivery_provider'])): ?>
                                <p class="helper-text">投遞來源：<?= e((string) $notification['delivery_provider']) ?></p>
                            <?php endif; ?>
                            <?php if (!empty($notification['delivery_error'])): ?>
                                <p class="helper-text">投遞說明：<?= e((string) $notification['delivery_error']) ?></p>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
