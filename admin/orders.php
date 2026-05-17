<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/frontend.php';

$admin = require_admin('login.php');
$adminId = (int) $admin['user_id'];
$errors = [];
$flash = flash_message();
$focusOrderId = (int) ($_GET['order_id'] ?? 0);

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

function redirect_to_order(int $orderId): void
{
    redirect('orders.php?order_id=' . $orderId);
}

function load_order_for_update(PDO $pdo, int $orderId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT o.*, u.email, u.name AS user_name
         FROM orders o
         INNER JOIN users u ON u.user_id = o.user_id
         WHERE o.order_id = ?
         LIMIT 1
         FOR UPDATE'
    );
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();

    return $order ?: null;
}

function create_order_notification(PDO $pdo, int $userId, int $orderId, ?int $invoiceId, string $subject, string $content): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO notifications (user_id, order_id, invoice_id, channel, subject, content, status)
         VALUES (?, ?, ?, "email", ?, ?, "pending")'
    );
    $stmt->execute([$userId, $orderId, $invoiceId, $subject, $content]);
}

function ensure_invoice(PDO $pdo, array $order): array
{
    $stmt = $pdo->prepare('SELECT * FROM invoices WHERE order_id = ? LIMIT 1 FOR UPDATE');
    $stmt->execute([(int) $order['order_id']]);
    $invoice = $stmt->fetch();

    if ($invoice) {
        return $invoice;
    }

    $invoiceNumber = 'INV' . date('Ymd') . str_pad((string) $order['order_id'], 8, '0', STR_PAD_LEFT);
    $stmt = $pdo->prepare(
        'INSERT INTO invoices (order_id, invoice_number, invoice_status)
         VALUES (?, ?, "issued")'
    );
    $stmt->execute([(int) $order['order_id'], $invoiceNumber]);

    $stmt = $pdo->prepare('SELECT * FROM invoices WHERE invoice_id = ? LIMIT 1');
    $stmt->execute([(int) $pdo->lastInsertId()]);

    return $stmt->fetch();
}

function refresh_assignment_status(PDO $pdo, int $orderId): void
{
    $stmt = $pdo->prepare(
        'SELECT
            COUNT(*) AS total_count,
            SUM(CASE WHEN item_status = "assigned" THEN 1 ELSE 0 END) AS assigned_count
         FROM order_items
         WHERE order_id = ? AND item_status <> "cancelled"'
    );
    $stmt->execute([$orderId]);
    $summary = $stmt->fetch();

    $total = (int) ($summary['total_count'] ?? 0);
    $assigned = (int) ($summary['assigned_count'] ?? 0);

    if ($total > 0 && $total === $assigned) {
        $stmt = $pdo->prepare(
            'UPDATE orders
             SET order_status = "assigned"
             WHERE order_id = ? AND payment_status = "paid" AND review_status = "approved" AND order_status <> "completed"'
        );
        $stmt->execute([$orderId]);
    }
}

function available_positions_for_item(array $positionsByType, int $typeId, ?int $currentPositionId, int $detailId): array
{
    $positions = $positionsByType[$typeId] ?? [];

    return array_values(array_filter($positions, static function (array $position) use ($currentPositionId, $detailId): bool {
        $reservedOrderItemId = $position['reserved_order_item_id'] === null ? null : (int) $position['reserved_order_item_id'];
        $reservedByAnotherItem = $position['active_reservation_id'] !== null && $reservedOrderItemId !== $detailId;

        return !$reservedByAnotherItem
            && ($position['status'] === 'available' || (int) $position['position_id'] === $currentPositionId);
    }));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors[] = '表單已過期，請重新送出。';
    }

    $action = trim((string) ($_POST['action'] ?? ''));
    $orderId = (int) ($_POST['order_id'] ?? 0);

    if ($errors === [] && $orderId <= 0 && $action !== 'assign_position') {
        $errors[] = '找不到要處理的訂單。';
    }

    if ($errors === []) {
        $pdo = db();
        $pdo->beginTransaction();

        try {
            if ($action === 'confirm_payment') {
                $order = load_order_for_update($pdo, $orderId);
                if (!$order) {
                    throw new RuntimeException('找不到要確認付款的訂單。');
                }

                $before = $order;
                $stmt = $pdo->prepare(
                    'SELECT * FROM payments
                     WHERE order_id = ?
                     ORDER BY payment_id DESC
                     LIMIT 1
                     FOR UPDATE'
                );
                $stmt->execute([$orderId]);
                $payment = $stmt->fetch();

                if (!$payment) {
                    throw new RuntimeException('此訂單沒有金流紀錄，無法對帳。');
                }

                $stmt = $pdo->prepare(
                    'UPDATE payments
                     SET payment_status = "confirmed", paid_at = COALESCE(paid_at, NOW())
                     WHERE payment_id = ?'
                );
                $stmt->execute([(int) $payment['payment_id']]);

                $stmt = $pdo->prepare(
                    'UPDATE orders
                     SET payment_status = "paid",
                         order_status = CASE WHEN order_status = "pending_payment" THEN "paid" ELSE order_status END,
                         paid_at = COALESCE(paid_at, NOW())
                     WHERE order_id = ?'
                );
                $stmt->execute([$orderId]);

                $invoice = ensure_invoice($pdo, $order);
                create_order_notification(
                    $pdo,
                    (int) $order['user_id'],
                    $orderId,
                    (int) $invoice['invoice_id'],
                    '付款已確認',
                    '您的訂單 ' . $order['order_number'] . ' 已確認付款，電子收據號碼為 ' . $invoice['invoice_number'] . '。'
                );

                audit_log($adminId, 'admin_payment_confirm', 'orders', (string) $orderId, $before, [
                    'payment_id' => (int) $payment['payment_id'],
                    'invoice_number' => $invoice['invoice_number'],
                    'payment_status' => 'paid',
                ]);

                $pdo->commit();
                set_flash('付款已確認，並已開立電子收據。');
                redirect_to_order($orderId);
            }

            if ($action === 'approve_order') {
                $order = load_order_for_update($pdo, $orderId);
                if (!$order) {
                    throw new RuntimeException('找不到要審核的訂單。');
                }

                if ($order['payment_status'] !== 'paid') {
                    throw new RuntimeException('請先完成金流對帳，再審核通過。');
                }

                $stmt = $pdo->prepare(
                    'UPDATE orders
                     SET review_status = "approved",
                         reviewed_at = NOW(),
                         order_status = CASE WHEN order_status = "pending_payment" THEN "paid" ELSE order_status END
                     WHERE order_id = ?'
                );
                $stmt->execute([$orderId]);

                create_order_notification(
                    $pdo,
                    (int) $order['user_id'],
                    $orderId,
                    null,
                    '訂單審核通過',
                    '您的訂單 ' . $order['order_number'] . ' 已審核通過，後台將為您安排實體燈位。'
                );

                audit_log($adminId, 'admin_order_approve', 'orders', (string) $orderId, $order, [
                    'review_status' => 'approved',
                ]);

                $pdo->commit();
                set_flash('訂單已審核通過。');
                redirect_to_order($orderId);
            }

            if ($action === 'reject_order') {
                $order = load_order_for_update($pdo, $orderId);
                if (!$order) {
                    throw new RuntimeException('找不到要駁回的訂單。');
                }

                $stmt = $pdo->prepare('SELECT position_id FROM order_items WHERE order_id = ? AND position_id IS NOT NULL FOR UPDATE');
                $stmt->execute([$orderId]);
                $positionIds = array_map(static function (array $row): int {
                    return (int) $row['position_id'];
                }, $stmt->fetchAll());

                if ($positionIds !== []) {
                    $placeholders = implode(',', array_fill(0, count($positionIds), '?'));
                    $stmt = $pdo->prepare("UPDATE lamp_positions SET status = 'available', occupied_until = NULL WHERE position_id IN ($placeholders)");
                    $stmt->execute($positionIds);
                }

                $stmt = $pdo->prepare(
                    'UPDATE lamp_reservations lr
                     INNER JOIN order_items oi ON oi.detail_id = lr.order_item_id
                     SET lr.status = "cancelled"
                     WHERE oi.order_id = ?
                       AND lr.status = "active"'
                );
                $stmt->execute([$orderId]);

                $stmt = $pdo->prepare(
                    'UPDATE order_items
                     SET item_status = "cancelled", position_id = NULL, assigned_at = NULL
                     WHERE order_id = ?'
                );
                $stmt->execute([$orderId]);

                $stmt = $pdo->prepare(
                    'UPDATE orders
                     SET review_status = "rejected", order_status = "cancelled", reviewed_at = NOW()
                     WHERE order_id = ?'
                );
                $stmt->execute([$orderId]);

                create_order_notification(
                    $pdo,
                    (int) $order['user_id'],
                    $orderId,
                    null,
                    '訂單審核未通過',
                    '您的訂單 ' . $order['order_number'] . ' 未通過審核，如需協助請透過問題回饋聯絡。'
                );

                audit_log($adminId, 'admin_order_reject', 'orders', (string) $orderId, $order, [
                    'review_status' => 'rejected',
                    'order_status' => 'cancelled',
                    'released_positions' => $positionIds,
                ]);

                $pdo->commit();
                set_flash('訂單已駁回並釋放燈位。');
                redirect_to_order($orderId);
            }

            if ($action === 'assign_position') {
                $detailId = (int) ($_POST['detail_id'] ?? 0);
                $positionId = (int) ($_POST['position_id'] ?? 0);

                if ($detailId <= 0 || $positionId <= 0) {
                    throw new RuntimeException('請選擇要分配的燈位。');
                }

                $stmt = $pdo->prepare(
                    'SELECT oi.*, o.user_id, o.order_id, o.order_number, o.payment_status, o.review_status, o.order_status, o.paid_at
                     FROM order_items oi
                     INNER JOIN orders o ON o.order_id = oi.order_id
                     WHERE oi.detail_id = ?
                     LIMIT 1
                     FOR UPDATE'
                );
                $stmt->execute([$detailId]);
                $item = $stmt->fetch();

                if (!$item) {
                    throw new RuntimeException('找不到要分配燈位的訂單明細。');
                }

                if ($item['payment_status'] !== 'paid' || $item['review_status'] !== 'approved') {
                    throw new RuntimeException('請先完成付款確認與訂單審核，再分配燈位。');
                }

                if (in_array($item['item_status'], ['completed', 'cancelled'], true)) {
                    throw new RuntimeException('此明細狀態不可再分配燈位。');
                }

                $stmt = $pdo->prepare('SELECT * FROM lamp_positions WHERE position_id = ? LIMIT 1 FOR UPDATE');
                $stmt->execute([$positionId]);
                $position = $stmt->fetch();

                if (!$position || (int) $position['type_id'] !== (int) $item['type_id']) {
                    throw new RuntimeException('選擇的燈位不符合此燈種。');
                }

                $currentPositionId = $item['position_id'] === null ? null : (int) $item['position_id'];
                if ($position['status'] !== 'available' && (int) $position['position_id'] !== $currentPositionId) {
                    throw new RuntimeException('此燈位目前不是空位。');
                }

                expire_lamp_reservations($pdo);
                $stmt = $pdo->prepare(
                    'SELECT *
                     FROM lamp_reservations
                     WHERE position_id = ?
                       AND status = "active"
                       AND expires_at > NOW()
                     ORDER BY reservation_id DESC
                     LIMIT 1
                     FOR UPDATE'
                );
                $stmt->execute([$positionId]);
                $activeReservation = $stmt->fetch();
                if ($activeReservation && (int) ($activeReservation['order_item_id'] ?? 0) !== $detailId) {
                    throw new RuntimeException('此燈位目前已被其他購物車或訂單保留。');
                }

                if ($currentPositionId !== null && $currentPositionId !== (int) $position['position_id']) {
                    $stmt = $pdo->prepare('UPDATE lamp_positions SET status = "available", occupied_until = NULL WHERE position_id = ?');
                    $stmt->execute([$currentPositionId]);
                }

                $activePeriod = active_lamp_service_period();
                $blessingStartDate = $item['blessing_start_date'] ?: lamp_blessing_start_date((string) ($item['paid_at'] ?? ''));
                $occupiedUntil = $item['blessing_end_date'] ?: ($activePeriod['blessing_end_date'] ?? null);

                if ($occupiedUntil === null) {
                    throw new RuntimeException('目前沒有可套用的年度謝燈日，請先設定年度燈期。');
                }

                $stmt = $pdo->prepare(
                    'UPDATE lamp_positions
                     SET status = "occupied", occupied_until = ?
                     WHERE position_id = ?'
                );
                $stmt->execute([$occupiedUntil, $positionId]);

                $stmt = $pdo->prepare(
                    'UPDATE order_items
                     SET position_id = ?, assigned_at = NOW(), item_status = "assigned",
                         blessing_start_date = COALESCE(blessing_start_date, ?),
                         blessing_end_date = COALESCE(blessing_end_date, ?)
                     WHERE detail_id = ?'
                );
                $stmt->execute([$positionId, $blessingStartDate, $occupiedUntil, $detailId]);

                if ($activeReservation) {
                    $stmt = $pdo->prepare(
                        'UPDATE lamp_reservations
                         SET status = "converted",
                             order_item_id = ?,
                             converted_at = NOW()
                         WHERE reservation_id = ?'
                    );
                    $stmt->execute([$detailId, (int) $activeReservation['reservation_id']]);
                }

                $stmt = $pdo->prepare(
                    'UPDATE lamp_reservations
                     SET status = "cancelled"
                     WHERE order_item_id = ?
                       AND status = "active"'
                );
                $stmt->execute([$detailId]);

                refresh_assignment_status($pdo, (int) $item['order_id']);

                create_order_notification(
                    $pdo,
                    (int) $item['user_id'],
                    (int) $item['order_id'],
                    null,
                    '燈位已分配',
                    '您的訂單 ' . $item['order_number'] . ' 已為 ' . $item['dependent_name_snapshot'] . ' 分配 ' . $item['lantern_name_snapshot'] . ' 燈位：' . $position['position_code'] . '。'
                );

                audit_log($adminId, 'admin_lamp_position_assign', 'order_items', (string) $detailId, $item, [
                    'position_id' => $positionId,
                    'position_code' => $position['position_code'],
                    'order_id' => (int) $item['order_id'],
                ]);

                $pdo->commit();
                set_flash('燈位已分配。');
                redirect_to_order((int) $item['order_id']);
            }

            if ($action === 'mark_completed') {
                $order = load_order_for_update($pdo, $orderId);
                if (!$order) {
                    throw new RuntimeException('找不到要標記過火的訂單。');
                }

                $stmt = $pdo->prepare(
                    'SELECT COUNT(*) FROM order_items
                     WHERE order_id = ? AND item_status NOT IN ("assigned", "completed", "cancelled")'
                );
                $stmt->execute([$orderId]);
                if ((int) $stmt->fetchColumn() > 0) {
                    throw new RuntimeException('仍有尚未安燈的明細，不能標記已過火。');
                }

                $stmt = $pdo->prepare('UPDATE order_items SET item_status = "completed" WHERE order_id = ? AND item_status = "assigned"');
                $stmt->execute([$orderId]);

                $stmt = $pdo->prepare('UPDATE orders SET order_status = "completed" WHERE order_id = ?');
                $stmt->execute([$orderId]);

                create_order_notification(
                    $pdo,
                    (int) $order['user_id'],
                    $orderId,
                    null,
                    '點燈已過火',
                    '您的訂單 ' . $order['order_number'] . ' 已完成過火，感謝您的參與。'
                );

                audit_log($adminId, 'admin_order_complete', 'orders', (string) $orderId, $order, [
                    'order_status' => 'completed',
                ]);

                $pdo->commit();
                set_flash('訂單已標記為已過火。');
                redirect_to_order($orderId);
            }

            throw new RuntimeException('未知的管理操作。');
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
    'pending_payment' => ['待付款', 'o.payment_status = "unpaid" AND o.order_status <> "cancelled"'],
    'pending_review' => ['待審核', 'o.payment_status = "paid" AND o.review_status = "pending" AND o.order_status <> "cancelled"'],
    'needs_assignment' => ['待安燈', 'o.payment_status = "paid" AND o.review_status = "approved" AND o.order_status = "paid"'],
    'assigned' => ['已安燈', 'o.order_status = "assigned"'],
    'completed' => ['已過火', 'o.order_status = "completed"'],
    'cancelled' => ['已取消', 'o.order_status = "cancelled"'],
];

if (!isset($filters[$statusFilter])) {
    $statusFilter = 'all';
}

$stmt = db()->query(
    'SELECT o.*, u.name AS user_name, u.email
     FROM orders o
     INNER JOIN users u ON u.user_id = o.user_id
     WHERE ' . $filters[$statusFilter][1] . '
     ORDER BY o.created_at DESC, o.order_id DESC
     LIMIT 80'
);
$orders = $stmt->fetchAll();

$itemsByOrder = [];
$paymentsByOrder = [];
$invoicesByOrder = [];
$notificationsByOrder = [];
$positionsByType = [];

if ($orders !== []) {
    $orderIds = array_map(static function (array $order): int {
        return (int) $order['order_id'];
    }, $orders);
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));

    $stmt = db()->prepare(
        "SELECT oi.*, lt.name AS type_name, lp.position_code, lp.area, lp.row_no, lp.col_no
         FROM order_items oi
         INNER JOIN lantern_types lt ON lt.type_id = oi.type_id
         LEFT JOIN lamp_positions lp ON lp.position_id = oi.position_id
         WHERE oi.order_id IN ($placeholders)
         ORDER BY oi.detail_id"
    );
    $stmt->execute($orderIds);
    foreach ($stmt->fetchAll() as $item) {
        $itemsByOrder[(int) $item['order_id']][] = $item;
    }

    $stmt = db()->prepare(
        "SELECT *
         FROM payments
         WHERE order_id IN ($placeholders)
         ORDER BY payment_id DESC"
    );
    $stmt->execute($orderIds);
    foreach ($stmt->fetchAll() as $payment) {
        $paymentsByOrder[(int) $payment['order_id']][] = $payment;
    }

    $stmt = db()->prepare(
        "SELECT *
         FROM invoices
         WHERE order_id IN ($placeholders)
         ORDER BY invoice_id DESC"
    );
    $stmt->execute($orderIds);
    foreach ($stmt->fetchAll() as $invoice) {
        $invoicesByOrder[(int) $invoice['order_id']][] = $invoice;
    }

    $stmt = db()->prepare(
        "SELECT *
         FROM notifications
         WHERE order_id IN ($placeholders)
         ORDER BY notify_id DESC"
    );
    $stmt->execute($orderIds);
    foreach ($stmt->fetchAll() as $notification) {
        $notificationsByOrder[(int) $notification['order_id']][] = $notification;
    }
}

expire_lamp_reservations();
$stmt = db()->query(
    'SELECT lp.*, lt.name AS type_name,
            lr.reservation_id AS active_reservation_id,
            lr.order_item_id AS reserved_order_item_id,
            lr.expires_at AS reserved_until
     FROM lamp_positions lp
     INNER JOIN lantern_types lt ON lt.type_id = lp.type_id
     LEFT JOIN lamp_reservations lr ON lr.reservation_id = (
         SELECT lr2.reservation_id
         FROM lamp_reservations lr2
         WHERE lr2.position_id = lp.position_id
           AND lr2.status = "active"
           AND lr2.expires_at > NOW()
         ORDER BY lr2.reservation_id DESC
         LIMIT 1
     )
     ORDER BY lp.type_id, lp.status, lp.position_code'
);
foreach ($stmt->fetchAll() as $position) {
    $positionsByType[(int) $position['type_id']][] = $position;
}
?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>訂單與金流管理</title>
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
                <h1>訂單與金流管理</h1>
                <p class="helper-text">審核訂單、確認付款、開立電子收據，並將點燈明細分配到實體燈位。</p>
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
                <a class="status-pill <?= $statusFilter === $key ? 'active' : '' ?>" href="orders.php?status=<?= e($key) ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </div>

        <?php if ($orders === []): ?>
            <section class="panel section-gap">
                <p class="helper-text">目前沒有符合條件的訂單。</p>
            </section>
        <?php else: ?>
            <div class="stack-list section-gap">
                <?php foreach ($orders as $order): ?>
                    <?php
                    $orderId = (int) $order['order_id'];
                    $isFocused = $focusOrderId === $orderId;
                    $canConfirmPayment = $order['payment_status'] === 'unpaid' && $order['order_status'] !== 'cancelled';
                    $canApprove = $order['payment_status'] === 'paid' && $order['review_status'] === 'pending' && $order['order_status'] !== 'cancelled';
                    $canReject = !in_array($order['order_status'], ['cancelled', 'completed'], true) && $order['review_status'] !== 'rejected';
                    $canComplete = $order['order_status'] === 'assigned';
                    ?>
                    <section class="panel order-card <?= $isFocused ? 'highlight-panel' : '' ?>">
                        <div class="section-heading">
                            <div>
                                <h2>訂單 <?= e($order['order_number']) ?></h2>
                                <p class="helper-text">
                                    <?= e($order['user_name']) ?> / <?= e($order['email']) ?>，建立時間：<?= e($order['created_at']) ?>
                                </p>
                            </div>
                            <div class="order-total">NT$ <?= e(number_format((float) $order['total_amount'])) ?></div>
                        </div>

                        <div class="status-row">
                            <?php if ($order['service_year'] !== null): ?>
                                <span><?= e((string) $order['service_year']) ?> 年度燈期</span>
                            <?php endif; ?>
                            <span><?= e(order_status_label($order['order_status'])) ?></span>
                            <span><?= e(payment_status_label($order['payment_status'])) ?></span>
                            <span><?= e(review_status_label($order['review_status'])) ?></span>
                        </div>

                        <?php if ($order['note']): ?>
                            <p><strong>訂單備註：</strong><?= e($order['note']) ?></p>
                        <?php endif; ?>

                        <div class="admin-actions">
                            <?php if ($canConfirmPayment): ?>
                                <form method="post" action="orders.php">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="confirm_payment">
                                    <input type="hidden" name="order_id" value="<?= $orderId ?>">
                                    <button class="button" type="submit">確認付款並開立收據</button>
                                </form>
                            <?php endif; ?>

                            <?php if ($canApprove): ?>
                                <form method="post" action="orders.php">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="approve_order">
                                    <input type="hidden" name="order_id" value="<?= $orderId ?>">
                                    <button class="button" type="submit">審核通過</button>
                                </form>
                            <?php endif; ?>

                            <?php if ($canReject): ?>
                                <form method="post" action="orders.php">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="reject_order">
                                    <input type="hidden" name="order_id" value="<?= $orderId ?>">
                                    <button class="button secondary" type="submit">駁回訂單</button>
                                </form>
                            <?php endif; ?>

                            <?php if ($canComplete): ?>
                                <form method="post" action="orders.php">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="mark_completed">
                                    <input type="hidden" name="order_id" value="<?= $orderId ?>">
                                    <button class="button" type="submit">標記已過火</button>
                                </form>
                            <?php endif; ?>
                        </div>

                        <h3>金流對帳紀錄</h3>
                        <div class="table-wrap">
                            <table class="data-table compact-table">
                                <thead>
                                    <tr>
                                        <th>付款方式</th>
                                        <th>對帳狀態</th>
                                        <th>交易編號</th>
                                        <th>金額</th>
                                        <th>付款時間</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($paymentsByOrder[$orderId] ?? [] as $payment): ?>
                                        <tr>
                                            <td><?= e(payment_method_label($payment['payment_method'])) ?></td>
                                            <td><?= e(payment_record_status_label($payment['payment_status'])) ?></td>
                                            <td><?= e($payment['transaction_no']) ?></td>
                                            <td>NT$ <?= e(number_format((float) $payment['amount'])) ?></td>
                                            <td><?= e($payment['paid_at'] ?? '尚未付款') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <h3>電子收據</h3>
                        <?php if (empty($invoicesByOrder[$orderId])): ?>
                            <p class="helper-text">尚未開立電子收據。確認付款後會自動建立。</p>
                        <?php else: ?>
                            <div class="status-row">
                                <?php foreach ($invoicesByOrder[$orderId] as $invoice): ?>
                                    <span><?= e($invoice['invoice_number']) ?> / <?= e(invoice_status_label($invoice['invoice_status'])) ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <h3>點燈訂單明細與燈位分配</h3>
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>燈種</th>
                                        <th>祈福對象</th>
                                        <th>明細狀態</th>
                                        <th>目前燈位</th>
                                        <th>到期日</th>
                                        <th>分配燈位</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($itemsByOrder[$orderId] ?? [] as $item): ?>
                                        <?php
                                        $currentPositionId = $item['position_id'] === null ? null : (int) $item['position_id'];
                                        $availablePositions = available_positions_for_item($positionsByType, (int) $item['type_id'], $currentPositionId, (int) $item['detail_id']);
                                        $canAssign = $order['payment_status'] === 'paid'
                                            && $order['review_status'] === 'approved'
                                            && !in_array($item['item_status'], ['completed', 'cancelled'], true);
                                        ?>
                                        <tr>
                                            <td><?= e($item['lantern_name_snapshot']) ?></td>
                                            <td><?= e($item['dependent_name_snapshot']) ?></td>
                                            <td><?= e(order_item_status_label($item['item_status'])) ?></td>
                                            <td><?= e($item['position_code'] ?? '尚未分配') ?></td>
                                            <td><?= e($item['blessing_end_date'] ?? '尚未設定') ?></td>
                                            <td>
                                                <?php if ($canAssign): ?>
                                                    <form class="inline-form" method="post" action="orders.php">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="assign_position">
                                                        <input type="hidden" name="detail_id" value="<?= (int) $item['detail_id'] ?>">
                                                        <select name="position_id" required>
                                                            <option value="">選擇燈位</option>
                                                            <?php foreach ($availablePositions as $position): ?>
                                                                <option value="<?= (int) $position['position_id'] ?>" <?= $currentPositionId === (int) $position['position_id'] ? 'selected' : '' ?>>
                                                                    <?= e($position['position_code'] . '（' . ($position['active_reservation_id'] ? '保留中' : lamp_position_status_label($position['status'])) . '）') ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <button class="button secondary" type="submit">儲存</button>
                                                    </form>
                                                <?php else: ?>
                                                    <span class="helper-text">需先付款確認與審核通過</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <h3>通知紀錄</h3>
                        <?php if (empty($notificationsByOrder[$orderId])): ?>
                            <p class="helper-text">目前尚無通知紀錄。</p>
                        <?php else: ?>
                            <div class="stack-list">
                                <?php foreach (array_slice($notificationsByOrder[$orderId], 0, 4) as $notification): ?>
                                    <article class="list-item">
                                        <div class="section-heading">
                                            <h4><?= e($notification['subject']) ?></h4>
                                            <span class="status-pill"><?= e(notification_channel_label($notification['channel'])) ?> / <?= e(notification_status_label($notification['status'])) ?></span>
                                        </div>
                                        <p><?= e($notification['content']) ?></p>
                                        <p class="helper-text">建立時間：<?= e($notification['created_at']) ?></p>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>
</body>
</html>
