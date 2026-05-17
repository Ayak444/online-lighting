<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function ensure_cart_id(int $userId): int
{
    $stmt = db()->prepare('SELECT cart_id FROM carts WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $cartId = $stmt->fetchColumn();

    if ($cartId !== false) {
        return (int) $cartId;
    }

    $stmt = db()->prepare('INSERT INTO carts (user_id) VALUES (?)');
    $stmt->execute([$userId]);

    return (int) db()->lastInsertId();
}

function lamp_reservation_minutes(): int
{
    return 60;
}

function expire_lamp_reservations(?PDO $pdo = null): void
{
    $pdo = $pdo ?: db();
    $stmt = $pdo->prepare(
        'UPDATE lamp_reservations
         SET status = "expired"
         WHERE status = "active"
           AND expires_at <= NOW()'
    );
    $stmt->execute();
}

function available_lamp_counts_by_type(array $typeIds): array
{
    if ($typeIds === []) {
        return [];
    }

    $pdo = db();
    expire_lamp_reservations($pdo);

    $typeIds = array_values(array_unique(array_map('intval', $typeIds)));
    $placeholders = implode(',', array_fill(0, count($typeIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT lp.type_id, COUNT(*) AS available_count
         FROM lamp_positions lp
         WHERE lp.status = 'available'
           AND lp.type_id IN ($placeholders)
           AND NOT EXISTS (
               SELECT 1
               FROM lamp_reservations lr
               WHERE lr.position_id = lp.position_id
                 AND lr.status = 'active'
                 AND lr.expires_at > NOW()
           )
         GROUP BY lp.type_id"
    );
    $stmt->execute($typeIds);

    $counts = array_fill_keys($typeIds, 0);
    foreach ($stmt->fetchAll() as $row) {
        $counts[(int) $row['type_id']] = (int) $row['available_count'];
    }

    return $counts;
}

function active_lamp_reservation_for_cart_item(PDO $pdo, int $cartItemId, int $userId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT lr.*, lp.position_code
         FROM lamp_reservations lr
         INNER JOIN lamp_positions lp ON lp.position_id = lr.position_id
         WHERE lr.cart_item_id = ?
           AND lr.user_id = ?
           AND lr.status = "active"
           AND lr.expires_at > NOW()
         ORDER BY lr.reservation_id DESC
         LIMIT 1
         FOR UPDATE'
    );
    $stmt->execute([$cartItemId, $userId]);
    $reservation = $stmt->fetch();

    return $reservation ?: null;
}

function reserve_lamp_position_for_cart_item(PDO $pdo, int $cartItemId, int $typeId, int $userId): ?array
{
    expire_lamp_reservations($pdo);

    $reservation = active_lamp_reservation_for_cart_item($pdo, $cartItemId, $userId);
    if ($reservation !== null) {
        return $reservation;
    }

    $stmt = $pdo->prepare(
        'UPDATE lamp_reservations
         SET status = "cancelled"
         WHERE cart_item_id = ?
           AND user_id = ?
           AND status = "active"'
    );
    $stmt->execute([$cartItemId, $userId]);

    $stmt = $pdo->prepare(
        'SELECT lp.*
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
    $stmt->execute([$typeId]);
    $position = $stmt->fetch();

    if (!$position) {
        return null;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO lamp_reservations (
            cart_item_id,
            position_id,
            user_id,
            reserved_at,
            expires_at,
            status
         ) VALUES (?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? MINUTE), "active")'
    );
    $stmt->execute([
        $cartItemId,
        (int) $position['position_id'],
        $userId,
        lamp_reservation_minutes(),
    ]);

    $reservationId = (int) $pdo->lastInsertId();
    $stmt = $pdo->prepare(
        'SELECT lr.*, lp.position_code
         FROM lamp_reservations lr
         INNER JOIN lamp_positions lp ON lp.position_id = lr.position_id
         WHERE lr.reservation_id = ?
         LIMIT 1'
    );
    $stmt->execute([$reservationId]);

    return $stmt->fetch() ?: null;
}

function refresh_lamp_reservation_for_cart_item(PDO $pdo, int $cartItemId, int $typeId, int $userId): ?array
{
    expire_lamp_reservations($pdo);

    $reservation = active_lamp_reservation_for_cart_item($pdo, $cartItemId, $userId);
    if ($reservation !== null) {
        $stmt = $pdo->prepare(
            'UPDATE lamp_reservations
             SET reserved_at = NOW(),
                 expires_at = DATE_ADD(NOW(), INTERVAL ? MINUTE)
             WHERE reservation_id = ?'
        );
        $stmt->execute([lamp_reservation_minutes(), (int) $reservation['reservation_id']]);

        $stmt = $pdo->prepare(
            'SELECT lr.*, lp.position_code
             FROM lamp_reservations lr
             INNER JOIN lamp_positions lp ON lp.position_id = lr.position_id
             WHERE lr.reservation_id = ?
             LIMIT 1'
        );
        $stmt->execute([(int) $reservation['reservation_id']]);

        return $stmt->fetch() ?: null;
    }

    return reserve_lamp_position_for_cart_item($pdo, $cartItemId, $typeId, $userId);
}

function cancel_cart_item_reservations(PDO $pdo, int $cartItemId, int $userId): void
{
    $stmt = $pdo->prepare(
        'UPDATE lamp_reservations
         SET status = "cancelled"
         WHERE cart_item_id = ?
           AND user_id = ?
           AND status = "active"'
    );
    $stmt->execute([$cartItemId, $userId]);
}

function reservation_time_left_text(?string $expiresAt): string
{
    if ($expiresAt === null || $expiresAt === '') {
        return '未保留';
    }

    try {
        $expires = new DateTimeImmutable($expiresAt);
    } catch (Throwable) {
        return '未保留';
    }

    $seconds = $expires->getTimestamp() - time();
    if ($seconds <= 0) {
        return '已過期';
    }

    $minutes = intdiv($seconds + 59, 60);
    if ($minutes >= 60) {
        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;
        return $hours . ' 小時 ' . $remainingMinutes . ' 分鐘';
    }

    return $minutes . ' 分鐘';
}

function active_lamp_service_period(): ?array
{
    $stmt = db()->query(
        'SELECT *
         FROM lamp_service_periods
         WHERE status = "active"
         ORDER BY service_year DESC, period_id DESC
         LIMIT 1'
    );
    $period = $stmt->fetch();

    return $period ?: null;
}

function default_lamp_service_period_dates(int $serviceYear): array
{
    $knownDates = [
        2026 => [
            'blessing_start_date' => '2026-02-16',
            'blessing_end_date' => '2027-01-22',
        ],
    ];

    if (isset($knownDates[$serviceYear])) {
        return $knownDates[$serviceYear];
    }

    return [
        'blessing_start_date' => sprintf('%04d-01-01', $serviceYear),
        'blessing_end_date' => sprintf('%04d-12-31', $serviceYear),
    ];
}

function lamp_blessing_start_date(?string $sourceDateTime = null): string
{
    $sourceDateTime = trim((string) $sourceDateTime);

    try {
        $date = $sourceDateTime === ''
            ? new DateTimeImmutable('now')
            : new DateTimeImmutable($sourceDateTime);
    } catch (Throwable) {
        $date = new DateTimeImmutable('now');
    }

    return $date->format('Y-m-d');
}

function lamp_service_period_by_id(int $periodId): ?array
{
    $stmt = db()->prepare(
        'SELECT *
         FROM lamp_service_periods
         WHERE period_id = ?
         LIMIT 1'
    );
    $stmt->execute([$periodId]);
    $period = $stmt->fetch();

    return $period ?: null;
}

function annual_flow_match_field_label(string $field): string
{
    return [
        'always' => '全部信眾',
        'zodiac' => '生肖',
        'birth_time' => '出生時辰',
        'lunar_birthday' => '農曆生日',
    ][$field] ?? $field;
}

function annual_flow_split_values(?string $rawValues): array
{
    if ($rawValues === null || trim($rawValues) === '') {
        return [];
    }

    $values = preg_split('/[,，、|]/u', $rawValues) ?: [];

    return array_values(array_filter(array_map(static function (string $value): string {
        return trim($value);
    }, $values), static function (string $value): bool {
        return $value !== '';
    }));
}

function annual_flow_rule_matches(array $rule, array $dependent): bool
{
    $field = (string) $rule['match_field'];
    if ($field === 'always') {
        return true;
    }

    $candidate = trim((string) ($dependent[$field] ?? ''));
    if ($candidate === '') {
        return false;
    }

    $values = annual_flow_split_values($rule['match_values'] ?? null);
    if ($values === []) {
        return false;
    }

    foreach ($values as $value) {
        if ($candidate === $value || str_contains($candidate, $value) || str_contains($value, $candidate)) {
            return true;
        }
    }

    return false;
}

function annual_flow_recommendations(int $serviceYear, array $dependent): array
{
    $stmt = db()->prepare(
        'SELECT afr.*, lt.name AS lantern_name, lt.price, lt.slug
         FROM annual_flow_rules afr
         INNER JOIN lantern_types lt ON lt.type_id = afr.type_id
         WHERE afr.service_year = ?
           AND afr.is_active = 1
           AND lt.is_active = 1
         ORDER BY afr.priority ASC, afr.rule_id ASC'
    );
    $stmt->execute([$serviceYear]);
    $rules = $stmt->fetchAll();

    $recommendations = [];
    foreach ($rules as $rule) {
        if (!annual_flow_rule_matches($rule, $dependent)) {
            continue;
        }

        $typeId = (int) $rule['type_id'];
        if (!isset($recommendations[$typeId])) {
            $recommendations[$typeId] = [
                'type_id' => $typeId,
                'lantern_name' => $rule['lantern_name'],
                'price' => (float) $rule['price'],
                'slug' => $rule['slug'],
                'reasons' => [],
            ];
        }

        $recommendations[$typeId]['reasons'][] = $rule['recommendation_reason'];
    }

    return array_values($recommendations);
}

function public_nav(?array $user): string
{
    $links = [
        ['index.php', '首頁'],
        ['articles.php', '公告文化'],
        ['flow_tables.php', '流年表'],
        ['lanterns.php', '點燈大廳'],
        ['lamp_wall.php', '燈位牆'],
    ];

    if ($user !== null) {
        $links[] = ['profile.php', '會員中心'];
        $links[] = ['renewal_advisor.php', '智慧續燈'];
        $links[] = ['cart.php', '購物車'];
        $links[] = ['orders.php', '點燈紀錄'];
        $links[] = ['notifications.php', '通知中心'];
        $links[] = ['feedback.php', '問題回饋'];
        $links[] = ['logout.php', '登出'];
    } else {
        $links[] = ['login.php', '登入'];
        $links[] = ['register.php', '註冊'];
    }

    $html = '<nav>';
    foreach ($links as [$href, $label]) {
        $html .= '<a href="' . e($href) . '">' . e($label) . '</a>';
    }
    $html .= '</nav>';

    return $html;
}

function flash_message(): ?string
{
    start_app_session();
    $message = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return is_string($message) ? $message : null;
}

function set_flash(string $message): void
{
    start_app_session();
    $_SESSION['flash'] = $message;
}

function order_status_label(string $status): string
{
    return [
        'pending_payment' => '未付款',
        'paid' => '已付款',
        'assigned' => '已安燈',
        'completed' => '已過火',
        'cancelled' => '已取消',
    ][$status] ?? $status;
}

function payment_status_label(string $status): string
{
    return [
        'unpaid' => '未付款',
        'paid' => '已付款',
        'refunded' => '已退款',
    ][$status] ?? $status;
}

function payment_method_label(string $method): string
{
    return [
        'bank_transfer' => '銀行匯款',
        'credit_card' => '信用卡',
        'mobile_payment' => '行動支付',
        'convenience_store' => '超商繳費',
        'cash' => '現金',
        'mock' => '模擬付款',
    ][$method] ?? $method;
}

function payment_record_status_label(string $status): string
{
    return [
        'pending' => '待對帳',
        'confirmed' => '已確認',
        'failed' => '付款失敗',
        'cancelled' => '已取消',
    ][$status] ?? $status;
}

function review_status_label(string $status): string
{
    return [
        'pending' => '待審核',
        'approved' => '已審核',
        'rejected' => '已駁回',
    ][$status] ?? $status;
}

function order_item_status_label(string $status): string
{
    return [
        'pending' => '待分配',
        'assigned' => '已安燈',
        'completed' => '已過火',
        'cancelled' => '已取消',
    ][$status] ?? $status;
}

function lamp_position_status_label(string $status): string
{
    return [
        'available' => '空位',
        'reserved' => '保留中',
        'occupied' => '佔用',
        'maintenance' => '維修中',
    ][$status] ?? $status;
}

function invoice_status_label(string $status): string
{
    return [
        'issued' => '已開立',
        'voided' => '已作廢',
    ][$status] ?? $status;
}

function notification_channel_label(string $channel): string
{
    return [
        'email' => 'Email',
        'sms' => '簡訊',
        'line' => 'Line',
        'system' => '系統',
    ][$channel] ?? $channel;
}

function notification_status_label(string $status): string
{
    return [
        'pending' => '待發送',
        'sent' => '已發送',
        'failed' => '發送失敗',
        'read' => '已讀',
    ][$status] ?? $status;
}

function notification_delivery_status_label(?string $status): string
{
    return [
        'sent' => '已投遞',
        'failed' => '投遞失敗',
        'skipped' => '暫不投遞',
    ][$status ?? ''] ?? '尚未投遞';
}

function lamp_service_period_status_label(string $status): string
{
    return [
        'draft' => '草稿',
        'active' => '啟用中',
        'closed' => '已結束',
    ][$status] ?? $status;
}

function scheduled_job_type_label(string $type): string
{
    return [
        'expiration_reminder' => '點燈到期提醒',
        'event_broadcast' => '活動廣播',
        'custom' => '自訂通知',
    ][$type] ?? $type;
}

function scheduled_job_status_label(string $status): string
{
    return [
        'active' => '啟用中',
        'paused' => '暫停',
        'finished' => '已完成',
    ][$status] ?? $status;
}

function article_type_label(string $type): string
{
    return [
        'announcement' => '最新公告',
        'blog' => '文化部落格',
        'temple_history' => '廟宇歷史',
        'deity_intro' => '神明介紹',
        'lantern_intro' => '燈種介紹',
    ][$type] ?? $type;
}

function article_status_label(string $status): string
{
    return [
        'draft' => '草稿',
        'published' => '已發布',
        'archived' => '已封存',
    ][$status] ?? $status;
}

function feedback_category_label(string $category): string
{
    return [
        'qa' => 'Q&A',
        'bug' => 'Bug 回報',
        'order' => '訂單問題',
        'other' => '其他',
    ][$category] ?? $category;
}

function feedback_status_label(string $status): string
{
    return [
        'open' => '待處理',
        'processing' => '處理中',
        'closed' => '已結案',
    ][$status] ?? $status;
}

function role_label(string $roleName): string
{
    return [
        'member' => '一般會員',
        'staff' => '工作人員',
        'admin' => '系統管理員',
    ][$roleName] ?? $roleName;
}

function gender_label(string $gender): string
{
    return [
        'male' => '男',
        'female' => '女',
        'other' => '其他',
        'unspecified' => '未指定',
    ][$gender] ?? $gender;
}

function user_active_label(int $isActive): string
{
    return $isActive === 1 ? '啟用' : '停用';
}

function audit_action_label(string $action): string
{
    return [
        'member_login' => '會員登入',
        'member_oauth_login' => '會員第三方登入',
        'member_oauth_register' => '第三方登入建立會員',
        'member_oauth_link' => '會員綁定第三方登入',
        'member_phone_login_code_request' => '手機登入驗證碼申請',
        'member_phone_login' => '手機驗證碼登入',
        'admin_login' => '管理員登入',
        'admin_login_denied' => '後台登入拒絕',
        'member_password_change' => '會員更改密碼',
        'admin_password_change' => '後台重設密碼',
        'member_register' => '會員註冊',
        'member_profile_update' => '會員資料更新',
        'dependent_create' => '新增祈福對象',
        'dependent_update' => '更新祈福對象',
        'dependent_delete' => '刪除祈福對象',
        'cart_item_create' => '加入購物車',
        'cart_item_delete' => '刪除購物車項目',
        'order_create' => '建立訂單',
        'member_mock_payment_complete' => '會員測試略過付款',
        'feedback_create' => '送出問題回饋',
        'admin_payment_confirm' => '後台確認付款',
        'admin_order_approve' => '後台審核訂單',
        'admin_order_reject' => '後台駁回訂單',
        'admin_lamp_position_assign' => '後台分配燈位',
        'admin_order_complete' => '後台標記已過火',
        'admin_lantern_type_create' => '新增燈種',
        'admin_lantern_type_update' => '更新燈種',
        'admin_lantern_type_toggle' => '切換燈種狀態',
        'admin_lamp_position_create' => '新增燈位',
        'admin_lamp_position_update' => '更新燈位',
        'admin_lamp_position_delete' => '刪除燈位',
        'admin_article_create' => '新增文章',
        'admin_article_update' => '更新文章',
        'admin_article_archive' => '封存文章',
        'admin_feedback_reply' => '回覆問題回饋',
        'admin_user_create' => '新增會員帳號',
        'admin_user_update' => '更新會員權限',
        'admin_service_period_save' => '儲存年度燈期',
        'admin_statistics_generate' => '產生統計報表',
        'admin_scheduled_job_save' => '儲存排程任務',
        'admin_scheduled_job_status' => '更新排程狀態',
        'admin_notification_status' => '更新通知狀態',
        'member_notification_read' => '會員已讀通知',
        'member_notification_preferences_update' => '會員更新通知偏好',
        'system_scheduled_job_run' => '系統執行排程',
        'system_notification_delivery' => '系統投遞通知',
        'admin_annual_flow_rule_save' => '儲存流年規則',
        'admin_annual_flow_rule_toggle' => '切換流年規則狀態',
        'member_renewal_recommendation_add' => '智慧續燈加入購物車',
    ][$action] ?? $action;
}
