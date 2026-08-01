<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/frontend.php';

require_once __DIR__ . '/../config/notification_delivery.php';
require_once __DIR__ . '/../config/birth_profile.php';

$user = require_login('login.php');
$userId = (int) $user['user_id'];
$errors = [];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

function clean_input(string $key): string
{
    return trim((string) ($_POST[$key] ?? ''));
}

function nullable_input(string $key): ?string
{
    $value = clean_input($key);
    return $value === '' ? null : $value;
}

function valid_date_or_null(?string $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }

    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
}

function profile_flow_subject(array $subject): array
{
    $derived = birth_profile_derive(
        $subject['birthday'] ?? null,
        $subject['birth_clock_time'] ?? null
    );

    $subject['birth_time'] = $subject['birth_time'] ?? $derived['birth_time'];
    $subject['lunar_birthday'] = $subject['lunar_birthday'] ?: $derived['lunar_birthday'];
    $subject['zodiac'] = $subject['zodiac'] ?: $derived['zodiac'];

    return $subject;
}

function profile_auto_flow_reminder(int $serviceYear, array $subject): array
{
    $subject = profile_flow_subject($subject);
    $zodiac = trim((string) ($subject['zodiac'] ?? ''));

    if ($zodiac === '') {
        return [
            'title' => '資料不足',
            'notice' => '請先填寫西元生日與出生時間，系統才會自動換算生肖、農曆生日、時辰與流年提醒。',
            'appendix' => null,
            'lanterns' => [],
        ];
    }

    $stmt = db()->prepare(
        'SELECT flow_notice, appendix
         FROM annual_flow_entries
         WHERE service_year = ?
           AND zodiac_animal = ?
           AND is_active = 1
         LIMIT 1'
    );
    $stmt->execute([$serviceYear, $zodiac]);
    $entry = $stmt->fetch() ?: null;

    $recommendations = annual_flow_recommendations($serviceYear, $subject);
    $lanterns = array_values(array_unique(array_map(static function (array $recommendation): string {
        return (string) $recommendation['lantern_name'];
    }, $recommendations)));

    return [
        'title' => $serviceYear . ' 年 ' . $zodiac . '生肖流年提醒',
        'notice' => $entry['flow_notice'] ?? '今年尚未建立此生肖的流年提醒，仍可依祈福需求自行選燈。',
        'appendix' => $entry['appendix'] ?? null,
        'lanterns' => $lanterns,
    ];
}

function dependent_payload(): array
{
    $gender = clean_input('dependent_gender');
    if (!in_array($gender, ['male', 'female', 'other', 'unspecified'], true)) {
        $gender = 'unspecified';
    }

    $birthday = valid_date_or_null(nullable_input('dependent_birthday'));
    $birthClockTime = nullable_input('dependent_birth_clock_time');
    $derived = birth_profile_derive($birthday, $birthClockTime);

    return [
        'name' => clean_input('dependent_name'),
        'relationship' => nullable_input('relationship'),
        'gender' => $gender,
        'phone' => nullable_input('dependent_phone'),
        'national_id' => nullable_input('national_id'),
        'birthday' => $birthday,
        'birth_clock_time' => $birthClockTime,
        'birth_time' => $derived['birth_time'],
        'lunar_birthday' => $derived['lunar_birthday'],
        'zodiac' => $derived['zodiac'],
        'note' => nullable_input('note'),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors[] = '表單已過期，請重新送出。';
    }

    $action = clean_input('action');

    if ($errors === [] && $action === 'update_profile') {
        $gender = clean_input('gender');
        if (!in_array($gender, ['male', 'female', 'other', 'unspecified'], true)) {
            $gender = 'unspecified';
        }

        $profile = [
            'name' => clean_input('name'),
            'gender' => $gender,
            'phone' => nullable_input('phone'),
            'address' => nullable_input('address'),
            'birthday' => valid_date_or_null(nullable_input('birthday')),
            'birth_clock_time' => nullable_input('birth_clock_time'),
        ];
        $derived = birth_profile_derive($profile['birthday'], $profile['birth_clock_time']);
        $profile['lunar_birthday'] = $derived['lunar_birthday'];
        $profile['zodiac'] = $derived['zodiac'];

        if ($profile['name'] === '') {
            $errors[] = '請輸入姓名。';
        }

        if ($profile['birth_clock_time'] !== null && birth_profile_hour_branch($profile['birth_clock_time']) === null) {
            $errors[] = '出生時間格式不正確。';
        }

        if ($errors === []) {
            $stmt = db()->prepare(
                'UPDATE users
                 SET name = ?, gender = ?, phone = ?, address = ?, birthday = ?, birth_clock_time = ?,
                     lunar_birthday = ?, zodiac = ?, annual_reminder = NULL
                 WHERE user_id = ?'
            );
            $stmt->execute([
                $profile['name'],
                $profile['gender'],
                $profile['phone'],
                $profile['address'],
                $profile['birthday'],
                $profile['birth_clock_time'],
                $profile['lunar_birthday'],
                $profile['zodiac'],
                $userId,
            ]);

            sync_self_dependent($userId, $profile);

            audit_log($userId, 'member_profile_update', 'users', (string) $userId, null, $profile);
            $_SESSION['flash'] = '會員資料已更新。';
            redirect('profile.php');
        }
    }

    if ($errors === [] && $action === 'change_password') {
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $newPasswordConfirm = (string) ($_POST['new_password_confirm'] ?? '');

        if ($currentPassword === '') {
            $errors[] = '請輸入目前密碼。';
        }

        if (strlen($newPassword) < 8) {
            $errors[] = '新密碼至少需要 8 個字元。';
        }

        if ($newPassword !== $newPasswordConfirm) {
            $errors[] = '兩次輸入的新密碼不一致。';
        }

        if ($errors === []) {
            $stmt = db()->prepare('SELECT password_hash FROM users WHERE user_id = ? AND auth_provider = "local" LIMIT 1');
            $stmt->execute([$userId]);
            $passwordHash = $stmt->fetchColumn();

            if (!$passwordHash || !password_verify($currentPassword, (string) $passwordHash)) {
                $errors[] = '目前密碼不正確。';
            } else {
                $stmt = db()->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?');
                $stmt->execute([
                    password_hash($newPassword, PASSWORD_DEFAULT),
                    $userId,
                ]);

                audit_log($userId, 'member_password_change', 'users', (string) $userId, null, [
                    'changed_by' => 'self',
                    'changed_at' => date('Y-m-d H:i:s'),
                ]);
                $_SESSION['flash'] = '密碼已更新。';
                redirect('profile.php');
            }
        }
    }

    if ($errors === [] && $action === 'update_notification_preferences') {
        $before = notification_preferences_for_user($userId);
        $after = save_notification_preferences($userId, [
            'email_enabled' => isset($_POST['email_enabled']),
            'sms_enabled' => isset($_POST['sms_enabled']),
            'line_enabled' => isset($_POST['line_enabled']),
            'system_enabled' => isset($_POST['system_enabled']),
        ]);

        audit_log($userId, 'member_notification_preferences_update', 'user_notification_preferences', (string) $userId, $before, $after);
        $_SESSION['flash'] = '通知偏好已更新。';
        redirect('profile.php');
    }

    if ($errors === [] && $action === 'save_dependent') {
        $dependent = dependent_payload();

        if ($dependent['name'] === '') {
            $errors[] = '請輸入祈福對象姓名。';
        }

        $dependentId = (int) ($_POST['dependent_id'] ?? 0);

        if ($errors === [] && $dependentId > 0) {
            $stmt = db()->prepare('SELECT * FROM dependents WHERE dependent_id = ? AND user_id = ? LIMIT 1');
            $stmt->execute([$dependentId, $userId]);
            $before = $stmt->fetch();

            if (!$before) {
                $errors[] = '找不到要編輯的祈福對象。';
            } elseif ((int) ($before['is_self_profile'] ?? 0) === 1) {
                $errors[] = '本人資料由會員資料同步，請在會員資料區更新。';
            } else {
                $stmt = db()->prepare(
                    'UPDATE dependents
                     SET name = ?, relationship = ?, gender = ?, phone = ?, national_id = ?, birthday = ?,
                         birth_clock_time = ?, birth_time = ?, lunar_birthday = ?, zodiac = ?, note = ?
                     WHERE dependent_id = ? AND user_id = ?'
                );
                $stmt->execute([
                    $dependent['name'],
                    $dependent['relationship'],
                    $dependent['gender'],
                    $dependent['phone'],
                    $dependent['national_id'],
                    $dependent['birthday'],
                    $dependent['birth_clock_time'],
                    $dependent['birth_time'],
                    $dependent['lunar_birthday'],
                    $dependent['zodiac'],
                    $dependent['note'],
                    $dependentId,
                    $userId,
                ]);

                audit_log($userId, 'dependent_update', 'dependents', (string) $dependentId, $before, $dependent);
                $_SESSION['flash'] = '祈福對象已更新。';
                redirect('profile.php');
            }
        }

        if ($errors === [] && $dependentId === 0) {
            $stmt = db()->prepare(
                'INSERT INTO dependents (
                    user_id,
                    name,
                    relationship,
                    gender,
                    phone,
                    national_id,
                    birthday,
                    birth_clock_time,
                    birth_time,
                    lunar_birthday,
                    zodiac,
                    note
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $userId,
                $dependent['name'],
                $dependent['relationship'],
                $dependent['gender'],
                $dependent['phone'],
                $dependent['national_id'],
                $dependent['birthday'],
                $dependent['birth_clock_time'],
                $dependent['birth_time'],
                $dependent['lunar_birthday'],
                $dependent['zodiac'],
                $dependent['note'],
            ]);

            $dependentId = (int) db()->lastInsertId();
            audit_log($userId, 'dependent_create', 'dependents', (string) $dependentId, null, $dependent);
            $_SESSION['flash'] = '祈福對象已新增。';
            redirect('profile.php');
        }
    }

    if ($errors === [] && $action === 'delete_dependent') {
        $dependentId = (int) ($_POST['dependent_id'] ?? 0);
        $stmt = db()->prepare('SELECT * FROM dependents WHERE dependent_id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$dependentId, $userId]);
        $before = $stmt->fetch();

        if ($before) {
            if ((int) ($before['is_self_profile'] ?? 0) === 1) {
                $errors[] = '本人資料由會員資料同步，不能刪除。';
            } else {
                $stmt = db()->prepare('DELETE FROM dependents WHERE dependent_id = ? AND user_id = ?');
                $stmt->execute([$dependentId, $userId]);
                audit_log($userId, 'dependent_delete', 'dependents', (string) $dependentId, $before, null);
                $_SESSION['flash'] = '祈福對象已刪除。';
                redirect('profile.php');
            }
        }

        $errors[] = '找不到要刪除的祈福對象。';
    }
}

$user = require_login('login.php');
$stmt = db()->prepare(
    'SELECT *
     FROM dependents d
     WHERE d.user_id = ?
       AND NOT (
           d.is_self_profile IS NULL
           AND d.relationship IN ("本人", "自己", "Self")
           AND EXISTS (
               SELECT 1
               FROM dependents self_d
               WHERE self_d.user_id = d.user_id
                 AND self_d.is_self_profile = 1
           )
       )
     ORDER BY d.is_self_profile DESC, d.created_at DESC, d.dependent_id DESC'
);
$stmt->execute([$userId]);
$dependents = $stmt->fetchAll();

$editDependent = null;
$editDependentId = (int) ($_GET['edit_dependent_id'] ?? 0);
if ($editDependentId > 0) {
    $stmt = db()->prepare('SELECT * FROM dependents WHERE dependent_id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$editDependentId, $userId]);
    $editDependent = $stmt->fetch() ?: null;
}

$dependentForm = $editDependent ?: [
    'dependent_id' => '',
    'name' => '',
    'relationship' => '',
    'gender' => 'unspecified',
    'phone' => '',
    'national_id' => '',
    'birthday' => '',
    'birth_clock_time' => '',
    'birth_time' => '',
    'lunar_birthday' => '',
    'zodiac' => '',
    'note' => '',
];


$notificationPreferences = notification_preferences_for_user($userId);
$activePeriod = active_lamp_service_period();
$serviceYear = $activePeriod ? (int) $activePeriod['service_year'] : (int) date('Y');
$userFlowReminder = profile_auto_flow_reminder($serviceYear, $user);
$dependentFlowReminders = [];
foreach ($dependents as $dependent) {
    $dependentFlowReminders[(int) $dependent['dependent_id']] = profile_auto_flow_reminder($serviceYear, $dependent);
}
?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>會員中心</title>
    <link rel="stylesheet" href="../assets/css/main.css">
</head>
<body>
    <header class="site-header">
        <h1>會員中心</h1>
        <?= public_nav($user) ?>
    </header>

    <main class="page">
        <h1>會員資料與祈福名冊</h1>

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
            <h2>會員資料</h2>
            <form class="form grid-form" method="post" action="profile.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_profile">

                <label>
                    姓名
                    <input type="text" name="name" value="<?= e($user['name']) ?>" required>
                </label>

                <label>
                    Email
                    <input type="email" value="<?= e($user['email']) ?>" disabled>
                </label>

                <label>
                    性別
                    <select name="gender">
                        <option value="unspecified" <?= $user['gender'] === 'unspecified' ? 'selected' : '' ?>>未指定</option>
                        <option value="male" <?= $user['gender'] === 'male' ? 'selected' : '' ?>>男</option>
                        <option value="female" <?= $user['gender'] === 'female' ? 'selected' : '' ?>>女</option>
                        <option value="other" <?= $user['gender'] === 'other' ? 'selected' : '' ?>>其他</option>
                    </select>
                </label>

                <label>
                    手機電話
                    <input type="tel" name="phone" value="<?= e($user['phone']) ?>">
                </label>

                <label>
                    地址
                    <input type="text" name="address" value="<?= e($user['address']) ?>">
                </label>

                <label>
                    國曆生日
                    <input type="date" name="birthday" value="<?= e($user['birthday']) ?>">
                </label>

                <label>
                    出生時間
                    <input type="time" name="birth_clock_time" value="<?= e(substr((string) ($user['birth_clock_time'] ?? ''), 0, 5)) ?>">
                </label>

                <label>
                    農曆生日
                    <input type="text" value="<?= e($user['lunar_birthday']) ?>" disabled>
                </label>

                <label>
                    生肖
                    <input type="text" value="<?= e($user['zodiac']) ?>" disabled>
                </label>

                <div class="full-span auto-flow-box">
                    <div class="section-heading">
                        <div>
                            <h3>流年提醒（系統自動判斷）</h3>
                            <p class="helper-text">依 <?= e((string) $serviceYear) ?> 年流年表、生肖、農曆生日與出生時辰自動計算，會員不需要自行填寫。</p>
                        </div>
                    </div>
                    <p><strong><?= e($userFlowReminder['title']) ?></strong></p>
                    <p><?= e($userFlowReminder['notice']) ?></p>
                    <?php if (!empty($userFlowReminder['appendix'])): ?>
                        <p class="helper-text"><?= e((string) $userFlowReminder['appendix']) ?></p>
                    <?php endif; ?>
                    <div class="status-row">
                        <?php if ($userFlowReminder['lanterns'] === []): ?>
                            <span>尚無特定推薦燈種</span>
                        <?php else: ?>
                            <?php foreach ($userFlowReminder['lanterns'] as $lanternName): ?>
                                <span><?= e($lanternName) ?></span>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <button class="button" type="submit">更新會員資料</button>
            </form>
        </section>



        <section class="panel section-gap">
            <h2>通知偏好</h2>
            <form class="form notification-preference-form" method="post" action="profile.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_notification_preferences">

                <label class="checkbox-row">
                    <input type="checkbox" name="email_enabled" <?= (int) $notificationPreferences['email_enabled'] === 1 ? 'checked' : '' ?>>
                    Email 通知
                </label>

                <label class="checkbox-row">
                    <input type="checkbox" name="sms_enabled" <?= (int) $notificationPreferences['sms_enabled'] === 1 ? 'checked' : '' ?>>
                    簡訊通知
                </label>



                <label class="checkbox-row">
                    <input type="checkbox" name="system_enabled" <?= (int) $notificationPreferences['system_enabled'] === 1 ? 'checked' : '' ?>>
                    站內通知
                </label>

                <div class="full-span">
                    <button class="button" type="submit">更新通知偏好</button>
                </div>
            </form>
        </section>

        <?php if (($user['auth_provider'] ?? 'local') === 'local'): ?>
            <section class="panel section-gap">
                <h2>更改密碼</h2>
                <form class="form grid-form" method="post" action="profile.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="change_password">

                    <label>
                        目前密碼
                        <input type="password" name="current_password" required>
                    </label>

                    <label>
                        新密碼
                        <input type="password" name="new_password" required minlength="8">
                    </label>

                    <label>
                        確認新密碼
                        <input type="password" name="new_password_confirm" required minlength="8">
                    </label>

                    <div class="full-span">
                        <button class="button" type="submit">更新密碼</button>
                    </div>
                </form>
            </section>
        <?php endif; ?>

        <section class="panel section-gap">
            <h2><?= $editDependent ? '編輯祈福對象' : '新增祈福對象' ?></h2>
            <form class="form grid-form" method="post" action="profile.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_dependent">
                <input type="hidden" name="dependent_id" value="<?= e((string) ($dependentForm['dependent_id'] ?? '')) ?>">

                <label>
                    姓名
                    <input type="text" name="dependent_name" value="<?= e($dependentForm['name']) ?>" required>
                </label>

                <label>
                    關係
                    <input type="text" name="relationship" value="<?= e($dependentForm['relationship']) ?>" placeholder="本人、父親、母親、子女">
                </label>

                <label>
                    性別
                    <select name="dependent_gender">
                        <option value="unspecified" <?= $dependentForm['gender'] === 'unspecified' ? 'selected' : '' ?>>未指定</option>
                        <option value="male" <?= $dependentForm['gender'] === 'male' ? 'selected' : '' ?>>男</option>
                        <option value="female" <?= $dependentForm['gender'] === 'female' ? 'selected' : '' ?>>女</option>
                        <option value="other" <?= $dependentForm['gender'] === 'other' ? 'selected' : '' ?>>其他</option>
                    </select>
                </label>

                <label>
                    手機電話
                    <input type="tel" name="dependent_phone" value="<?= e($dependentForm['phone']) ?>">
                </label>

                <label>
                    身分證
                    <input type="text" name="national_id" value="<?= e($dependentForm['national_id']) ?>">
                </label>

                <label>
                    國曆生日
                    <input type="date" name="dependent_birthday" value="<?= e($dependentForm['birthday']) ?>">
                </label>

                <label>
                    出生時間
                    <input type="time" name="dependent_birth_clock_time" value="<?= e(substr((string) ($dependentForm['birth_clock_time'] ?? ''), 0, 5)) ?>">
                </label>

                <label>
                    系統推算農曆生日
                    <input type="text" value="<?= e($dependentForm['lunar_birthday']) ?>" disabled>
                </label>

                <label>
                    系統推算生肖 / 時辰
                    <input type="text" value="<?= e(trim((string) ($dependentForm['zodiac'] ?? '') . ' ' . (string) ($dependentForm['birth_time'] ?? ''))) ?>" disabled>
                </label>

                <label class="full-span">
                    備註
                    <input type="text" name="note" value="<?= e($dependentForm['note']) ?>">
                </label>

                <button class="button" type="submit"><?= $editDependent ? '儲存祈福對象' : '新增祈福對象' ?></button>
                <?php if ($editDependent): ?>
                    <a class="button secondary" href="profile.php">取消編輯</a>
                <?php endif; ?>
            </form>
        </section>

        <section class="panel section-gap">
            <h2>祈福人名冊</h2>

            <?php if ($dependents === []): ?>
                <p class="helper-text">目前尚未建立祈福對象。</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>姓名</th>
                                <th>關係</th>
                                <th>生日</th>
                                <th>農曆 / 時辰</th>
                                <th>生肖</th>
                                <th>流年提醒</th>
                                <th>電話</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dependents as $dependent): ?>
                                <tr>
                                    <td><?= e($dependent['name']) ?></td>
                                    <td><?= e($dependent['relationship']) ?></td>
                                    <td><?= e($dependent['birthday']) ?></td>
                                    <td><?= e(trim((string) ($dependent['lunar_birthday'] ?? '-') . ' / ' . (string) ($dependent['birth_time'] ?? '-'))) ?></td>
                                    <td><?= e($dependent['zodiac']) ?></td>
                                    <td>
                                        <?php $flowReminder = $dependentFlowReminders[(int) $dependent['dependent_id']] ?? null; ?>
                                        <?php if ($flowReminder): ?>
                                            <strong><?= e($flowReminder['title']) ?></strong>
                                            <p class="helper-text"><?= e($flowReminder['lanterns'] === [] ? '尚無特定推薦燈種' : '推薦：' . implode('、', $flowReminder['lanterns'])) ?></p>
                                        <?php else: ?>
                                            <span class="helper-text">系統尚未計算</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= e($dependent['phone']) ?></td>
                                    <td class="table-actions">
                                        <?php if ((int) ($dependent['is_self_profile'] ?? 0) === 1): ?>
                                            <span class="helper-text">由會員資料同步</span>
                                        <?php else: ?>
                                            <a href="profile.php?edit_dependent_id=<?= (int) $dependent['dependent_id'] ?>">編輯</a>
                                            <form method="post" action="profile.php">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete_dependent">
                                                <input type="hidden" name="dependent_id" value="<?= (int) $dependent['dependent_id'] ?>">
                                                <button class="link-button" type="submit">刪除</button>
                                            </form>
                                        <?php endif; ?>
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
