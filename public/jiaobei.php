<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/frontend.php';

$user   = current_user();
$userId = $user ? (int) $user['user_id'] : null;
$pdo    = db();
$errors = [];
$result = null;

// ── POST: 執行擲筊 ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors[] = '表單驗證失敗，請重新操作。';
    }

    if ($errors === []) {
        $question = trim(substr((string)($_POST['question'] ?? ''), 0, 200));
        $left     = random_int(0, 1);   // 1=正面朝上
        $right    = random_int(0, 1);

        if ($left === 1 && $right === 0 || $left === 0 && $right === 1) {
            $resultKey = 'sheng';
        } elseif ($left === 0 && $right === 0) {
            $resultKey = 'yin';
        } else {
            $resultKey = 'xiao';
        }

        $stmt = $pdo->prepare(
            'INSERT INTO jiaobei_records (user_id, question, result, left_face, right_face)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $question, $resultKey, $left, $right]);

        $result = [
            'key'      => $resultKey,
            'left'     => $left,
            'right'    => $right,
            'question' => $question,
        ];
    }
}

// ── 擲筊歷史（登入會員，最近10筆）────────────────────────────────────
$history = [];
if ($userId !== null) {
    $stmt = $pdo->prepare(
        'SELECT result, question, left_face, right_face, created_at
         FROM jiaobei_records
         WHERE user_id = ?
         ORDER BY record_id DESC
         LIMIT 10'
    );
    $stmt->execute([$userId]);
    $history = $stmt->fetchAll();
}

// ── 結果文字 ──────────────────────────────────────────────────────────
$resultInfo = [
    'sheng' => [
        'title'   => '聖筊',
        'emoji'   => '🙏',
        'color'   => '#c8a84b',
        'summary' => '神明允准，心誠則靈',
        'desc'    => '一正一反，神明已知您的心意，此事可行。請繼續虔誠前行，所求之事皆有庇佑。',
    ],
    'yin'   => [
        'title'   => '陰筊',
        'emoji'   => '🙇',
        'color'   => '#7c7c8a',
        'summary' => '神明未應，時機未到',
        'desc'    => '兩面皆反，此時尚非適當時機，或所求之事需再三思量。建議靜心等待，再行請示。',
    ],
    'xiao'  => [
        'title'   => '笑筊',
        'emoji'   => '😊',
        'color'   => '#e07b39',
        'summary' => '神明微笑，再請一次',
        'desc'    => '兩面皆正，神明正在微笑，此次請示未能給予明確答覆。請調整心態，再次請示。',
    ],
];

function result_label(string $key): string
{
    return ['sheng' => '聖筊', 'yin' => '陰筊', 'xiao' => '笑筊'][$key] ?? $key;
}
?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>線上擲筊 — 線上點燈系統</title>
    <meta name="description" content="虔誠祈願，線上擲筊，聆聽神明指引。">
    <link rel="stylesheet" href="../assets/css/main.css">
    <style>
        /* ── 整體頁面 ───────────────────────────── */
        .jiaobei-page {
            max-width: 700px;
            margin: 0 auto;
            padding: 2rem 1rem 4rem;
        }

        /* ── 標題區 ─────────────────────────────── */
        .jb-hero {
            text-align: center;
            padding: 2rem 0 1.5rem;
        }
        .jb-hero h1 {
            font-size: 2rem;
            color: var(--color-primary, #8b1a1a);
            margin-bottom: .4rem;
        }
        .jb-hero p { color: #666; font-size: .95rem; }

        /* ── 表單 ───────────────────────────────── */
        .jb-form-card {
            background: #fff;
            border: 1px solid #e8d5c0;
            border-radius: 12px;
            padding: 1.8rem;
            box-shadow: 0 2px 16px rgba(139,26,26,.07);
            margin-bottom: 2rem;
        }
        .jb-form-card label {
            display: block;
            font-weight: 600;
            margin-bottom: .5rem;
            color: #4a2c0a;
        }
        .jb-form-card input[type="text"] {
            width: 100%;
            padding: .65rem .9rem;
            border: 1px solid #d4b896;
            border-radius: 8px;
            font-size: 1rem;
            margin-bottom: 1.2rem;
            transition: border-color .2s;
            box-sizing: border-box;
        }
        .jb-form-card input[type="text"]:focus {
            outline: none;
            border-color: #8b1a1a;
        }
        .jb-throw-btn {
            display: block;
            width: 100%;
            padding: .85rem;
            background: linear-gradient(135deg, #8b1a1a, #c0392b);
            color: #fff;
            border: none;
            border-radius: 10px;
            font-size: 1.1rem;
            font-weight: 700;
            cursor: pointer;
            letter-spacing: .08em;
            transition: transform .15s, box-shadow .15s;
            box-shadow: 0 4px 14px rgba(139,26,26,.35);
        }
        .jb-throw-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(139,26,26,.45);
        }
        .jb-throw-btn:active { transform: translateY(0); }

        /* ── 筊杯動畫舞台 ────────────────────────── */
        .jb-stage {
            display: flex;
            justify-content: center;
            gap: 3rem;
            margin: 1.5rem 0 .5rem;
            min-height: 110px;
            align-items: center;
        }
        .jb-cup {
            width: 60px;
            height: 90px;
            perspective: 400px;
            cursor: default;
        }
        .jb-cup-inner {
            width: 100%;
            height: 100%;
            position: relative;
            transform-style: preserve-3d;
            animation: none;
            border-radius: 50% 50% 44% 44% / 30% 30% 70% 70%;
        }
        /* 正面（棕紅色凸面） */
        .jb-face, .jb-back {
            position: absolute;
            inset: 0;
            border-radius: 50% 50% 44% 44% / 30% 30% 70% 70%;
            backface-visibility: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.6rem;
        }
        .jb-face {
            background: radial-gradient(ellipse at 38% 30%, #d4955a, #8b4513);
            box-shadow: inset -4px -4px 12px rgba(0,0,0,.3), 0 4px 12px rgba(0,0,0,.25);
        }
        .jb-back {
            background: radial-gradient(ellipse at 38% 30%, #c0c0c0, #7a7a7a);
            box-shadow: inset -4px -4px 12px rgba(0,0,0,.3), 0 4px 12px rgba(0,0,0,.2);
            transform: rotateX(180deg);
        }

        /* 拋出動畫 */
        @keyframes throwSpin {
            0%   { transform: rotateX(0deg) translateY(0); }
            20%  { transform: rotateX(360deg) translateY(-28px); }
            50%  { transform: rotateX(900deg) translateY(-40px); }
            80%  { transform: rotateX(1440deg) translateY(-10px); }
            100% { transform: rotateX(FINAL_ANGLE) translateY(0); }
        }
        .cup-spinning .jb-cup-inner {
            animation: throwSpin .9s cubic-bezier(.4,0,.2,1) forwards;
        }
        /* 落地後的最終角度由 JS 設定 */

        /* 粒子 */
        .jb-particles {
            position: absolute;
            pointer-events: none;
            width: 100%;
            height: 100%;
            top: 0; left: 0;
        }
        .particle {
            position: absolute;
            width: 6px; height: 6px;
            border-radius: 50%;
            opacity: 0;
        }
        @keyframes particleFly {
            0%   { transform: translate(0,0) scale(1); opacity: 1; }
            100% { transform: translate(var(--tx), var(--ty)) scale(0); opacity: 0; }
        }

        /* ── 結果卡片 ────────────────────────────── */
        .jb-result {
            border-radius: 14px;
            padding: 2rem 1.8rem;
            text-align: center;
            margin-bottom: 2rem;
            border: 2px solid;
            animation: resultReveal .5s ease;
        }
        @keyframes resultReveal {
            from { opacity:0; transform: translateY(12px) scale(.97); }
            to   { opacity:1; transform: translateY(0) scale(1); }
        }
        .jb-result-emoji { font-size: 3rem; margin-bottom: .5rem; }
        .jb-result-title {
            font-size: 2.2rem;
            font-weight: 800;
            letter-spacing: .12em;
            margin-bottom: .4rem;
        }
        .jb-result-summary {
            font-size: 1.05rem;
            font-weight: 600;
            margin-bottom: .8rem;
        }
        .jb-result-desc { font-size: .93rem; line-height: 1.7; color: #444; }
        .jb-result-question {
            margin-top: 1rem;
            padding: .6rem 1rem;
            background: rgba(0,0,0,.05);
            border-radius: 8px;
            font-size: .88rem;
            color: #555;
        }

        /* ── 歷史紀錄 ────────────────────────────── */
        .jb-history { margin-top: 2.5rem; }
        .jb-history h2 {
            font-size: 1.1rem;
            font-weight: 700;
            color: #4a2c0a;
            margin-bottom: .8rem;
            border-bottom: 2px solid #e8d5c0;
            padding-bottom: .4rem;
        }
        .jb-history-list { display: flex; flex-direction: column; gap: .5rem; }
        .jb-history-item {
            display: flex;
            align-items: center;
            gap: .75rem;
            padding: .6rem .9rem;
            background: #fdf8f3;
            border-radius: 8px;
            border: 1px solid #eee;
            font-size: .88rem;
        }
        .jb-history-badge {
            padding: .2rem .6rem;
            border-radius: 20px;
            font-weight: 700;
            font-size: .8rem;
            white-space: nowrap;
        }
        .badge-sheng { background: #fef3cd; color: #856404; }
        .badge-yin   { background: #e9ecef; color: #495057; }
        .badge-xiao  { background: #ffe5d0; color: #c45c00; }
        .jb-history-q { flex: 1; color: #333; }
        .jb-history-q.empty { color: #aaa; font-style: italic; }
        .jb-history-time { color: #aaa; font-size: .8rem; white-space: nowrap; }
    </style>
</head>
<body>
    <header class="site-header">
        <h1>線上點燈系統</h1>
        <?= public_nav($user) ?>
    </header>

    <main class="page">
        <div class="jiaobei-page">

            <div class="jb-hero">
                <h1>🏮 線上擲筊</h1>
                <p>虔誠祈願，擲筊問事，聆聽神明指引</p>
            </div>

            <?php if ($errors !== []): ?>
                <div class="alert error">
                    <?php foreach ($errors as $err): ?>
                        <p><?= e($err) ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- 結果展示 -->
            <?php if ($result !== null):
                $ri = $resultInfo[$result['key']];
            ?>
            <div class="jb-result" style="border-color:<?= e($ri['color']) ?>;background:<?= e($ri['color']) ?>18">
                <div class="jb-result-emoji"><?= $ri['emoji'] ?></div>
                <div class="jb-result-title" style="color:<?= e($ri['color']) ?>"><?= e($ri['title']) ?></div>
                <div class="jb-result-summary"><?= e($ri['summary']) ?></div>
                <div class="jb-result-desc"><?= e($ri['desc']) ?></div>
                <?php if ($result['question'] !== ''): ?>
                    <div class="jb-result-question">祈求：「<?= e($result['question']) ?>」</div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- 擲筊表單 -->
            <div class="jb-form-card">
                <!-- 筊杯動畫舞台 -->
                <div class="jb-stage" id="jbStage" style="position:relative">
                    <?php
                    $showLeft  = $result ? (int)$result['left']  : -1;
                    $showRight = $result ? (int)$result['right'] : -1;
                    ?>
                    <div class="jb-cup" id="cupLeft">
                        <div class="jb-cup-inner" id="cupLeftInner"
                             style="<?= $showLeft === 0 ? 'transform:rotateX(180deg)' : '' ?>">
                            <div class="jb-face">🌙</div>
                            <div class="jb-back">⬛</div>
                        </div>
                    </div>
                    <div class="jb-cup" id="cupRight">
                        <div class="jb-cup-inner" id="cupRightInner"
                             style="<?= $showRight === 0 ? 'transform:rotateX(180deg)' : '' ?>">
                            <div class="jb-face">🌙</div>
                            <div class="jb-back">⬛</div>
                        </div>
                    </div>
                </div>

                <form method="post" action="jiaobei.php" id="jbForm">
                    <?= csrf_field() ?>
                    <label for="jb-question">您的祈求（可留空）</label>
                    <input type="text" id="jb-question" name="question"
                           placeholder="例：此次求職能否順利？"
                           maxlength="200"
                           value="<?= e($result['question'] ?? '') ?>">
                    <button type="submit" class="jb-throw-btn" id="jbBtn">
                        🎋 擲筊問事
                    </button>
                </form>
            </div>

            <!-- 擲筊規則說明 -->
            <div class="jb-form-card" style="background:#fdf8f3">
                <h3 style="margin-top:0;color:#4a2c0a;font-size:1rem">擲筊說明</h3>
                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:.8rem;text-align:center;font-size:.88rem">
                    <div style="padding:.8rem;background:#fef3cd;border-radius:8px;border:1px solid #f5c842">
                        <div style="font-size:1.4rem">🌙⬛</div>
                        <strong style="color:#856404">聖筊</strong>
                        <div style="color:#666;margin-top:.3rem">一正一反<br>神明允准</div>
                    </div>
                    <div style="padding:.8rem;background:#e9ecef;border-radius:8px;border:1px solid #ced4da">
                        <div style="font-size:1.4rem">⬛⬛</div>
                        <strong style="color:#495057">陰筊</strong>
                        <div style="color:#666;margin-top:.3rem">兩面皆反<br>神明未應</div>
                    </div>
                    <div style="padding:.8rem;background:#ffe5d0;border-radius:8px;border:1px solid #ffb37c">
                        <div style="font-size:1.4rem">🌙🌙</div>
                        <strong style="color:#c45c00">笑筊</strong>
                        <div style="color:#666;margin-top:.3rem">兩面皆正<br>神明微笑</div>
                    </div>
                </div>
            </div>

            <!-- 歷史紀錄 -->
            <?php if ($userId !== null && $history !== []): ?>
            <div class="jb-history">
                <h2>我的擲筊紀錄（最近 10 次）</h2>
                <div class="jb-history-list">
                    <?php foreach ($history as $h):
                        $badgeClass = 'badge-' . $h['result'];
                    ?>
                    <div class="jb-history-item">
                        <span class="jb-history-badge <?= e($badgeClass) ?>"><?= e(result_label($h['result'])) ?></span>
                        <span class="jb-history-q <?= $h['question'] === '' ? 'empty' : '' ?>">
                            <?= $h['question'] !== '' ? e($h['question']) : '（未填祈求）' ?>
                        </span>
                        <span class="jb-history-time"><?= e(substr($h['created_at'], 0, 16)) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php elseif ($userId === null): ?>
            <p class="helper-text" style="text-align:center;margin-top:1.5rem">
                <a href="login.php">登入</a>後可查看擲筊歷史紀錄。
            </p>
            <?php endif; ?>

        </div>
    </main>

    <script>
    (function () {
        const form = document.getElementById('jbForm');
        const btn  = document.getElementById('jbBtn');
        const leftInner  = document.getElementById('cupLeftInner');
        const rightInner = document.getElementById('cupRightInner');

        form.addEventListener('submit', function (e) {
            btn.disabled = true;
            btn.textContent = '⏳ 擲筊中…';

            // 隨機最終角度（PHP 會決定真正結果，這裡只是動畫效果）
            const finalL = Math.random() > .5 ? 0 : 180;
            const finalR = Math.random() > .5 ? 0 : 180;

            // 設定 CSS 變數
            leftInner.style.setProperty('--final', finalL + 'deg');
            rightInner.style.setProperty('--final', finalR + 'deg');

            // 加上 spinning class 觸發動畫
            leftInner.parentElement.classList.add('cup-spinning');
            rightInner.parentElement.classList.add('cup-spinning');

            // 更新動畫關鍵幀的最終角度（動態設定）
            const styleTag = document.createElement('style');
            styleTag.textContent = `
                #cupLeftInner  { animation: throwSpin .9s cubic-bezier(.4,0,.2,1) forwards; }
                #cupRightInner { animation: throwSpin .95s cubic-bezier(.4,0,.2,1) forwards; }
                @keyframes throwSpin {
                    0%   { transform: rotateX(0deg)     translateY(0); }
                    20%  { transform: rotateX(360deg)   translateY(-28px); }
                    50%  { transform: rotateX(900deg)   translateY(-40px); }
                    80%  { transform: rotateX(1440deg)  translateY(-10px); }
                    100% { transform: rotateX(${1440 + finalL}deg) translateY(0); }
                }
            `;
            document.head.appendChild(styleTag);
        });
    })();
    </script>
</body>
</html>
