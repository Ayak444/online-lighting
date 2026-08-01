<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/frontend.php';
require_once __DIR__ . '/../config/notification_delivery.php';

$user = require_login('login.php');
$userId = (int) $user['user_id'];
$errors = [];
$flash = flash_message();
$old = [
    'category' => 'qa',
    'subject' => '',
    'content' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors[] = '表單已過期，請重新送出。';
    }

    $old['category'] = trim((string) ($_POST['category'] ?? 'qa'));
    $old['subject'] = trim((string) ($_POST['subject'] ?? ''));
    $old['content'] = trim((string) ($_POST['content'] ?? ''));

    if (!in_array($old['category'], ['qa', 'bug', 'order', 'other'], true)) {
        $errors[] = '問題分類不正確。';
    }

    if ($old['subject'] === '') {
        $errors[] = '請輸入主旨。';
    }

    if ($old['content'] === '') {
        $errors[] = '請輸入問題內容。';
    }

    if ($errors === []) {
        $stmt = db()->prepare(
            'INSERT INTO feedbacks (user_id, category, subject, content, status)
             VALUES (?, ?, ?, ?, "open")'
        );
        $stmt->execute([
            $userId,
            $old['category'],
            $old['subject'],
            $old['content'],
        ]);
        $feedbackId = (int) db()->lastInsertId();

        $stmt = db()->prepare(
            'INSERT INTO notifications (user_id, channel, subject, content, status)
             VALUES (?, "email", ?, ?, "pending")'
        );
        $stmt->execute([
            $userId,
            '已收到您的問題回饋',
            '您好，我們已收到您的問題回饋：「' . $old['subject'] . '」。後台管理員處理或回覆時，系統會再寄 Email 通知您。',
        ]);
        notification_process_by_id((int) db()->lastInsertId(), true);

        audit_log($userId, 'feedback_create', 'feedbacks', (string) $feedbackId, null, $old);
        set_flash('問題回饋已送出。');
        redirect('feedback.php');
    }
}

$stmt = db()->prepare(
    'SELECT feedback_id, category, subject, content, status, admin_reply, replied_at, created_at
     FROM feedbacks
     WHERE user_id = ?
     ORDER BY created_at DESC, feedback_id DESC'
);
$stmt->execute([$userId]);
$feedbacks = $stmt->fetchAll();
?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>問題回饋</title>
    <link rel="stylesheet" href="../assets/css/main.css">
</head>
<body>
    <header class="site-header">
        <h1>問題回饋</h1>
        <?= public_nav($user) ?>
    </header>

    <main class="page">
        <h1>問題回饋系統</h1>

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
            <h2>送出回饋</h2>
            <form class="form" method="post" action="feedback.php">
                <?= csrf_field() ?>

                <label>
                    分類
                    <select name="category">
                        <option value="qa" <?= $old['category'] === 'qa' ? 'selected' : '' ?>>Q&amp;A</option>
                        <option value="bug" <?= $old['category'] === 'bug' ? 'selected' : '' ?>>Bug 回報</option>
                        <option value="order" <?= $old['category'] === 'order' ? 'selected' : '' ?>>訂單問題</option>
                        <option value="other" <?= $old['category'] === 'other' ? 'selected' : '' ?>>其他</option>
                    </select>
                </label>

                <label>
                    主旨
                    <input type="text" name="subject" value="<?= e($old['subject']) ?>" required>
                </label>

                <label>
                    內容
                    <textarea name="content" rows="5" required><?= e($old['content']) ?></textarea>
                </label>

                <button class="button" type="submit">送出回饋</button>
            </form>
        </section>

        <section class="panel section-gap">
            <h2>我的回饋紀錄</h2>

            <?php if ($feedbacks === []): ?>
                <p class="helper-text">目前尚無回饋紀錄。</p>
            <?php else: ?>
                <div class="stack-list">
                    <?php foreach ($feedbacks as $feedback): ?>
                        <article class="list-item">
                            <div class="section-heading">
                                <h3><?= e($feedback['subject']) ?></h3>
                                <span class="status-pill"><?= e(feedback_status_label($feedback['status'])) ?></span>
                            </div>
                            <p class="helper-text"><?= e(feedback_category_label($feedback['category'])) ?></p>
                            <p><?= e($feedback['content']) ?></p>
                            <?php if ($feedback['admin_reply']): ?>
                                <p><strong>後台回覆：</strong><?= e($feedback['admin_reply']) ?></p>
                                <p class="helper-text">回覆時間：<?= e($feedback['replied_at'] ?? '-') ?></p>
                            <?php endif; ?>
                            <p class="helper-text">送出時間：<?= e($feedback['created_at']) ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
