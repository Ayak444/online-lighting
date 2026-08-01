<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/frontend.php';

$admin = require_admin('login.php');
$adminId = (int) $admin['user_id'];
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$errors = [];
$flash = flash_message();
$perPage = 50;

function db_admin_nav(): string
{
    $links = [
        ['dashboard.php', '總覽'],
        ['database.php', '資料庫'],
        ['users.php', '會員權限'],
        ['lantern_types.php', '燈種'],
        ['lamp_positions.php', '燈位'],
        ['orders.php', '訂單'],
        ['articles.php', '公告文化'],
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

const TABLE_LABELS = [
    'annual_flow_entries'          => '年度流程紀錄',
    'annual_flow_rules'            => '年度流程規則',
    'articles'                     => '公告文章',
    'audit_logs'                   => '稽核操作日誌',
    'auth_identities'              => '身份驗證紀錄',
    'carts'                        => '購物車',
    'cart_items'                   => '購物車項目',
    'dependents'                   => '受益人',
    'feedbacks'                    => '意見回饋',
    'invoices'                     => '發票',
    'lamp_positions'               => '燈位',
    'lamp_reservations'            => '燈位預約',
    'lamp_service_periods'         => '燈位服務期間',
    'lantern_types'                => '燈種類型',
    'notifications'                => '通知',
    'notification_deliveries'      => '通知發送紀錄',
    'oauth_states'                 => 'OAuth 狀態',
    'orders'                       => '訂單',
    'order_items'                  => '訂單項目',
    'payments'                     => '付款紀錄',
    'phone_login_codes'            => '手機登入驗證碼',
    'roles'                        => '角色權限',
    'scheduled_jobs'               => '排程工作',
    'statistics'                   => '統計數據',
    'users'                        => '會員',
    'user_notification_preferences' => '會員通知偏好',
    'user_roles'                   => '會員角色',
];

const COLUMN_TRANSLATIONS = [
    'created_at' => '建立時間', 'updated_at' => '更新/修改時間', 'user_id' => '會員編號',
    'status' => '狀態', 'order_id' => '訂單編號', 'order_number' => '訂單號碼',
    'service_period_id' => '服務燈期 ID', 'service_year' => '服務年度', 'total_amount' => '總金額',
    'payment_status' => '付款狀態', 'order_status' => '訂單狀態', 'review_status' => '審核狀態',
    'note' => '備註', 'paid_at' => '付款時間', 'reviewed_at' => '審核時間',
    'detail_id' => '明細編號', 'type_id' => '燈種編號', 'dependent_id' => '祈福對象編號',
    'position_id' => '實體燈位編號', 'lantern_name_snapshot' => '燈種名稱 (結帳快照)',
    'dependent_name_snapshot' => '祈福姓名 (結帳快照)', 'price_snapshot' => '價格 (結帳快照)',
    'renewal_source_detail_id' => '續點來源明細 ID', 'blessing_start_date' => '點燈起始日',
    'blessing_end_date' => '點燈到期日', 'assigned_at' => '系統配燈時間', 'item_status' => '明細狀態',
    'position_code' => '燈位代碼(如 WC-A-01)', 'area' => '區域', 'row_no' => '排', 'col_no' => '列',
    'occupied_until' => '燈位佔用到期日', 'email' => '電子信箱', 'password_hash' => '密碼(雜湊)',
    'name' => '名稱/姓名', 'phone' => '電話', 'address' => '地址', 'role' => '身分權限',
    'last_login_at' => '最後登入時間', 'birthday' => '國曆生日', 'zodiac' => '生肖',
    'relation' => '與會員關係', 'entry_id' => '紀錄編號', 'zodiac_order' => '生肖排序',
    'zodiac_branch' => '地支', 'zodiac_animal' => '生肖動物', 'zodiac_label' => '顯示標籤',
    'flow_notice' => '流年運勢提醒', 'appendix' => '附註說明', 'is_active' => '是否啟用',
    'rule_id' => '推薦規則 ID', 'rule_name' => '規則名稱', 'match_field' => '條件配對欄位',
    'match_values' => '條件配對值', 'recommendation_reason' => '向信眾推薦的理由',
    'priority' => '推薦優先權', 'period_id' => '燈期編號', 'registration_open_at' => '開放報名時間',
    'reminder_days_before' => '到期前幾天提醒', 'notes' => '內部筆記', 'action' => '動作(新增/修改/刪除)',
    'table_name' => '異動資料表', 'record_id' => '異動資料 ID', 'before_data' => '修改前資料(JSON)',
    'after_data' => '修改後資料(JSON)', 'ip_address' => 'IP位址', 'user_agent' => '瀏覽器資訊',
    'price' => '價格', 'stock_quantity' => '總庫存量', 'description' => '介紹說明', 'image_url' => '圖片路徑',
    'prayer_wish' => '祈福心願', 'target_period_id' => '目標燈期 ID', 'preferred_position_id' => '偏好沿用燈位 ID',
    'cart_item_id' => '購物車項目 ID', 'cart_id' => '購物車 ID', 'reservation_id' => '保留紀錄 ID',
    'expires_at' => '保留到期時間', 'article_id' => '文章 ID', 'author_id' => '發布者 ID',
    'article_type' => '文章類型', 'title' => '標題', 'content' => '內文', 'published_at' => '發布時間',
    'payment_method' => '付款方式', 'amount' => '金額', 'transaction_no' => '金流交易序號',
    'raw_payload' => '金流原始回傳資料', 'category' => '分類', 'subject' => '主旨',
    'admin_reply' => '管理員回覆', 'replied_by' => '回覆者', 'replied_at' => '回覆時間',
    'order_item_id' => '訂單明細 ID', 'recommended_for' => '推薦對象', 'not_recommended_for' => '不推薦對象',
    'job_type' => '排程類型', 'cron_expression' => 'Cron 排程語法', 'token_hash' => 'Token 雜湊值',
    'consumed_at' => '消耗/使用時間', 'job_id' => '工作 ID', 'relationship' => '關係', 'email_enabled' => '啟用 Email',
    'log_id' => '日誌 ID', 'feedback_id' => '回饋 ID', 'line_enabled' => '啟用 Line', 'birth_clock_time' => '出生時辰',
    'count_by_type_json' => '各燈種數量 (JSON)', 'profile_json' => '個人資料 (JSON)', 'statistic_id' => '統計 ID',
    'blessing_description' => '祈福說明', 'period_end' => '燈期結束日', 'national_id' => '身分證字號',
    'system_enabled' => '啟用系統通知', 'requested_ip' => '請求 IP', 'last_run_at' => '最後執行時間',
    'total_lantern_count' => '總點燈數', 'auth_provider' => '驗證提供者', 'role_id' => '角色 ID',
    'invoice_number' => '發票號碼', 'birth_time' => '出生時間', 'created_by' => '建立者', 'delivery_id' => '發送紀錄 ID',
    'sent_at' => '發送時間', 'code_hash' => '驗證碼雜湊', 'state_hash' => '狀態雜湊', 'slug' => '代稱',
    'notify_id' => '通知 ID', 'period_start' => '燈期起始日', 'total_revenue' => '總營收', 'display_name' => '顯示名稱',
    'flow_mode' => '流年模式', 'generated_at' => '產生時間', 'invoice_status' => '發票狀態', 'used_at' => '使用時間',
    'provider_response' => '提供者回應', 'verified_at' => '驗證時間', 'next_run_at' => '下次執行時間',
    'role_name' => '角色名稱', 'redirect_path' => '重導向路徑', 'identity_id' => '身分 ID', 'sort_order' => '排序權重',
    'payer_name' => '付款人姓名', 'sms_enabled' => '啟用簡訊', 'linked_at' => '綁定時間', 'error_message' => '錯誤訊息',
    'image_path' => '圖片路徑', 'code_id' => '驗證碼 ID', 'generated_by' => '產生者', 'attempt_count' => '嘗試次數',
    'invoice_id' => '發票 ID', 'gender' => '性別', 'payment_id' => '付款 ID', 'is_self_profile' => '是否為本人資料',
    'lunar_birthday' => '農曆生日', 'issued_at' => '開立時間', 'provider_message_id' => '提供商訊息 ID',
    'provider_id' => '提供商 ID', 'provider_user_id' => '提供商用戶 ID', 'attempt_no' => '重試次數',
    'recipient' => '收件者', 'provider' => '提供商', 'target_channel' => '目標頻道', 'provider_email' => '提供商 Email',
    'converted_at' => '轉換時間', 'next_retry_at' => '下次重試時間', 'channel' => '通知頻道', 'trigger_time' => '觸發時間',
    'annual_reminder' => '年度提醒', 'job_name' => '工作名稱', 'reserved_at' => '保留時間', 'reset_id' => '重置 ID',
    'question' => '詢問問題/祈求事項', 'left_face' => '左筊', 'right_face' => '右筊', 'result' => '擲筊/抽籤結果',


];

function column_translation(string $column): string
{
    return COLUMN_TRANSLATIONS[$column] ?? '';
}

function table_label(string $table): string
{
    return TABLE_LABELS[$table] ?? $table;
}

function quote_identifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function database_tables(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT table_name
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_type = "BASE TABLE"
         ORDER BY table_name'
    );

    return array_map(static function (array $row): string {
        return (string) $row['table_name'];
    }, $stmt->fetchAll());
}

function table_exists_in_list(string $table, array $tables): bool
{
    return in_array($table, $tables, true);
}

function table_columns(PDO $pdo, string $table): array
{
    $stmt = $pdo->query('SHOW FULL COLUMNS FROM ' . quote_identifier($table));
    return $stmt->fetchAll();
}

function primary_key_columns(PDO $pdo, string $table): array
{
    $stmt = $pdo->prepare(
        'SELECT column_name
         FROM information_schema.key_column_usage
         WHERE table_schema = DATABASE()
           AND table_name = ?
           AND constraint_name = "PRIMARY"
         ORDER BY ordinal_position'
    );
    $stmt->execute([$table]);

    return array_map(static function (array $row): string {
        return (string) $row['column_name'];
    }, $stmt->fetchAll());
}

function column_by_name(array $columns): array
{
    $map = [];
    foreach ($columns as $column) {
        $map[(string) $column['Field']] = $column;
    }

    return $map;
}

function editable_column(array $column, array $primaryKeys, bool $isEdit): bool
{
    $field = (string) $column['Field'];
    $extra = strtolower((string) $column['Extra']);

    if (str_contains($extra, 'auto_increment') || str_contains($extra, 'generated')) {
        return false;
    }

    if ($isEdit && in_array($field, $primaryKeys, true)) {
        return false;
    }

    return true;
}

function enum_options(string $type): ?array
{
    if (!str_starts_with(strtolower($type), 'enum(')) {
        return null;
    }

    preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $type, $matches);
    return array_map(static function (string $value): string {
        return stripcslashes($value);
    }, $matches[1]);
}

function normalize_form_value(array $column, mixed $raw, bool $forInsert): mixed
{
    $value = trim((string) $raw);
    $nullable = strtoupper((string) $column['Null']) === 'YES';
    $hasDefault = $column['Default'] !== null;

    if ($value === '') {
        if ($forInsert && ($nullable || $hasDefault)) {
            return '__OMIT__';
        }

        if ($nullable) {
            return null;
        }
    }

    return $value;
}

function encode_pk_token(array $row, array $primaryKeys): string
{
    $values = [];
    foreach ($primaryKeys as $key) {
        $values[$key] = isset($row[$key]) ? (string) $row[$key] : '';
    }

    return base64_encode(json_encode($values, JSON_UNESCAPED_UNICODE));
}

function decode_pk_token(?string $token): ?array
{
    if ($token === null || $token === '') {
        return null;
    }

    $json = base64_decode($token, true);
    if ($json === false) {
        return null;
    }

    $values = json_decode($json, true);
    return is_array($values) ? $values : null;
}

function pk_where_sql(array $primaryKeys, array $pkValues, array &$params): string
{
    $parts = [];
    foreach ($primaryKeys as $key) {
        if (!array_key_exists($key, $pkValues)) {
            throw new RuntimeException('缺少主鍵欄位：' . $key);
        }

        $parts[] = quote_identifier($key) . ' = ?';
        $params[] = $pkValues[$key];
    }

    return implode(' AND ', $parts);
}

function fetch_row_by_pk(PDO $pdo, string $table, array $primaryKeys, array $pkValues): ?array
{
    $params = [];
    $where = pk_where_sql($primaryKeys, $pkValues, $params);
    $stmt = $pdo->prepare('SELECT * FROM ' . quote_identifier($table) . ' WHERE ' . $where . ' LIMIT 1');
    $stmt->execute($params);
    $row = $stmt->fetch();

    return $row ?: null;
}

function short_cell_value(mixed $value): string
{
    if ($value === null) {
        return 'NULL';
    }

    $text = (string) $value;
    if ($text === '') {
        return '';
    }

    return strlen($text) > 120 ? substr($text, 0, 117) . '...' : $text;
}

function render_input(array $column, mixed $value, bool $disabled = false): string
{
    $field = (string) $column['Field'];
    $type = strtolower((string) $column['Type']);
    $valueString = $value === null ? '' : (string) $value;
    $name = 'values[' . $field . ']';
    $disabledAttr = $disabled ? ' disabled' : '';
    $requiredAttr = strtoupper((string) $column['Null']) === 'NO' && $column['Default'] === null ? ' required' : '';
    $enumOptions = enum_options($type);

    if ($enumOptions !== null) {
        $html = '<select name="' . e($name) . '"' . $disabledAttr . $requiredAttr . '>';
        if (strtoupper((string) $column['Null']) === 'YES') {
            $html .= '<option value="">NULL</option>';
        }
        foreach ($enumOptions as $option) {
            $selected = $option === $valueString ? ' selected' : '';
            $html .= '<option value="' . e($option) . '"' . $selected . '>' . e($option) . '</option>';
        }
        $html .= '</select>';

        return $html;
    }

    if (str_contains($type, 'text') || str_contains($type, 'json')) {
        return '<textarea name="' . e($name) . '" rows="4"' . $disabledAttr . $requiredAttr . '>' . e($valueString) . '</textarea>';
    }

    $inputType = 'text';
    $extra = '';
    if (str_contains($type, 'int') || str_contains($type, 'decimal') || str_contains($type, 'float') || str_contains($type, 'double')) {
        $inputType = 'number';
        $extra = ' step="any"';
    } elseif (str_starts_with($type, 'date')) {
        $inputType = 'date';
    } elseif (str_starts_with($type, 'datetime') || str_starts_with($type, 'timestamp')) {
        $inputType = 'datetime-local';
        $valueString = str_replace(' ', 'T', substr($valueString, 0, 16));
    } elseif (str_starts_with($type, 'time')) {
        $inputType = 'time';
    }

    return '<input type="' . e($inputType) . '" name="' . e($name) . '" value="' . e($valueString) . '"' . $extra . $disabledAttr . $requiredAttr . '>';
}

$tables = database_tables($pdo);
$selectedTable = (string) ($_GET['table'] ?? ($_POST['table'] ?? ($tables[0] ?? '')));
if ($selectedTable !== '' && !table_exists_in_list($selectedTable, $tables)) {
    $errors[] = '找不到指定的資料表。';
    $selectedTable = $tables[0] ?? '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors[] = '表單驗證失敗，請重新操作。';
    }

    $action = (string) ($_POST['action'] ?? '');
    $postTable = (string) ($_POST['table'] ?? '');

    if ($errors === [] && !table_exists_in_list($postTable, $tables)) {
        $errors[] = '資料表不合法。';
    }

    if ($errors === []) {
        $selectedTable = $postTable;
        $columns = table_columns($pdo, $selectedTable);
        $columnMap = column_by_name($columns);
        $primaryKeys = primary_key_columns($pdo, $selectedTable);

        try {
            if ($action === 'insert') {
                $fieldSql = [];
                $placeholders = [];
                $params = [];
                $after = [];

                foreach ($columns as $column) {
                    if (!editable_column($column, $primaryKeys, false)) {
                        continue;
                    }

                    $field = (string) $column['Field'];
                    $raw = $_POST['values'][$field] ?? '';
                    $value = normalize_form_value($column, $raw, true);
                    if ($value === '__OMIT__') {
                        continue;
                    }

                    $fieldSql[] = quote_identifier($field);
                    $placeholders[] = '?';
                    $params[] = $value;
                    $after[$field] = $value;
                }

                if ($fieldSql === []) {
                    throw new RuntimeException('沒有可新增的欄位。');
                }

                $sql = 'INSERT INTO ' . quote_identifier($selectedTable)
                    . ' (' . implode(', ', $fieldSql) . ') VALUES (' . implode(', ', $placeholders) . ')';
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);

                audit_log($adminId, 'database_insert', $selectedTable, (string) $pdo->lastInsertId(), null, $after);
                set_flash('資料已新增。');
                redirect('database.php?table=' . rawurlencode($selectedTable));
            } elseif ($action === 'update') {
                if ($primaryKeys === []) {
                    throw new RuntimeException('此資料表沒有主鍵，無法安全修改。');
                }

                $pkValues = decode_pk_token((string) ($_POST['pk'] ?? ''));
                if ($pkValues === null) {
                    throw new RuntimeException('主鍵資料不正確。');
                }

                $before = fetch_row_by_pk($pdo, $selectedTable, $primaryKeys, $pkValues);
                if ($before === null) {
                    throw new RuntimeException('找不到要修改的資料。');
                }

                $sets = [];
                $params = [];
                $after = [];
                foreach ($columns as $column) {
                    if (!editable_column($column, $primaryKeys, true)) {
                        continue;
                    }

                    $field = (string) $column['Field'];
                    $raw = $_POST['values'][$field] ?? '';
                    $value = normalize_form_value($column, $raw, false);
                    $sets[] = quote_identifier($field) . ' = ?';
                    $params[] = $value;
                    $after[$field] = $value;
                }

                if ($sets === []) {
                    throw new RuntimeException('沒有可修改的欄位。');
                }

                $where = pk_where_sql($primaryKeys, $pkValues, $params);
                $sql = 'UPDATE ' . quote_identifier($selectedTable) . ' SET ' . implode(', ', $sets) . ' WHERE ' . $where;
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);

                audit_log($adminId, 'database_update', $selectedTable, json_encode($pkValues, JSON_UNESCAPED_UNICODE), $before, $after);
                set_flash('資料已修改。');
                redirect('database.php?table=' . rawurlencode($selectedTable));
            } elseif ($action === 'delete') {
                if ($primaryKeys === []) {
                    throw new RuntimeException('此資料表沒有主鍵，無法安全刪除。');
                }

                $pkValues = decode_pk_token((string) ($_POST['pk'] ?? ''));
                if ($pkValues === null) {
                    throw new RuntimeException('主鍵資料不正確。');
                }

                if ($selectedTable === 'users' && isset($pkValues['user_id']) && (int) $pkValues['user_id'] === $adminId) {
                    throw new RuntimeException('不能從資料庫工具刪除目前登入中的管理員帳號。');
                }

                $before = fetch_row_by_pk($pdo, $selectedTable, $primaryKeys, $pkValues);
                if ($before === null) {
                    throw new RuntimeException('找不到要刪除的資料。');
                }

                $params = [];
                $where = pk_where_sql($primaryKeys, $pkValues, $params);
                $stmt = $pdo->prepare('DELETE FROM ' . quote_identifier($selectedTable) . ' WHERE ' . $where);
                $stmt->execute($params);

                audit_log($adminId, 'database_delete', $selectedTable, json_encode($pkValues, JSON_UNESCAPED_UNICODE), $before, null);
                set_flash('資料已刪除。');
                redirect('database.php?table=' . rawurlencode($selectedTable));
            } else {
                $errors[] = '未知的操作。';
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$mode = (string) ($_GET['mode'] ?? 'list');
$page = max(1, (int) ($_GET['page'] ?? 1));
$search = trim((string) ($_GET['q'] ?? ''));
$offset = ($page - 1) * $perPage;
$columns = $selectedTable === '' ? [] : table_columns($pdo, $selectedTable);
$columnMap = column_by_name($columns);
$primaryKeys = $selectedTable === '' ? [] : primary_key_columns($pdo, $selectedTable);
$editRow = null;

if ($selectedTable !== '' && $mode === 'edit') {
    $pkValues = decode_pk_token($_GET['pk'] ?? null);
    if ($pkValues === null) {
        $errors[] = '主鍵資料不正確。';
        $mode = 'list';
    } else {
        $editRow = fetch_row_by_pk($pdo, $selectedTable, $primaryKeys, $pkValues);
        if ($editRow === null) {
            $errors[] = '找不到要編輯的資料。';
            $mode = 'list';
        }
    }
}

$rowCount = 0;
$rows = [];
if ($selectedTable !== '') {
    $whereSql = '';
    $queryParams = [];
    if ($search !== '') {
        $searchParts = [];
        foreach ($columns as $column) {
            $searchParts[] = quote_identifier($column['Field']) . ' LIKE ?';
            $queryParams[] = '%' . $search . '%';
        }
        if ($searchParts !== []) {
            $whereSql = ' WHERE ' . implode(' OR ', $searchParts);
        }
    }

    if ($whereSql !== '') {
        $stmtCount = $pdo->prepare('SELECT COUNT(*) FROM ' . quote_identifier($selectedTable) . $whereSql);
        $stmtCount->execute($queryParams);
        $rowCount = (int) $stmtCount->fetchColumn();
    } else {
        $rowCount = (int) $pdo->query('SELECT COUNT(*) FROM ' . quote_identifier($selectedTable))->fetchColumn();
    }

    $orderSql = $primaryKeys === [] ? '' : ' ORDER BY ' . implode(', ', array_map(static function (string $key): string {
        return quote_identifier($key) . ' DESC';
    }, $primaryKeys));
    $stmt = $pdo->prepare('SELECT * FROM ' . quote_identifier($selectedTable) . $whereSql . $orderSql . ' LIMIT ? OFFSET ?');
    
    $bindIndex = 1;
    foreach ($queryParams as $param) {
        $stmt->bindValue($bindIndex++, $param, PDO::PARAM_STR);
    }
    $stmt->bindValue($bindIndex++, $perPage, PDO::PARAM_INT);
    $stmt->bindValue($bindIndex, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
}

$totalPages = max(1, (int) ceil($rowCount / $perPage));
?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>資料庫管理 - 點燈系統後台</title>
    <link rel="stylesheet" href="../assets/css/main.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
    <style>
        .page-fullwidth {
            max-width: 98%;
            margin: 0 auto;
            padding: 20px;
        }
        .database-grid {
            display: grid;
            grid-template-columns: 200px 1fr;
            gap: 24px;
        }
        @media (max-width: 768px) {
            .database-grid {
                grid-template-columns: 1fr;
            }
        }
        .table-wrap {
            overflow-x: auto;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .data-table {
            width: 100%;
            min-width: 1000px; /* Force minimum width to prevent squishing */
        }
        .data-table th, .data-table td {
            white-space: nowrap;
        }
    </style>
</head>
<body>
    <header class="site-header">
        <h1>點燈系統後台</h1>
        <?= db_admin_nav() ?>
    </header>

    <main class="page-fullwidth">
        <section class="section-heading">
            <div>
                <h1>資料庫管理</h1>
                <p>管理員限定工具，可查看資料表結構與執行單筆新增、修改、刪除。</p>
            </div>
            <?php if ($selectedTable !== ''): ?>
                <a class="button" href="database.php?table=<?= e(rawurlencode($selectedTable)) ?>&mode=add">新增資料</a>
            <?php endif; ?>
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

        <div class="database-grid">
            <aside class="panel">
                <h2>資料表</h2>
                <div class="database-table-list">
                    <?php foreach ($tables as $table): ?>
                        <a class="status-pill <?= $table === $selectedTable ? 'active' : '' ?>" href="database.php?table=<?= e(rawurlencode($table)) ?>" title="<?= e($table) ?>">
                            <?= e(table_label($table)) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
                <form class="form" method="get" action="database.php" style="margin-top: 20px;">
                    <input type="hidden" name="table" value="<?= e($selectedTable) ?>">
                    <label>
                        全域搜尋此表
                        <div style="display: flex; gap: 8px;">
                            <input type="text" name="q" value="<?= e($search) ?>" placeholder="輸入關鍵字...">
                            <button type="submit" class="button">搜尋</button>
                            <?php if ($search !== ''): ?>
                                <a href="database.php?table=<?= e(rawurlencode($selectedTable)) ?>" class="button secondary">清除</a>
                            <?php endif; ?>
                        </div>
                    </label>
                </form>
            </aside>

            <section class="panel">
                <?php if ($selectedTable === ''): ?>
                    <p>目前沒有可管理的資料表。</p>
                <?php else: ?>
                    <div class="section-heading">
                        <div>
                            <h2><?= e(table_label($selectedTable)) ?> <small style="font-weight:400;font-size:.65em;color:var(--color-muted,#888)"><?= e($selectedTable) ?></small></h2>
                            <p><?= e((string) $rowCount) ?> 筆資料，<?= e((string) count($columns)) ?> 個欄位</p>
                        </div>
                        <a class="button secondary" href="database.php?table=<?= e(rawurlencode($selectedTable)) ?>">回列表</a>
                    </div>

                    <?php if ($mode === 'add' || $mode === 'edit'): ?>
                        <?php $isEdit = $mode === 'edit'; ?>
                        <form class="form grid-form" method="post" action="database.php">
                            <?= csrf_field() ?>
                            <input type="hidden" name="table" value="<?= e($selectedTable) ?>">
                            <input type="hidden" name="action" value="<?= $isEdit ? 'update' : 'insert' ?>">
                            <?php if ($isEdit && $editRow !== null): ?>
                                <input type="hidden" name="pk" value="<?= e(encode_pk_token($editRow, $primaryKeys)) ?>">
                            <?php endif; ?>

                            <?php foreach ($columns as $column): ?>
                                <?php
                                $field = (string) $column['Field'];
                                $value = $isEdit && $editRow !== null ? ($editRow[$field] ?? null) : null;
                                $editable = editable_column($column, $primaryKeys, $isEdit);
                                ?>
                                <label class="<?= str_contains(strtolower((string) $column['Type']), 'text') ? 'full-span' : '' ?>">
                                    <?= e($field) ?>
                                    <small><?= e((string) $column['Type']) ?><?= in_array($field, $primaryKeys, true) ? '，主鍵' : '' ?><?= !$editable ? '，系統欄位' : '' ?></small>
                                    <?= render_input($column, $value, !$editable) ?>
                                </label>
                            <?php endforeach; ?>

                            <div class="admin-actions full-span">
                                <button class="button" type="submit"><?= $isEdit ? '儲存修改' : '新增資料' ?></button>
                                <a class="button secondary" href="database.php?table=<?= e(rawurlencode($selectedTable)) ?>">取消</a>
                            </div>
                        </form>
                    <?php else: ?>
                        <h3>欄位結構</h3>
                        <div class="table-wrap">
                            <table class="data-table compact-table">
                                <thead>
                                    <tr>
                                        <th>欄位 (英文)</th>
                                        <th>中文說明</th>
                                        <th>型態</th>
                                        <th>NULL</th>
                                        <th>Key</th>
                                        <th>預設值</th>
                                        <th>Extra</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($columns as $column): ?>
                                        <tr>
                                            <td><strong><?= e((string) $column['Field']) ?></strong></td>
                                            <td><span style="color:#0056b3; font-weight:bold;"><?= e(column_translation((string) $column['Field'])) ?></span></td>
                                            <td><?= e((string) $column['Type']) ?></td>
                                            <td><?= e((string) $column['Null']) ?></td>
                                            <td><?= e((string) $column['Key']) ?></td>
                                            <td><?= e($column['Default'] === null ? 'NULL' : (string) $column['Default']) ?></td>
                                            <td><?= e((string) $column['Extra']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <h3 class="section-gap">資料內容</h3>
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <?php foreach ($columns as $column): ?>
                                            <th><?= e((string) $column['Field']) ?></th>
                                        <?php endforeach; ?>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($rows as $row): ?>
                                        <?php $pkToken = $primaryKeys === [] ? '' : encode_pk_token($row, $primaryKeys); ?>
                                        <tr>
                                            <?php foreach ($columns as $column): ?>
                                                <?php $field = (string) $column['Field']; ?>
                                                <td><?= e(short_cell_value($row[$field] ?? null)) ?></td>
                                            <?php endforeach; ?>
                                            <td>
                                                <?php if ($primaryKeys !== []): ?>
                                                    <div class="table-actions">
                                                        <a href="database.php?table=<?= e(rawurlencode($selectedTable)) ?>&mode=edit&pk=<?= e(rawurlencode($pkToken)) ?>">編輯</a>
                                                        <form method="post" action="database.php" onsubmit="return confirm('確定要刪除這筆資料？');">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="table" value="<?= e($selectedTable) ?>">
                                                            <input type="hidden" name="action" value="delete">
                                                            <input type="hidden" name="pk" value="<?= e($pkToken) ?>">
                                                            <button class="link-button danger" type="submit">刪除</button>
                                                        </form>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="helper-text">無主鍵</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if ($rows === []): ?>
                                        <tr>
                                            <td colspan="<?= count($columns) + 1 ?>">目前沒有資料。</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="admin-actions">
                            <?php $searchQueryString = $search !== '' ? '&q=' . e(rawurlencode($search)) : ''; ?>
                            <?php if ($page > 1): ?>
                                <a class="button secondary" href="database.php?table=<?= e(rawurlencode($selectedTable)) ?>&page=<?= $page - 1 ?><?= $searchQueryString ?>">上一頁</a>
                            <?php endif; ?>
                            <span class="helper-text">第 <?= e((string) $page) ?> / <?= e((string) $totalPages) ?> 頁</span>
                            <?php if ($page < $totalPages): ?>
                                <a class="button secondary" href="database.php?table=<?= e(rawurlencode($selectedTable)) ?>&page=<?= $page + 1 ?><?= $searchQueryString ?>">下一頁</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        </div>
    </main>
</body>
</html>
