<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/frontend.php';
require_once __DIR__ . '/../config/birth_profile.php';

$user = current_user();
$activePeriod = active_lamp_service_period();
$serviceYear = $activePeriod ? (int) $activePeriod['service_year'] : (int) date('Y');
$serviceYearLabel = $serviceYear === 2026 ? '2026 丙午年' : $serviceYear . ' 年';

function annual_flow_age_list(int $serviceYear, string $zodiac): string
{
    $ages = [];
    for ($age = 1; $age <= 96; $age++) {
        $lunarBirthYear = $serviceYear - $age + 1;
        if (birth_profile_zodiac_for_lunar_year($lunarBirthYear) === $zodiac) {
            $ages[] = $age;
        }
    }

    return implode('、', $ages);
}

$stmt = db()->prepare(
    'SELECT *
     FROM annual_flow_entries
     WHERE service_year = ?
       AND is_active = 1
     ORDER BY zodiac_order ASC, entry_id ASC'
);
$stmt->execute([$serviceYear]);
$entries = $stmt->fetchAll();

$stmt = db()->prepare(
    'SELECT afr.match_values AS zodiac, lt.name AS lantern_name
     FROM annual_flow_rules afr
     INNER JOIN lantern_types lt ON lt.type_id = afr.type_id
     WHERE afr.service_year = ?
       AND afr.is_active = 1
       AND afr.match_field = "zodiac"
       AND afr.rule_name LIKE "FLOW_TABLE_%"
     ORDER BY afr.priority ASC, lt.sort_order ASC, afr.rule_id ASC'
);
$stmt->execute([$serviceYear]);
$ruleRows = $stmt->fetchAll();

$lanternsByZodiac = [];
foreach ($ruleRows as $row) {
    $zodiac = (string) $row['zodiac'];
    $lanternsByZodiac[$zodiac][] = (string) $row['lantern_name'];
}
?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>流年表</title>
    <link rel="stylesheet" href="../assets/css/main.css">
</head>
<body>
    <header class="site-header">
        <h1>流年表</h1>
        <?= public_nav($user) ?>
    </header>

    <main class="page">
        <section class="hero temple-hero">
            <h2><?= e($serviceYearLabel) ?>流年歲運表</h2>
            <p>依今年生肖歲運整理年齡、注意事項、附註與建議燈種，提供智慧續燈與前台查詢使用。</p>
        </section>

        <?php if ($entries === []): ?>
            <section class="panel section-gap">
                <p class="helper-text">目前尚未匯入可顯示的流年表資料。</p>
            </section>
        <?php else: ?>
            <section class="panel section-gap">
                <div class="section-heading">
                    <div>
                        <h2>今年流年表</h2>
                        <p class="helper-text">只保留目前啟用年度，不再顯示或維護過去年份流年表；系統推薦只列入目前可點的燈種。</p>
                    </div>
                </div>

                <div class="table-wrap">
                    <table class="data-table flow-table-detail">
                        <thead>
                            <tr>
                                <th>生肖</th>
                                <th>年齡</th>
                                <th>建議燈種</th>
                                <th>流年歲運注意事項</th>
                                <th>附註</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($entries as $entry): ?>
                                <?php
                                $zodiac = (string) $entry['zodiac_animal'];
                                $lanternNames = array_values(array_unique($lanternsByZodiac[$zodiac] ?? []));
                                ?>
                                <tr>
                                    <td>
                                        <strong><?= e($entry['zodiac_label']) ?></strong>
                                    </td>
                                    <td><?= e(annual_flow_age_list($serviceYear, $zodiac)) ?></td>
                                    <td><?= e($lanternNames === [] ? '依個人資料評估' : implode('、', $lanternNames)) ?></td>
                                    <td><?= e($entry['flow_notice']) ?></td>
                                    <td><?= e((string) ($entry['appendix'] ?? '-')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>
    </main>
</body>
</html>
