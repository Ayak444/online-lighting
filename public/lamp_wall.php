<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/frontend.php';

$user = current_user();
$selectedTypeId = (int) ($_GET['type_id'] ?? 0);

$lanternTypes = db()->query(
    'SELECT type_id, name
     FROM lantern_types
     WHERE is_active = 1
     ORDER BY sort_order ASC, type_id ASC'
)->fetchAll();

if ($selectedTypeId <= 0 && $lanternTypes !== []) {
    $selectedTypeId = (int) $lanternTypes[0]['type_id'];
}

$validTypeIds = array_map(static fn (array $type): int => (int) $type['type_id'], $lanternTypes);
if ($selectedTypeId > 0 && !in_array($selectedTypeId, $validTypeIds, true)) {
    $selectedTypeId = (int) ($validTypeIds[0] ?? 0);
}

expire_lamp_reservations();
$stmt = db()->prepare(
    'SELECT lp.*, lt.name AS lantern_name,
            oi.dependent_name_snapshot,
            oi.blessing_end_date,
            o.order_number,
            lr.reservation_id AS active_reservation_id,
            lr.expires_at AS reserved_until
     FROM lamp_positions lp
     INNER JOIN lantern_types lt ON lt.type_id = lp.type_id
     LEFT JOIN lamp_reservations lr ON lr.reservation_id = (
         SELECT lr2.reservation_id
         FROM lamp_reservations lr2
         WHERE lr2.position_id = lp.position_id
           AND lr2.status = "active"
           AND lr2.expires_at > NOW()
         ORDER BY lr2.reservation_id DESC
         LIMIT 1
     )
     LEFT JOIN order_items oi ON oi.detail_id = (
         SELECT oi2.detail_id
         FROM order_items oi2
         WHERE oi2.position_id = lp.position_id
           AND oi2.item_status IN ("assigned", "completed")
           AND (oi2.blessing_end_date IS NULL OR oi2.blessing_end_date >= CURRENT_DATE)
         ORDER BY oi2.detail_id DESC
         LIMIT 1
     )
     LEFT JOIN orders o ON o.order_id = oi.order_id
     WHERE lp.type_id = ?
     ORDER BY lp.area ASC, lp.row_no ASC, lp.col_no ASC, lp.position_code ASC'
);
$stmt->execute([$selectedTypeId]);
$positions = $stmt->fetchAll();

$areas = [];
$statusCounts = ['available' => 0, 'reserved' => 0, 'occupied' => 0, 'maintenance' => 0];
foreach ($positions as $index => $position) {
    $areaName = trim((string) ($position['area'] ?? '')) ?: '主燈牆';
    $rowNo = $position['row_no'] === null ? 1 : (int) $position['row_no'];
    $colNo = $position['col_no'] === null ? $index + 1 : (int) $position['col_no'];
    $status = $position['active_reservation_id'] !== null ? 'reserved' : (string) $position['status'];
    $position['effective_status'] = $status;
    $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;

    if (!isset($areas[$areaName])) {
        $areas[$areaName] = [
            'max_row' => 1,
            'max_col' => 1,
            'slots' => [],
        ];
    }

    $areas[$areaName]['max_row'] = max($areas[$areaName]['max_row'], $rowNo);
    $areas[$areaName]['max_col'] = max($areas[$areaName]['max_col'], $colNo);
    $areas[$areaName]['slots'][$rowNo][$colNo] = $position;
}
?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>視覺化燈位牆</title>
    <link rel="stylesheet" href="../assets/css/main.css">
</head>
<body>
    <header class="site-header">
        <h1>視覺化燈位牆</h1>
        <?= public_nav($user) ?>
    </header>

    <main class="page">
        <section class="panel">
            <div class="section-heading">
                <div>
                    <h1>實體燈位牆</h1>
                    <p class="helper-text">依燈種顯示實體座標、剩餘空位、已點燈與維修狀態。</p>
                </div>
            </div>

            <form class="filter-form" method="get" action="lamp_wall.php">
                <select name="type_id">
                    <?php foreach ($lanternTypes as $type): ?>
                        <option value="<?= (int) $type['type_id'] ?>" <?= (int) $type['type_id'] === $selectedTypeId ? 'selected' : '' ?>>
                            <?= e($type['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button class="button" type="submit">切換燈種</button>
            </form>

            <div class="lamp-wall-legend">
                <span class="legend-item available">空位 <?= (int) ($statusCounts['available'] ?? 0) ?></span>
                <span class="legend-item reserved">保留中 <?= (int) ($statusCounts['reserved'] ?? 0) ?></span>
                <span class="legend-item occupied">已點燈 <?= (int) ($statusCounts['occupied'] ?? 0) ?></span>
                <span class="legend-item maintenance">維修 <?= (int) ($statusCounts['maintenance'] ?? 0) ?></span>
            </div>
        </section>

        <?php if ($positions === []): ?>
            <section class="panel section-gap">
                <p class="helper-text">目前這個燈種尚未建立實體燈位。</p>
            </section>
        <?php else: ?>
            <?php foreach ($areas as $areaName => $area): ?>
                <section class="panel section-gap">
                    <div class="section-heading">
                        <h2><?= e($areaName) ?></h2>
                    </div>

                    <div class="lamp-wall-grid" style="--wall-cols: <?= (int) $area['max_col'] ?>;">
                        <?php for ($row = 1; $row <= (int) $area['max_row']; $row++): ?>
                            <?php for ($col = 1; $col <= (int) $area['max_col']; $col++): ?>
                                <?php $slot = $area['slots'][$row][$col] ?? null; ?>
                                <?php if ($slot === null): ?>
                                    <div class="lamp-wall-slot empty" aria-hidden="true"></div>
                                <?php else: ?>
                                    <article class="lamp-wall-slot <?= e($slot['effective_status']) ?>">
                                        <strong><?= e($slot['position_code']) ?></strong>
                                        <span><?= e(lamp_position_status_label($slot['effective_status'])) ?></span>
                                        <?php if ($slot['dependent_name_snapshot']): ?>
                                            <small><?= e($slot['dependent_name_snapshot']) ?></small>
                                        <?php elseif ($slot['effective_status'] === 'reserved'): ?>
                                            <small>保留至 <?= e($slot['reserved_until']) ?></small>
                                        <?php endif; ?>
                                    </article>
                                <?php endif; ?>
                            <?php endfor; ?>
                        <?php endfor; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>
</body>
</html>
