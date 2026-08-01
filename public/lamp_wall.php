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
       AND lp.status <> "retired"
     ORDER BY lp.area ASC, lp.row_no ASC, lp.col_no ASC, lp.position_code ASC'
);
$stmt->execute([$selectedTypeId]);
$positions = $stmt->fetchAll();

$areas = [];
$visualLamps = [];
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

    $side = $colNo % 2 === 1 ? 'left' : 'right';
    $depth = (int) ceil($colNo / 2);
    $position['visual_side'] = $side;
    $position['visual_depth'] = $depth;
    $position['visual_level'] = $rowNo <= 1 ? '上層' : ($rowNo === 2 ? '中層' : '下層');
    $position['visual_distance'] = $depth <= 1 ? '近側' : ($depth === 2 ? '中段' : '靠近主神');
    $visualLamps[] = $position;
}

usort($visualLamps, static function (array $a, array $b): int {
    return [$a['visual_depth'], $a['row_no'], $a['col_no'], $a['position_code']]
        <=> [$b['visual_depth'], $b['row_no'], $b['col_no'], $b['position_code']];
});

$leftVisualLamps = array_values(array_filter($visualLamps, static fn(array $lamp): bool => $lamp['visual_side'] === 'left'));
$rightVisualLamps = array_values(array_filter($visualLamps, static fn(array $lamp): bool => $lamp['visual_side'] === 'right'));

$maxVisualDepth = 1;
foreach ($visualLamps as $lamp) {
    $maxVisualDepth = max($maxVisualDepth, (int) $lamp['visual_depth']);
}

$leftVisualRows = [];
$rightVisualRows = [];
for ($depth = $maxVisualDepth; $depth >= 1; $depth--) {
    $leftVisualRows[$depth] = array_values(array_filter($leftVisualLamps, static fn(array $lamp): bool => (int) $lamp['visual_depth'] === $depth));
    $rightVisualRows[$depth] = array_values(array_filter($rightVisualLamps, static fn(array $lamp): bool => (int) $lamp['visual_depth'] === $depth));
}
?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>視覺化燈位牆</title>
    <link rel="stylesheet" href="../assets/css/main.css?v=20260607-lamp-gui">
    <style>
        .temple-gui {
            position: relative;
            min-height: 620px;
            overflow: hidden;
            padding: 24px;
            border: 1px solid #d6b77a;
            border-radius: 8px;
            background:
                radial-gradient(circle at 50% 10%, rgba(255, 223, 135, 0.48), transparent 24%),
                linear-gradient(180deg, #4c241c 0%, #8a4e2a 28%, #f5ead4 76%, #fffaf0 100%);
            box-shadow: inset 0 0 0 1px rgba(255, 244, 210, 0.45);
        }

        .temple-gui::before,
        .temple-gui::after {
            content: "";
            position: absolute;
            top: 128px;
            bottom: 22px;
            width: 34%;
            background: repeating-linear-gradient(
                0deg,
                rgba(255, 196, 67, 0.16) 0 12px,
                rgba(86, 46, 24, 0.08) 12px 20px
            );
            opacity: 0.72;
            pointer-events: none;
        }

        .temple-gui::before {
            left: 18px;
            transform: skewY(7deg);
            transform-origin: right top;
        }

        .temple-gui::after {
            right: 18px;
            transform: skewY(-7deg);
            transform-origin: left top;
        }

        .temple-deity {
            position: relative;
            z-index: 2;
            display: grid;
            place-items: center;
            width: min(320px, 70%);
            min-height: 120px;
            margin: 0 auto 26px;
            color: #fff7d8;
            text-align: center;
            border: 2px solid rgba(255, 213, 95, 0.9);
            border-radius: 999px 999px 18px 18px;
            background: radial-gradient(circle at 50% 24%, #ffe37b 0 8%, #a94425 42%, #371711 100%);
            box-shadow: 0 0 42px rgba(255, 203, 73, 0.7), 0 18px 40px rgba(42, 16, 10, 0.38);
        }

        .temple-deity span {
            font-size: 14px;
        }

        .temple-deity strong {
            font-size: 30px;
        }

        .temple-gui-grid {
            position: relative;
            z-index: 2;
            display: grid;
            grid-template-columns: minmax(220px, 1fr) minmax(180px, 0.56fr) minmax(220px, 1fr);
            gap: 20px;
            align-items: stretch;
        }

        .temple-aisle {
            position: relative;
            min-height: 430px;
            border-right: 1px solid rgba(111, 69, 37, 0.28);
            border-left: 1px solid rgba(111, 69, 37, 0.28);
            background: linear-gradient(180deg, rgba(255,255,255,0.12), rgba(255,255,255,0.84));
            clip-path: polygon(28% 0, 72% 0, 100% 100%, 0 100%);
        }

        .temple-aisle span {
            position: absolute;
            left: 50%;
            width: max-content;
            transform: translateX(-50%);
            padding: 5px 10px;
            color: #6f4429;
            font-size: 13px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.8);
        }

        .temple-aisle .far {
            top: 18px;
        }

        .temple-aisle .near {
            bottom: 18px;
        }

        .temple-lamp-side {
            display: grid;
            align-content: stretch;
            gap: 12px;
            padding: 14px;
            border-radius: 8px;
            background: rgba(42, 22, 13, 0.24);
            box-shadow: inset 0 0 24px rgba(31, 15, 8, 0.2);
        }

        .temple-depth-row {
            display: grid;
            grid-template-columns: 66px 1fr;
            gap: 8px;
            align-items: stretch;
        }

        .depth-label {
            display: grid;
            place-items: center;
            min-height: 84px;
            padding: 6px;
            color: #fff2ca;
            font-size: 12px;
            text-align: center;
            border: 1px solid rgba(255, 222, 142, 0.35);
            border-radius: 8px;
            background: rgba(72, 35, 20, 0.5);
        }

        .depth-lamps {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(62px, 1fr));
            gap: 8px;
        }

        .temple-lamp-side.left {
            transform: perspective(900px) rotateY(11deg);
            transform-origin: right center;
        }

        .temple-lamp-side.right {
            transform: perspective(900px) rotateY(-11deg);
            transform-origin: left center;
        }

        .gui-lamp {
            position: relative;
            display: grid;
            place-items: center;
            gap: 4px;
            min-height: 84px;
            padding: 8px 6px;
            color: #3a2418;
            text-align: center;
            border: 1px solid rgba(121, 73, 35, 0.32);
            border-radius: 8px;
            background: rgba(255, 242, 204, 0.88);
            box-shadow: 0 10px 18px rgba(45, 24, 12, 0.2);
        }

        .gui-lamp::before {
            content: "";
            width: 30px;
            height: 36px;
            border-radius: 999px 999px 9px 9px;
            background: radial-gradient(circle at 50% 22%, #fffbd0 0 20%, #ffc640 48%, #b86617 100%);
            box-shadow: 0 0 20px rgba(255, 195, 54, 0.85);
        }

        .gui-lamp strong {
            font-size: 12px;
            line-height: 1.1;
        }

        .gui-lamp small {
            color: #765945;
            font-size: 11px;
            line-height: 1.25;
        }

        .gui-lamp em {
            max-width: 100%;
            overflow: hidden;
            color: #5b4334;
            font-size: 11px;
            font-style: normal;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .gui-lamp.available {
            background: rgba(235, 255, 239, 0.9);
            border-color: #97cfaa;
        }

        .gui-lamp.reserved {
            background: rgba(255, 247, 216, 0.94);
            border-color: #dfc86f;
        }

        .gui-lamp.occupied {
            background: rgba(255, 235, 230, 0.94);
            border-color: #dfa095;
        }

        .gui-lamp.maintenance::before {
            filter: grayscale(0.9);
            opacity: 0.52;
        }

        .gui-lamp.maintenance {
            background: rgba(255, 247, 216, 0.94);
            border-color: #dfc86f;
        }

        @media (max-width: 760px) {
            .temple-gui-grid {
                grid-template-columns: 1fr;
            }

            .temple-lamp-side.left,
            .temple-lamp-side.right {
                transform: none;
            }

            .temple-depth-row {
                grid-template-columns: 1fr;
            }

            .temple-aisle {
                min-height: 120px;
            }
        }
    </style>
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
            <section class="panel section-gap temple-wall-panel">
                <div class="section-heading">
                    <div>
                        <h2>廟內光明燈 GUI</h2>
                        <p class="helper-text">上方是主神神龕，中間是參拜通道，左右兩側以燈泡圖示呈現每個實體燈位。</p>
                    </div>
                </div>

                <div class="temple-gui" aria-label="廟內光明燈視覺化 GUI">
                    <div class="temple-deity">
                        <span>主神位置</span>
                        <strong>神龕</strong>
                    </div>

                    <div class="temple-gui-grid">
                        <div class="temple-lamp-side left" aria-label="左側光明燈牆">
                            <?php foreach ($leftVisualRows as $depth => $rowLamps): ?>
                                <?php if ($rowLamps === []) { continue; } ?>
                                <div class="temple-depth-row">
                                    <span class="depth-label"><?= $depth === $maxVisualDepth ? '靠近主神' : ($depth === 1 ? '入口近側' : '中段') ?></span>
                                    <div class="depth-lamps">
                                        <?php foreach ($rowLamps as $lamp): ?>
                                            <article class="gui-lamp <?= e($lamp['effective_status']) ?>" title="<?= e($lamp['position_code'] . ' / ' . $lamp['visual_distance'] . ' / ' . $lamp['visual_level']) ?>">
                                                <strong><?= e($lamp['position_code']) ?></strong>
                                                <small><?= e($lamp['visual_distance']) ?> · <?= e($lamp['visual_level']) ?></small>
                                                <?php if ($lamp['effective_status'] === 'occupied' && $lamp['dependent_name_snapshot']): ?>
                                                    <em><?= e($lamp['dependent_name_snapshot']) ?></em>
                                                <?php else: ?>
                                                    <em><?= e(lamp_position_status_label($lamp['effective_status'])) ?></em>
                                                <?php endif; ?>
                                            </article>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="temple-aisle" aria-label="參拜通道">
                            <span class="far">靠近主神</span>
                            <span class="near">入口方向</span>
                        </div>

                        <div class="temple-lamp-side right" aria-label="右側光明燈牆">
                            <?php foreach ($rightVisualRows as $depth => $rowLamps): ?>
                                <?php if ($rowLamps === []) { continue; } ?>
                                <div class="temple-depth-row">
                                    <span class="depth-label"><?= $depth === $maxVisualDepth ? '靠近主神' : ($depth === 1 ? '入口近側' : '中段') ?></span>
                                    <div class="depth-lamps">
                                        <?php foreach ($rowLamps as $lamp): ?>
                                            <article class="gui-lamp <?= e($lamp['effective_status']) ?>" title="<?= e($lamp['position_code'] . ' / ' . $lamp['visual_distance'] . ' / ' . $lamp['visual_level']) ?>">
                                                <strong><?= e($lamp['position_code']) ?></strong>
                                                <small><?= e($lamp['visual_distance']) ?> · <?= e($lamp['visual_level']) ?></small>
                                                <?php if ($lamp['effective_status'] === 'occupied' && $lamp['dependent_name_snapshot']): ?>
                                                    <em><?= e($lamp['dependent_name_snapshot']) ?></em>
                                                <?php else: ?>
                                                    <em><?= e(lamp_position_status_label($lamp['effective_status'])) ?></em>
                                                <?php endif; ?>
                                            </article>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </section>

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
                                        <?php if ($slot['effective_status'] === 'occupied' && $slot['dependent_name_snapshot']): ?>
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
