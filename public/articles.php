<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/frontend.php';

$user = current_user();
$filter = trim((string) ($_GET['type'] ?? 'all'));
$validFilters = ['all', 'announcement', 'blog', 'temple_history', 'deity_intro', 'lantern_intro'];
if (!in_array($filter, $validFilters, true)) {
    $filter = 'all';
}

$where = $filter === 'all' ? 'status = "published"' : 'status = "published" AND article_type = ?';
$stmt = db()->prepare(
    "SELECT article_id, article_type, title, content, published_at
     FROM articles
     WHERE $where
     ORDER BY published_at DESC, article_id DESC"
);
$stmt->execute($filter === 'all' ? [] : [$filter]);
$articles = $stmt->fetchAll();
?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>公告文化</title>
    <link rel="stylesheet" href="../assets/css/main.css">
</head>
<body>
    <header class="site-header">
        <h1>公告文化</h1>
        <?= public_nav($user) ?>
    </header>

    <main class="page">
        <section class="hero temple-hero">
            <h2>公告與文化內容</h2>
            <p>瀏覽最新公告、廟宇歷史、神明介紹、民俗慶典與燈種介紹。</p>
        </section>

        <div class="filter-row">
            <?php foreach ($validFilters as $type): ?>
                <a class="status-pill <?= $filter === $type ? 'active' : '' ?>" href="articles.php?type=<?= e($type) ?>">
                    <?= e($type === 'all' ? '全部' : article_type_label($type)) ?>
                </a>
            <?php endforeach; ?>
        </div>

        <section class="panel section-gap">
            <?php if ($articles === []): ?>
                <p class="helper-text">目前尚無符合條件的文章。</p>
            <?php else: ?>
                <div class="stack-list">
                    <?php foreach ($articles as $article): ?>
                        <article class="list-item">
                            <div class="section-heading">
                                <div>
                                    <h2><?= e($article['title']) ?></h2>
                                    <p class="helper-text">
                                        <?= e(article_type_label($article['article_type'])) ?> / <?= e($article['published_at'] ?? '') ?>
                                    </p>
                                </div>
                                <a href="article.php?id=<?= (int) $article['article_id'] ?>">閱讀全文</a>
                            </div>
                            <p><?= e(mb_substr(strip_tags($article['content']), 0, 140)) ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
