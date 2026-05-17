<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/frontend.php';

$user = require_login('login.php');
$userId = (int) $user['user_id'];
$errors = [];
$flash = flash_message();
$focusOrderId = (int) ($_GET['order_id'] ?? 0);

function member_load_order_for_update(PDO $pdo, int $orderId, int $userId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT *
         FROM orders
         WHERE order_id = ?
           AND user_id = ?
         LIMIT 1
         FOR UPDATE'
    );
    $stmt->execute([$orderId, $userId]);
    $order = $stmt->fetch();

    return $order ?: null;
}

function member_ensure_invoice(PDO $pdo, array $order): array
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

function member_create_order_notification(PDO $pdo, int $userId, int $orderId, ?int $invoiceId, string $subject, string $content): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO notifications (user_id, order_id, invoice_id, channel, subject, content, status)
         VALUES (?, ?, ?, "system", ?, ?, "pending")'
    );
    $stmt->execute([$userId, $orderId, $invoiceId, $subject, $content]);
}

function member_assign_available_lamp_positions(PDO $pdo, array $order): array
{
    $stmt = $pdo->prepare(
        'SELECT *
         FROM order_items
         WHERE order_id = ?
           AND item_status <> "cancelled"
         ORDER BY detail_id ASC
         FOR UPDATE'
    );
    $stmt->execute([(int) $order['order_id']]);
    $items = $stmt->fetchAll();

    if ($items === []) {
        throw new RuntimeException('此訂單沒有可安燈的明細。');
    }

    $activePeriod = active_lamp_service_period();
    $assigned = [];
    expire_lamp_reservations($pdo);

    foreach ($items as $item) {
        if (in_array($item['item_status'], ['assigned', 'completed'], true) && $item['position_id'] !== null) {
            continue;
        }

        $stmt = $pdo->prepare(
            'SELECT lp.*, lr.reservation_id
             FROM lamp_reservations lr
             INNER JOIN lamp_positions lp ON lp.position_id = lr.position_id
             WHERE lr.order_item_id = ?
               AND lr.status = "active"
               AND lr.expires_at > NOW()
               AND lp.status = "available"
             ORDER BY lr.reservation_id DESC
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute([(int) $item['detail_id']]);
        $position = $stmt->fetch();

        if (!$position) {
            $stmt = $pdo->prepare(
                'SELECT lp.*, NULL AS reservation_id
                 FROM lamp_positions lp
                 WHERE lp.type_id = ?
                   AND lp.status = "available"
                   AND NOT EXISTS (
                       SELECT 1
                       FROM lamp_reservations lr
                       WHERE lr.position_id = lp.position_id
                         AND lr.status = "active"
                         AND lr.expires_at > NOW()
                   )
                 ORDER BY lp.area ASC, lp.row_no ASC, lp.col_no ASC, lp.position_id ASC
                 LIMIT 1
                 FOR UPDATE'
            );
            $stmt->execute([(int) $item['type_id']]);
            $position = $stmt->fetch();
        }

        if (!$position) {
            throw new RuntimeException($item['lantern_name_snapshot'] . ' 目前沒有可用燈位，請先到後台新增燈位。');
        }

        $blessingStartDate = $item['blessing_start_date'] ?: lamp_blessing_start_date((string) ($order['paid_at'] ?? ''));
        $blessingEndDate = $item['blessing_end_date'] ?: ($activePeriod['blessing_end_date'] ?? null);

        if ($blessingEndDate === null) {
            throw new RuntimeException('目前沒有可套用的年度謝燈日，請先設定年度燈期。');
        }

        $stmt = $pdo->prepare(
            'UPDATE lamp_positions
             SET status = "occupied", occupied_until = ?
             WHERE position_id = ?'
        );
        $stmt->execute([$blessingEndDate, (int) $position['position_id']]);

        $stmt = $pdo->prepare(
            'UPDATE order_items
             SET position_id = ?,
                 assigned_at = NOW(),
                 item_status = "assigned",
                 blessing_start_date = COALESCE(blessing_start_date, ?),
                 blessing_end_date = COALESCE(blessing_end_date, ?)
             WHERE detail_id = ?'
        );
        $stmt->execute([(int) $position['position_id'], $blessingStartDate, $blessingEndDate, (int) $item['detail_id']]);

        if (!empty($position['reservation_id'])) {
            $stmt = $pdo->prepare(
                'UPDATE lamp_reservations
                 SET status = "converted",
                     converted_at = NOW()
                 WHERE reservation_id = ?'
            );
            $stmt->execute([(int) $position['reservation_id']]);
        }

        $stmt = $pdo->prepare(
            'UPDATE lamp_reservations
             SET status = "cancelled"
             WHERE order_item_id = ?
               AND status = "active"'
        );
        $stmt->execute([(int) $item['detail_id']]);

        $assigned[] = [
            'lantern' => $item['lantern_name_snapshot'],
            'dependent' => $item['dependent_name_snapshot'],
            'position_code' => $position['position_code'],
        ];
    }

    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM order_items
         WHERE order_id = ?
           AND item_status NOT IN ("assigned", "completed", "cancelled")'
    );
    $stmt->execute([(int) $order['order_id']]);

    if ((int) $stmt->fetchColumn() === 0) {
        $stmt = $pdo->prepare(
            'UPDATE orders
             SET order_status = "assigned"
             WHERE order_id = ?
               AND payment_status = "paid"
               AND review_status = "approved"
               AND order_status <> "completed"'
        );
        $stmt->execute([(int) $order['order_id']]);
    }

    return $assigned;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors[] = '表單已過期，請重新送出。';
    }

    $action = trim((string) ($_POST['action'] ?? ''));
    $orderId = (int) ($_POST['order_id'] ?? 0);

    if ($errors === [] && $action !== 'mock_pay_and_light') {
        $errors[] = '未知的訂單操作。';
    }

    if ($errors === [] && $orderId <= 0) {
        $errors[] = '找不到要處理的訂單。';
    }

    if ($errors === []) {
        $pdo = db();
        $pdo->beginTransaction();

        try {
            $order = member_load_order_for_update($pdo, $orderId, $userId);
            if (!$order) {
                throw new RuntimeException('找不到要處理的訂單。');
            }

            if (in_array($order['order_status'], ['cancelled', 'completed'], true)) {
                throw new RuntimeException('此訂單狀態不能再執行測試付款。');
            }

            if ($order['payment_status'] === 'refunded') {
                throw new RuntimeException('已退款訂單不能執行測試付款。');
            }

            $before = $order;
            $transactionNo = 'MOCK' . date('YmdHis') . str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT);
            $rawPayload = json_encode([
                'source' => 'frontend_demo_skip_payment',
                'confirmed_by' => 'member_demo_button',
                'order_number' => $order['order_number'],
                'confirmed_at' => date('c'),
            ], JSON_UNESCAPED_UNICODE);

            $stmt = $pdo->prepare(
                'SELECT *
                 FROM payments
                 WHERE order_id = ?
                 ORDER BY payment_id DESC
                 LIMIT 1
                 FOR UPDATE'
            );
            $stmt->execute([$orderId]);
            $payment = $stmt->fetch();

            if ($payment) {
                $stmt = $pdo->prepare(
                    'UPDATE payments
                     SET payment_method = "mock",
                         payment_status = "confirmed",
                         transaction_no = ?,
                         paid_at = COALESCE(paid_at, NOW()),
                         raw_payload = ?
                     WHERE payment_id = ?'
                );
                $stmt->execute([$transactionNo, $rawPayload, (int) $payment['payment_id']]);
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO payments (order_id, payment_method, amount, payment_status, transaction_no, paid_at, raw_payload)
                     VALUES (?, "mock", ?, "confirmed", ?, NOW(), ?)'
                );
                $stmt->execute([$orderId, (float) $order['total_amount'], $transactionNo, $rawPayload]);
            }

            $stmt = $pdo->prepare(
                'UPDATE orders
                 SET payment_status = "paid",
                     order_status = CASE WHEN order_status = "pending_payment" THEN "paid" ELSE order_status END,
                     review_status = "approved",
                     paid_at = COALESCE(paid_at, NOW()),
                     reviewed_at = COALESCE(reviewed_at, NOW())
                 WHERE order_id = ?'
            );
            $stmt->execute([$orderId]);

            $invoice = member_ensure_invoice($pdo, $order);
            $assigned = member_assign_available_lamp_positions($pdo, $order);
            $assignedText = $assigned === []
                ? '既有燈位已確認完成。'
                : implode('、', array_map(static function (array $row): string {
                    return $row['dependent'] . '的' . $row['lantern'] . '：' . $row['position_code'];
                }, $assigned));

            member_create_order_notification(
                $pdo,
                $userId,
                $orderId,
                (int) $invoice['invoice_id'],
                '測試付款完成',
                '您的訂單 ' . $order['order_number'] . ' 已用展示模式略過付款，並完成安燈。燈位：' . $assignedText
            );

            audit_log($userId, 'member_mock_payment_complete', 'orders', (string) $orderId, $before, [
                'payment_status' => 'paid',
                'review_status' => 'approved',
                'order_status' => 'assigned',
                'transaction_no' => $transactionNo,
                'assigned' => $assigned,
            ]);

            $pdo->commit();
            set_flash('展示模式已略過付款，訂單已完成付款確認並自動安燈。');
            redirect('orders.php?order_id=' . $orderId);
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $errors[] = $throwable->getMessage();
        }
    }
}

$stmt = db()->prepare(
    'SELECT order_id, order_number, service_year, total_amount, payment_status, order_status, review_status, note, created_at
     FROM orders
     WHERE user_id = ?
     ORDER BY created_at DESC, order_id DESC'
);
$stmt->execute([$userId]);
$orders = $stmt->fetchAll();

$itemsByOrder = [];
$paymentsByOrder = [];
$invoicesByOrder = [];
$notificationsByOrder = [];

if ($orders !== []) {
    $orderIds = array_map(static function (array $order): int {
        return (int) $order['order_id'];
    }, $orders);
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));

    $stmt = db()->prepare(
        "SELECT oi.order_id, oi.detail_id, oi.lantern_name_snapshot, oi.dependent_name_snapshot, oi.price_snapshot,
                oi.blessing_start_date, oi.blessing_end_date, oi.item_status, lp.position_code
         FROM order_items oi
         LEFT JOIN lamp_positions lp ON lp.position_id = oi.position_id
         WHERE oi.order_id IN ($placeholders)
         ORDER BY oi.detail_id"
    );
    $stmt->execute($orderIds);

    foreach ($stmt->fetchAll() as $item) {
        $itemsByOrder[(int) $item['order_id']][] = $item;
    }

    $stmt = db()->prepare(
        "SELECT order_id, payment_method, amount, payment_status, transaction_no, paid_at, created_at
         FROM payments
         WHERE order_id IN ($placeholders)
         ORDER BY payment_id"
    );
    $stmt->execute($orderIds);

    foreach ($stmt->fetchAll() as $payment) {
        $paymentsByOrder[(int) $payment['order_id']][] = $payment;
    }

    $stmt = db()->prepare(
        "SELECT order_id, invoice_number, invoice_status, issued_at
         FROM invoices
         WHERE order_id IN ($placeholders)
         ORDER BY invoice_id DESC"
    );
    $stmt->execute($orderIds);

    foreach ($stmt->fetchAll() as $invoice) {
        $invoicesByOrder[(int) $invoice['order_id']][] = $invoice;
    }

    $stmt = db()->prepare(
        "SELECT order_id, channel, subject, content, status, sent_at, created_at
         FROM notifications
         WHERE order_id IN ($placeholders) AND user_id = ?
         ORDER BY notify_id DESC"
    );
    $stmt->execute([...$orderIds, $userId]);

    foreach ($stmt->fetchAll() as $notification) {
        $notificationsByOrder[(int) $notification['order_id']][] = $notification;
    }
}

$focusedOrder = null;
foreach ($orders as $order) {
    if ((int) $order['order_id'] === $focusOrderId) {
        $focusedOrder = $order;
        break;
    }
}
$showSuccessStage = $focusedOrder !== null && in_array($focusedOrder['order_status'], ['assigned', 'completed'], true);
?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>點燈紀錄</title>
    <link rel="stylesheet" href="../assets/css/main.css">
</head>
<body>
    <header class="site-header">
        <h1>點燈紀錄</h1>
        <?= public_nav($user) ?>
    </header>

    <main class="page">
        <h1>訂單紀錄查詢</h1>

        <section class="panel">
            <div class="section-heading">
                <div>
                    <h2>年度續燈建議</h2>
                    <p class="helper-text">下一年度請重新依祈福對象的生辰資料與當年度流年表產生建議，再自行調整燈種。</p>
                </div>
                <a class="button" href="renewal_advisor.php">前往智慧續燈</a>
            </div>
        </section>

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

        <?php if ($showSuccessStage && $focusedOrder !== null): ?>
            <?php $focusedItems = $itemsByOrder[(int) $focusedOrder['order_id']] ?? []; ?>
            <section class="panel success-stage">
                <div class="section-heading">
                    <div>
                        <h2>點燈成功</h2>
                        <p class="helper-text">訂單 <?= e($focusedOrder['order_number']) ?> 已完成付款確認與安燈，以下是本年度點燈資訊。</p>
                    </div>
                    <a class="button secondary" href="lamp_wall.php">查看燈位牆</a>
                </div>

                <div class="process-steps" aria-label="點燈流程">
                    <span class="done">1. 建立訂單</span>
                    <span class="done">2. 付款完成</span>
                    <span class="done">3. 審核通過</span>
                    <span class="done">4. 安燈成功</span>
                </div>

                <div class="success-lamp-grid">
                    <?php foreach ($focusedItems as $item): ?>
                        <article class="success-lamp-item">
                            <strong><?= e($item['lantern_name_snapshot']) ?></strong>
                            <span><?= e($item['dependent_name_snapshot']) ?></span>
                            <span>燈位 <?= e($item['position_code'] ?? '尚未分配') ?></span>
                            <small><?= e($item['blessing_start_date'] ?? '付款安燈後起算') ?> 至 <?= e($item['blessing_end_date'] ?? '年度謝燈日') ?></small>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($orders === []): ?>
            <section class="panel">
                <p class="helper-text">目前尚無訂單紀錄。</p>
                <a class="button" href="lanterns.php">前往點燈大廳</a>
            </section>
        <?php else: ?>
            <div class="stack-list">
                <?php foreach ($orders as $order): ?>
                    <?php
                    $orderId = (int) $order['order_id'];
                    $isFocused = $focusOrderId === $orderId;
                    ?>
                    <section class="panel order-card <?= $isFocused ? 'highlight-panel' : '' ?>">
                        <div class="section-heading">
                            <div>
                                <h2>訂單 <?= e($order['order_number']) ?></h2>
                                <p class="helper-text">建立時間：<?= e($order['created_at']) ?></p>
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

                        <div class="process-steps compact" aria-label="訂單進度">
                            <span class="done">1. 建立訂單</span>
                            <span class="<?= $order['payment_status'] === 'paid' ? 'done' : 'current' ?>">2. 付款</span>
                            <span class="<?= $order['review_status'] === 'approved' ? 'done' : ($order['payment_status'] === 'paid' ? 'current' : '') ?>">3. 審核</span>
                            <span class="<?= in_array($order['order_status'], ['assigned', 'completed'], true) ? 'done' : ($order['review_status'] === 'approved' ? 'current' : '') ?>">4. 安燈</span>
                        </div>

                        <?php if ($order['payment_status'] === 'unpaid' && $order['order_status'] === 'pending_payment'): ?>
                            <section class="demo-payment-panel">
                                <div>
                                    <h3>目前未串接實際金流</h3>
                                    <p class="helper-text">展示時可略過付款，系統會建立模擬付款紀錄、開立電子收據，並自動分配可用燈位。</p>
                                </div>
                                <form method="post" action="orders.php">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="mock_pay_and_light">
                                    <input type="hidden" name="order_id" value="<?= $orderId ?>">
                                    <button class="button" type="submit">略過付款並完成測試點燈</button>
                                </form>
                            </section>
                        <?php elseif ($order['payment_status'] === 'paid' && in_array($order['order_status'], ['assigned', 'completed'], true)): ?>
                            <div class="alert success">
                                <p>此訂單已完成付款與安燈，可在下方查看燈位與年度到期日。</p>
                            </div>
                        <?php endif; ?>

                        <?php if ($order['note']): ?>
                            <p><strong>訂單備註：</strong><?= e($order['note']) ?></p>
                        <?php endif; ?>

                        <h3>金流對帳紀錄</h3>
                        <div class="table-wrap">
                            <table class="data-table compact-table">
                                <thead>
                                    <tr>
                                        <th>付款方式</th>
                                        <th>付款狀態</th>
                                        <th>交易編號</th>
                                        <th>金額</th>
                                        <th>建立時間</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($paymentsByOrder[$orderId] ?? [] as $payment): ?>
                                        <tr>
                                            <td><?= e(payment_method_label($payment['payment_method'])) ?></td>
                                            <td><?= e(payment_record_status_label($payment['payment_status'])) ?></td>
                                            <td><?= e($payment['transaction_no']) ?></td>
                                            <td>NT$ <?= e(number_format((float) $payment['amount'])) ?></td>
                                            <td><?= e($payment['created_at']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <h3>電子收據</h3>
                        <?php if (empty($invoicesByOrder[$orderId])): ?>
                            <p class="helper-text">付款確認後會由後台開立電子收據。</p>
                        <?php else: ?>
                            <div class="status-row">
                                <?php foreach ($invoicesByOrder[$orderId] as $invoice): ?>
                                    <span><?= e($invoice['invoice_number']) ?> / <?= e(invoice_status_label($invoice['invoice_status'])) ?> / <?= e($invoice['issued_at']) ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <h3>點燈訂單明細</h3>
                        <div class="table-wrap">
                            <table class="data-table compact-table">
                                <thead>
                                    <tr>
                                        <th>燈種</th>
                                        <th>祈福對象</th>
                                        <th>燈位</th>
                                        <th>起始日</th>
                                        <th>到期日</th>
                                        <th>金額</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($itemsByOrder[$orderId] ?? [] as $item): ?>
                                        <tr>
                                            <td><?= e($item['lantern_name_snapshot']) ?></td>
                                            <td><?= e($item['dependent_name_snapshot']) ?></td>
                                            <td><?= e($item['position_code'] ?? '尚未分配') ?></td>
                                            <td><?= e($item['blessing_start_date'] ?? '付款安燈後起算') ?></td>
                                            <td><?= e($item['blessing_end_date'] ?? '年度謝燈日') ?></td>
                                            <td>NT$ <?= e(number_format((float) $item['price_snapshot'])) ?></td>
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
                                <?php foreach ($notificationsByOrder[$orderId] as $notification): ?>
                                    <article class="list-item">
                                        <div class="section-heading">
                                            <h4><?= e($notification['subject']) ?></h4>
                                            <span class="status-pill"><?= e($notification['status']) ?></span>
                                        </div>
                                        <p><?= e($notification['content']) ?></p>
                                        <p class="helper-text">管道：<?= e($notification['channel']) ?>，建立時間：<?= e($notification['created_at']) ?></p>
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
