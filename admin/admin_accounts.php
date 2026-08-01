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
        ['admin_accounts.php', '管理員帳號'],
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

function admin_role_id(PDO $pdo): int
{
    $stmt = $pdo->prepare('SELECT role_id FROM roles WHERE role_name = "admin" LIMIT 1');
    $stmt->execute();
    $roleId = $stmt->fetchColumn();

    if ($roleId === false) {
        throw new RuntimeException('找不到 admin 角色，請先確認 roles 資料表。');
    }

    return (int) $roleId;
}

function member_role_id(PDO $pdo): ?int
{
    $stmt = $pdo->prepare('SELECT role_id FROM roles WHERE role_name = "member" LIMIT 1');
    $stmt->execute();
    $roleId = $stmt->fetchColumn();

    return $roleId === false ? null : (int) $roleId;
}

function load_admin_account(int $userId): ?array
{
    $stmt = db()->prepare(
        'SELECT u.*
         FROM users u
         INNER JOIN user_roles ur ON ur.user_id = u.user_id
         INNER JOIN roles r ON r.role_id = ur.role_id
         WHERE u.user_id = ?
           AND r.role_name = "admin"
         LIMIT 1'
    );
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    return $user ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors[] = '表單驗證失敗，請重新送出。';
    }

    $action = trim((string) ($_POST['action'] ?? 'create_admin'));

    if ($errors === [] && $action === 'create_admin') {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $name = trim((string) ($_POST['name'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $phone = trim((string) ($_POST['phone'] ?? '')) ?: null;

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = '請輸入正確的 Email。';
        }

        if ($name === '') {
            $errors[] = '請輸入管理員姓名。';
        }

        if (strlen($password) < 8) {
            $errors[] = '密碼至少需要 8 個字元。';
        }

        if ($errors === []) {
            $pdo = db();
            $pdo->beginTransaction();

            try {
                $adminRoleId = admin_role_id($pdo);
                $memberRoleId = member_role_id($pdo);

                $stmt = $pdo->prepare(
                    'INSERT INTO users (email, password_hash, name, gender, phone, auth_provider, is_active)
                     VALUES (?, ?, ?, "unspecified", ?, "local", 1)'
                );
                $stmt->execute([
                    $email,
                    password_hash($password, PASSWORD_DEFAULT),
                    $name,
                    $phone,
                ]);
                $newUserId = (int) $pdo->lastInsertId();

                $stmt = $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)');
                $stmt->execute([$newUserId, $adminRoleId]);
                if ($memberRoleId !== null && $memberRoleId !== $adminRoleId) {
                    $stmt->execute([$newUserId, $memberRoleId]);
                }

                $stmt = $pdo->prepare('INSERT INTO carts (user_id) VALUES (?)');
                $stmt->execute([$newUserId]);

                audit_log($adminId, 'admin_account_create', 'users', (string) $newUserId, null, [
                    'email' => $email,
                    'name' => $name,
                    'roles' => ['admin', 'member'],
                ]);

                $pdo->commit();
                set_flash('管理員帳號已建立，可使用此 Email 與密碼登入後台。');
                redirect('admin_accounts.php');
            } catch (Throwable $throwable) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                if ($throwable instanceof PDOException && $throwable->getCode() === '23000') {
                    $errors[] = 'Email 已存在，請改用其他 Email。';
                } else {
                    $errors[] = $throwable->getMessage();
                }
            }
        }
    }

    if ($errors === [] && $action === 'toggle_admin') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $nextActive = (int) ($_POST['is_active'] ?? 0) === 1 ? 1 : 0;

        if ($userId <= 0) {
            $errors[] = '管理員編號不正確。';
        } elseif ($userId === $adminId && $nextActive === 0) {
            $errors[] = '不能停用目前登入中的管理員帳號。';
        } else {
            $before = load_admin_account($userId);
            if (!$before) {
                $errors[] = '找不到管理員帳號。';
            } else {
                $stmt = db()->prepare('UPDATE users SET is_active = ? WHERE user_id = ?');
                $stmt->execute([$nextActive, $userId]);
                $after = load_admin_account($userId);
                audit_log($adminId, 'admin_account_toggle', 'users', (string) $userId, $before, $after);
                set_flash($nextActive === 1 ? '管理員帳號已啟用。' : '管理員帳號已停用。');
                redirect('admin_accounts.php');
            }
        }
    }
}

$stmt = db()->query(
    'SELECT u.user_id, u.email, u.name, u.phone, u.is_active, u.created_at,
            GROUP_CONCAT(r.display_name ORDER BY r.role_id SEPARATOR "、") AS role_names
     FROM users u
     INNER JOIN user_roles ur ON ur.user_id = u.user_id
     INNER JOIN roles r ON r.role_id = ur.role_id
     WHERE EXISTS (
         SELECT 1
         FROM user_roles admin_ur
         INNER JOIN roles admin_r ON admin_r.role_id = admin_ur.role_id
         WHERE admin_ur.user_id = u.user_id
           AND admin_r.role_name = "admin"
     )
     GROUP BY u.user_id, u.email, u.name, u.phone, u.is_active, u.created_at
     ORDER BY u.created_at DESC, u.user_id DESC'
);
$admins = $stmt->fetchAll();
?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>管理員帳號</title>
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
                <h1>管理員帳號</h1>
                <p class="helper-text">建立可登入後台的管理員帳號，系統會自動授予 admin 權限。</p>
            </div>
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
            <h2>新增管理員</h2>
            <form class="form grid-form" method="post" action="admin_accounts.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_admin">

                <label>
                    Email
                    <input type="email" name="email" required>
                </label>

                <label>
                    姓名
                    <input type="text" name="name" required>
                </label>

                <label>
                    初始密碼
                    <input type="password" name="password" minlength="8" required>
                </label>

                <label>
                    電話
                    <input type="tel" name="phone">
                </label>

                <div class="full-span">
                    <button class="button" type="submit">建立管理員帳號</button>
                </div>
            </form>
        </section>

        <section class="panel section-gap">
            <h2>目前管理員</h2>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>姓名</th>
                            <th>Email</th>
                            <th>角色</th>
                            <th>狀態</th>
                            <th>建立時間</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($admins as $row): ?>
                            <tr>
                                <td><?= e($row['name']) ?></td>
                                <td>
                                    <?= e($row['email']) ?>
                                    <?php if (!empty($row['phone'])): ?>
                                        <p class="helper-text"><?= e($row['phone']) ?></p>
                                    <?php endif; ?>
                                </td>
                                <td><?= e((string) $row['role_names']) ?></td>
                                <td><?= e(user_active_label((int) $row['is_active'])) ?></td>
                                <td><?= e($row['created_at']) ?></td>
                                <td>
                                    <div class="table-actions">
                                        <a href="users.php?edit_id=<?= (int) $row['user_id'] ?>">編輯</a>
                                        <?php if ((int) $row['user_id'] !== $adminId): ?>
                                            <form method="post" action="admin_accounts.php">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="toggle_admin">
                                                <input type="hidden" name="user_id" value="<?= (int) $row['user_id'] ?>">
                                                <input type="hidden" name="is_active" value="<?= (int) $row['is_active'] === 1 ? 0 : 1 ?>">
                                                <button class="link-button" type="submit"><?= (int) $row['is_active'] === 1 ? '停用' : '啟用' ?></button>
                                            </form>
                                        <?php endif; ?>
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
