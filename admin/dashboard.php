<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';

$user = require_admin('login.php');
?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>後台管理首頁</title>
    <link rel="stylesheet" href="../assets/css/main.css">
</head>
<body>
    <header class="site-header">
        <h1>後台管理</h1>
        <nav>
            <a href="dashboard.php">總覽</a>
            <a href="database.php">資料庫</a>
            <a href="lantern_types.php">燈種</a>
            <a href="lamp_positions.php">燈位</a>
            <a href="lamp_wall_editor.php">燈牆編輯</a>
            <a href="orders.php">訂單</a>
            <a href="service_periods.php">年度燈期</a>
            <a href="annual_flow_rules.php">流年規則</a>
            <a href="reports.php">統計報表</a>
            <a href="scheduled_jobs.php">排程任務</a>
            <a href="notifications.php">通知中心</a>
            <a href="users.php">會員權限</a>
            <a href="admin_accounts.php">管理員帳號</a>
            <a href="articles.php">公告文化</a>
            <a href="feedbacks.php">回饋</a>
            <a href="logs.php">操作軌跡</a>
            <a href="logout.php">登出</a>
        </nav>
    </header>

    <main class="page">
        <h1>後台管理首頁</h1>
        <p>目前登入：<?= e($user['name']) ?>。下一步會開始建立燈種、燈位、訂單等後台 CRUD。</p>
    </main>
</body>
</html>
