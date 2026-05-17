<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/frontend.php';

$admin = require_admin('login.php');
$adminId = (int) $admin['user_id'];
$errors = [];
$flash = flash_message();
$selectedMonth = trim((string) ($_GET['month'] ?? date('Y-m')));

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

function report_period(string $selectedMonth): array
{
    $date = DateTimeImmutable::createFromFormat('Y-m-d', $selectedMonth . '-01');
    if (!$date) {
        $date = new DateTimeImmutable('first day of this month');
    }

    return [
        $date->format('Y-m'),
        $date->format('Y-m-01'),
        $date->format('Y-m-t'),
    ];
}

function load_report(string $startDate, string $endDate): array
{
    $pdo = db();

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS paid_order_count,
                COALESCE(SUM(total_amount), 0) AS total_revenue
         FROM orders
         WHERE payment_status = "paid"
           AND DATE(paid_at) BETWEEN ? AND ?'
    );
    $stmt->execute([$startDate, $endDate]);
    $orderSummary = $stmt->fetch() ?: ['paid_order_count' => 0, 'total_revenue' => 0];

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS total_lantern_count,
                SUM(CASE WHEN oi.position_id IS NOT NULL THEN 1 ELSE 0 END) AS assigned_count
         FROM order_items oi
         INNER JOIN orders o ON o.order_id = oi.order_id
         WHERE o.payment_status = "paid"
           AND DATE(o.paid_at) BETWEEN ? AND ?
           AND oi.item_status <> "cancelled"'
    );
    $stmt->execute([$startDate, $endDate]);
    $itemSummary = $stmt->fetch() ?: ['total_lantern_count' => 0, 'assigned_count' => 0];

    $stmt = $pdo->prepare(
        'SELECT oi.lantern_name_snapshot AS lantern_name,
                COUNT(*) AS lantern_count,
                COALESCE(SUM(oi.price_snapshot), 0) AS lantern_revenue
         FROM order_items oi
         INNER JOIN orders o ON o.order_id = oi.order_id
         WHERE o.payment_status = "paid"
           AND DATE(o.paid_at) BETWEEN ? AND ?
           AND oi.item_status <> "cancelled"
         GROUP BY oi.lantern_name_snapshot
         ORDER BY lantern_count DESC, lantern_name ASC'
    );
    $stmt->execute([$startDate, $endDate]);
    $countByTypeRows = $stmt->fetchAll();

    return [
        'paid_order_count' => (int) ($orderSummary['paid_order_count'] ?? 0),
        'total_revenue' => (float) ($orderSummary['total_revenue'] ?? 0),
        'total_lantern_count' => (int) ($itemSummary['total_lantern_count'] ?? 0),
        'assigned_count' => (int) ($itemSummary['assigned_count'] ?? 0),
        'count_by_type_rows' => $countByTypeRows,
    ];
}

[$selectedMonth, $periodStart, $periodEnd] = report_period($selectedMonth);
$report = load_report($periodStart, $periodEnd);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors[] = '表單驗證失敗，請重新送出。';
    }

    $postedMonth = trim((string) ($_POST['month'] ?? $selectedMonth));
    [$postedMonth, $periodStart, $periodEnd] = report_period($postedMonth);
    $report = load_report($periodStart, $periodEnd);

    if ($errors === []) {
        $countByType = [];
        foreach ($report['count_by_type_rows'] as $row) {
            $countByType[(string) $row['lantern_name']] = (int) $row['lantern_count'];
        }

        $stmt = db()->prepare(
            'INSERT INTO statistics (
                generated_by,
                period_start,
                period_end,
                total_revenue,
                total_lantern_count,
                count_by_type_json
             ) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $adminId,
            $periodStart,
            $periodEnd,
            $report['total_revenue'],
            $report['total_lantern_count'],
            json_encode($countByType, JSON_UNESCAPED_UNICODE),
        ]);

        audit_log($adminId, 'admin_statistics_generate', 'statistics', (string) db()->lastInsertId(), null, [
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'total_revenue' => $report['total_revenue'],
            'total_lantern_count' => $report['total_lantern_count'],
        ]);

        set_flash('統計快照已建立。');
        redirect('reports.php?month=' . urlencode($postedMonth));
    }
}

$snapshots = db()->query(
    'SELECT s.*, u.name AS generated_by_name
     FROM statistics s
     LEFT JOIN users u ON u.user_id = s.generated_by
     ORDER BY generated_at DESC, statistic_id DESC
     LIMIT 10'
)->fetchAll();
?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>統計報表</title>
    <link rel="stylesheet" href="../assets/css/main.css">
</head>
<body>
    <header class="site-header">
        <h1>後台管理</h1>
        <?= admin_nav() ?>
    </header>

    <main class="page">
        <h1>統計報表</h1>

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
            <form class="filter-form" method="get" action="reports.php">
                <input type="month" name="month" value="<?= e($selectedMonth) ?>">
                <button class="button" type="submit">查詢月份</button>
            </form>
        </section>

        <section class="card-grid section-gap">
            <article class="feature-card">
                <h2>已付款訂單</h2>
                <p><?= e((string) $report['paid_order_count']) ?> 筆</p>
            </article>
            <article class="feature-card">
                <h2>營收</h2>
                <p>NT$ <?= e(number_format((float) $report['total_revenue'])) ?></p>
            </article>
            <article class="feature-card">
                <h2>點燈數</h2>
                <p><?= e((string) $report['total_lantern_count']) ?> 盞</p>
            </article>
            <article class="feature-card">
                <h2>已配燈位</h2>
                <p><?= e((string) $report['assigned_count']) ?> 盞</p>
            </article>
        </section>

        <section class="split-grid section-gap">
            <section class="panel">
                <div class="section-heading">
                    <div>
                        <h2>燈種統計</h2>
                        <p class="helper-text"><?= e($periodStart) ?> 到 <?= e($periodEnd) ?></p>
                    </div>
                </div>

                <div class="table-wrap">
                    <table class="data-table compact-table">
                        <thead>
                            <tr>
                                <th>燈種</th>
                                <th>數量</th>
                                <th>金額</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($report['count_by_type_rows'] as $row): ?>
                                <tr>
                                    <td><?= e($row['lantern_name']) ?></td>
                                    <td><?= e((string) $row['lantern_count']) ?></td>
                                    <td>NT$ <?= e(number_format((float) $row['lantern_revenue'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($report['count_by_type_rows'] === []): ?>
                                <tr>
                                    <td colspan="3">這個月份尚無付款資料。</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="panel">
                <div class="section-heading">
                    <div>
                        <h2>儲存統計快照</h2>
                        <p class="helper-text">將目前查詢結果寫入統計資料表，方便驗收與留存。</p>
                    </div>
                </div>

                <form class="compact-form" method="post" action="reports.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="month" value="<?= e($selectedMonth) ?>">
                    <button class="button" type="submit">建立本月快照</button>
                </form>
            </section>
        </section>

        <section class="panel section-gap">
            <div class="section-heading">
                <div>
                    <h2>最近統計快照</h2>
                </div>
            </div>

            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>期間</th>
                            <th>營收</th>
                            <th>點燈數</th>
                            <th>建立者</th>
                            <th>建立時間</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($snapshots as $snapshot): ?>
                            <tr>
                                <td><?= e($snapshot['period_start']) ?> 到 <?= e($snapshot['period_end']) ?></td>
                                <td>NT$ <?= e(number_format((float) $snapshot['total_revenue'])) ?></td>
                                <td><?= e((string) $snapshot['total_lantern_count']) ?></td>
                                <td><?= e((string) ($snapshot['generated_by_name'] ?? '系統')) ?></td>
                                <td><?= e($snapshot['generated_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</body>
</html>
