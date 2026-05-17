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

function clean_post(string $key): string
{
    return trim((string) ($_POST[$key] ?? ''));
}

function nullable_post(string $key): ?string
{
    $value = clean_post($key);
    return $value === '' ? null : $value;
}

function nullable_int_post(string $key): ?int
{
    $value = clean_post($key);
    if ($value === '') {
        return null;
    }

    return filter_var($value, FILTER_VALIDATE_INT) === false ? null : (int) $value;
}

function valid_date_or_null(?string $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }

    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
}

function lamp_position_form_defaults(): array
{
    return [
        'position_id' => '',
        'type_id' => '',
        'position_code' => '',
        'area' => '',
        'row_no' => '',
        'col_no' => '',
        'status' => 'available',
        'occupied_until' => '',
        'note' => '',
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors[] = '表單已過期，請重新送出。';
    }

    $action = clean_post('action');

    if ($errors === [] && $action === 'save_lamp_position') {
        $positionId = (int) ($_POST['position_id'] ?? 0);
        $typeId = (int) ($_POST['type_id'] ?? 0);
        $positionCode = strtoupper(clean_post('position_code'));
        $status = clean_post('status');
        $rowNo = nullable_int_post('row_no');
        $colNo = nullable_int_post('col_no');
        $occupiedUntil = valid_date_or_null(nullable_post('occupied_until'));

        if ($typeId <= 0) {
            $errors[] = '請選擇燈種。';
        }

        if ($positionCode === '' || preg_match('/^[A-Z0-9-]{2,50}$/', $positionCode) !== 1) {
            $errors[] = '燈位代碼請使用 2-50 個英文、數字或連字號。';
        }

        if (!in_array($status, ['available', 'occupied', 'maintenance'], true)) {
            $errors[] = '燈位狀態不正確。';
        }

        if (clean_post('row_no') !== '' && $rowNo === null) {
            $errors[] = '列號必須是整數。';
        }

        if (clean_post('col_no') !== '' && $colNo === null) {
            $errors[] = '欄號必須是整數。';
        }

        if (nullable_post('occupied_until') !== null && $occupiedUntil === null) {
            $errors[] = '佔用到期日格式不正確。';
        }

        if ($status === 'available') {
            $occupiedUntil = null;
        }

        $stmt = db()->prepare('SELECT type_id FROM lantern_types WHERE type_id = ? LIMIT 1');
        $stmt->execute([$typeId]);
        if (!$stmt->fetch()) {
            $errors[] = '選擇的燈種不存在。';
        }

        if ($positionId > 0 && $status !== 'occupied') {
            $stmt = db()->prepare(
                'SELECT COUNT(*) FROM order_items
                 WHERE position_id = ? AND item_status IN ("assigned", "completed")'
            );
            $stmt->execute([$positionId]);
            if ((int) $stmt->fetchColumn() > 0) {
                $errors[] = '此燈位已有點燈紀錄，不能改成空位或維修。';
            }
        }

        $payload = [
            'type_id' => $typeId,
            'position_code' => $positionCode,
            'area' => nullable_post('area'),
            'row_no' => $rowNo,
            'col_no' => $colNo,
            'status' => $status,
            'occupied_until' => $occupiedUntil,
            'note' => nullable_post('note'),
        ];

        if ($errors === []) {
            try {
                if ($positionId > 0) {
                    $stmt = db()->prepare('SELECT * FROM lamp_positions WHERE position_id = ? LIMIT 1');
                    $stmt->execute([$positionId]);
                    $before = $stmt->fetch();

                    if (!$before) {
                        $errors[] = '找不到要編輯的燈位。';
                    } else {
                        $stmt = db()->prepare(
                            'UPDATE lamp_positions
                             SET type_id = ?, position_code = ?, area = ?, row_no = ?, col_no = ?, status = ?, occupied_until = ?, note = ?
                             WHERE position_id = ?'
                        );
                        $stmt->execute([
                            $payload['type_id'],
                            $payload['position_code'],
                            $payload['area'],
                            $payload['row_no'],
                            $payload['col_no'],
                            $payload['status'],
                            $payload['occupied_until'],
                            $payload['note'],
                            $positionId,
                        ]);

                        audit_log($adminId, 'admin_lamp_position_update', 'lamp_positions', (string) $positionId, $before, $payload);
                        set_flash('燈位已更新。');
                        redirect('lamp_positions.php');
                    }
                } else {
                    $stmt = db()->prepare(
                        'INSERT INTO lamp_positions (type_id, position_code, area, row_no, col_no, status, occupied_until, note)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    $stmt->execute([
                        $payload['type_id'],
                        $payload['position_code'],
                        $payload['area'],
                        $payload['row_no'],
                        $payload['col_no'],
                        $payload['status'],
                        $payload['occupied_until'],
                        $payload['note'],
                    ]);

                    $newPositionId = (int) db()->lastInsertId();
                    audit_log($adminId, 'admin_lamp_position_create', 'lamp_positions', (string) $newPositionId, null, $payload);
                    set_flash('燈位已新增。');
                    redirect('lamp_positions.php');
                }
            } catch (PDOException $exception) {
                if ($exception->getCode() === '23000') {
                    $errors[] = '燈位代碼已存在，請換一個代碼。';
                } else {
                    $errors[] = '儲存燈位失敗，請稍後再試。';
                }
            }
        }
    }

    if ($errors === [] && $action === 'delete_lamp_position') {
        $positionId = (int) ($_POST['position_id'] ?? 0);

        $stmt = db()->prepare('SELECT * FROM lamp_positions WHERE position_id = ? LIMIT 1');
        $stmt->execute([$positionId]);
        $before = $stmt->fetch();

        if (!$before) {
            $errors[] = '找不到要刪除的燈位。';
        } else {
            $stmt = db()->prepare('SELECT COUNT(*) FROM order_items WHERE position_id = ?');
            $stmt->execute([$positionId]);
            if ((int) $stmt->fetchColumn() > 0) {
                $errors[] = '此燈位已有訂單明細紀錄，為了保留點燈軌跡不能刪除。';
            } else {
                $stmt = db()->prepare('DELETE FROM lamp_positions WHERE position_id = ?');
                $stmt->execute([$positionId]);

                audit_log($adminId, 'admin_lamp_position_delete', 'lamp_positions', (string) $positionId, $before, null);
                set_flash('燈位已刪除。');
                redirect('lamp_positions.php');
            }
        }
    }
}

$stmt = db()->query('SELECT type_id, name, is_active FROM lantern_types ORDER BY sort_order, type_id');
$lanternTypes = $stmt->fetchAll();

$editPosition = null;
$editId = (int) ($_GET['edit_id'] ?? 0);
if ($editId > 0) {
    $stmt = db()->prepare('SELECT * FROM lamp_positions WHERE position_id = ? LIMIT 1');
    $stmt->execute([$editId]);
    $editPosition = $stmt->fetch() ?: null;
}

$form = $editPosition ?: lamp_position_form_defaults();

$stmt = db()->query(
    'SELECT lp.*, lt.name AS lantern_name,
            COUNT(oi.detail_id) AS order_item_count,
            SUM(CASE WHEN oi.item_status IN ("assigned", "completed") THEN 1 ELSE 0 END) AS active_item_count
     FROM lamp_positions lp
     INNER JOIN lantern_types lt ON lt.type_id = lp.type_id
     LEFT JOIN order_items oi ON oi.position_id = lp.position_id
     GROUP BY lp.position_id, lp.type_id, lp.position_code, lp.area, lp.row_no, lp.col_no, lp.status,
              lp.occupied_until, lp.note, lp.created_at, lp.updated_at, lt.name
     ORDER BY lt.sort_order, lp.area, lp.row_no, lp.col_no, lp.position_code'
);
$positions = $stmt->fetchAll();
?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>實體燈位管理</title>
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
                <h1>實體燈位管理</h1>
                <p class="helper-text">維護燈牆實體座標、庫存狀態、維修狀態與佔用到期日。</p>
            </div>
            <?php if ($editPosition): ?>
                <a class="button secondary" href="lamp_positions.php">取消編輯</a>
            <?php endif; ?>
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
            <h2><?= $editPosition ? '編輯燈位' : '新增燈位' ?></h2>
            <form class="form grid-form" method="post" action="lamp_positions.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_lamp_position">
                <input type="hidden" name="position_id" value="<?= e((string) ($form['position_id'] ?? '')) ?>">

                <label>
                    所屬燈種
                    <select name="type_id" required>
                        <option value="">請選擇</option>
                        <?php foreach ($lanternTypes as $type): ?>
                            <option value="<?= (int) $type['type_id'] ?>" <?= (int) ($form['type_id'] ?: 0) === (int) $type['type_id'] ? 'selected' : '' ?>>
                                <?= e($type['name'] . ((int) $type['is_active'] === 1 ? '' : '（停用）')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    燈位代碼
                    <input type="text" name="position_code" value="<?= e((string) $form['position_code']) ?>" placeholder="例如：GM-A-01" required>
                </label>

                <label>
                    區域
                    <input type="text" name="area" value="<?= e((string) ($form['area'] ?? '')) ?>" placeholder="例如：A">
                </label>

                <label>
                    狀態
                    <select name="status" required>
                        <?php foreach (['available', 'occupied', 'maintenance'] as $status): ?>
                            <option value="<?= e($status) ?>" <?= $form['status'] === $status ? 'selected' : '' ?>>
                                <?= e(lamp_position_status_label($status)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    列號
                    <input type="number" name="row_no" value="<?= e((string) ($form['row_no'] ?? '')) ?>">
                </label>

                <label>
                    欄號
                    <input type="number" name="col_no" value="<?= e((string) ($form['col_no'] ?? '')) ?>">
                </label>

                <label>
                    佔用到期日
                    <input type="date" name="occupied_until" value="<?= e((string) ($form['occupied_until'] ?? '')) ?>">
                </label>

                <label>
                    備註
                    <input type="text" name="note" value="<?= e((string) ($form['note'] ?? '')) ?>">
                </label>

                <div class="full-span">
                    <button class="button" type="submit"><?= $editPosition ? '儲存燈位' : '新增燈位' ?></button>
                </div>
            </form>
        </section>

        <section class="panel section-gap">
            <div class="section-heading">
                <h2>燈位列表</h2>
                <a href="lantern_types.php">管理燈種</a>
            </div>

            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>燈位代碼</th>
                            <th>燈種</th>
                            <th>座標</th>
                            <th>狀態</th>
                            <th>到期日</th>
                            <th>使用紀錄</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($positions as $position): ?>
                            <tr>
                                <td><strong><?= e($position['position_code']) ?></strong></td>
                                <td><?= e($position['lantern_name']) ?></td>
                                <td>
                                    <?= e(($position['area'] ?: '-') . ' / R' . ($position['row_no'] ?? '-') . ' C' . ($position['col_no'] ?? '-')) ?>
                                </td>
                                <td><?= e(lamp_position_status_label($position['status'])) ?></td>
                                <td><?= e($position['occupied_until'] ?? '-') ?></td>
                                <td>
                                    <div class="status-row">
                                        <span>歷史 <?= (int) $position['order_item_count'] ?></span>
                                        <span>有效 <?= (int) $position['active_item_count'] ?></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="table-actions">
                                        <a href="lamp_positions.php?edit_id=<?= (int) $position['position_id'] ?>">編輯</a>
                                        <form method="post" action="lamp_positions.php">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete_lamp_position">
                                            <input type="hidden" name="position_id" value="<?= (int) $position['position_id'] ?>">
                                            <button class="link-button" type="submit">刪除</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</body>
</html>
