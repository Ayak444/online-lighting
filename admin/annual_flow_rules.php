<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/frontend.php';

$admin = require_admin('login.php');
$adminId = (int) $admin['user_id'];
$errors = [];
$flash = flash_message();
$editRuleId = (int) ($_GET['edit'] ?? 0);

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

function load_rule(int $ruleId): ?array
{
    $stmt = db()->prepare('SELECT * FROM annual_flow_rules WHERE rule_id = ? LIMIT 1');
    $stmt->execute([$ruleId]);
    $rule = $stmt->fetch();

    return $rule ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors[] = '表單驗證失敗，請重新送出。';
    }

    $action = trim((string) ($_POST['action'] ?? 'save_rule'));
    $ruleId = (int) ($_POST['rule_id'] ?? 0);

    if ($errors === [] && $action === 'save_rule') {
        $serviceYear = (int) ($_POST['service_year'] ?? 0);
        $typeId = (int) ($_POST['type_id'] ?? 0);
        $ruleName = trim((string) ($_POST['rule_name'] ?? ''));
        $matchField = trim((string) ($_POST['match_field'] ?? 'always'));
        $matchValues = trim((string) ($_POST['match_values'] ?? '')) ?: null;
        $reason = trim((string) ($_POST['recommendation_reason'] ?? ''));
        $priority = (int) ($_POST['priority'] ?? 100);
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($serviceYear < 2000 || $serviceYear > 2100) {
            $errors[] = '年度格式不正確。';
        }

        if ($typeId <= 0) {
            $errors[] = '請選擇推薦燈種。';
        }

        if ($ruleName === '') {
            $errors[] = '規則名稱不可空白。';
        }

        if (!in_array($matchField, ['always', 'zodiac', 'birth_time', 'lunar_birthday'], true)) {
            $errors[] = '比對欄位不正確。';
        }

        if ($matchField !== 'always' && $matchValues === null) {
            $errors[] = '指定比對欄位時，請填入比對值。';
        }

        if ($reason === '') {
            $errors[] = '推薦原因不可空白。';
        }

        if ($priority < 0 || $priority > 999) {
            $errors[] = '排序權重需介於 0 到 999。';
        }

        if ($errors === []) {
            $before = $ruleId > 0 ? load_rule($ruleId) : null;

            if ($ruleId > 0 && $before === null) {
                $errors[] = '找不到要更新的流年規則。';
            } elseif ($ruleId > 0) {
                $stmt = db()->prepare(
                    'UPDATE annual_flow_rules
                     SET service_year = ?,
                         type_id = ?,
                         rule_name = ?,
                         match_field = ?,
                         match_values = ?,
                         recommendation_reason = ?,
                         priority = ?,
                         is_active = ?
                     WHERE rule_id = ?'
                );
                $stmt->execute([
                    $serviceYear,
                    $typeId,
                    $ruleName,
                    $matchField,
                    $matchValues,
                    $reason,
                    $priority,
                    $isActive,
                    $ruleId,
                ]);
            } else {
                $stmt = db()->prepare(
                    'INSERT INTO annual_flow_rules (
                        service_year,
                        type_id,
                        rule_name,
                        match_field,
                        match_values,
                        recommendation_reason,
                        priority,
                        is_active
                     ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $serviceYear,
                    $typeId,
                    $ruleName,
                    $matchField,
                    $matchValues,
                    $reason,
                    $priority,
                    $isActive,
                ]);
                $ruleId = (int) db()->lastInsertId();
            }

            if ($errors === []) {
                $after = load_rule($ruleId);
                audit_log($adminId, 'admin_annual_flow_rule_save', 'annual_flow_rules', (string) $ruleId, $before, $after);
                set_flash('流年規則已儲存。');
                redirect('annual_flow_rules.php?edit=' . $ruleId);
            }
        }
    }

    if ($errors === [] && $action === 'toggle_rule') {
        $before = load_rule($ruleId);
        if ($before === null) {
            $errors[] = '找不到要切換的流年規則。';
        } else {
            $nextStatus = (int) $before['is_active'] === 1 ? 0 : 1;
            $stmt = db()->prepare('UPDATE annual_flow_rules SET is_active = ? WHERE rule_id = ?');
            $stmt->execute([$nextStatus, $ruleId]);
            $after = load_rule($ruleId);
            audit_log($adminId, 'admin_annual_flow_rule_toggle', 'annual_flow_rules', (string) $ruleId, $before, $after);
            set_flash('流年規則狀態已更新。');
            redirect('annual_flow_rules.php');
        }
    }
}

$lanternTypes = db()->query(
    'SELECT type_id, name
     FROM lantern_types
     WHERE is_active = 1
     ORDER BY sort_order ASC, type_id ASC'
)->fetchAll();

$rules = db()->query(
    'SELECT afr.*, lt.name AS lantern_name
     FROM annual_flow_rules afr
     INNER JOIN lantern_types lt ON lt.type_id = afr.type_id
     ORDER BY afr.service_year DESC, afr.priority ASC, afr.rule_id ASC'
)->fetchAll();

$editRule = $editRuleId > 0 ? load_rule($editRuleId) : null;
$formRule = $editRule ?: [
    'rule_id' => 0,
    'service_year' => (int) date('Y'),
    'type_id' => '',
    'rule_name' => '',
    'match_field' => 'always',
    'match_values' => '',
    'recommendation_reason' => '',
    'priority' => 100,
    'is_active' => 1,
];
?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>流年規則管理</title>
    <link rel="stylesheet" href="../assets/css/main.css">
</head>
<body>
    <header class="site-header">
        <h1>後台管理</h1>
        <?= admin_nav() ?>
    </header>

    <main class="page">
        <h1>流年規則管理</h1>

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
                    <h2><?= (int) $formRule['rule_id'] > 0 ? '編輯規則' : '新增規則' ?></h2>
                    <p class="helper-text">後台依年度維護流年表邏輯，前台智慧續燈會依這裡的規則產生推薦。</p>
                </div>
            </div>

            <form class="compact-form grid-form" method="post" action="annual_flow_rules.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_rule">
                <input type="hidden" name="rule_id" value="<?= e((string) $formRule['rule_id']) ?>">

                <label>
                    服務年度
                    <input type="number" name="service_year" min="2000" max="2100" value="<?= e((string) $formRule['service_year']) ?>" required>
                </label>

                <label>
                    推薦燈種
                    <select name="type_id" required>
                        <option value="">請選擇燈種</option>
                        <?php foreach ($lanternTypes as $lanternType): ?>
                            <option value="<?= e((string) $lanternType['type_id']) ?>" <?= (string) $formRule['type_id'] === (string) $lanternType['type_id'] ? 'selected' : '' ?>>
                                <?= e($lanternType['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    規則名稱
                    <input type="text" name="rule_name" value="<?= e((string) $formRule['rule_name']) ?>" required>
                </label>

                <label>
                    比對欄位
                    <select name="match_field" required>
                        <?php foreach (['always', 'zodiac', 'birth_time', 'lunar_birthday'] as $field): ?>
                            <option value="<?= e($field) ?>" <?= $formRule['match_field'] === $field ? 'selected' : '' ?>>
                                <?= e(annual_flow_match_field_label($field)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="full-span">
                    比對值
                    <input type="text" name="match_values" value="<?= e((string) ($formRule['match_values'] ?? '')) ?>" placeholder="例如：馬,鼠,牛,兔 或 申時">
                </label>

                <label class="full-span">
                    推薦原因
                    <textarea name="recommendation_reason" rows="3" required><?= e((string) $formRule['recommendation_reason']) ?></textarea>
                </label>

                <label>
                    排序權重
                    <input type="number" name="priority" min="0" max="999" value="<?= e((string) $formRule['priority']) ?>" required>
                </label>

                <label class="checkbox-row">
                    <input type="checkbox" name="is_active" value="1" <?= (int) $formRule['is_active'] === 1 ? 'checked' : '' ?>>
                    啟用這筆規則
                </label>

                <div class="full-span admin-actions">
                    <button class="button" type="submit">儲存規則</button>
                    <?php if ((int) $formRule['rule_id'] > 0): ?>
                        <a class="button secondary" href="annual_flow_rules.php">新增另一筆</a>
                    <?php endif; ?>
                </div>
            </form>
        </section>

        <section class="panel section-gap">
            <div class="section-heading">
                <div>
                    <h2>規則列表</h2>
                </div>
            </div>

            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>年度</th>
                            <th>規則</th>
                            <th>推薦燈種</th>
                            <th>比對條件</th>
                            <th>原因</th>
                            <th>排序</th>
                            <th>狀態</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rules as $rule): ?>
                            <tr>
                                <td><?= e((string) $rule['service_year']) ?></td>
                                <td><?= e($rule['rule_name']) ?></td>
                                <td><?= e($rule['lantern_name']) ?></td>
                                <td>
                                    <?= e(annual_flow_match_field_label($rule['match_field'])) ?>
                                    <?php if ($rule['match_values']): ?>
                                        <p class="helper-text"><?= e($rule['match_values']) ?></p>
                                    <?php endif; ?>
                                </td>
                                <td><?= e($rule['recommendation_reason']) ?></td>
                                <td><?= e((string) $rule['priority']) ?></td>
                                <td><?= (int) $rule['is_active'] === 1 ? '啟用中' : '已停用' ?></td>
                                <td>
                                    <div class="table-actions">
                                        <a href="annual_flow_rules.php?edit=<?= e((string) $rule['rule_id']) ?>">編輯</a>
                                        <form method="post" action="annual_flow_rules.php">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="toggle_rule">
                                            <input type="hidden" name="rule_id" value="<?= e((string) $rule['rule_id']) ?>">
                                            <button class="link-button" type="submit"><?= (int) $rule['is_active'] === 1 ? '停用' : '啟用' ?></button>
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
