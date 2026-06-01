<?php
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/Auth.php';
require_once __DIR__ . '/includes/session.php';

$user = auth()->getUser();
$siteName = getSetting('site_name', 'NAS影库');

$history = [];
$grouped = [];

if ($user) {
    $history = db()->fetchAll(
        "SELECT ph.*, mi.title, mi.type, mi.poster_path, mi.year, mf.file_name, mf.id as file_id
         FROM play_history ph
         JOIN media_items mi ON ph.media_id = mi.id
         JOIN media_files mf ON ph.file_id = mf.id
         WHERE ph.user_id = ?
         ORDER BY ph.played_at DESC
         LIMIT 200",
        [$user['id']]
    );

    $grouped = [];
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));

    foreach ($history as $h) {
        $day = date('Y-m-d', strtotime($h['played_at']));
        if ($day === $today) {
            $group = '今天';
        } elseif ($day === $yesterday) {
            $group = '昨天';
        } else {
            $group = $day;
        }
        $grouped[$group][] = $h;
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>播放记录 - <?= e($siteName) ?></title>
    <link rel="icon" type="image/png" href="/live_icon_cut.png">
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        .history-page { min-height: 100vh; background: var(--bg-primary); }
        .history-content { max-width: 900px; margin: 0 auto; padding: 80px 24px 40px; }
        .history-content h1 { font-size: 24px; color: var(--text-primary); margin-bottom: 24px; }
        .history-group { margin-bottom: 24px; }
        .history-group-title { font-size: 14px; color: var(--text-muted); margin-bottom: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
        .history-item { display: flex; align-items: center; gap: 14px; padding: 10px 12px; border-radius: 8px; cursor: pointer; transition: background 0.15s; border: 1px solid transparent; text-decoration: none; color: inherit; }
        .history-item:hover { background: var(--bg-hover); border-color: var(--border); }
        .history-poster { width: 52px; height: 72px; border-radius: 4px; overflow: hidden; flex-shrink: 0; background: var(--bg-hover); }
        .history-poster img { width: 100%; height: 100%; object-fit: cover; }
        .history-info { flex: 1; min-width: 0; }
        .history-info .hi-title { font-size: 14px; color: var(--text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .history-info .hi-meta { font-size: 12px; color: var(--text-muted); margin-top: 3px; }
        .history-progress { flex-shrink: 0; width: 80px; }
        .history-progress .hp-bar { height: 3px; background: rgba(255,255,255,0.1); border-radius: 2px; overflow: hidden; margin-bottom: 3px; }
        .history-progress .hp-fill { height: 100%; background: var(--accent); }
        .history-progress .hp-text { font-size: 11px; color: var(--text-muted); text-align: right; }
        .history-time { font-size: 11px; color: var(--text-muted); width: 70px; text-align: right; flex-shrink: 0; }
        .history-empty { text-align: center; padding: 80px 20px; color: var(--text-muted); }
        .history-empty svg { margin-bottom: 16px; opacity: 0.3; }

        @media (max-width: 768px) {
            .history-content { padding: 70px 16px 24px; }
            .history-time { display: none; }
        }
    </style>
</head>
<body class="<?= themeClass() ?>">
    <div class="history-page">
        <nav class="top-nav">
            <div class="nav-left">
                <a href="/index.php" class="logo"><img src="/live_icon_cut.png" alt="<?= e($siteName) ?>" height="32"></a>
                <span style="color:var(--text-muted);font-size:14px;">播放记录</span>
            </div>
            <div class="nav-right">
                <?php if ($user): ?>
                    <span style="color:var(--text-muted);font-size:14px;"><?= e($user['display_name'] ?? $user['username']) ?></span>
                <?php endif; ?>
            </div>
        </nav>

        <div class="history-content">
            <h1>播放记录</h1>

            <?php if (empty($grouped)): ?>
                <div class="history-empty">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    <p>暂无播放记录</p>
                </div>
            <?php else: ?>
                <?php foreach ($grouped as $group => $items): ?>
                    <div class="history-group">
                        <div class="history-group-title"><?= e($group) ?></div>
                        <?php foreach ($items as $h): 
                            $pct = $h['duration'] > 0 ? min(100, round($h['position'] / $h['duration'] * 100)) : 0;
                            $positionStr = floor($h['position'] / 60) . '分';
                            $durationStr = floor($h['duration'] / 60) . '分';
                            $posterUrl = $h['poster_path'] ? 'https://image.tmdb.org/t/p/w92' . $h['poster_path'] : '';
                        ?>
                            <a href="/player.php?file=<?= $h['file_id'] ?>" class="history-item">
                                <div class="history-poster">
                                    <?php if ($posterUrl): ?>
                                        <img src="<?= $posterUrl ?>" alt="" loading="lazy">
                                    <?php endif; ?>
                                </div>
                                <div class="history-info">
                                    <div class="hi-title"><?= e($h['title']) ?></div>
                                    <div class="hi-meta">
                                        <?= e($h['file_name']) ?>
                                        <?php if ($h['type'] === 'tv'): ?> · 剧集<?php endif; ?>
                                        <?php if ($h['year']): ?> · <?= $h['year'] ?><?php endif; ?>
                                    </div>
                                </div>
                                <div class="history-progress">
                                    <div class="hp-bar"><div class="hp-fill" style="width:<?= $pct ?>%"></div></div>
                                    <div class="hp-text">
                                        <?php if ($pct >= 95): ?>
                                            已看完
                                        <?php else: ?>
                                            <?= $positionStr ?> / <?= $durationStr ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="history-time"><?= date('H:i', strtotime($h['played_at'])) ?></div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <script src="/assets/js/notify.js"></script>
</body>
</html>
