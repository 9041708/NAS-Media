<?php
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/Auth.php';
require_once __DIR__ . '/includes/session.php';

$siteName = getSetting('site_name', 'NAS影库');
$version = getSetting('app_version', '3.0.0');
$user = auth()->getUser();

$changelog = file_get_contents(__DIR__ . '/CHANGELOG.md');
$changelogHtml = $changelog 
    ? '<pre style="white-space:pre-wrap;font-family:inherit;line-height:1.8;">' . e($changelog) . '</pre>'
    : '<p>暂无更新日志</p>';

$stats = [
    'movies' => db()->fetchColumn("SELECT COUNT(*) FROM media_items WHERE type='movie'"),
    'tv' => db()->fetchColumn("SELECT COUNT(*) FROM media_items WHERE type='tv'"),
    'files' => db()->fetchColumn("SELECT COUNT(*) FROM media_files"),
    'users' => db()->fetchColumn("SELECT COUNT(*) FROM users"),
];
$totalSize = db()->fetchColumn("SELECT COALESCE(SUM(file_size),0) FROM media_files");
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>关于 - <?= e($siteName) ?></title>
    <link rel="icon" type="image/png" href="/live_icon_cut.png">
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        .about-page { min-height:100vh;background:var(--bg-primary);color:var(--text-primary); }
        .about-header { text-align:center;padding:60px 20px 40px; }
        .about-header h1 { font-size:28px;margin-bottom:8px; }
        .about-header .ver { color:var(--text-muted);font-size:14px; }
        .about-stats { display:flex;justify-content:center;gap:24px;flex-wrap:wrap;margin-bottom:40px; }
        .about-stat { text-align:center;padding:16px 24px;background:var(--bg-card);border-radius:10px;border:1px solid var(--border);min-width:100px; }
        .about-stat .num { font-size:24px;font-weight:700;color:var(--accent); }
        .about-stat .lbl { font-size:12px;color:var(--text-muted);margin-top:4px; }
        .about-content { max-width:800px;margin:0 auto;padding:0 20px 60px; }
        .about-content h2 { font-size:18px;margin-bottom:16px;padding-bottom:8px;border-bottom:1px solid var(--border); }
        .about-links { display:flex;gap:12px;flex-wrap:wrap;margin-bottom:24px; }
        .about-links a { color:var(--accent);text-decoration:none;font-size:14px; }
        .about-links a:hover { text-decoration:underline; }
    </style>
</head>
<body class="<?= themeClass() ?>">
<div class="about-page">
    <nav class="top-nav">
        <div class="nav-left">
            <a href="/index.php" class="logo"><img src="/live_icon_cut.png" alt="<?= e($siteName) ?>" height="32"></a>
            <span style="color:var(--text-muted);font-size:14px;">关于</span>
        </div>
        <div class="nav-right">
            <?php if ($user): ?>
                <span style="color:var(--text-muted);font-size:14px;"><?= e($user['display_name'] ?? $user['username']) ?></span>
                <?php if ($user['role'] === 'admin'): ?>
                    <a href="/admin/index.php" class="btn btn-sm btn-outline">管理</a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </nav>

    <div class="about-header">
        <h1><?= e($siteName) ?></h1>
        <div class="ver">版本 v<?= e($version) ?></div>
    </div>

    <div class="about-stats">
        <div class="about-stat"><div class="num"><?= $stats['movies'] ?></div><div class="lbl">电影</div></div>
        <div class="about-stat"><div class="num"><?= $stats['tv'] ?></div><div class="lbl">剧集</div></div>
        <div class="about-stat"><div class="num"><?= $stats['files'] ?></div><div class="lbl">文件</div></div>
        <div class="about-stat"><div class="num"><?= formatSize($totalSize) ?></div><div class="lbl">总大小</div></div>
        <div class="about-stat"><div class="num"><?= $stats['users'] ?></div><div class="lbl">用户</div></div>
    </div>

    <div class="about-content">
        <div class="about-links">
            <a href="https://github.com" target="_blank">GitHub</a>
            <a href="https://www.themoviedb.org/" target="_blank">TMDB</a>
        </div>
        <h2>更新日志</h2>
        <?= $changelogHtml ?>
    </div>
</div>
</body>
</html>
