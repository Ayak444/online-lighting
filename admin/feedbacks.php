<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/frontend.php';

$admin = require_admin('login.php');
$adminId = (int) $admin['user_id'];
$errors = [];
$flash = flash_message();

function admin_nav(): string
{
    $links = [
        ['dashboard.php', '總覽'],
        ['lantern_types.php', '燈種'],
        ['lamp_positions.php', '燈位'],
        ['orders.php', '訂單'],
        ['service_periods.php', '年度燈期'],
        ['annual_flow_rules.php', '流年規則'],
        ['reports.php', '統計報表'],
        ['scheduled_jobs.php', '排程任務'],
        ['notifications.php', '通知中心'],
        ['users.php', '會員權限'],
        ['articles.php', '公告文化'],
        ['feedbacks.php', '回饋'],
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
        $errors[] = '表單已過期，請重新送出。';
    }

    $feedbackId = (int) ($_POST['feedback_id'] ?? 0);
    $status = trim((string) ($_POST['status'] ?? 'processing'));
    $adminReply = trim((string) ($_POST['admin_reply'] ?? ''));

    if ($feedbackId <= 0) {
        $errors[] = '找不到要回覆的回饋。';
    }

    if (!in_array($status, ['open', 'processing', 'closed'], true)) {
        $errors[] = '回饋狀態不正確。';
    }

    if ($status === 'closed' && $adminReply === '') {
        $errors[] = '結案前請輸入後台回覆。';
    }

    if ($errors === []) {
        $pdo = db();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'SELECT f.*, u.email, u.name AS user_name
                 FROM feedbacks f
                 LEFT JOIN users u ON u.user_id = f.user_id
                 WHERE f.feedback_id = ?
                 LIMIT 1
                 FOR UPDATE'
            );
            $stmt->execute([$feedbackId]);
            $before = $stmt->fetch();

            if (!$before) {
                throw new RuntimeException('找不到要回覆的回饋。');
            }

            $replyValue = $adminReply === '' ? null : $adminReply;
            $repliedAt = $replyValue === null ? null : date('Y-m-d H:i:s');
            $stmt = $pdo->prepare(
                'UPDATE feedbacks
                 SET status = ?, admin_reply = ?, replied_by = ?, replied_at = ?
                 WHERE feedback_id = ?'
            );
            $stmt->execute([
                $status,
                $replyValue,
                $replyValue === null ? null : $adminId,
                $repliedAt,
                $feedbackId,
            ]);

            if (!empty($before['user_id']) && $replyValue !== null) {
                $stmt = $pdo->prepare(
                    'INSERT INTO notifications (user_id, channel, subject, content, status)
                     VALUES (?, "email", ?, ?, "pending")'
                );
                $stmt->execute([
                    (int) $before['user_id'],
                    '問題回饋已回覆',
                    '您的回饋「' . $before['subject'] . '」已有後台回覆，請至問題回饋頁查看。',
                ]);
            }

            audit_log($adminId, 'admin_feedback_reply', 'feedbacks', (string) $feedbackId, $before, [
                'status' => $status,
                'admin_reply' => $replyValue,
                'replied_by' => $adminId,
                'replied_at' => $repliedAt,
            ]);

            $pdo->commit();
            set_flash('回饋已更新。');
            redirect('feedbacks.php');
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $errors[] = $throwable->getMessage();
        }
    }
}

$statusFilter = trim((string) ($_GET['status'] ?? 'all'));
$filters = [
    'all' => ['全部', '1 = 1'],
    'open' => ['待處理', 'f.status = "open"'],
    'processing' => ['處理中', 'f.status = "processing"'],
    'closed' => ['已結案', 'f.status = "closed"'],
];

if (!isset($filters[$statusFilter])) {
    $statusFilter = 'all';
}

$stmt = db()->query(
    'SELECT f.*, u.name AS user_name, u.email, admin_user.name AS replied_by_name
     FROM feedbacks f
     LEFT JOIN users u ON u.user_id = f.user_id
     LEFT JOIN users admin_user ON admin_user.user_id = f.replied_by
     WHERE ' . $filters[$statusFilter][1] . '
     ORDER BY f.created_at DESC, f.feedback_id DESC'
);
$feedbacks = $stmt->fetchAll();
?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>問題回饋管理</title>
    <link rel="stylesheet" href="../assets/css/main.css">
</head>
<body>
    <header class="site-header">
        <h1>後台管理</h1>
        <?= admin_nav() ?>
    </header>

    <main class="page">
        <div class="section-heading">
            <div>
                <h1>問題回饋管理</h1>
                <p class="helper-text">處理 Q&A、Bug、訂單問題與其他會員回饋，回覆後會同步出現在會員前台。</p>
            </div>
        </div>

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

        <div class="filter-row">
            <?php foreach ($filters as $key => [$label]): ?>
                <a class="status-pill <?= $statusFilter === $key ? 'active' : '' ?>" href="feedbacks.php?status=<?= e($key) ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </div>

        <?php if ($feedbacks === []): ?>
            <section class="panel section-gap">
                <p class="helper-text">目前沒有符合條件的回饋。</p>
            </section>
        <?php else: ?>
            <div class="stack-list section-gap">
                <?php foreach ($feedbacks as $feedback): ?>
                    <section class="panel">
                        <div class="section-heading">
                            <div>
                                <h2><?= e($feedback['subject']) ?></h2>
                                <p class="helper-text">
                                    <?= e(feedback_category_label($feedback['category'])) ?> /
                                    <?= e($feedback['user_name'] ?? '訪客') ?> /
                                    <?= e($feedback['email'] ?? '-') ?> /
                                    <?= e($feedback['created_at']) ?>
                                </p>
                            </div>
                            <span class="status-pill"><?= e(feedback_status_label($feedback['status'])) ?></span>
                        </div>

                        <p><?= e($feedback['content']) ?></p>

                        <?php if ($feedback['admin_reply']): ?>
                            <div class="alert">
                                <p><strong>目前回覆：</strong><?= e($feedback['admin_reply']) ?></p>
                                <p class="helper-text">
                                    回覆人：<?= e($feedback['replied_by_name'] ?? '-') ?>，
                                    回覆時間：<?= e($feedback['replied_at'] ?? '-') ?>
                                </p>
                            </div>
                        <?php endif; ?>

                        <form class="form section-gap" method="post" action="feedbacks.php">
                            <?= csrf_field() ?>
                            <input type="hidden" name="feedback_id" value="<?= (int) $feedback['feedback_id'] ?>">

                            <label>
                                處理狀態
                                <select name="status">
                                    <?php foreach (['open', 'processing', 'closed'] as $status): ?>
                                        <option value="<?= e($status) ?>" <?= $feedback['status'] === $status ? 'selected' : '' ?>>
                                            <?= e(feedback_status_label($status)) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>

                            <label>
                                後台回覆
                                <textarea name="admin_reply" rows="4"><?= e((string) ($feedback['admin_reply'] ?? '')) ?></textarea>
                            </label>

                            <button class="button" type="submit">儲存回覆</button>
                        </form>
                    </section>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>
</body>
</html>
