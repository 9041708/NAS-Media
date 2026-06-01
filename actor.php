<?php
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/Auth.php';
require_once __DIR__ . '/includes/session.php';

$name = trim($_GET['name'] ?? '');
if (!$name) { header('Location: /index.php'); exit; }

$siteName = getSetting('site_name', 'NAS影库');
$user = auth()->getUser();

$person = tmdb()->searchPerson($name);
$personDetails = null;
$bio = '';
$photo = null;
$birthday = '';
$birthplace = '';

if ($person) {
    $personDetails = tmdb()->getPersonDetails((int)$person['id']);
    if ($personDetails) {
        $bio = $personDetails['biography'] ?? '';
        $photo = $personDetails['profile_path'] ?? null;
        $birthday = $personDetails['birthday'] ?? '';
        $birthplace = $personDetails['place_of_birth'] ?? '';
    }
}

$localMedia = db()->fetchAll(
    "SELECT * FROM media_items WHERE cast_list LIKE ? AND EXISTS (SELECT 1 FROM media_files mf WHERE mf.media_id = media_items.id) ORDER BY rating DESC LIMIT 50",
    ["%$name%"]
);

$movies = array_filter($localMedia, fn($m) => $m['type'] === 'movie');
$tvShows = array_filter($localMedia, fn($m) => $m['type'] === 'tv');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($name) ?> - 演员 - <?= e($siteName) ?></title>
    <link rel="icon" type="image/png" href="/live_icon_cut.png">
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        .actor-page { min-height:100vh;background:var(--bg-primary);color:var(--text-primary); }
        .actor-header { display:flex;gap:32px;align-items:flex-start;max-width:900px;margin:0 auto;padding:80px 24px 32px; }
        .actor-photo { flex-shrink:0;width:180px;border-radius:12px;overflow:hidden;box-shadow:0 8px 32px rgba(0,0,0,.5); }
        .actor-photo img { width:100%;aspect-ratio:2/3;object-fit:cover;display:block; }
        .actor-info h1 { font-size:28px;margin-bottom:8px; }
        .actor-meta { font-size:13px;color:var(--text-muted);margin-bottom:12px; }
        .actor-bio { font-size:14px;color:var(--text-secondary);line-height:1.8; }
        .actor-section { max-width:1100px;margin:0 auto;padding:0 24px 32px; }
        .actor-section h2 { font-size:18px;margin-bottom:14px; }
        .actor-row { display:flex;gap:14px;overflow-x:auto;padding-bottom:8px; }
        .actor-row::-webkit-scrollbar{height:4px;}
        .actor-row::-webkit-scrollbar-thumb{background:rgba(255,255,255,.12);border-radius:2px;}
        .actor-card { flex-shrink:0;width:150px;border-radius:10px;overflow:hidden;background:var(--bg-card);border:1px solid var(--border);cursor:pointer;transition:transform .2s;text-decoration:none;color:inherit; }
        .actor-card:hover{transform:translateY(-4px);}
        .actor-card img{width:100%;aspect-ratio:2/3;object-fit:cover;display:block;background:var(--bg-hover);}
        .actor-card .ac-title{font-size:12px;padding:8px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
        .actor-card .ac-year{font-size:11px;color:var(--text-muted);padding:0 8px 8px;}

        @media(max-width:768px){.actor-header{flex-direction:column;align-items:center;text-align:center;}
            .actor-photo{width:140px;}}
    </style>
</head>
<body class="<?= themeClass() ?>">
<div class="actor-page">
    <nav class="top-nav" style="background:transparent;position:absolute;top:0;z-index:10;">
        <div class="nav-left">
            <a href="/index.php" class="logo"><img src="/live_icon_cut.png" height="32"></a>
            <a href="javascript:history.back()" style="color:var(--text-muted);text-decoration:none;font-size:14px;">← 返回</a>
        </div>
        <div class="nav-right">
            <?php if ($user): ?>
                <span style="color:var(--text-muted);font-size:14px;"><?= e($user['display_name'] ?? $user['username']) ?></span>
            <?php endif; ?>
        </div>
    </nav>

    <div class="actor-header">
        <div class="actor-photo">
            <?php if ($photo): ?>
                <img src="https://image.tmdb.org/t/p/w300<?= $photo ?>" alt="<?= e($name) ?>">
            <?php else: ?>
                <div style="width:100%;aspect-ratio:2/3;background:var(--bg-hover);display:flex;align-items:center;justify-content:center;font-size:48px;color:var(--text-muted);">👤</div>
            <?php endif; ?>
        </div>
        <div class="actor-info">
            <h1><?= e($name) ?></h1>
            <div class="actor-meta">
                <?php if ($birthday): ?><?= $birthday ?><?php endif; ?>
                <?php if ($birthplace): ?> · <?= e($birthplace) ?><?php endif; ?>
            </div>
            <div class="actor-bio"><?= nl2br(e($bio ?: '暂无介绍')) ?></div>
        </div>
    </div>

    <?php if (!empty($movies)): ?>
    <div class="actor-section">
        <h2>电影作品</h2>
        <div class="actor-row">
            <?php foreach ($movies as $m): ?>
                <a href="/show.php?id=<?= $m['id'] ?>" class="actor-card">
                    <?php if ($m['poster_path']): ?>
                        <img src="https://image.tmdb.org/t/p/w342<?= $m['poster_path'] ?>" loading="lazy">
                    <?php endif; ?>
                    <div class="ac-title"><?= e($m['title']) ?></div>
                    <?php if ($m['year']): ?><div class="ac-year"><?= $m['year'] ?></div><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($tvShows)): ?>
    <div class="actor-section">
        <h2>剧集作品</h2>
        <div class="actor-row">
            <?php foreach ($tvShows as $m): ?>
                <a href="/show.php?id=<?= $m['id'] ?>" class="actor-card">
                    <?php if ($m['poster_path']): ?>
                        <img src="https://image.tmdb.org/t/p/w342<?= $m['poster_path'] ?>" loading="lazy">
                    <?php endif; ?>
                    <div class="ac-title"><?= e($m['title']) ?></div>
                    <?php if ($m['year']): ?><div class="ac-year"><?= $m['year'] ?></div><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (empty($movies) && empty($tvShows)): ?>
        <div class="actor-section" style="text-align:center;padding-top:40px;color:var(--text-muted);">
            暂无关联影视作品
        </div>
    <?php endif; ?>
</div>
</body>
</html>
