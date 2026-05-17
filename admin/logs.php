<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/frontend.php';

require_admin('login.php');

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

function decode_log_json(?string $json): string
{
    if ($json === null || $json === '') {
        return '-';
    }

    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return $json;
    }

    unset($decoded['password_hash']);

    return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

$group = trim((string) ($_GET['group'] ?? 'all'));
$q = trim((string) ($_GET['q'] ?? ''));
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));

$groups = [
    'all' => ['全部紀錄', '1 = 1'],
    'security' => ['安全紀錄', "al.action IN ('member_login', 'member_oauth_login', 'member_oauth_register', 'member_oauth_link', 'member_phone_login_code_request', 'member_phone_login', 'admin_login', 'admin_login_denied', 'member_password_change', 'admin_password_change')"],
    'login' => ['登入紀錄', "al.action IN ('member_login', 'member_oauth_login', 'member_phone_login', 'admin_login', 'admin_login_denied')"],
    'password' => ['更改密碼紀錄', "al.action IN ('member_password_change', 'admin_password_change')"],
    'admin' => ['管理員操作', "al.action LIKE 'admin_%'"],
    'member' => ['一般會員操作', "al.action NOT LIKE 'admin_%'"],
];

if (!isset($groups[$group])) {
    $group = 'all';
}

$conditions = [$groups[$group][1]];
$params = [];

if ($q !== '') {
    $conditions[] = '(al.action LIKE ? OR al.table_name LIKE ? OR al.record_id LIKE ? OR al.ip_address LIKE ? OR u.email LIKE ? OR u.name LIKE ?)';
    $keyword = '%' . $q . '%';
    array_push($params, $keyword, $keyword, $keyword, $keyword, $keyword, $keyword);
}

if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) === 1) {
    $conditions[] = 'al.created_at >= ?';
    $params[] = $dateFrom . ' 00:00:00';
}

if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) === 1) {
    $conditions[] = 'al.created_at <= ?';
    $params[] = $dateTo . ' 23:59:59';
}

$where = implode(' AND ', $conditions);

$stmt = db()->prepare(
    "SELECT al.*, u.name AS actor_name, u.email AS actor_email
     FROM audit_logs al
     LEFT JOIN users u ON u.user_id = al.user_id
     WHERE $where
     ORDER BY al.created_at DESC, al.log_id DESC
     LIMIT 200"
);
$stmt->execute($params);
$logs = $stmt->fetchAll();
?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>操作軌跡紀錄</title>
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
                <h1>操作軌跡紀錄</h1>
                <p class="helper-text">查詢登入時間/IP、密碼變更時間/IP，以及管理員操作紀錄。</p>
            </div>
        </div>

        <section class="panel">
            <form class="filter-form log-filter-form" method="get" action="logs.php">
                <input type="text" name="q" value="<?= e($q) ?>" placeholder="搜尋操作者、動作、IP、資料表">
                <select name="group">
                    <?php foreach ($groups as $key => [$label]): ?>
                        <option value="<?= e($key) ?>" <?= $group === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="date" name="date_from" value="<?= e($dateFrom) ?>">
                <input type="date" name="date_to" value="<?= e($dateTo) ?>">
                <button class="button secondary" type="submit">查詢</button>
            </form>
        </section>

        <section class="panel section-gap">
            <div class="section-heading">
                <h2><?= e($groups[$group][0]) ?></h2>
                <span class="helper-text">最多顯示 200 筆</span>
            </div>

            <?php if ($logs === []): ?>
                <p class="helper-text">目前沒有符合條件的紀錄。</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table log-table">
                        <thead>
                            <tr>
                                <th>時間</th>
                                <th>操作</th>
                                <th>操作者</th>
                                <th>資料表/ID</th>
                                <th>IP</th>
                                <th>異動內容</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td><?= e($log['created_at']) ?></td>
                                    <td>
                                        <strong><?= e(audit_action_label($log['action'])) ?></strong>
                                        <p class="helper-text"><?= e($log['action']) ?></p>
                                    </td>
                                    <td>
                                        <?= e($log['actor_name'] ?? '系統/未知') ?>
                                        <p class="helper-text"><?= e($log['actor_email'] ?? '-') ?></p>
                                    </td>
                                    <td><?= e(($log['table_name'] ?? '-') . ' / ' . ($log['record_id'] ?? '-')) ?></td>
                                    <td><?= e($log['ip_address'] ?? '-') ?></td>
                                    <td>
                                        <details>
                                            <summary>查看</summary>
                                            <div class="log-detail-grid">
                                                <div>
                                                    <strong>異動前</strong>
                                                    <pre><?= e(decode_log_json($log['before_data'])) ?></pre>
                                                </div>
                                                <div>
                                                    <strong>異動後</strong>
                                                    <pre><?= e(decode_log_json($log['after_data'])) ?></pre>
                                                </div>
                                            </div>
                                        </details>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
