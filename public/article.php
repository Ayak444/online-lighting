<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/frontend.php';

$user = current_user();
$articleId = (int) ($_GET['id'] ?? 0);

$stmt = db()->prepare(
    'SELECT article_id, article_type, title, content, published_at
     FROM articles
     WHERE article_id = ? AND status = "published"
     LIMIT 1'
);
$stmt->execute([$articleId]);
$article = $stmt->fetch();

if (!$article) {
    http_response_code(404);
}
?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $article ? e($article['title']) : '找不到文章' ?></title>
    <link rel="stylesheet" href="../assets/css/main.css">
</head>
<body>
    <header class="site-header">
        <h1>公告文化</h1>
        <?= public_nav($user) ?>
    </header>

    <main class="page">
        <?php if (!$article): ?>
            <section class="panel">
                <h1>找不到文章</h1>
                <p class="helper-text">此文章不存在或尚未發布。</p>
                <a class="button" href="articles.php">回公告文化</a>
            </section>
        <?php else: ?>
            <article class="panel article-detail">
                <div class="section-heading">
                    <div>
                        <h1><?= e($article['title']) ?></h1>
                        <p class="helper-text">
                            <?= e(article_type_label($article['article_type'])) ?> / <?= e($article['published_at'] ?? '') ?>
                        </p>
                    </div>
                    <a href="articles.php?type=<?= e($article['article_type']) ?>">同類文章</a>
                </div>
                <div class="article-body">
                    <?= nl2br(e($article['content'])) ?>
                </div>
            </article>
        <?php endif; ?>
    </main>
</body>
</html>
