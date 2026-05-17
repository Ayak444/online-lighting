<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/frontend.php';

$user = require_login('login.php');
$userId = (int) $user['user_id'];
$cartId = ensure_cart_id($userId);
$activePeriod = active_lamp_service_period();
$errors = [];
$flash = flash_message();

function load_cart_items(int $cartId): array
{
    expire_lamp_reservations();

    $stmt = db()->prepare(
        'SELECT ci.cart_item_id, ci.prayer_wish, lt.type_id, lt.name AS lantern_name, lt.price,
                d.dependent_id, d.name AS dependent_name, d.birthday, d.zodiac,
                lr.reservation_id, lr.status AS reservation_status, lr.expires_at AS reserved_until,
                rp.position_code AS reserved_position_code
         FROM cart_items ci
         INNER JOIN lantern_types lt ON lt.type_id = ci.type_id
         INNER JOIN dependents d ON d.dependent_id = ci.dependent_id
         LEFT JOIN lamp_reservations lr ON lr.reservation_id = (
             SELECT lr2.reservation_id
             FROM lamp_reservations lr2
             WHERE lr2.cart_item_id = ci.cart_item_id
             ORDER BY
                 CASE WHEN lr2.status = "active" AND lr2.expires_at > NOW() THEN 0 ELSE 1 END,
                 lr2.reservation_id DESC
             LIMIT 1
         )
         LEFT JOIN lamp_positions rp ON rp.position_id = lr.position_id
         WHERE ci.cart_id = ?
         ORDER BY ci.created_at DESC, ci.cart_item_id DESC'
    );
    $stmt->execute([$cartId]);

    return $stmt->fetchAll();
}

function available_count_by_type(array $typeIds): array
{
    return available_lamp_counts_by_type($typeIds);
}

function validate_cart_stock(array $cartItems): array
{
    $quantityByType = [];
    $nameByType = [];

    foreach ($cartItems as $item) {
        $typeId = (int) $item['type_id'];
        $quantityByType[$typeId] = ($quantityByType[$typeId] ?? 0) + 1;
        $nameByType[$typeId] = $item['lantern_name'];
    }

    $availableByType = available_count_by_type(array_keys($quantityByType));
    $stockErrors = [];

    foreach ($quantityByType as $typeId => $quantity) {
        $available = $availableByType[$typeId] ?? 0;
        if ($quantity > $available) {
            $stockErrors[] = $nameByType[$typeId] . ' 剩餘燈位不足，目前剩餘 ' . $available . ' 位。';
        }
    }

    return $stockErrors;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors[] = '表單已過期，請重新送出。';
    }

    $action = trim((string) ($_POST['action'] ?? ''));

    if ($errors === [] && $action === 'remove_item') {
        $cartItemId = (int) ($_POST['cart_item_id'] ?? 0);
        $stmt = db()->prepare(
            'SELECT ci.cart_item_id, ci.type_id, ci.dependent_id
             FROM cart_items ci
             WHERE ci.cart_item_id = ? AND ci.cart_id = ?
             LIMIT 1'
        );
        $stmt->execute([$cartItemId, $cartId]);
        $before = $stmt->fetch();

        if ($before) {
            cancel_cart_item_reservations(db(), $cartItemId, $userId);
            $stmt = db()->prepare('DELETE FROM cart_items WHERE cart_item_id = ? AND cart_id = ?');
            $stmt->execute([$cartItemId, $cartId]);
            audit_log($userId, 'cart_item_delete', 'cart_items', (string) $cartItemId, $before, null);
            set_flash('購物車項目已刪除。');
            redirect('cart.php');
        }

        $errors[] = '找不到要刪除的購物車項目。';
    }

    if ($errors === [] && $action === 'refresh_reservation') {
        $cartItemId = (int) ($_POST['cart_item_id'] ?? 0);
        $stmt = db()->prepare(
            'SELECT ci.cart_item_id, ci.type_id
             FROM cart_items ci
             WHERE ci.cart_item_id = ? AND ci.cart_id = ?
             LIMIT 1'
        );
        $stmt->execute([$cartItemId, $cartId]);
        $cartItem = $stmt->fetch();

        if (!$cartItem) {
            $errors[] = '找不到要重新保留的購物車項目。';
        } else {
            $pdo = db();
            $pdo->beginTransaction();

            try {
                $reservation = refresh_lamp_reservation_for_cart_item($pdo, $cartItemId, (int) $cartItem['type_id'], $userId);
                if ($reservation === null) {
                    throw new RuntimeException('此燈種目前沒有可保留的燈位，請稍後再試或改選其他燈種。');
                }

                $pdo->commit();
                set_flash('已重新保留燈位至 ' . $reservation['expires_at'] . '。');
                redirect('cart.php');
            } catch (Throwable $throwable) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $errors[] = $throwable instanceof RuntimeException ? $throwable->getMessage() : '重新保留失敗，請稍後再試。';
            }
        }
    }

    if ($errors === [] && $action === 'checkout') {
        $cartItems = load_cart_items($cartId);
        $paymentMethod = trim((string) ($_POST['payment_method'] ?? 'bank_transfer'));
        $allowedPaymentMethods = ['bank_transfer', 'credit_card', 'mobile_payment', 'convenience_store'];

        if (!in_array($paymentMethod, $allowedPaymentMethods, true)) {
            $errors[] = '付款方式不正確。';
        }

        if ($cartItems === []) {
            $errors[] = '購物車目前沒有項目。';
        }

        if ($activePeriod === null) {
            $errors[] = '目前尚未設定可報名的年度燈期，請稍後再試。';
        }

        if ($errors === []) {
            $pdo = db();
            $pdo->beginTransaction();

            try {
                $reservationsByCartItem = [];
                foreach ($cartItems as $item) {
                    $reservation = reserve_lamp_position_for_cart_item($pdo, (int) $item['cart_item_id'], (int) $item['type_id'], $userId);
                    if ($reservation === null) {
                        throw new RuntimeException($item['lantern_name'] . ' 目前沒有可保留的燈位，請移除或改選其他燈種。');
                    }

                    $reservationsByCartItem[(int) $item['cart_item_id']] = $reservation;
                }

                $totalAmount = array_reduce($cartItems, static function (float $sum, array $item): float {
                    return $sum + (float) $item['price'];
                }, 0.0);

                $orderNumber = 'OL' . date('YmdHis') . str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT);

                $stmt = $pdo->prepare(
                    'INSERT INTO orders (order_number, user_id, service_period_id, service_year, total_amount, payment_status, order_status, review_status, note)
                     VALUES (?, ?, ?, ?, ?, "unpaid", "pending_payment", "pending", ?)'
                );
                $stmt->execute([
                    $orderNumber,
                    $userId,
                    (int) $activePeriod['period_id'],
                    (int) $activePeriod['service_year'],
                    $totalAmount,
                    trim((string) ($_POST['note'] ?? '')) ?: null,
                ]);
                $orderId = (int) $pdo->lastInsertId();

                $stmt = $pdo->prepare(
                    'INSERT INTO order_items (order_id, type_id, dependent_id, lantern_name_snapshot, dependent_name_snapshot, price_snapshot, blessing_start_date, blessing_end_date)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );

                foreach ($cartItems as $item) {
                    $stmt->execute([
                        $orderId,
                        (int) $item['type_id'],
                        (int) $item['dependent_id'],
                        $item['lantern_name'],
                        $item['dependent_name'],
                        (float) $item['price'],
                        null,
                        $activePeriod['blessing_end_date'],
                    ]);

                    $detailId = (int) $pdo->lastInsertId();
                    $reservation = $reservationsByCartItem[(int) $item['cart_item_id']] ?? null;
                    if ($reservation !== null) {
                        $linkReservation = $pdo->prepare(
                            'UPDATE lamp_reservations
                             SET order_item_id = ?
                             WHERE reservation_id = ?'
                        );
                        $linkReservation->execute([$detailId, (int) $reservation['reservation_id']]);
                    }
                }

                $stmt = $pdo->prepare(
                    'INSERT INTO payments (order_id, payment_method, amount, payment_status, transaction_no, raw_payload)
                     VALUES (?, ?, ?, "pending", ?, ?)'
                );
                $transactionNo = 'PAY' . date('YmdHis') . str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT);
                $stmt->execute([
                    $orderId,
                    $paymentMethod,
                    $totalAmount,
                    $transactionNo,
                    json_encode([
                        'source' => 'frontend_checkout',
                        'payment_method' => $paymentMethod,
                        'order_number' => $orderNumber,
                        'service_year' => (int) $activePeriod['service_year'],
                        'blessing_end_date' => $activePeriod['blessing_end_date'],
                    ], JSON_UNESCAPED_UNICODE),
                ]);

                $stmt = $pdo->prepare(
                    'INSERT INTO notifications (user_id, order_id, channel, subject, content, status)
                     VALUES (?, ?, "email", ?, ?, "pending")'
                );
                $stmt->execute([
                    $userId,
                    $orderId,
                    '訂單已建立',
                    '您的訂單 ' . $orderNumber . ' 已建立，付款方式為 ' . payment_method_label($paymentMethod) . '。本年度燈期到期日為 ' . $activePeriod['blessing_end_date'] . '，請等待後台審核。',
                ]);

                $stmt = $pdo->prepare('DELETE FROM cart_items WHERE cart_id = ?');
                $stmt->execute([$cartId]);

                audit_log($userId, 'order_create', 'orders', (string) $orderId, null, [
                    'order_number' => $orderNumber,
                    'total_amount' => $totalAmount,
                    'payment_method' => $paymentMethod,
                    'service_year' => (int) $activePeriod['service_year'],
                    'blessing_end_date' => $activePeriod['blessing_end_date'],
                    'items' => count($cartItems),
                ]);

                $pdo->commit();
                set_flash('訂單已建立，請依付款方式完成付款，並等待後台審核與安燈。');
                redirect('orders.php?order_id=' . $orderId);
            } catch (Throwable $throwable) {
                $pdo->rollBack();
                $errors[] = '建立訂單失敗，請稍後再試。';
            }
        }
    }
}

$cartItems = load_cart_items($cartId);
$typeIds = array_values(array_unique(array_map(static function (array $item): int {
    return (int) $item['type_id'];
}, $cartItems)));
$availableByType = available_count_by_type($typeIds);
$totalAmount = array_reduce($cartItems, static function (float $sum, array $item): float {
    return $sum + (float) $item['price'];
}, 0.0);
?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>購物車</title>
    <link rel="stylesheet" href="../assets/css/main.css">
</head>
<body>
    <header class="site-header">
        <h1>購物車</h1>
        <?= public_nav($user) ?>
    </header>

    <main class="page">
        <h1>確認資料與建立訂單</h1>

        <section class="panel">
            <div class="section-heading">
                <div>
                    <h2>點燈流程</h2>
                    <p class="helper-text">先確認祈福對象與燈種，建立訂單後再付款；目前系統提供展示用略過付款按鈕，方便完整測試到安燈成功。</p>
                </div>
            </div>
            <div class="process-steps" aria-label="點燈流程">
                <span class="done">1. 選擇燈種</span>
                <span class="current">2. 確認訂單</span>
                <span>3. 付款</span>
                <span>4. 安燈成功</span>
            </div>
        </section>

        <section class="panel section-gap">
            <div class="section-heading">
                <div>
                    <h2>年度燈期</h2>
                    <?php if ($activePeriod): ?>
                        <p class="helper-text">
                            <?= e((string) $activePeriod['service_year']) ?> 年度點燈共用燈期，
                            開燈日 <?= e($activePeriod['blessing_start_date']) ?>，
                            謝燈日 <?= e($activePeriod['blessing_end_date']) ?>。
                        </p>
                    <?php else: ?>
                        <p class="helper-text">目前尚未啟用年度燈期，暫時無法結帳。</p>
                    <?php endif; ?>
                </div>
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

        <section class="panel">
            <?php if ($cartItems === []): ?>
                <p class="helper-text">購物車目前沒有項目。</p>
                <a class="button" href="lanterns.php">前往點燈大廳</a>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>燈種</th>
                                <th>祈福對象</th>
                                <th>生日</th>
                                <th>生肖</th>
                                <th>備註</th>
                                <th>保留狀態</th>
                                <th>剩餘燈位</th>
                                <th>金額</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cartItems as $item): ?>
                                <?php
                                $reservationActive = ($item['reservation_status'] ?? '') === 'active'
                                    && !empty($item['reserved_until']);
                                ?>
                                <tr>
                                    <td><?= e($item['lantern_name']) ?></td>
                                    <td><?= e($item['dependent_name']) ?></td>
                                    <td><?= e($item['birthday']) ?></td>
                                    <td><?= e($item['zodiac']) ?></td>
                                    <td><?= e($item['prayer_wish']) ?></td>
                                    <td>
                                        <?php if ($reservationActive): ?>
                                            <div class="reservation-state active">
                                                <strong>已保留 <?= e($item['reserved_position_code'] ?? '') ?></strong>
                                                <span>至 <?= e($item['reserved_until']) ?></span>
                                                <small>剩 <?= e(reservation_time_left_text($item['reserved_until'])) ?></small>
                                            </div>
                                        <?php else: ?>
                                            <div class="reservation-state expired">
                                                <strong>保留已過期</strong>
                                                <span>結帳前需重新保留</span>
                                            </div>
                                        <?php endif; ?>
                                        <form method="post" action="cart.php">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="refresh_reservation">
                                            <input type="hidden" name="cart_item_id" value="<?= (int) $item['cart_item_id'] ?>">
                                            <button class="link-button" type="submit"><?= $reservationActive ? '延長保留' : '重新保留' ?></button>
                                        </form>
                                    </td>
                                    <td><?= (int) ($availableByType[(int) $item['type_id']] ?? 0) ?> 位</td>
                                    <td>NT$ <?= e(number_format((float) $item['price'])) ?></td>
                                    <td>
                                        <form method="post" action="cart.php">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="remove_item">
                                            <input type="hidden" name="cart_item_id" value="<?= (int) $item['cart_item_id'] ?>">
                                            <button class="link-button" type="submit">刪除</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <form class="form checkout-form" method="post" action="cart.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="checkout">

                    <label>
                        付款方式
                        <select name="payment_method" required>
                            <option value="bank_transfer">銀行匯款</option>
                            <option value="credit_card">信用卡</option>
                            <option value="mobile_payment">行動支付</option>
                            <option value="convenience_store">超商繳費</option>
                        </select>
                    </label>

                    <label>
                        訂單備註
                        <input type="text" name="note" placeholder="例如：請協助確認資料">
                    </label>

                    <div class="checkout-total">總金額：NT$ <?= e(number_format($totalAmount)) ?></div>
                    <p class="helper-text">購物車會保留項目，但燈位只保留 1 小時；建立訂單時會重新確認保留狀態。若要展示完整流程，可到點燈紀錄按「略過付款並完成測試點燈」。</p>
                    <button class="button" type="submit">建立訂單</button>
                </form>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
