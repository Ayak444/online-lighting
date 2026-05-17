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

function valid_date_or_null(?string $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }

    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
}

function user_form_defaults(): array
{
    return [
        'user_id' => '',
        'email' => '',
        'name' => '',
        'gender' => 'unspecified',
        'phone' => '',
        'address' => '',
        'birthday' => '',
        'lunar_birthday' => '',
        'zodiac' => '',
        'annual_reminder' => '',
        'is_active' => '1',
        'roles' => ['member'],
    ];
}

function selected_roles_from_post(array $validRoleNames): array
{
    $roles = $_POST['role_names'] ?? [];
    if (!is_array($roles)) {
        $roles = [];
    }

    $roles = array_values(array_unique(array_map('strval', $roles)));
    $roles = array_values(array_filter($roles, static function (string $roleName) use ($validRoleNames): bool {
        return in_array($roleName, $validRoleNames, true);
    }));

    return $roles;
}

function load_user_roles_assoc(array $userIds): array
{
    if ($userIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $stmt = db()->prepare(
        "SELECT ur.user_id, r.role_name
         FROM user_roles ur
         INNER JOIN roles r ON r.role_id = ur.role_id
         WHERE ur.user_id IN ($placeholders)
         ORDER BY r.role_name"
    );
    $stmt->execute($userIds);

    $rolesByUser = [];
    foreach ($stmt->fetchAll() as $row) {
        $rolesByUser[(int) $row['user_id']][] = $row['role_name'];
    }

    return $rolesByUser;
}

function sync_user_roles(PDO $pdo, int $userId, array $roleNames, array $rolesByName): void
{
    $stmt = $pdo->prepare('DELETE FROM user_roles WHERE user_id = ?');
    $stmt->execute([$userId]);

    $stmt = $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)');
    foreach ($roleNames as $roleName) {
        $stmt->execute([$userId, (int) $rolesByName[$roleName]['role_id']]);
    }
}

$stmt = db()->query('SELECT role_id, role_name, display_name FROM roles ORDER BY role_id');
$roles = $stmt->fetchAll();
$rolesByName = [];
foreach ($roles as $role) {
    $rolesByName[$role['role_name']] = $role;
}
$validRoleNames = array_keys($rolesByName);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors[] = '表單已過期，請重新送出。';
    }

    $action = clean_post('action');

    if ($errors === [] && $action === 'save_user') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $email = strtolower(clean_post('email'));
        $password = (string) ($_POST['password'] ?? '');
        $gender = clean_post('gender');
        $birthday = valid_date_or_null(nullable_post('birthday'));
        $selectedRoles = selected_roles_from_post($validRoleNames);
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = '請輸入正確的 Email。';
        }

        if (clean_post('name') === '') {
            $errors[] = '請輸入姓名。';
        }

        if (!in_array($gender, ['male', 'female', 'other', 'unspecified'], true)) {
            $errors[] = '性別欄位不正確。';
        }

        if (nullable_post('birthday') !== null && $birthday === null) {
            $errors[] = '生日格式不正確。';
        }

        if ($selectedRoles === []) {
            $errors[] = '請至少選擇一個角色。';
        }

        if ($userId === 0 && strlen($password) < 8) {
            $errors[] = '新增帳號時密碼至少需要 8 個字元。';
        }

        if ($userId > 0 && $password !== '' && strlen($password) < 8) {
            $errors[] = '新密碼至少需要 8 個字元。';
        }

        if ($userId === $adminId && $isActive === 0) {
            $errors[] = '不能停用目前登入中的管理員帳號。';
        }

        if ($userId === $adminId && !in_array('admin', $selectedRoles, true)) {
            $errors[] = '不能移除目前登入帳號的系統管理員角色。';
        }

        $payload = [
            'email' => $email,
            'name' => clean_post('name'),
            'gender' => $gender,
            'phone' => nullable_post('phone'),
            'address' => nullable_post('address'),
            'birthday' => $birthday,
            'lunar_birthday' => nullable_post('lunar_birthday'),
            'zodiac' => nullable_post('zodiac'),
            'annual_reminder' => nullable_post('annual_reminder'),
            'is_active' => $isActive,
            'roles' => $selectedRoles,
        ];

        if ($errors === []) {
            $pdo = db();
            $pdo->beginTransaction();

            try {
                if ($userId > 0) {
                    $stmt = $pdo->prepare('SELECT * FROM users WHERE user_id = ? LIMIT 1 FOR UPDATE');
                    $stmt->execute([$userId]);
                    $before = $stmt->fetch();

                    if (!$before) {
                        throw new RuntimeException('找不到要編輯的會員。');
                    }

                    $beforeRoles = get_user_roles($userId);
                    $passwordChanged = $password !== '';
                    if ($password !== '') {
                        $stmt = $pdo->prepare(
                            'UPDATE users
                             SET email = ?, password_hash = ?, name = ?, gender = ?, phone = ?, address = ?, birthday = ?,
                                 lunar_birthday = ?, zodiac = ?, annual_reminder = ?, is_active = ?
                             WHERE user_id = ?'
                        );
                        $stmt->execute([
                            $payload['email'],
                            password_hash($password, PASSWORD_DEFAULT),
                            $payload['name'],
                            $payload['gender'],
                            $payload['phone'],
                            $payload['address'],
                            $payload['birthday'],
                            $payload['lunar_birthday'],
                            $payload['zodiac'],
                            $payload['annual_reminder'],
                            $payload['is_active'],
                            $userId,
                        ]);
                    } else {
                        $stmt = $pdo->prepare(
                            'UPDATE users
                             SET email = ?, name = ?, gender = ?, phone = ?, address = ?, birthday = ?,
                                 lunar_birthday = ?, zodiac = ?, annual_reminder = ?, is_active = ?
                             WHERE user_id = ?'
                        );
                        $stmt->execute([
                            $payload['email'],
                            $payload['name'],
                            $payload['gender'],
                            $payload['phone'],
                            $payload['address'],
                            $payload['birthday'],
                            $payload['lunar_birthday'],
                            $payload['zodiac'],
                            $payload['annual_reminder'],
                            $payload['is_active'],
                            $userId,
                        ]);
                    }

                    sync_user_roles($pdo, $userId, $selectedRoles, $rolesByName);
                    audit_log($adminId, 'admin_user_update', 'users', (string) $userId, array_merge($before, [
                        'roles' => $beforeRoles,
                    ]), $payload);

                    if ($passwordChanged) {
                        audit_log($adminId, 'admin_password_change', 'users', (string) $userId, null, [
                            'changed_by_admin_id' => $adminId,
                            'target_user_id' => $userId,
                            'target_email' => $payload['email'],
                            'changed_at' => date('Y-m-d H:i:s'),
                        ]);
                    }

                    if ($userId === $adminId) {
                        $_SESSION['roles'] = get_user_roles($adminId);
                        $_SESSION['user_name'] = $payload['name'];
                    }

                    $pdo->commit();
                    set_flash('會員資料與角色已更新。');
                    redirect('users.php?edit_id=' . $userId);
                }

                $stmt = $pdo->prepare(
                    'INSERT INTO users
                        (email, password_hash, name, gender, phone, address, birthday, lunar_birthday, zodiac, annual_reminder, auth_provider, is_active)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "local", ?)'
                );
                $stmt->execute([
                    $payload['email'],
                    password_hash($password, PASSWORD_DEFAULT),
                    $payload['name'],
                    $payload['gender'],
                    $payload['phone'],
                    $payload['address'],
                    $payload['birthday'],
                    $payload['lunar_birthday'],
                    $payload['zodiac'],
                    $payload['annual_reminder'],
                    $payload['is_active'],
                ]);
                $newUserId = (int) $pdo->lastInsertId();

                sync_user_roles($pdo, $newUserId, $selectedRoles, $rolesByName);

                $stmt = $pdo->prepare('INSERT INTO carts (user_id) VALUES (?)');
                $stmt->execute([$newUserId]);

                audit_log($adminId, 'admin_user_create', 'users', (string) $newUserId, null, $payload);

                $pdo->commit();
                set_flash('會員帳號已新增。');
                redirect('users.php?edit_id=' . $newUserId);
            } catch (Throwable $throwable) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                if ($throwable instanceof PDOException && $throwable->getCode() === '23000') {
                    $errors[] = 'Email 已被使用，請換一個 Email。';
                } else {
                    $errors[] = $throwable->getMessage();
                }
            }
        }
    }
}

$editUser = null;
$editUserRoles = [];
$editDependents = [];
$editId = (int) ($_GET['edit_id'] ?? 0);
if ($editId > 0) {
    $stmt = db()->prepare('SELECT * FROM users WHERE user_id = ? LIMIT 1');
    $stmt->execute([$editId]);
    $editUser = $stmt->fetch() ?: null;

    if ($editUser) {
        $editUserRoles = get_user_roles($editId);
        $stmt = db()->prepare('SELECT * FROM dependents WHERE user_id = ? ORDER BY created_at DESC, dependent_id DESC');
        $stmt->execute([$editId]);
        $editDependents = $stmt->fetchAll();
    }
}

$form = $editUser ?: user_form_defaults();
if ($editUser) {
    $form['roles'] = $editUserRoles;
}

$q = trim((string) ($_GET['q'] ?? ''));
$roleFilter = trim((string) ($_GET['role'] ?? 'all'));
$activeFilter = trim((string) ($_GET['active'] ?? 'all'));

if ($roleFilter !== 'all' && !in_array($roleFilter, $validRoleNames, true)) {
    $roleFilter = 'all';
}

if (!in_array($activeFilter, ['all', 'active', 'inactive'], true)) {
    $activeFilter = 'all';
}

$conditions = [];
$params = [];

if ($q !== '') {
    $conditions[] = '(u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)';
    $keyword = '%' . $q . '%';
    $params[] = $keyword;
    $params[] = $keyword;
    $params[] = $keyword;
}

if ($activeFilter === 'active') {
    $conditions[] = 'u.is_active = 1';
}

if ($activeFilter === 'inactive') {
    $conditions[] = 'u.is_active = 0';
}

if ($roleFilter !== 'all') {
    $conditions[] = 'EXISTS (
        SELECT 1
        FROM user_roles filter_ur
        INNER JOIN roles filter_r ON filter_r.role_id = filter_ur.role_id
        WHERE filter_ur.user_id = u.user_id AND filter_r.role_name = ?
    )';
    $params[] = $roleFilter;
}

$where = $conditions === [] ? '1 = 1' : implode(' AND ', $conditions);
$stmt = db()->prepare(
    "SELECT u.user_id, u.email, u.name, u.gender, u.phone, u.birthday, u.is_active, u.created_at,
            COUNT(DISTINCT d.dependent_id) AS dependent_count,
            COUNT(DISTINCT o.order_id) AS order_count,
            COUNT(DISTINCT f.feedback_id) AS feedback_count
     FROM users u
     LEFT JOIN dependents d ON d.user_id = u.user_id
     LEFT JOIN orders o ON o.user_id = u.user_id
     LEFT JOIN feedbacks f ON f.user_id = u.user_id
     WHERE $where
     GROUP BY u.user_id, u.email, u.name, u.gender, u.phone, u.birthday, u.is_active, u.created_at
     ORDER BY u.created_at DESC, u.user_id DESC
     LIMIT 100"
);
$stmt->execute($params);
$users = $stmt->fetchAll();
$rolesByUser = load_user_roles_assoc(array_map(static function (array $user): int {
    return (int) $user['user_id'];
}, $users));
?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>會員與權限管理</title>
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
                <h1>會員與權限管理</h1>
                <p class="helper-text">維護信眾資料、工作人員帳號與 RBAC 多重身分。</p>
            </div>
            <?php if ($editUser): ?>
                <a class="button secondary" href="users.php">新增帳號</a>
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
            <h2><?= $editUser ? '編輯會員與角色' : '新增會員/工作人員帳號' ?></h2>
            <form class="form grid-form" method="post" action="users.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_user">
                <input type="hidden" name="user_id" value="<?= e((string) ($form['user_id'] ?? '')) ?>">

                <label>
                    Email
                    <input type="email" name="email" value="<?= e((string) $form['email']) ?>" required>
                </label>

                <label>
                    姓名
                    <input type="text" name="name" value="<?= e((string) $form['name']) ?>" required>
                </label>

                <label>
                    <?= $editUser ? '新密碼（不改請留空）' : '登入密碼' ?>
                    <input type="password" name="password" minlength="8" <?= $editUser ? '' : 'required' ?>>
                </label>

                <label>
                    性別
                    <select name="gender">
                        <?php foreach (['unspecified', 'male', 'female', 'other'] as $gender): ?>
                            <option value="<?= e($gender) ?>" <?= $form['gender'] === $gender ? 'selected' : '' ?>>
                                <?= e(gender_label($gender)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    手機電話
                    <input type="tel" name="phone" value="<?= e((string) ($form['phone'] ?? '')) ?>">
                </label>

                <label>
                    國曆生日
                    <input type="date" name="birthday" value="<?= e((string) ($form['birthday'] ?? '')) ?>">
                </label>

                <label class="full-span">
                    地址
                    <input type="text" name="address" value="<?= e((string) ($form['address'] ?? '')) ?>">
                </label>

                <label>
                    農曆生辰八字
                    <input type="text" name="lunar_birthday" value="<?= e((string) ($form['lunar_birthday'] ?? '')) ?>">
                </label>

                <label>
                    生肖
                    <input type="text" name="zodiac" value="<?= e((string) ($form['zodiac'] ?? '')) ?>">
                </label>

                <label class="full-span">
                    流年提醒
                    <input type="text" name="annual_reminder" value="<?= e((string) ($form['annual_reminder'] ?? '')) ?>">
                </label>

                <div class="full-span">
                    <h3>角色權限</h3>
                    <div class="role-checkbox-grid">
                        <?php foreach ($roles as $role): ?>
                            <label class="checkbox-row">
                                <input type="checkbox" name="role_names[]" value="<?= e($role['role_name']) ?>" <?= in_array($role['role_name'], $form['roles'] ?? [], true) ? 'checked' : '' ?>>
                                <?= e($role['display_name']) ?>（<?= e($role['role_name']) ?>）
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <label class="full-span">
                    帳號狀態
                    <span class="checkbox-row">
                        <input type="checkbox" name="is_active" value="1" <?= (int) ($form['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
                        啟用此帳號
                    </span>
                </label>

                <div class="full-span">
                    <button class="button" type="submit"><?= $editUser ? '儲存會員與權限' : '新增帳號' ?></button>
                </div>
            </form>
        </section>

        <?php if ($editUser): ?>
            <section class="panel section-gap">
                <h2>此會員的祈福名冊</h2>
                <?php if ($editDependents === []): ?>
                    <p class="helper-text">此會員尚未建立祈福對象。</p>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="data-table compact-table">
                            <thead>
                                <tr>
                                    <th>姓名</th>
                                    <th>關係</th>
                                    <th>生日</th>
                                    <th>生肖</th>
                                    <th>電話</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($editDependents as $dependent): ?>
                                    <tr>
                                        <td><?= e($dependent['name']) ?></td>
                                        <td><?= e($dependent['relationship']) ?></td>
                                        <td><?= e($dependent['birthday']) ?></td>
                                        <td><?= e($dependent['zodiac']) ?></td>
                                        <td><?= e($dependent['phone']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <section class="panel section-gap">
            <div class="section-heading">
                <h2>會員列表</h2>
                <span class="helper-text">最多顯示 100 筆</span>
            </div>

            <form class="filter-form" method="get" action="users.php">
                <input type="text" name="q" value="<?= e($q) ?>" placeholder="搜尋姓名、Email、電話">
                <select name="role">
                    <option value="all">全部角色</option>
                    <?php foreach ($roles as $role): ?>
                        <option value="<?= e($role['role_name']) ?>" <?= $roleFilter === $role['role_name'] ? 'selected' : '' ?>>
                            <?= e($role['display_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select name="active">
                    <option value="all" <?= $activeFilter === 'all' ? 'selected' : '' ?>>全部狀態</option>
                    <option value="active" <?= $activeFilter === 'active' ? 'selected' : '' ?>>啟用</option>
                    <option value="inactive" <?= $activeFilter === 'inactive' ? 'selected' : '' ?>>停用</option>
                </select>
                <button class="button secondary" type="submit">查詢</button>
            </form>

            <div class="table-wrap section-gap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>會員</th>
                            <th>角色</th>
                            <th>狀態</th>
                            <th>祈福對象</th>
                            <th>訂單</th>
                            <th>回饋</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $listUser): ?>
                            <?php $userRoles = $rolesByUser[(int) $listUser['user_id']] ?? []; ?>
                            <tr>
                                <td>
                                    <strong><?= e($listUser['name']) ?></strong>
                                    <p class="helper-text"><?= e($listUser['email']) ?> / <?= e($listUser['phone'] ?? '-') ?></p>
                                </td>
                                <td>
                                    <div class="status-row">
                                        <?php foreach ($userRoles as $roleName): ?>
                                            <span><?= e(role_label($roleName)) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                                <td><?= e(user_active_label((int) $listUser['is_active'])) ?></td>
                                <td><?= (int) $listUser['dependent_count'] ?></td>
                                <td><?= (int) $listUser['order_count'] ?></td>
                                <td><?= (int) $listUser['feedback_count'] ?></td>
                                <td><a href="users.php?edit_id=<?= (int) $listUser['user_id'] ?>">編輯</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</body>
</html>
