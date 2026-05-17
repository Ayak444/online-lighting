<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/frontend.php';

$admin = require_admin('login.php');
$adminId = (int) $admin['user_id'];
$errors = [];
$flash = flash_message();

function admin_nav(): string
{
    $links = [
        ['dashboard.php', '總覽'],
        ['lantern_types.php', '燈種'],
        ['lamp_positions.php', '燈位'],
        ['orders.php', '訂單'],
        ['service_periods.php', '年度燈期'],
        ['annual_flow_rules.php', '流年規則'],
        ['reports.php', '統計報表'],
        ['scheduled_jobs.php', '排程任務'],
        ['notifications.php', '通知中心'],
        ['users.php', '會員權限'],
        ['articles.php', '公告文化'],
        ['feedbacks.php', '回饋'],
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

function clean_post(string $key): string
{
    return trim((string) ($_POST[$key] ?? ''));
}

function nullable_post(string $key): ?string
{
    $value = clean_post($key);
    return $value === '' ? null : $value;
}

function article_form_defaults(): array
{
    return [
        'article_id' => '',
        'article_type' => 'announcement',
        'title' => '',
        'content' => '',
        'status' => 'draft',
        'published_at' => '',
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors[] = '表單已過期，請重新送出。';
    }

    $action = clean_post('action');

    if ($errors === [] && $action === 'save_article') {
        $articleId = (int) ($_POST['article_id'] ?? 0);
        $articleType = clean_post('article_type');
        $title = clean_post('title');
        $content = clean_post('content');
        $status = clean_post('status');
        $publishedAt = nullable_post('published_at');

        if (!in_array($articleType, ['announcement', 'blog', 'temple_history', 'deity_intro', 'lantern_intro'], true)) {
            $errors[] = '文章類型不正確。';
        }

        if ($title === '') {
            $errors[] = '請輸入標題。';
        }

        if ($content === '') {
            $errors[] = '請輸入內容。';
        }

        if (!in_array($status, ['draft', 'published', 'archived'], true)) {
            $errors[] = '文章狀態不正確。';
        }

        if ($publishedAt !== null && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $publishedAt) !== 1) {
            $errors[] = '發布時間格式不正確。';
        }

        $publishedAtValue = $publishedAt === null ? null : str_replace('T', ' ', $publishedAt) . ':00';
        if ($status === 'published' && $publishedAtValue === null) {
            $publishedAtValue = date('Y-m-d H:i:s');
        }

        if ($status !== 'published') {
            $publishedAtValue = null;
        }

        $payload = [
            'author_id' => $adminId,
            'article_type' => $articleType,
            'title' => $title,
            'content' => $content,
            'status' => $status,
            'published_at' => $publishedAtValue,
        ];

        if ($errors === []) {
            if ($articleId > 0) {
                $stmt = db()->prepare('SELECT * FROM articles WHERE article_id = ? LIMIT 1');
                $stmt->execute([$articleId]);
                $before = $stmt->fetch();

                if (!$before) {
                    $errors[] = '找不到要編輯的文章。';
                } else {
                    if ($status === 'published' && $publishedAtValue === null) {
                        $publishedAtValue = $before['published_at'] ?: date('Y-m-d H:i:s');
                    }

                    $stmt = db()->prepare(
                        'UPDATE articles
                         SET author_id = ?, article_type = ?, title = ?, content = ?, status = ?, published_at = ?
                         WHERE article_id = ?'
                    );
                    $stmt->execute([
                        $adminId,
                        $articleType,
                        $title,
                        $content,
                        $status,
                        $publishedAtValue,
                        $articleId,
                    ]);

                    audit_log($adminId, 'admin_article_update', 'articles', (string) $articleId, $before, $payload);
                    set_flash('文章已更新。');
                    redirect('articles.php');
                }
            } else {
                $stmt = db()->prepare(
                    'INSERT INTO articles (author_id, article_type, title, content, status, published_at)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $adminId,
                    $articleType,
                    $title,
                    $content,
                    $status,
                    $publishedAtValue,
                ]);

                $newArticleId = (int) db()->lastInsertId();
                audit_log($adminId, 'admin_article_create', 'articles', (string) $newArticleId, null, $payload);
                set_flash('文章已新增。');
                redirect('articles.php');
            }
        }
    }

    if ($errors === [] && $action === 'archive_article') {
        $articleId = (int) ($_POST['article_id'] ?? 0);
        $stmt = db()->prepare('SELECT * FROM articles WHERE article_id = ? LIMIT 1');
        $stmt->execute([$articleId]);
        $before = $stmt->fetch();

        if (!$before) {
            $errors[] = '找不到要封存的文章。';
        } else {
            $stmt = db()->prepare('UPDATE articles SET status = "archived", published_at = NULL WHERE article_id = ?');
            $stmt->execute([$articleId]);

            audit_log($adminId, 'admin_article_archive', 'articles', (string) $articleId, $before, [
                'status' => 'archived',
            ]);
            set_flash('文章已封存。');
            redirect('articles.php');
        }
    }
}

$editArticle = null;
$editId = (int) ($_GET['edit_id'] ?? 0);
if ($editId > 0) {
    $stmt = db()->prepare('SELECT * FROM articles WHERE article_id = ? LIMIT 1');
    $stmt->execute([$editId]);
    $editArticle = $stmt->fetch() ?: null;
}

$form = $editArticle ?: article_form_defaults();
$publishedAtForInput = '';
if (!empty($form['published_at'])) {
    $publishedAtForInput = str_replace(' ', 'T', substr((string) $form['published_at'], 0, 16));
}

$filter = trim((string) ($_GET['type'] ?? 'all'));
$validFilters = ['all', 'announcement', 'blog', 'temple_history', 'deity_intro', 'lantern_intro'];
if (!in_array($filter, $validFilters, true)) {
    $filter = 'all';
}

$where = $filter === 'all' ? '1 = 1' : 'article_type = ?';
$stmt = db()->prepare(
    "SELECT a.*, u.name AS author_name
     FROM articles a
     LEFT JOIN users u ON u.user_id = a.author_id
     WHERE $where
     ORDER BY a.updated_at DESC, a.article_id DESC"
);
$stmt->execute($filter === 'all' ? [] : [$filter]);
$articles = $stmt->fetchAll();
?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>公告文化管理</title>
    <link rel="stylesheet" href="../assets/css/main.css">
</head>
<body>
    <header class="site-header">
        <h1>後台管理</h1>
        <?= admin_nav() ?>
    </header>

    <main class="page">
        <div class="section-heading">
            <div>
                <h1>公告文化管理</h1>
                <p class="helper-text">維護最新公告、廟宇歷史、神明介紹、民俗文化部落格與燈種介紹。</p>
            </div>
            <?php if ($editArticle): ?>
                <a class="button secondary" href="articles.php">取消編輯</a>
            <?php endif; ?>
        </div>

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
            <h2><?= $editArticle ? '編輯文章' : '新增文章' ?></h2>
            <form class="form grid-form" method="post" action="articles.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_article">
                <input type="hidden" name="article_id" value="<?= e((string) ($form['article_id'] ?? '')) ?>">

                <label>
                    文章類型
                    <select name="article_type" required>
                        <?php foreach (['announcement', 'blog', 'temple_history', 'deity_intro', 'lantern_intro'] as $type): ?>
                            <option value="<?= e($type) ?>" <?= $form['article_type'] === $type ? 'selected' : '' ?>>
                                <?= e(article_type_label($type)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    狀態
                    <select name="status" required>
                        <?php foreach (['draft', 'published', 'archived'] as $status): ?>
                            <option value="<?= e($status) ?>" <?= $form['status'] === $status ? 'selected' : '' ?>>
                                <?= e(article_status_label($status)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="full-span">
                    標題
                    <input type="text" name="title" value="<?= e((string) $form['title']) ?>" required>
                </label>

                <label class="full-span">
                    內容
                    <textarea name="content" rows="8" required><?= e((string) $form['content']) ?></textarea>
                </label>

                <label>
                    發布時間
                    <input type="datetime-local" name="published_at" value="<?= e($publishedAtForInput) ?>">
                </label>

                <div class="full-span">
                    <button class="button" type="submit"><?= $editArticle ? '儲存文章' : '新增文章' ?></button>
                </div>
            </form>
        </section>

        <section class="panel section-gap">
            <div class="section-heading">
                <h2>文章列表</h2>
                <a href="../public/articles.php">查看前台文章</a>
            </div>

            <div class="filter-row">
                <?php foreach ($validFilters as $type): ?>
                    <a class="status-pill <?= $filter === $type ? 'active' : '' ?>" href="articles.php?type=<?= e($type) ?>">
                        <?= e($type === 'all' ? '全部' : article_type_label($type)) ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <div class="table-wrap section-gap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>類型</th>
                            <th>標題</th>
                            <th>狀態</th>
                            <th>作者</th>
                            <th>發布時間</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($articles as $article): ?>
                            <tr>
                                <td><?= e(article_type_label($article['article_type'])) ?></td>
                                <td>
                                    <strong><?= e($article['title']) ?></strong>
                                    <p class="helper-text"><?= e(mb_substr(strip_tags($article['content']), 0, 60)) ?></p>
                                </td>
                                <td><?= e(article_status_label($article['status'])) ?></td>
                                <td><?= e($article['author_name'] ?? '-') ?></td>
                                <td><?= e($article['published_at'] ?? '-') ?></td>
                                <td>
                                    <div class="table-actions">
                                        <a href="articles.php?edit_id=<?= (int) $article['article_id'] ?>">編輯</a>
                                        <form method="post" action="articles.php">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="archive_article">
                                            <input type="hidden" name="article_id" value="<?= (int) $article['article_id'] ?>">
                                            <button class="link-button" type="submit">封存</button>
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
