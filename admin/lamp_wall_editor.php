<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/frontend.php';

$admin = require_admin('login.php');
$adminId = (int) $admin['user_id'];
$errors = [];
$flash = flash_message();
$typeId = (int) ($_GET['type_id'] ?? ($_POST['type_id'] ?? 0));
$area = trim((string) ($_GET['area'] ?? ($_POST['area'] ?? '')));

function admin_nav(): string
{
    $links = [
        ['dashboard.php', '儀表板'],
        ['database.php', '資料庫'],
        ['lantern_types.php', '燈種'],
        ['lamp_positions.php', '燈位'],
        ['lamp_wall_editor.php', '燈牆編輯'],
        ['orders.php', '訂單'],
        ['service_periods.php', '服務年度'],
        ['annual_flow_rules.php', '流年規則'],
        ['reports.php', '報表'],
        ['scheduled_jobs.php', '排程'],
        ['notifications.php', '通知中心'],
        ['users.php', '使用者'],
        ['articles.php', '公告'],
        ['feedbacks.php', '回饋'],
        ['logs.php', '操作紀錄'],
        ['logout.php', '登出'],
    ];

    $html = '<nav>';
    foreach ($links as [$href, $label]) {
        $html .= '<a href="' . e($href) . '">' . e($label) . '</a>';
    }
    $html .= '</nav>';

    return $html;
}

$lanternTypes = db()->query(
    'SELECT type_id, name
     FROM lantern_types
     WHERE is_active = 1
     ORDER BY sort_order, type_id'
)->fetchAll();

if ($typeId <= 0 && $lanternTypes !== []) {
    $typeId = (int) $lanternTypes[0]['type_id'];
}

$areasStmt = db()->prepare(
    'SELECT DISTINCT area
     FROM lamp_positions
     WHERE type_id = ?
     ORDER BY area'
);
$areasStmt->execute([$typeId]);
$areas = array_values(array_filter(array_map(static function (array $row): string {
    return (string) $row['area'];
}, $areasStmt->fetchAll()), static fn(string $value): bool => $value !== ''));

if ($area === '' && $areas !== []) {
    $area = $areas[0];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors[] = '表單驗證失敗，請重新送出。';
    }

    $positionId = (int) ($_POST['position_id'] ?? 0);
    $rowNo = max(1, (int) ($_POST['row_no'] ?? 1));
    $colNo = max(1, (int) ($_POST['col_no'] ?? 1));
    $nextStatus = trim((string) ($_POST['status'] ?? 'available'));
    $nextArea = trim((string) ($_POST['next_area'] ?? $area));
    $note = trim((string) ($_POST['note'] ?? ''));

    if ($positionId <= 0) {
        $errors[] = '燈位編號不正確。';
    }

    if (!in_array($nextStatus, ['available', 'occupied', 'maintenance', 'retired'], true)) {
        $errors[] = '燈位狀態不正確。';
    }

    if ($nextArea === '') {
        $errors[] = '請填寫區域。';
    }

    if ($errors === []) {
        $pdo = db();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare('SELECT * FROM lamp_positions WHERE position_id = ? LIMIT 1 FOR UPDATE');
            $stmt->execute([$positionId]);
            $before = $stmt->fetch();

            if (!$before) {
                throw new RuntimeException('找不到燈位資料。');
            }

            if ($before['status'] === 'occupied' && $nextStatus !== 'occupied') {
                $stmt = $pdo->prepare(
                    'SELECT COUNT(*)
                     FROM order_items
                     WHERE position_id = ?
                       AND item_status IN ("assigned", "completed")'
                );
                $stmt->execute([$positionId]);
                if ((int) $stmt->fetchColumn() > 0) {
                    throw new RuntimeException('此燈位已有已分配訂單，不能直接改成可用或維護。');
                }
            }

            $stmt = $pdo->prepare(
                'UPDATE lamp_positions
                 SET area = ?,
                     row_no = ?,
                     col_no = ?,
                     status = ?,
                     note = ?,
                     occupied_until = CASE WHEN ? IN ("available", "retired") THEN NULL ELSE occupied_until END
                 WHERE position_id = ?'
            );
            $stmt->execute([$nextArea, $rowNo, $colNo, $nextStatus, $note === '' ? null : $note, $nextStatus, $positionId]);

            $stmt = $pdo->prepare('SELECT * FROM lamp_positions WHERE position_id = ? LIMIT 1');
            $stmt->execute([$positionId]);
            $after = $stmt->fetch();

            audit_log($adminId, 'admin_lamp_wall_visual_update', 'lamp_positions', (string) $positionId, $before, $after ?: null);
            $pdo->commit();

            set_flash('燈牆位置已更新。');
            redirect('lamp_wall_editor.php?type_id=' . (int) $before['type_id'] . '&area=' . urlencode($nextArea));
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $throwable->getMessage();
        }
    }
}

$stmt = db()->prepare(
    'SELECT lp.*, lt.name AS type_name
     FROM lamp_positions lp
     INNER JOIN lantern_types lt ON lt.type_id = lp.type_id
     WHERE lp.type_id = ?
       AND lp.area = ?
     ORDER BY lp.row_no, lp.col_no, lp.position_id'
);
$stmt->execute([$typeId, $area]);
$positions = $stmt->fetchAll();

$maxRow = 1;
$maxCol = 1;
$positionMap = [];
foreach ($positions as $position) {
    $rowNo = max(1, (int) $position['row_no']);
    $colNo = max(1, (int) $position['col_no']);
    $maxRow = max($maxRow, $rowNo);
    $maxCol = max($maxCol, $colNo);
    $positionMap[$rowNo . '-' . $colNo] = $position;
}

$maxRow = min(max($maxRow, 6), 40);
$maxCol = min(max($maxCol, 8), 40);
?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>燈牆視覺化編輯</title>
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
                <h1>燈牆視覺化編輯</h1>
                <p class="helper-text">以格狀牆面查看燈位，直接調整列、欄、區域、狀態與備註。</p>
            </div>
            <a class="button secondary" href="lamp_positions.php">回燈位清單</a>
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

        <section class="panel">
            <form class="filter-form" method="get" action="lamp_wall_editor.php">
                <select name="type_id">
                    <?php foreach ($lanternTypes as $type): ?>
                        <option value="<?= (int) $type['type_id'] ?>" <?= $typeId === (int) $type['type_id'] ? 'selected' : '' ?>>
                            <?= e($type['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select name="area">
                    <?php foreach ($areas as $areaOption): ?>
                        <option value="<?= e($areaOption) ?>" <?= $area === $areaOption ? 'selected' : '' ?>>
                            <?= e($areaOption) ?>
                        </option>
                    <?php endforeach; ?>
                    <?php if ($areas === []): ?>
                        <option value="">尚無區域</option>
                    <?php endif; ?>
                </select>
                <button class="button" type="submit">切換燈牆</button>
            </form>
        </section>

        <section class="panel section-gap">
            <div class="lamp-wall-editor" style="--wall-cols: <?= $maxCol ?>;">
                <?php for ($row = 1; $row <= $maxRow; $row++): ?>
                    <?php for ($col = 1; $col <= $maxCol; $col++): ?>
                        <?php $position = $positionMap[$row . '-' . $col] ?? null; ?>
                        <?php if ($position): ?>
                            <article class="wall-slot wall-slot-<?= e((string) $position['status']) ?>">
                                <strong><?= e($position['position_code']) ?></strong>
                                <span><?= e(lamp_position_status_label($position['status'])) ?></span>
                                <small>R<?= $row ?> C<?= $col ?></small>
                                <form method="post" action="lamp_wall_editor.php?type_id=<?= $typeId ?>&area=<?= urlencode($area) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="position_id" value="<?= (int) $position['position_id'] ?>">
                                    <input type="hidden" name="type_id" value="<?= $typeId ?>">
                                    <label>
                                        列
                                        <input type="number" name="row_no" min="1" max="40" value="<?= $row ?>">
                                    </label>
                                    <label>
                                        欄
                                        <input type="number" name="col_no" min="1" max="40" value="<?= $col ?>">
                                    </label>
                                    <label>
                                        區域
                                        <input type="text" name="next_area" maxlength="50" value="<?= e($position['area']) ?>">
                                    </label>
                                    <label>
                                        狀態
                                        <select name="status">
                                            <?php foreach (['available', 'occupied', 'maintenance', 'retired'] as $status): ?>
                                                <option value="<?= e($status) ?>" <?= $position['status'] === $status ? 'selected' : '' ?>>
                                                    <?= e(lamp_position_status_label($status)) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <label>
                                        備註
                                        <input type="text" name="note" maxlength="255" value="<?= e((string) ($position['note'] ?? '')) ?>">
                                    </label>
                                    <button class="button secondary" type="submit">儲存</button>
                                </form>
                            </article>
                        <?php else: ?>
                            <div class="wall-slot wall-slot-empty">
                                <span>空格</span>
                                <small>R<?= $row ?> C<?= $col ?></small>
                            </div>
                        <?php endif; ?>
                    <?php endfor; ?>
                <?php endfor; ?>
            </div>
        </section>
    </main>
</body>
</html>
