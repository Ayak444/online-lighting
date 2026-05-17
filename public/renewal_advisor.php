<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/frontend.php';

$user = require_login('login.php');
$userId = (int) $user['user_id'];
$activePeriod = active_lamp_service_period();
$errors = [];
$flash = flash_message();
$selectedDependentId = (int) ($_GET['dependent_id'] ?? $_POST['dependent_id'] ?? 0);

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

if ($selectedDependentId <= 0 && $dependents !== []) {
    $selectedDependentId = (int) $dependents[0]['dependent_id'];
}

$selectedDependent = null;
foreach ($dependents as $dependent) {
    if ((int) $dependent['dependent_id'] === $selectedDependentId) {
        $selectedDependent = $dependent;
        break;
    }
}

if ($selectedDependent === null && $selectedDependentId > 0) {
    $errors[] = '找不到指定的祈福對象。';
}

$recommendations = [];
$activeFlowRuleCount = 0;
if ($activePeriod !== null && $selectedDependent !== null) {
    $recommendations = annual_flow_recommendations((int) $activePeriod['service_year'], $selectedDependent);
}

if ($activePeriod !== null) {
    $stmt = db()->prepare(
        'SELECT COUNT(*)
         FROM annual_flow_rules
         WHERE service_year = ?
           AND is_active = 1
           AND rule_name LIKE "FLOW_TABLE_%"'
    );
    $stmt->execute([(int) $activePeriod['service_year']]);
    $activeFlowRuleCount = (int) $stmt->fetchColumn();
}

$allLanternTypes = db()->query(
    'SELECT type_id, name, price
     FROM lantern_types
     WHERE is_active = 1
     ORDER BY sort_order ASC, type_id ASC'
)->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errors === []) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors[] = '表單驗證失敗，請重新送出。';
    }

    $selectedTypeIds = array_values(array_unique(array_map('intval', (array) ($_POST['type_ids'] ?? []))));
    $selectedTypeIds = array_values(array_filter($selectedTypeIds, static fn (int $typeId): bool => $typeId > 0));

    if ($activePeriod === null) {
        $errors[] = '目前尚未啟用年度燈期，暫時無法送出續燈建議。';
    }

    if ($selectedDependent === null) {
        $errors[] = '請先選擇祈福對象。';
    }

    if ($selectedTypeIds === []) {
        $errors[] = '請至少保留一個想續點的燈種。';
    }

    $validLanternTypeIds = array_map(static fn (array $row): int => (int) $row['type_id'], $allLanternTypes);
    foreach ($selectedTypeIds as $typeId) {
        if (!in_array($typeId, $validLanternTypeIds, true)) {
            $errors[] = '選擇的燈種資料不正確。';
            break;
        }
    }

    if ($errors === []) {
        $cartId = ensure_cart_id($userId);
        $stmt = db()->prepare(
            'INSERT INTO cart_items (cart_id, type_id, dependent_id, prayer_wish)
             VALUES (?, ?, ?, ?)'
        );

        $selectedReasons = [];
        foreach ($recommendations as $recommendation) {
            $selectedReasons[(int) $recommendation['type_id']] = implode(' ', $recommendation['reasons']);
        }

        foreach ($selectedTypeIds as $typeId) {
            $prayerWish = trim((string) ($selectedReasons[$typeId] ?? '智慧續燈自選燈種'));
            $stmt->execute([
                $cartId,
                $typeId,
                (int) $selectedDependent['dependent_id'],
                $prayerWish !== '' ? $prayerWish : null,
            ]);
        }

        audit_log($userId, 'member_renewal_recommendation_add', 'cart_items', null, null, [
            'dependent_id' => (int) $selectedDependent['dependent_id'],
            'service_year' => (int) $activePeriod['service_year'],
            'type_ids' => $selectedTypeIds,
        ]);

        set_flash('智慧續燈建議已加入購物車，請再次確認燈種與付款方式。');
        redirect('cart.php');
    }
}

$recommendedTypeIds = array_map(static fn (array $row): int => (int) $row['type_id'], $recommendations);
?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>智慧續燈</title>
    <link rel="stylesheet" href="../assets/css/main.css">
</head>
<body>
    <header class="site-header">
        <h1>智慧續燈</h1>
        <?= public_nav($user) ?>
    </header>

    <main class="page">
        <h1>年度續燈建議</h1>

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

        <?php if ($activePeriod !== null && $activeFlowRuleCount === 0): ?>
            <div class="alert error">
                <p><?= e((string) $activePeriod['service_year']) ?> 年流年表尚未建立，暫時無法提供智慧續燈建議。</p>
            </div>
        <?php endif; ?>

        <section class="panel">
            <div class="section-heading">
                <div>
                    <h2>選擇祈福對象</h2>
                    <?php if ($activePeriod): ?>
                        <p class="helper-text">目前分析年度：<?= e((string) $activePeriod['service_year']) ?> 年。</p>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($dependents === []): ?>
                <p class="helper-text">請先到會員中心新增祈福對象與生辰資料。</p>
                <a class="button" href="profile.php">前往會員中心</a>
            <?php else: ?>
                <form class="filter-form" method="get" action="renewal_advisor.php">
                    <select name="dependent_id">
                        <?php foreach ($dependents as $dependent): ?>
                            <option value="<?= e((string) $dependent['dependent_id']) ?>" <?= (int) $dependent['dependent_id'] === $selectedDependentId ? 'selected' : '' ?>>
                                <?= e($dependent['name'] . ($dependent['relationship'] ? ' / ' . $dependent['relationship'] : '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button class="button" type="submit">重新分析</button>
                </form>
            <?php endif; ?>
        </section>

        <?php if ($selectedDependent): ?>
            <section class="split-grid section-gap">
                <section class="panel">
                    <div class="section-heading">
                        <div>
                            <h2>分析依據</h2>
                        </div>
                    </div>
                    <dl class="data-list">
                        <div><dt>姓名</dt><dd><?= e($selectedDependent['name']) ?></dd></div>
                        <div><dt>生日</dt><dd><?= e((string) ($selectedDependent['birthday'] ?? '未填')) ?></dd></div>
                        <div><dt>農曆生日</dt><dd><?= e((string) ($selectedDependent['lunar_birthday'] ?? '未填')) ?></dd></div>
                        <div><dt>出生時辰</dt><dd><?= e((string) ($selectedDependent['birth_time'] ?? '未填')) ?></dd></div>
                        <div><dt>生肖</dt><dd><?= e((string) ($selectedDependent['zodiac'] ?? '未填')) ?></dd></div>
                    </dl>
                </section>

                <section class="panel">
                    <div class="section-heading">
                        <div>
                            <h2>系統建議</h2>
                            <p class="helper-text">推薦只作為初始草案，會員可以自行保留、移除或加選其他燈種。</p>
                        </div>
                    </div>

                    <?php if ($recommendations === []): ?>
                        <p class="helper-text">目前資料還不足或尚無符合的年度規則，請自行選擇燈種。</p>
                    <?php else: ?>
                        <div class="stack-list">
                            <?php foreach ($recommendations as $recommendation): ?>
                                <article class="list-item">
                                    <h3><?= e($recommendation['lantern_name']) ?></h3>
                                    <p>NT$ <?= e(number_format((float) $recommendation['price'])) ?></p>
                                    <?php foreach ($recommendation['reasons'] as $reason): ?>
                                        <p class="helper-text"><?= e($reason) ?></p>
                                    <?php endforeach; ?>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            </section>

            <section class="panel section-gap">
                <div class="section-heading">
                    <div>
                        <h2>自行編輯燈種</h2>
                    </div>
                </div>

                <form class="compact-form" method="post" action="renewal_advisor.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="dependent_id" value="<?= e((string) $selectedDependent['dependent_id']) ?>">

                    <div class="role-checkbox-grid">
                        <?php foreach ($allLanternTypes as $lanternType): ?>
                            <label class="checkbox-row">
                                <input
                                    type="checkbox"
                                    name="type_ids[]"
                                    value="<?= e((string) $lanternType['type_id']) ?>"
                                    <?= in_array((int) $lanternType['type_id'], $recommendedTypeIds, true) ? 'checked' : '' ?>
                                >
                                <?= e($lanternType['name']) ?> / NT$ <?= e(number_format((float) $lanternType['price'])) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <button class="button" type="submit">加入購物車再確認</button>
                </form>
            </section>
        <?php endif; ?>
    </main>
</body>
</html>
