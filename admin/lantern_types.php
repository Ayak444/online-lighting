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

function lantern_type_form_defaults(): array
{
    return [
        'type_id' => '',
        'slug' => '',
        'name' => '',
        'description' => '',
        'blessing_description' => '',
        'recommended_for' => '',
        'not_recommended_for' => '',
        'price' => '0',
        'image_path' => '',
        'is_active' => '1',
        'sort_order' => '0',
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors[] = '表單已過期，請重新送出。';
    }

    $action = clean_post('action');

    if ($errors === [] && $action === 'save_lantern_type') {
        $typeId = (int) ($_POST['type_id'] ?? 0);
        $slug = strtolower(clean_post('slug'));
        $name = clean_post('name');
        $priceInput = clean_post('price');
        $sortInput = clean_post('sort_order');

        if ($slug === '' || preg_match('/^[a-z0-9-]{2,80}$/', $slug) !== 1) {
            $errors[] = '代碼請使用 2-80 個小寫英文、數字或連字號。';
        }

        if ($name === '') {
            $errors[] = '請輸入燈種名稱。';
        }

        if (!is_numeric($priceInput) || (float) $priceInput < 0) {
            $errors[] = '價格必須是 0 以上的數字。';
        }

        if ($sortInput === '' || filter_var($sortInput, FILTER_VALIDATE_INT) === false) {
            $errors[] = '排序必須是整數。';
        }

        $payload = [
            'slug' => $slug,
            'name' => $name,
            'description' => nullable_post('description'),
            'blessing_description' => nullable_post('blessing_description'),
            'recommended_for' => nullable_post('recommended_for'),
            'not_recommended_for' => nullable_post('not_recommended_for'),
            'price' => (float) $priceInput,
            'image_path' => nullable_post('image_path'),
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
            'sort_order' => (int) $sortInput,
        ];

        if ($errors === []) {
            try {
                if ($typeId > 0) {
                    $stmt = db()->prepare('SELECT * FROM lantern_types WHERE type_id = ? LIMIT 1');
                    $stmt->execute([$typeId]);
                    $before = $stmt->fetch();

                    if (!$before) {
                        $errors[] = '找不到要編輯的燈種。';
                    } else {
                        $stmt = db()->prepare(
                            'UPDATE lantern_types
                             SET slug = ?, name = ?, description = ?, blessing_description = ?, recommended_for = ?,
                                 not_recommended_for = ?, price = ?, image_path = ?, is_active = ?, sort_order = ?
                             WHERE type_id = ?'
                        );
                        $stmt->execute([
                            $payload['slug'],
                            $payload['name'],
                            $payload['description'],
                            $payload['blessing_description'],
                            $payload['recommended_for'],
                            $payload['not_recommended_for'],
                            $payload['price'],
                            $payload['image_path'],
                            $payload['is_active'],
                            $payload['sort_order'],
                            $typeId,
                        ]);

                        audit_log($adminId, 'admin_lantern_type_update', 'lantern_types', (string) $typeId, $before, $payload);
                        set_flash('燈種已更新。');
                        redirect('lantern_types.php');
                    }
                } else {
                    $stmt = db()->prepare(
                        'INSERT INTO lantern_types
                            (slug, name, description, blessing_description, recommended_for, not_recommended_for, price, image_path, is_active, sort_order)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    $stmt->execute([
                        $payload['slug'],
                        $payload['name'],
                        $payload['description'],
                        $payload['blessing_description'],
                        $payload['recommended_for'],
                        $payload['not_recommended_for'],
                        $payload['price'],
                        $payload['image_path'],
                        $payload['is_active'],
                        $payload['sort_order'],
                    ]);

                    $newTypeId = (int) db()->lastInsertId();
                    audit_log($adminId, 'admin_lantern_type_create', 'lantern_types', (string) $newTypeId, null, $payload);
                    set_flash('燈種已新增。');
                    redirect('lantern_types.php');
                }
            } catch (PDOException $exception) {
                if ($exception->getCode() === '23000') {
                    $errors[] = '燈種代碼已存在，請換一個代碼。';
                } else {
                    $errors[] = '儲存燈種失敗，請稍後再試。';
                }
            }
        }
    }

    if ($errors === [] && $action === 'toggle_lantern_type') {
        $typeId = (int) ($_POST['type_id'] ?? 0);
        $isActive = (int) ($_POST['is_active'] ?? 0) === 1 ? 1 : 0;

        $stmt = db()->prepare('SELECT * FROM lantern_types WHERE type_id = ? LIMIT 1');
        $stmt->execute([$typeId]);
        $before = $stmt->fetch();

        if (!$before) {
            $errors[] = '找不到要切換狀態的燈種。';
        } else {
            $stmt = db()->prepare('UPDATE lantern_types SET is_active = ? WHERE type_id = ?');
            $stmt->execute([$isActive, $typeId]);

            audit_log($adminId, 'admin_lantern_type_toggle', 'lantern_types', (string) $typeId, $before, [
                'is_active' => $isActive,
            ]);
            set_flash($isActive === 1 ? '燈種已啟用。' : '燈種已停用。');
            redirect('lantern_types.php');
        }
    }

    if ($errors === [] && $action === 'delete_lantern_type') {
        $typeId = (int) ($_POST['type_id'] ?? 0);

        $stmt = db()->prepare('SELECT * FROM lantern_types WHERE type_id = ? LIMIT 1');
        $stmt->execute([$typeId]);
        $before = $stmt->fetch();

        if (!$before) {
            $errors[] = '找不到要刪除的燈種。';
        } else {
            $dependencyChecks = [
                'lamp_positions' => 'SELECT COUNT(*) FROM lamp_positions WHERE type_id = ?',
                'order_items' => 'SELECT COUNT(*) FROM order_items WHERE type_id = ?',
                'cart_items' => 'SELECT COUNT(*) FROM cart_items WHERE type_id = ?',
                'annual_flow_rules' => 'SELECT COUNT(*) FROM annual_flow_rules WHERE type_id = ?',
            ];
            $dependencies = [];

            foreach ($dependencyChecks as $table => $sql) {
                $stmt = db()->prepare($sql);
                $stmt->execute([$typeId]);
                $count = (int) $stmt->fetchColumn();
                if ($count > 0) {
                    $dependencies[] = $table . '：' . $count . ' 筆';
                }
            }

            if ($dependencies !== []) {
                $stmt = db()->prepare('UPDATE lantern_types SET is_active = 0 WHERE type_id = ?');
                $stmt->execute([$typeId]);

                audit_log($adminId, 'admin_lantern_type_archive', 'lantern_types', (string) $typeId, $before, [
                    'is_active' => 0,
                    'dependencies' => $dependencies,
                ]);
                set_flash('此燈種已有關聯資料，已改為封存停用，前台不會再顯示。歷史訂單仍會保留原點燈紀錄。');
                redirect('lantern_types.php');
            } else {
                $stmt = db()->prepare('DELETE FROM lantern_types WHERE type_id = ?');
                $stmt->execute([$typeId]);

                audit_log($adminId, 'admin_lantern_type_delete', 'lantern_types', (string) $typeId, $before, null);
                set_flash('燈種已刪除。');
                redirect('lantern_types.php');
            }
        }
    }
}

$editType = null;
$editId = (int) ($_GET['edit_id'] ?? 0);
if ($editId > 0) {
    $stmt = db()->prepare('SELECT * FROM lantern_types WHERE type_id = ? LIMIT 1');
    $stmt->execute([$editId]);
    $editType = $stmt->fetch() ?: null;
}

$form = $editType ?: lantern_type_form_defaults();

expire_lamp_reservations();
$stmt = db()->query(
    'SELECT lt.*,
            COUNT(lp.position_id) AS total_positions,
            SUM(CASE WHEN lp.status = "available" AND lr.reservation_id IS NULL THEN 1 ELSE 0 END) AS available_count,
            SUM(CASE WHEN lr.reservation_id IS NOT NULL THEN 1 ELSE 0 END) AS reserved_count,
            SUM(CASE WHEN lp.status = "occupied" THEN 1 ELSE 0 END) AS occupied_count,
            SUM(CASE WHEN lp.status = "maintenance" THEN 1 ELSE 0 END) AS maintenance_count
     FROM lantern_types lt
     LEFT JOIN lamp_positions lp ON lp.type_id = lt.type_id
     LEFT JOIN lamp_reservations lr ON lr.reservation_id = (
         SELECT lr2.reservation_id
         FROM lamp_reservations lr2
         WHERE lr2.position_id = lp.position_id
           AND lr2.status = "active"
           AND lr2.expires_at > NOW()
         ORDER BY lr2.reservation_id DESC
         LIMIT 1
     )
     GROUP BY lt.type_id, lt.slug, lt.name, lt.description, lt.blessing_description, lt.recommended_for,
              lt.not_recommended_for, lt.price, lt.image_path, lt.is_active, lt.sort_order, lt.created_at, lt.updated_at
     ORDER BY lt.sort_order, lt.type_id'
);
$lanternTypes = $stmt->fetchAll();
?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>燈種管理</title>
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
                <h1>燈種管理</h1>
                <p class="helper-text">維護燈種名稱、祈福功德、建議對象、價格、排序與前台是否顯示。</p>
            </div>
            <?php if ($editType): ?>
                <a class="button secondary" href="lantern_types.php">取消編輯</a>
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
            <h2><?= $editType ? '編輯燈種' : '新增燈種' ?></h2>
            <form class="form grid-form" method="post" action="lantern_types.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_lantern_type">
                <input type="hidden" name="type_id" value="<?= e((string) ($form['type_id'] ?? '')) ?>">

                <label>
                    燈種代碼
                    <input type="text" name="slug" value="<?= e((string) $form['slug']) ?>" placeholder="例如：guangming" required>
                </label>

                <label>
                    燈種名稱
                    <input type="text" name="name" value="<?= e((string) $form['name']) ?>" required>
                </label>

                <label>
                    價格
                    <input type="number" name="price" min="0" step="1" value="<?= e((string) $form['price']) ?>" required>
                </label>

                <label>
                    排序
                    <input type="number" name="sort_order" value="<?= e((string) $form['sort_order']) ?>" required>
                </label>

                <label class="full-span">
                    燈種介紹
                    <textarea name="description" rows="3"><?= e((string) ($form['description'] ?? '')) ?></textarea>
                </label>

                <label class="full-span">
                    祈福功德說明
                    <textarea name="blessing_description" rows="3"><?= e((string) ($form['blessing_description'] ?? '')) ?></textarea>
                </label>

                <label class="full-span">
                    建議對象
                    <textarea name="recommended_for" rows="2"><?= e((string) ($form['recommended_for'] ?? '')) ?></textarea>
                </label>

                <label class="full-span">
                    不推薦對象
                    <textarea name="not_recommended_for" rows="2"><?= e((string) ($form['not_recommended_for'] ?? '')) ?></textarea>
                </label>

                <label>
                    圖片路徑
                    <input type="text" name="image_path" value="<?= e((string) ($form['image_path'] ?? '')) ?>" placeholder="assets/images/example.png">
                </label>

                <label>
                    前台顯示
                    <span class="checkbox-row">
                        <input type="checkbox" name="is_active" value="1" <?= (int) ($form['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
                        啟用此燈種
                    </span>
                </label>

                <div class="full-span">
                    <button class="button" type="submit"><?= $editType ? '儲存燈種' : '新增燈種' ?></button>
                </div>
            </form>
        </section>

        <section class="panel section-gap">
            <div class="section-heading">
                <h2>燈種列表</h2>
                <a href="lamp_positions.php">管理實體燈位</a>
            </div>

            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>排序</th>
                            <th>燈種</th>
                            <th>價格</th>
                            <th>狀態</th>
                            <th>燈位庫存</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lanternTypes as $type): ?>
                            <tr>
                                <td><?= (int) $type['sort_order'] ?></td>
                                <td>
                                    <strong><?= e($type['name']) ?></strong>
                                    <p class="helper-text"><?= e($type['slug']) ?></p>
                                </td>
                                <td>NT$ <?= e(number_format((float) $type['price'])) ?></td>
                                <td><?= (int) $type['is_active'] === 1 ? '啟用' : '停用' ?></td>
                                <td>
                                    <div class="status-row">
                                        <span>空位 <?= (int) $type['available_count'] ?></span>
                                        <span>保留 <?= (int) $type['reserved_count'] ?></span>
                                        <span>佔用 <?= (int) $type['occupied_count'] ?></span>
                                        <span>維修 <?= (int) $type['maintenance_count'] ?></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="table-actions">
                                        <a href="lantern_types.php?edit_id=<?= (int) $type['type_id'] ?>">編輯</a>
                                        <form method="post" action="lantern_types.php">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="toggle_lantern_type">
                                            <input type="hidden" name="type_id" value="<?= (int) $type['type_id'] ?>">
                                            <input type="hidden" name="is_active" value="<?= (int) $type['is_active'] === 1 ? 0 : 1 ?>">
                                            <button class="link-button" type="submit"><?= (int) $type['is_active'] === 1 ? '停用' : '啟用' ?></button>
                                        </form>
                                        <form method="post" action="lantern_types.php" onsubmit="return confirm('確定要刪除此燈種？已有關聯資料時系統會拒絕刪除。');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete_lantern_type">
                                            <input type="hidden" name="type_id" value="<?= (int) $type['type_id'] ?>">
                                            <button class="link-button danger" type="submit">刪除</button>
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
