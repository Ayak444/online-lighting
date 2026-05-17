<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/frontend.php';

$admin = require_admin('login.php');
$adminId = (int) $admin['user_id'];
$errors = [];
$flash = flash_message();
$editPeriodId = (int) ($_GET['edit'] ?? 0);

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

function normalize_datetime_local(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    $value = str_replace('T', ' ', $value);
    $date = DateTimeImmutable::createFromFormat('Y-m-d H:i', $value);
    if (!$date) {
        return null;
    }

    return $date->format('Y-m-d H:i:s');
}

function is_valid_date(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);

    return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value;
}

function load_period(int $periodId): ?array
{
    $stmt = db()->prepare('SELECT * FROM lamp_service_periods WHERE period_id = ? LIMIT 1');
    $stmt->execute([$periodId]);
    $period = $stmt->fetch();

    return $period ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors[] = '表單驗證失敗，請重新送出。';
    }

    $periodId = (int) ($_POST['period_id'] ?? 0);
    $serviceYear = (int) ($_POST['service_year'] ?? 0);
    $registrationOpenAt = normalize_datetime_local($_POST['registration_open_at'] ?? null);
    $blessingStartDate = trim((string) ($_POST['blessing_start_date'] ?? ''));
    $blessingEndDate = trim((string) ($_POST['blessing_end_date'] ?? ''));
    $reminderDaysBefore = (int) ($_POST['reminder_days_before'] ?? 30);
    $status = trim((string) ($_POST['status'] ?? 'draft'));
    $notes = trim((string) ($_POST['notes'] ?? '')) ?: null;

    if ($serviceYear < 2000 || $serviceYear > 2100) {
        $errors[] = '年度格式不正確。';
    }

    if (!is_valid_date($blessingStartDate) || !is_valid_date($blessingEndDate)) {
        $errors[] = '開燈日或謝燈日格式不正確。';
    }

    if ($errors === [] && $blessingStartDate > $blessingEndDate) {
        $errors[] = '謝燈日不得早於開燈日。';
    }

    if ($reminderDaysBefore < 0 || $reminderDaysBefore > 365) {
        $errors[] = '提醒提前天數需介於 0 到 365 天。';
    }

    if (!in_array($status, ['draft', 'active', 'closed'], true)) {
        $errors[] = '燈期狀態不正確。';
    }

    if ($errors === []) {
        $pdo = db();
        $pdo->beginTransaction();

        try {
            $before = null;

            if ($periodId > 0) {
                $stmt = $pdo->prepare('SELECT * FROM lamp_service_periods WHERE period_id = ? LIMIT 1 FOR UPDATE');
                $stmt->execute([$periodId]);
                $before = $stmt->fetch();

                if (!$before) {
                    throw new RuntimeException('找不到要更新的年度燈期。');
                }
            }

            if ($status === 'active') {
                $stmt = $pdo->prepare(
                    'UPDATE lamp_service_periods
                     SET status = "closed"
                     WHERE status = "active" AND period_id <> ?'
                );
                $stmt->execute([$periodId]);
            }

            if ($periodId > 0) {
                $stmt = $pdo->prepare(
                    'UPDATE lamp_service_periods
                     SET service_year = ?,
                         registration_open_at = ?,
                         blessing_start_date = ?,
                         blessing_end_date = ?,
                         reminder_days_before = ?,
                         status = ?,
                         notes = ?
                     WHERE period_id = ?'
                );
                $stmt->execute([
                    $serviceYear,
                    $registrationOpenAt,
                    $blessingStartDate,
                    $blessingEndDate,
                    $reminderDaysBefore,
                    $status,
                    $notes,
                    $periodId,
                ]);
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO lamp_service_periods (
                        created_by,
                        service_year,
                        registration_open_at,
                        blessing_start_date,
                        blessing_end_date,
                        reminder_days_before,
                        status,
                        notes
                     ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $adminId,
                    $serviceYear,
                    $registrationOpenAt,
                    $blessingStartDate,
                    $blessingEndDate,
                    $reminderDaysBefore,
                    $status,
                    $notes,
                ]);
                $periodId = (int) $pdo->lastInsertId();
            }

            $after = load_period($periodId);
            audit_log($adminId, 'admin_service_period_save', 'lamp_service_periods', (string) $periodId, $before ?: null, $after);

            $pdo->commit();
            set_flash('年度燈期已儲存。');
            redirect('service_periods.php?edit=' . $periodId);
        } catch (Throwable $throwable) {
            $pdo->rollBack();
            $errors[] = $throwable instanceof RuntimeException ? $throwable->getMessage() : '年度燈期儲存失敗。';
        }
    }
}

$periods = db()->query(
    'SELECT lsp.*, u.name AS creator_name
     FROM lamp_service_periods lsp
     LEFT JOIN users u ON u.user_id = lsp.created_by
     ORDER BY service_year DESC, period_id DESC'
)->fetchAll();

$editPeriod = $editPeriodId > 0 ? load_period($editPeriodId) : null;
$defaultServiceYear = (int) date('Y');
$defaultPeriodDates = default_lamp_service_period_dates($defaultServiceYear);
$formPeriod = $editPeriod ?: [
    'period_id' => 0,
    'service_year' => $defaultServiceYear,
    'registration_open_at' => null,
    'blessing_start_date' => $defaultPeriodDates['blessing_start_date'],
    'blessing_end_date' => $defaultPeriodDates['blessing_end_date'],
    'reminder_days_before' => 30,
    'status' => 'draft',
    'notes' => '',
];
?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>年度燈期設定</title>
    <link rel="stylesheet" href="../assets/css/main.css">
</head>
<body>
    <header class="site-header">
        <h1>後台管理</h1>
        <?= admin_nav() ?>
    </header>

    <main class="page">
        <h1>年度燈期設定</h1>

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
            <div class="section-heading">
                <div>
                    <h2><?= (int) $formPeriod['period_id'] > 0 ? '編輯年度燈期' : '新增年度燈期' ?></h2>
                    <p class="helper-text">所有同年度點燈共用同一組開燈日與謝燈日，結帳與到期提醒都會套用這裡的設定。</p>
                </div>
            </div>

            <form class="compact-form grid-form" method="post" action="service_periods.php">
                <?= csrf_field() ?>
                <input type="hidden" name="period_id" value="<?= e((string) $formPeriod['period_id']) ?>">

                <label>
                    服務年度
                    <input type="number" name="service_year" min="2000" max="2100" value="<?= e((string) $formPeriod['service_year']) ?>" required>
                </label>

                <label>
                    報名開放時間
                    <input type="datetime-local" name="registration_open_at" value="<?= e($formPeriod['registration_open_at'] ? date('Y-m-d\TH:i', strtotime((string) $formPeriod['registration_open_at'])) : '') ?>">
                </label>

                <label>
                    開燈日
                    <input type="date" name="blessing_start_date" value="<?= e((string) $formPeriod['blessing_start_date']) ?>" required>
                </label>

                <label>
                    謝燈日
                    <input type="date" name="blessing_end_date" value="<?= e((string) $formPeriod['blessing_end_date']) ?>" required>
                </label>

                <label>
                    提前提醒天數
                    <input type="number" name="reminder_days_before" min="0" max="365" value="<?= e((string) $formPeriod['reminder_days_before']) ?>" required>
                </label>

                <label>
                    狀態
                    <select name="status" required>
                        <?php foreach (['draft', 'active', 'closed'] as $status): ?>
                            <option value="<?= e($status) ?>" <?= $formPeriod['status'] === $status ? 'selected' : '' ?>>
                                <?= e(lamp_service_period_status_label($status)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="full-span">
                    備註
                    <textarea name="notes" rows="3"><?= e((string) ($formPeriod['notes'] ?? '')) ?></textarea>
                </label>

                <div class="full-span admin-actions">
                    <button class="button" type="submit">儲存年度燈期</button>
                    <?php if ((int) $formPeriod['period_id'] > 0): ?>
                        <a class="button secondary" href="service_periods.php">新增另一年度</a>
                    <?php endif; ?>
                </div>
            </form>
        </section>

        <section class="panel section-gap">
            <div class="section-heading">
                <div>
                    <h2>燈期列表</h2>
                    <p class="helper-text">同一時間只會有一筆啟用中的年度燈期。</p>
                </div>
            </div>

            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>年度</th>
                            <th>狀態</th>
                            <th>報名開放</th>
                            <th>開燈日</th>
                            <th>謝燈日</th>
                            <th>提醒天數</th>
                            <th>建立者</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($periods as $period): ?>
                            <tr>
                                <td><?= e((string) $period['service_year']) ?></td>
                                <td><?= e(lamp_service_period_status_label($period['status'])) ?></td>
                                <td><?= e((string) ($period['registration_open_at'] ?? '未設定')) ?></td>
                                <td><?= e($period['blessing_start_date']) ?></td>
                                <td><?= e($period['blessing_end_date']) ?></td>
                                <td><?= e((string) $period['reminder_days_before']) ?> 天</td>
                                <td><?= e((string) ($period['creator_name'] ?? '系統')) ?></td>
                                <td><a href="service_periods.php?edit=<?= e((string) $period['period_id']) ?>">編輯</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</body>
</html>
