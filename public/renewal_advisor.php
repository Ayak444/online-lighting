<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/frontend.php';

$user = require_login('login.php');
$userId = (int) $user['user_id'];
$activePeriod = active_lamp_service_period();
$targetPeriod = next_lamp_service_period($activePeriod);
$errors = [];
$flash = flash_message();
$selectedDependentId = (int) ($_GET['dependent_id'] ?? $_POST['dependent_id'] ?? 0);
$selectionMode = trim((string) ($_GET['mode'] ?? $_POST['selection_mode'] ?? 'original'));
if (!in_array($selectionMode, ['original', 'recommended'], true)) {
    $selectionMode = 'original';
}

$stmt = db()->prepare(
    'SELECT *
     FROM dependents d
     WHERE d.user_id = ?
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
    $errors[] = '找不到此祈福對象。';
}

$recommendations = [];
if ($targetPeriod !== null && $selectedDependent !== null) {
    $recommendations = annual_flow_recommendations((int) $targetPeriod['service_year'], $selectedDependent);
}
$recommendedTypeIds = array_map(static fn (array $row): int => (int) $row['type_id'], $recommendations);
$recommendationReasonsByType = [];
foreach ($recommendations as $recommendation) {
    $recommendationReasonsByType[(int) $recommendation['type_id']] = $recommendation['reasons'];
}

function renewal_previous_items(int $userId, int $dependentId): array
{
    $stmt = db()->prepare(
        'SELECT oi.detail_id,
                oi.type_id,
                oi.position_id,
                oi.lantern_name_snapshot,
                oi.dependent_name_snapshot,
                oi.price_snapshot,
                oi.blessing_start_date,
                oi.blessing_end_date,
                o.service_year,
                lt.name AS current_lantern_name,
                lt.price AS current_price,
                lp.position_code,
                lp.status AS position_status,
                lp.occupied_until
         FROM order_items oi
         INNER JOIN orders o ON o.order_id = oi.order_id
         INNER JOIN lantern_types lt ON lt.type_id = oi.type_id
         INNER JOIN lamp_positions lp ON lp.position_id = oi.position_id
         WHERE o.user_id = ?
           AND oi.dependent_id = ?
           AND o.payment_status = "paid"
           AND o.review_status = "approved"
           AND oi.item_status IN ("assigned", "completed")
           AND oi.position_id IS NOT NULL
           AND lt.is_active = 1
           AND lp.status <> "retired"
         ORDER BY o.service_year DESC, oi.detail_id DESC'
    );
    $stmt->execute([$userId, $dependentId]);

    $items = [];
    $seen = [];
    foreach ($stmt->fetchAll() as $row) {
        $key = (int) $row['type_id'] . ':' . (int) $row['position_id'];
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $items[] = $row;
    }

    return $items;
}

$previousItems = $selectedDependent ? renewal_previous_items($userId, (int) $selectedDependent['dependent_id']) : [];
$previousItemById = [];
foreach ($previousItems as $item) {
    $previousItemById[(int) $item['detail_id']] = $item;
}

$allLanternTypes = db()->query(
    'SELECT type_id, name, price
     FROM lantern_types
     WHERE is_active = 1
     ORDER BY sort_order ASC, type_id ASC'
)->fetchAll();
$allLanternTypeIds = array_map(static fn (array $row): int => (int) $row['type_id'], $allLanternTypes);
$nextYearBreakdown = lamp_stock_breakdown_by_type_for_period($allLanternTypeIds, $targetPeriod);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errors === []) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors[] = '表單已過期，請重新送出。';
    }

    if ($targetPeriod === null) {
        $errors[] = '尚未建立下一年度燈期，請先請管理員新增下一年度燈期後再續點。';
    }

    if ($selectedDependent === null) {
        $errors[] = '請選擇祈福對象。';
    }

    if ($selectionMode === 'original') {
        $selectedSourceIds = array_values(array_unique(array_map('intval', (array) ($_POST['source_detail_ids'] ?? []))));
        $selectedSourceIds = array_values(array_filter($selectedSourceIds, static fn (int $id): bool => $id > 0));

        if ($selectedSourceIds === []) {
            $errors[] = '請至少選擇一筆原本的點燈紀錄。';
        }

        foreach ($selectedSourceIds as $sourceId) {
            if (!isset($previousItemById[$sourceId])) {
                $errors[] = '選擇的原點燈紀錄不正確，請重新整理後再試。';
                break;
            }
        }
    } else {
        $selectedTypeIds = array_values(array_unique(array_map('intval', (array) ($_POST['type_ids'] ?? []))));
        $selectedTypeIds = array_values(array_filter($selectedTypeIds, static fn (int $typeId): bool => $typeId > 0));

        if ($selectedTypeIds === []) {
            $errors[] = '請至少選擇一個系統推薦燈種。';
        }

        foreach ($selectedTypeIds as $typeId) {
            if (!in_array($typeId, $allLanternTypeIds, true)) {
                $errors[] = '選擇的燈種不正確。';
                break;
            }
            $available = (int) ($nextYearBreakdown[$typeId]['available_count'] ?? 0);
            if ($available <= 0) {
                $name = '此燈種';
                foreach ($allLanternTypes as $lanternType) {
                    if ((int) $lanternType['type_id'] === $typeId) {
                        $name = (string) $lanternType['name'];
                        break;
                    }
                }
                $errors[] = lamp_stock_unavailable_message_for_period($typeId, $name, 1, $targetPeriod);
            }
        }
    }

    if ($errors === []) {
        $pdo = db();
        $pdo->beginTransaction();

        try {
            $cartId = ensure_cart_id($userId);

            if ($selectionMode === 'original') {
                $stmt = $pdo->prepare(
                    'INSERT INTO cart_items (
                        cart_id,
                        type_id,
                        dependent_id,
                        target_period_id,
                        renewal_source_detail_id,
                        preferred_position_id,
                        prayer_wish
                     ) VALUES (?, ?, ?, ?, ?, ?, ?)'
                );

                foreach ($selectedSourceIds as $sourceId) {
                    $source = $previousItemById[$sourceId];
                    $stmt->execute([
                        $cartId,
                        (int) $source['type_id'],
                        (int) $selectedDependent['dependent_id'],
                        (int) $targetPeriod['period_id'],
                        (int) $source['detail_id'],
                        (int) $source['position_id'],
                        '續點原燈位 ' . $source['position_code'] . ' 至 ' . $targetPeriod['blessing_end_date'],
                    ]);
                }
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO cart_items (cart_id, type_id, dependent_id, target_period_id, prayer_wish)
                     VALUES (?, ?, ?, ?, ?)'
                );

                foreach ($selectedTypeIds as $typeId) {
                    $reason = implode(' ', $recommendationReasonsByType[$typeId] ?? []);
                    $stmt->execute([
                        $cartId,
                        $typeId,
                        (int) $selectedDependent['dependent_id'],
                        (int) $targetPeriod['period_id'],
                        $reason !== '' ? '系統推薦續點：' . $reason : '系統推薦續點',
                    ]);
                }
            }

            audit_log($userId, 'member_renewal_recommendation_add', 'cart_items', null, null, [
                'dependent_id' => (int) $selectedDependent['dependent_id'],
                'target_service_year' => (int) $targetPeriod['service_year'],
                'mode' => $selectionMode,
            ]);

            $pdo->commit();
            set_flash('續點項目已加入購物車，請確認後建立下一年度訂單。');
            redirect('cart.php');
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $throwable instanceof RuntimeException ? $throwable->getMessage() : '加入續點項目失敗，請稍後再試。';
        }
    }
}
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
        <h1>智慧續燈</h1>

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
                    <h2>選擇續點對象</h2>
                    <?php if ($targetPeriod): ?>
                        <p class="helper-text">
                            續點目標為 <?= e((string) $targetPeriod['service_year']) ?> 年度，
                            開燈日 <?= e($targetPeriod['blessing_start_date']) ?>，
                            謝燈日 <?= e($targetPeriod['blessing_end_date']) ?>。
                        </p>
                    <?php else: ?>
                        <p class="helper-text">尚未建立下一年度燈期，暫時無法續點。</p>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($dependents === []): ?>
                <p class="helper-text">請先到會員中心新增本人或眷屬資料。</p>
                <a class="button" href="profile.php">前往會員中心</a>
            <?php else: ?>
                <form class="filter-form" method="get" action="renewal_advisor.php">
                    <select name="dependent_id">
                        <?php foreach ($dependents as $dependent): ?>
                            <option value="<?= (int) $dependent['dependent_id'] ?>" <?= (int) $dependent['dependent_id'] === $selectedDependentId ? 'selected' : '' ?>>
                                <?= e($dependent['name'] . ($dependent['relationship'] ? ' / ' . $dependent['relationship'] : '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button class="button" type="submit">查看續點</button>
                </form>
            <?php endif; ?>
        </section>

        <?php if ($selectedDependent): ?>
            <section class="split-grid section-gap">
                <section class="panel">
                    <h2>原本點燈紀錄</h2>
                    <?php if ($previousItems === []): ?>
                        <p class="helper-text">此祈福對象目前沒有可續點的已安燈紀錄。</p>
                    <?php else: ?>
                        <p class="helper-text">預設會沿用原本燈種與原燈位，只延長到下一年度謝燈日。</p>
                        <div class="stack-list">
                            <?php foreach ($previousItems as $item): ?>
                                <article class="list-item">
                                    <h3><?= e($item['current_lantern_name']) ?></h3>
                                    <p>原燈位：<?= e($item['position_code']) ?>，原年度：<?= e((string) $item['service_year']) ?></p>
                                    <p class="helper-text">目前到期日：<?= e((string) ($item['blessing_end_date'] ?? '-')) ?></p>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="panel">
                    <h2>系統推薦</h2>
                    <?php if ($recommendations === []): ?>
                        <p class="helper-text">下一年度流年規則目前沒有產生推薦燈種。</p>
                    <?php else: ?>
                        <p class="helper-text">推薦只作為參考，不會自動套用。按下套用後才會改成推薦燈種。</p>
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
                        <a class="button secondary" href="renewal_advisor.php?<?= e(http_build_query(['dependent_id' => $selectedDependentId, 'mode' => 'recommended'])) ?>">套用系統推薦燈種</a>
                    <?php endif; ?>
                </section>
            </section>

            <section class="panel section-gap">
                <div class="section-heading">
                    <div>
                        <h2><?= $selectionMode === 'recommended' ? '確認系統推薦燈種' : '確認沿用原本燈位' ?></h2>
                        <p class="helper-text">
                            <?= $selectionMode === 'recommended'
                                ? '目前是推薦模式，會依下一年度空位分配燈位。'
                                : '目前是原燈位模式，會保留原燈位並延長點燈期限。' ?>
                        </p>
                    </div>
                    <?php if ($selectionMode === 'recommended'): ?>
                        <a class="button secondary" href="renewal_advisor.php?<?= e(http_build_query(['dependent_id' => $selectedDependentId, 'mode' => 'original'])) ?>">改回原本燈位</a>
                    <?php endif; ?>
                </div>

                <form class="compact-form" method="post" action="renewal_advisor.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="dependent_id" value="<?= (int) $selectedDependent['dependent_id'] ?>">
                    <input type="hidden" name="selection_mode" value="<?= e($selectionMode) ?>">

                    <div class="role-checkbox-grid">
                        <?php if ($selectionMode === 'original'): ?>
                            <?php foreach ($previousItems as $item): ?>
                                <label class="checkbox-row">
                                    <input type="checkbox" name="source_detail_ids[]" value="<?= (int) $item['detail_id'] ?>" checked>
                                    <?= e($item['current_lantern_name']) ?> / 原燈位 <?= e($item['position_code']) ?> / 續到 <?= e((string) ($targetPeriod['blessing_end_date'] ?? '-')) ?>
                                </label>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <?php foreach ($allLanternTypes as $lanternType): ?>
                                <?php
                                $typeId = (int) $lanternType['type_id'];
                                $available = (int) ($nextYearBreakdown[$typeId]['available_count'] ?? 0);
                                ?>
                                <label class="checkbox-row">
                                    <input
                                        type="checkbox"
                                        name="type_ids[]"
                                        value="<?= $typeId ?>"
                                        <?= in_array($typeId, $recommendedTypeIds, true) ? 'checked' : '' ?>
                                        <?= $available <= 0 ? 'disabled' : '' ?>
                                    >
                                    <?= e($lanternType['name']) ?> / NT$ <?= e(number_format((float) $lanternType['price'])) ?> / 下一年度可用 <?= $available ?> 位
                                </label>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <button class="button" type="submit" <?= ($targetPeriod === null || ($selectionMode === 'original' && $previousItems === [])) ? 'disabled' : '' ?>>
                        加入購物車
                    </button>
                </form>
            </section>
        <?php endif; ?>
    </main>
</body>
</html>
