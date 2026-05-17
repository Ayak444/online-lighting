<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/frontend.php';

$user = current_user();
$errors = [];
$flash = flash_message();
$selectedDependentId = (int) ($_GET['dependent_id'] ?? ($_POST['dependent_id'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($user === null) {
        redirect('login.php');
    }

    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors[] = '表單已過期，請重新送出。';
    }

    $typeId = (int) ($_POST['type_id'] ?? 0);
    $dependentId = (int) ($_POST['dependent_id'] ?? 0);
    $userId = (int) $user['user_id'];

    if ($errors === []) {
        $stmt = db()->prepare('SELECT type_id, name FROM lantern_types WHERE type_id = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$typeId]);
        $lantern = $stmt->fetch();

        if (!$lantern) {
            $errors[] = '找不到此燈種。';
        }

        $stmt = db()->prepare('SELECT dependent_id, name FROM dependents WHERE dependent_id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$dependentId, $userId]);
        $dependent = $stmt->fetch();

        if (!$dependent) {
            $errors[] = '請選擇有效的祈福對象。';
        }
    }

    if ($errors === []) {
        $pdo = db();
        $pdo->beginTransaction();

        try {
            $cartId = ensure_cart_id($userId);
            $stmt = $pdo->prepare(
                'INSERT INTO cart_items (cart_id, type_id, dependent_id, prayer_wish)
                 VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([
                $cartId,
                $typeId,
                $dependentId,
                trim((string) ($_POST['prayer_wish'] ?? '')) ?: null,
            ]);

            $cartItemId = (int) $pdo->lastInsertId();
            $reservation = reserve_lamp_position_for_cart_item($pdo, $cartItemId, $typeId, $userId);
            if ($reservation === null) {
                throw new RuntimeException('此燈種目前沒有可保留的燈位，請改選其他燈種或稍後再試。');
            }

            audit_log($userId, 'cart_item_create', 'cart_items', (string) $cartItemId, null, [
                'type_id' => $typeId,
                'dependent_id' => $dependentId,
                'reservation_id' => (int) $reservation['reservation_id'],
                'position_code' => $reservation['position_code'],
                'expires_at' => $reservation['expires_at'],
            ]);

            $pdo->commit();
            set_flash('已加入購物車，並為您保留燈位 1 小時。');
            redirect('cart.php');
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $errors[] = $throwable instanceof RuntimeException ? $throwable->getMessage() : '加入購物車失敗，請稍後再試。';
        }
    }
}

$stmt = db()->query(
    'SELECT lt.type_id, lt.slug, lt.name, lt.description, lt.blessing_description, lt.recommended_for, lt.not_recommended_for,
            lt.price, lt.image_path
     FROM lantern_types lt
     WHERE lt.is_active = 1
     ORDER BY lt.sort_order, lt.type_id'
);
$lanterns = $stmt->fetchAll();
$availableCounts = available_lamp_counts_by_type(array_map(static fn (array $lantern): int => (int) $lantern['type_id'], $lanterns));
foreach ($lanterns as &$lantern) {
    $lantern['available_count'] = $availableCounts[(int) $lantern['type_id']] ?? 0;
}
unset($lantern);

$dependents = [];
$selectedDependent = null;
$recommendationsByType = [];
$recommendationReasonsByType = [];
$serviceYear = (int) date('Y');
$activePeriod = active_lamp_service_period();
if ($activePeriod !== null) {
    $serviceYear = (int) $activePeriod['service_year'];
}

if ($user !== null) {
    $stmt = db()->prepare(
        'SELECT dependent_id, name, relationship, gender, birthday, birth_clock_time, birth_time, lunar_birthday, zodiac
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
    $stmt->execute([(int) $user['user_id']]);
    $dependents = $stmt->fetchAll();

    if ($selectedDependentId <= 0 && count($dependents) === 1) {
        $selectedDependentId = (int) $dependents[0]['dependent_id'];
    }

    foreach ($dependents as $dependent) {
        if ((int) $dependent['dependent_id'] === $selectedDependentId) {
            $selectedDependent = $dependent;
            break;
        }
    }

    if ($selectedDependent !== null) {
        foreach (annual_flow_recommendations($serviceYear, $selectedDependent) as $recommendation) {
            $typeId = (int) $recommendation['type_id'];
            $recommendationsByType[$typeId] = $recommendation;
            $recommendationReasonsByType[$typeId] = $recommendation['reasons'];
        }
    }
}

function lantern_general_choice_label(string $slug): ?string
{
    return [
        'jixiang' => '可依需求選擇',
        'pingan' => '全戶平安祈福',
    ][$slug] ?? null;
}

function dependent_birth_profile_missing(array $dependent): array
{
    $missing = [];
    if (empty($dependent['birthday'])) {
        $missing[] = '西元生日';
    }
    if (empty($dependent['birth_time'])) {
        $missing[] = '出生時辰';
    }
    if (empty($dependent['zodiac'])) {
        $missing[] = '生肖';
    }
    if (empty($dependent['lunar_birthday'])) {
        $missing[] = '農曆生日';
    }

    return $missing;
}
?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>點燈大廳</title>
    <link rel="stylesheet" href="../assets/css/main.css">
</head>
<body>
    <header class="site-header">
        <h1>點燈大廳</h1>
        <?= public_nav($user) ?>
    </header>

    <main class="page">
        <h1>選擇祈福對象與推薦燈種</h1>

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

        <?php if ($user === null): ?>
            <section class="panel">
                <div class="section-heading">
                    <div>
                        <h2>先登入會員</h2>
                        <p class="helper-text">登入後才能讀取本人與眷屬資料，系統才可以依生辰資料標出推薦燈種。</p>
                    </div>
                    <a class="button" href="login.php">登入後點燈</a>
                </div>
            </section>
        <?php elseif ($dependents === []): ?>
            <div class="alert">
                <p>請先到會員中心新增祈福對象，再加入購物車。</p>
                <a href="profile.php">前往會員中心</a>
            </div>
        <?php else: ?>
            <section class="panel">
                <div class="section-heading">
                    <div>
                        <h2>1. 選擇要為誰點燈</h2>
                        <p class="helper-text">系統會使用此人的西元生日、出生時間、自動換算出的生肖、農曆生日與時辰，套用 <?= e((string) $serviceYear) ?> 年流年規則。</p>
                    </div>
                </div>

                <form class="target-picker-form" method="get" action="lanterns.php">
                    <label>
                        祈福對象
                        <select name="dependent_id" required>
                            <option value="">請選擇祈福對象</option>
                            <?php foreach ($dependents as $dependent): ?>
                                <option value="<?= (int) $dependent['dependent_id'] ?>" <?= (int) $dependent['dependent_id'] === $selectedDependentId ? 'selected' : '' ?>>
                                    <?= e($dependent['name'] . ($dependent['relationship'] ? '（' . $dependent['relationship'] . '）' : '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button class="button" type="submit">查看推薦燈種</button>
                    <a class="button secondary" href="profile.php">新增或修改祈福對象</a>
                </form>

                <?php if ($selectedDependent !== null): ?>
                    <?php $missingBirthFields = dependent_birth_profile_missing($selectedDependent); ?>
                    <div class="target-summary">
                        <span>目前對象：<?= e($selectedDependent['name']) ?></span>
                        <span>西元生日：<?= e($selectedDependent['birthday'] ?? '未填') ?></span>
                        <span>農曆生日：<?= e($selectedDependent['lunar_birthday'] ?? '未換算') ?></span>
                        <span>生肖：<?= e($selectedDependent['zodiac'] ?? '未換算') ?></span>
                        <span>時辰：<?= e($selectedDependent['birth_time'] ?? '未換算') ?></span>
                    </div>
                    <?php if ($missingBirthFields !== []): ?>
                        <div class="alert">
                            <p>此祈福對象缺少 <?= e(implode('、', $missingBirthFields)) ?>，系統推薦可能不完整。請到會員中心補齊生日與出生時間。</p>
                        </div>
                    <?php elseif ($recommendationsByType === []): ?>
                        <p class="helper-text">目前流年規則沒有對此對象產生特定推薦；仍可依祈福需求自行選燈。</p>
                    <?php else: ?>
                        <p class="helper-text">已依此對象的生辰衍生資料標記「系統推薦」燈種，仍可依自身需求調整選擇。</p>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="helper-text">請先選擇祈福對象，下方燈種會顯示用途，但暫不開放加入購物車。</p>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <h2 class="section-title">2. 選擇燈種</h2>
        <div class="card-grid lantern-grid">
            <?php foreach ($lanterns as $lantern): ?>
                <?php
                $typeId = (int) $lantern['type_id'];
                $isRecommended = isset($recommendationsByType[$typeId]);
                $generalLabel = lantern_general_choice_label((string) $lantern['slug']);
                ?>
                <article class="feature-card lantern-card">
                    <div class="lantern-symbol"><?= e(mb_substr($lantern['name'], 0, 1)) ?></div>
                    <div class="lantern-card-title">
                        <h2><?= e($lantern['name']) ?></h2>
                        <?php if ($isRecommended): ?>
                            <span class="recommend-badge">系統推薦</span>
                        <?php elseif ($generalLabel !== null): ?>
                            <span class="choice-badge"><?= e($generalLabel) ?></span>
                        <?php elseif ($selectedDependent !== null): ?>
                            <span class="neutral-badge">可自行選擇</span>
                        <?php endif; ?>
                    </div>
                    <p><?= e($lantern['description']) ?></p>
                    <p><strong>祈福功德：</strong><?= e($lantern['blessing_description']) ?></p>
                    <p><strong>建議對象：</strong><?= e($lantern['recommended_for']) ?></p>
                    <?php if ($lantern['not_recommended_for']): ?>
                        <p><strong>不推薦：</strong><?= e($lantern['not_recommended_for']) ?></p>
                    <?php endif; ?>
                    <div class="meta-row">
                        <span>NT$ <?= e(number_format((float) $lantern['price'])) ?></span>
                        <span>剩餘 <?= (int) $lantern['available_count'] ?> 位</span>
                    </div>

                    <?php if ($isRecommended): ?>
                        <div class="recommend-reasons">
                            <?php foreach (array_slice($recommendationReasonsByType[$typeId] ?? [], 0, 2) as $reason): ?>
                                <p><?= e($reason) ?></p>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($user === null): ?>
                        <a class="button" href="login.php">登入後點燈</a>
                    <?php else: ?>
                        <form class="compact-form" method="post" action="lanterns.php">
                            <?= csrf_field() ?>
                            <input type="hidden" name="type_id" value="<?= (int) $lantern['type_id'] ?>">
                            <input type="hidden" name="dependent_id" value="<?= (int) ($selectedDependent['dependent_id'] ?? 0) ?>">

                            <label>
                                願望備註
                                <input type="text" name="prayer_wish" placeholder="<?= $selectedDependent ? '例如：平安順利' : '請先選擇祈福對象' ?>" <?= $selectedDependent === null ? 'disabled' : '' ?>>
                            </label>

                            <button class="button" type="submit" <?= $selectedDependent === null ? 'disabled' : '' ?>>
                                <?= $isRecommended ? '加入推薦燈種' : '加入購物車' ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    </main>
</body>
</html>
