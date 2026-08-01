<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/frontend.php';

$user = current_user();

$stmt = db()->query(
    'SELECT article_id, article_type, title, content, published_at
     FROM articles
     WHERE status = "published"
     ORDER BY published_at DESC, article_id DESC
     LIMIT 4'
);
$articles = $stmt->fetchAll();

$stmt = db()->query(
    'SELECT lt.type_id, lt.name, lt.description, lt.price
     FROM lantern_types lt
     WHERE lt.is_active = 1
     ORDER BY lt.sort_order, lt.type_id
     LIMIT 5'
);
$lanterns = $stmt->fetchAll();
$availableCounts = available_lamp_counts_by_type(array_map(static fn (array $lantern): int => (int) $lantern['type_id'], $lanterns));
foreach ($lanterns as &$lantern) {
    $lantern['available_count'] = $availableCounts[(int) $lantern['type_id']] ?? 0;
}
unset($lantern);
?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>線上點燈系統</title>
    <link rel="stylesheet" href="../assets/css/main.css">
</head>
<body>
    <header class="site-header">
        <h1>線上點燈系統</h1>
        <?= public_nav($user) ?>
    </header>

    <main class="page">
        <section class="hero temple-hero">
            <h2>便捷祈福新選擇</h2>
            <p>提供會員資料、祈福名冊、燈種選擇、購物車與訂單紀錄查詢，讓點燈流程可以清楚留下資料庫紀錄。</p>
            <a class="button" href="lanterns.php">前往點燈大廳</a>
            <a class="button secondary" href="lamp_wall.php">查看實體燈位牆</a>
        </section>

        <section class="section-gap split-grid">
            <div class="panel">
                <h2>廟宇介紹</h2>
                <p>本系統依照線上點燈架構設計，展示廟宇文化、神明典故、公告與燈種建議，並支援前台會員點燈與後台資料維護。</p>
                <p>目前先完成可驗收主流程：註冊登入、祈福人名冊、燈種瀏覽、購物車、訂單查詢與問題回饋。</p>
            </div>

            <div class="panel">
                <h2>最新公告</h2>
                <?php if ($articles === []): ?>
                    <p class="helper-text">目前尚無公告。</p>
                <?php else: ?>
                    <div class="stack-list">
                        <?php foreach ($articles as $article): ?>
                            <article class="list-item">
                                <h3><a href="article.php?id=<?= (int) $article['article_id'] ?>"><?= e($article['title']) ?></a></h3>
                                <p><?= e(text_excerpt(strip_tags($article['content']), 80)) ?></p>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <p><a href="articles.php">查看全部公告文化</a></p>
            </div>
        </section>

        <section class="panel section-gap">
            <div class="section-heading">
                <h2>燈種入口</h2>
                <a href="lanterns.php">查看全部</a>
            </div>

            <div class="card-grid">
                <?php foreach ($lanterns as $lantern): ?>
                    <article class="feature-card">
                        <h3><?= e($lantern['name']) ?></h3>
                        <p><?= e(text_excerpt((string) $lantern['description'], 0, 72)) ?></p>
                        <div class="meta-row">
                            <span>NT$ <?= e(number_format((float) $lantern['price'])) ?></span>
                            <span>剩餘 <?= (int) $lantern['available_count'] ?> 位</span>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    </main>
</body>
</html>
