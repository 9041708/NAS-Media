<?php
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/Auth.php';
require_once __DIR__ . '/includes/session.php';

$user = auth()->getUser();
if (!$user) {
    header('Location: /login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: /index.php'); exit; }

$item = db()->fetchOne('SELECT * FROM media_items WHERE id = ?', [$id]);
if (!$item) { header('Location: /index.php'); exit; }

$files = db()->fetchAll(
    'SELECT * FROM media_files WHERE media_id = ? ORDER BY season_number, episode_number, file_name',
    [$id]
);

$seasons = [];
if ($item['type'] === 'tv') {
    foreach ($files as $f) {
        $sn = (int)($f['season_number'] ?? 1);
        if (!isset($seasons[$sn])) $seasons[$sn] = ['season' => $sn, 'episodes' => []];
        $seasons[$sn]['episodes'][] = $f;
    }
    ksort($seasons);
}

$siteName = getSetting('site_name', 'NAS影库');
$isAdmin = $user && $user['role'] === 'admin';

$isFavorited = false;
if ($user) {
    $fav = db()->fetchOne('SELECT id FROM favorites WHERE user_id = ? AND media_id = ?', [$user['id'], $id]);
    $isFavorited = (bool)$fav;
}

$playStatus = false;
if ($user) {
    $played = db()->fetchOne('SELECT id FROM play_history WHERE user_id = ? AND media_id = ? AND completed = 1', [$user['id'], $id]);
    $playStatus = (bool)$played;
}

$history = db()->fetchAll(
    'SELECT ph.*, mf.file_name FROM play_history ph JOIN media_files mf ON ph.file_id = mf.id WHERE ph.user_id = ? AND ph.media_id = ? ORDER BY ph.played_at DESC LIMIT 1',
    [$user['id'] ?? 0, $id]
);
$continueFile = $history[0] ?? null;

$castList = [];
if (!empty($item['cast_list'])) {
    foreach (explode(',', $item['cast_list']) as $n) { $n = trim($n); if ($n) $castList[] = $n; }
}

$collections = [];
if ($user) {
    $collections = db()->fetchAll(
        'SELECT c.id, c.name FROM collections c WHERE c.user_id = ? ORDER BY c.name',
        [$user['id']]
    );
}

$genres = !empty($item['genres']) ? array_map('trim', explode(',', $item['genres'])) : [];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($item['title']) ?> - <?= e($siteName) ?></title>
    <link rel="icon" type="image/png" href="/live_icon_cut.png">
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        :root { --accent: #e50914; --accent-hover:#f6121d; }
        .show-page { min-height:100vh; background:var(--bg-primary); color:var(--text-primary); }
        .show-topbar { position:absolute;top:0;left:0;right:0;z-index:10;display:flex;align-items:center;justify-content:space-between;padding:12px 24px;background:transparent; }
        .show-topbar .back-btn { display:flex;align-items:center;gap:8px;color:#fff;text-decoration:none;font-size:14px; }
        .show-topbar .back-btn:hover { color:#ccc; }

        .show-backdrop { width:100%;height:55vh;min-height:360px;overflow:hidden;position:relative; }
        .show-backdrop img,.show-backdrop .bk-fb { width:100%;height:100%;object-fit:cover;display:block; }
        .show-backdrop::after { content:'';position:absolute;inset:0;background:linear-gradient(0deg,var(--bg-primary) 0%,transparent 50%,rgba(0,0,0,.2) 100%);pointer-events:none; }

        .show-header { position:relative;z-index:2;margin-top:-130px;padding:0 40px 20px;display:flex;gap:32px;max-width:1280px;margin-left:auto;margin-right:auto; }
        .show-poster { flex-shrink:0;width:210px;border-radius:12px;overflow:hidden;box-shadow:0 12px 48px rgba(0,0,0,.65); }
        .show-poster img { width:100%;aspect-ratio:2/3;object-fit:cover;display:block; }
        .show-info { flex:1;padding-top:12px;min-width:0; }
        .show-info h1 { font-size:34px;color:#fff;margin:0 0 6px;line-height:1.3; }
        .show-original { font-size:15px;color:var(--text-muted);margin-bottom:6px; }
        .show-meta { display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:10px;font-size:13px;color:var(--text-secondary); }
        .show-meta .dot { color:var(--text-muted); }
        .show-meta .rating { color:#f59e0b;font-weight:700; }

        .show-actions { display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:14px; }
        .btn-act { display:inline-flex;align-items:center;gap:6px;padding:8px 20px;border-radius:8px;font-size:14px;cursor:pointer;border:none;text-decoration:none;font-weight:500;transition:all .15s; }
        .btn-play-main { background:var(--accent);color:#fff; }
        .btn-play-main:hover { background:var(--accent-hover); }
        .btn-trailer { background:rgba(255,255,255,.1);color:#fff; }
        .btn-trailer:hover { background:rgba(255,255,255,.18); }
        .btn-state { background:rgba(255,255,255,.06);border:1px solid var(--border);color:var(--text-secondary);font-size:13px; }
        .btn-state.played { color:#22c55e;border-color:rgba(34,197,94,.3); }
        .btn-fav { background:rgba(255,255,255,.06);border:1px solid var(--border);color:var(--text-secondary); }
        .btn-fav.active { color:#e50914;border-color:rgba(229,9,20,.4); }
        .btn-more { background:rgba(255,255,255,.06);border:1px solid var(--border);color:var(--text-secondary);padding:8px 12px; }

        .show-overview { font-size:14px;color:var(--text-secondary);line-height:1.8;max-width:800px;margin-bottom:16px; }

        .more-menu { position:relative;display:inline-block; }
        .more-dropdown { display:none;position:absolute;top:100%;right:0;margin-top:8px;background:rgba(17,24,47,.98);border:1px solid rgba(255,255,255,.1);border-radius:10px;min-width:200px;box-shadow:0 8px 32px rgba(0,0,0,.6);z-index:50;padding:4px 0;backdrop-filter:blur(20px); }
        .more-dropdown.show { display:block; }
        .more-dropdown button,.more-dropdown a { display:block;width:100%;text-align:left;padding:9px 16px;background:none;border:none;color:var(--text-primary);font-size:13px;cursor:pointer;white-space:nowrap;text-decoration:none; }
        .more-dropdown button:hover,.more-dropdown a:hover { background:rgba(255,255,255,.06); }

        .content-section { max-width:1280px;margin:0 auto;padding:0 40px 32px; }
        .section-title-row { display:flex;align-items:center;justify-content:space-between;margin-bottom:14px; }
        .section-title-row h2 { font-size:18px;color:var(--text-primary);margin:0; }

        .season-tabs { display:flex;gap:4px;margin-bottom:6px; }
        .season-tab { padding:7px 20px;background:none;border:none;color:var(--text-muted);font-size:14px;cursor:pointer;border-radius:6px;transition:all .15s; }
        .season-tab.active { background:rgba(255,255,255,.1);color:#fff;font-weight:600; }
        .season-tab:hover { color:#fff; }

        .scroll-row { display:flex;gap:14px;overflow-x:auto;padding-bottom:8px;scroll-snap-type:x mandatory; }
        .scroll-row::-webkit-scrollbar { height:4px; }
        .scroll-row::-webkit-scrollbar-thumb { background:rgba(255,255,255,.12);border-radius:2px; }

        .ep-card { flex-shrink:0;width:220px;border-radius:10px;overflow:hidden;background:var(--bg-card);border:1px solid var(--border);cursor:pointer;transition:transform .2s,border-color .2s;scroll-snap-align:start; }
        .ep-card:hover { transform:translateY(-4px);border-color:rgba(255,255,255,.18); }
        .ep-card-thumb { position:relative;width:100%;aspect-ratio:16/9;background:var(--bg-hover);overflow:hidden; }
        .ep-card-thumb img { width:100%;height:100%;object-fit:cover; }
        .ep-card-num { position:absolute;top:6px;left:6px;background:rgba(0,0,0,.78);color:#fff;border-radius:4px;padding:2px 8px;font-size:11px;font-weight:600; }
        .ep-card-dur { position:absolute;bottom:6px;right:6px;background:rgba(0,0,0,.78);color:#ccc;border-radius:4px;padding:2px 6px;font-size:10px; }
        .ep-card-info { padding:10px 10px; }
        .ep-card-title { font-size:12px;color:var(--text-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
        .ep-card-over { font-size:11px;color:var(--text-muted);margin-top:3px;line-height:1.4;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden; }

        .cast-card { flex-shrink:0;width:130px;text-align:center;border-radius:10px;overflow:hidden;background:var(--bg-card);border:1px solid var(--border);padding-bottom:8px; }
        .cast-card img { width:100%;aspect-ratio:2/3;object-fit:cover;display:block;background:var(--bg-hover); }
        .cast-card .cast-name { font-size:12px;color:var(--text-primary);margin-top:6px;padding:0 6px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
        .cast-card .cast-role { font-size:10px;color:var(--text-muted);padding:0 6px;margin-top:2px; }

        .sim-card { flex-shrink:0;width:150px;border-radius:10px;overflow:hidden;background:var(--bg-card);border:1px solid var(--border);cursor:pointer;transition:transform .2s;text-decoration:none;color:inherit; }

        .trailer-overlay { display:none;position:fixed;inset:0;background:rgba(0,0,0,.9);z-index:200;justify-content:center;align-items:center; }
        .trailer-overlay.show { display:flex; }
        .trailer-inner { position:relative;width:90%;max-width:900px;aspect-ratio:16/9; }
        .trailer-inner iframe { width:100%;height:100%;border:none;border-radius:8px; }
        .trailer-close { position:absolute;top:-36px;right:0;background:none;border:none;color:#fff;font-size:28px;cursor:pointer; }

        .sim-card { flex-shrink:0;width:150px;border-radius:10px;overflow:hidden;background:var(--bg-card);border:1px solid var(--border);cursor:pointer;transition:transform .2s;text-decoration:none;color:inherit; }
        .sim-card:hover { transform:translateY(-4px); }
        .sim-card img { width:100%;aspect-ratio:2/3;object-fit:cover;display:block;background:var(--bg-hover); }
        .sim-card .sim-title { font-size:12px;padding:8px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }

        .info-grid { display:flex;flex-wrap:wrap;gap:20px; }
        .info-item { }
        .info-item .il { font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px; }
        .info-item .iv { font-size:13px;color:var(--text-secondary); }

        @media(max-width:768px) {
            .show-backdrop { height:35vh;min-height:220px; }
            .show-header { flex-direction:column;align-items:center;margin-top:-60px;padding:0 16px 16px; }
            .show-poster { width:140px; }
            .show-info h1 { font-size:24px;text-align:center; }
            .show-meta,.show-actions { justify-content:center; }
            .content-section { padding:0 16px 24px; }
            .ep-card { width:180px; }
            .cast-card { width:100px; }
            .sim-card { width:120px; }
        }
    </style>
</head>
<body class="<?= themeClass() ?>">
<div class="show-page">
    <div class="show-topbar">
        <a href="/index.php" class="back-btn" onclick="document.getElementById('videoPlayer')?.pause();return true;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>
            <?= e($siteName) ?>
        </a>
        <div style="display:flex;gap:8px;align-items:center;">
            <?php if ($user): ?>
                <span style="color:var(--text-muted);font-size:13px;"><?= e($user['display_name'] ?? $user['username']) ?></span>
                <?php if ($isAdmin): ?><a href="/admin/index.php" class="btn-trailer" style="padding:6px 14px;font-size:12px;">管理</a><?php endif; ?>
                <a href="#" id="logoutBtn" style="color:var(--text-muted);font-size:13px;text-decoration:none;">退出</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="show-backdrop">
        <?php if ($item['backdrop_path']): ?>
            <img src="/api/image.php?size=original&path=<?= urlencode($item['backdrop_path']) ?>" alt="">
        <?php else: ?>
            <div class="bk-fb" style="background:linear-gradient(135deg,#0b0b1a,#13132b,#0d0d24);"></div>
        <?php endif; ?>
    </div>

    <div class="show-header">
        <div class="show-poster">
            <?php if ($item['poster_path']): ?>
                <img src="/api/image.php?size=w500&path=<?= urlencode($item['poster_path']) ?>" alt="">
            <?php else: ?>
                <div style="width:100%;aspect-ratio:2/3;background:var(--bg-hover);display:flex;align-items:center;justify-content:center;color:var(--text-muted);font-size:12px;text-align:center;padding:12px;"><?= e($item['title']) ?></div>
            <?php endif; ?>
        </div>
        <div class="show-info">
            <h1><?= e($item['title']) ?></h1>
            <?php if ($item['original_title'] && $item['original_title'] !== $item['title']): ?>
                <div class="show-original"><?= e($item['original_title']) ?></div>
            <?php endif; ?>
            <div class="show-meta">
                <?= $item['year'] ?: '' ?>
                <?php if ($item['runtime']): ?> <span class="dot">·</span> <?= $item['runtime'] ?>分钟<?php endif; ?>
                <?php if ($item['rating']): ?> <span class="dot">·</span> <span class="rating">★ <?= number_format($item['rating'], 1) ?></span><?php endif; ?>
                <?php if ($genres): ?> <span class="dot">·</span> <?= e(implode(' / ', array_slice($genres, 0, 3))) ?><?php endif; ?>
            </div>
            <div class="show-actions">
                <?php if (!empty($files)): ?>
                    <button class="btn-act btn-play-main" onclick="playFile(<?= $continueFile ? $continueFile['file_id'] : $files[0]['id'] ?>)">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                        <?= $continueFile ? ($item['type'] === 'tv' ? '继续 第' . ($continueFile['episode_number'] ?? '?') . '集' : '继续观看') : '播放' ?>
                    </button>
                <?php endif; ?>
                <?php if ($item['tmdb_id']): ?>
                    <button class="btn-act btn-trailer" onclick="openTrailer()">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><polygon points="5 3 19 12 5 21"/></svg>
                        预告片
                    </button>
                <?php endif; ?>
                <?php if ($user): ?>
                    <button class="btn-act btn-state <?= $playStatus ? 'played' : '' ?>" id="stateBtn" onclick="togglePlayed()">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="<?= $playStatus ? 'currentColor' : 'none' ?>" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                        <?= $playStatus ? '已看' : '未看' ?>
                    </button>
                    <button class="btn-act btn-fav <?= $isFavorited ? 'active' : '' ?>" id="favBtn" onclick="toggleFavorite()">
                        <?= $isFavorited ? '♥ 已收藏' : '♡ 收藏' ?>
                    </button>
                <?php endif; ?>
                <div class="more-menu">
                    <button class="btn-act btn-more" id="moreBtn" onclick="toggleMore()">···</button>
                    <div class="more-dropdown" id="moreDropdown">
                        <?php if ($continueFile): ?><button onclick="playFile(<?= $continueFile['file_id'] ?>)">从头播放</button><?php endif; ?>
                        <button onclick="playFile(<?= $files[0]['id'] ?? 0 ?>)">随机播放</button>
                        <?php if ($user): ?><button onclick="toggleFavorite()"><?= $isFavorited ? '取消收藏' : '添加到收藏夹' ?></button><?php endif; ?>
                        <button onclick="showAddToCollection()">添加到合集</button>
                        <?php if ($user): ?><button onclick="togglePlayed()"><?= $playStatus ? '标记为未看' : '标记为已看' ?></button><?php endif; ?>
                        <?php if ($isAdmin): ?>
                            <button onclick="openEditMetaOnShow()">编辑元数据</button>
                            <button onclick="refreshMetadata()">刷新元数据</button>
                            <button onclick="scrapeMetadata()">刮削元数据</button>
                            <button onclick="scanMediaFiles()">扫描媒体库文件</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <p class="show-overview"><?= e($item['overview'] ?: '暂无简介') ?></p>
        </div>
    </div>

    <?php if ($item['type'] === 'tv' && !empty($seasons)): ?>
    <div class="content-section">
        <div class="season-tabs" id="seasonTabs">
        <?php foreach ($seasons as $s): ?>
            <button class="season-tab <?= $s['season'] == (array_key_first($seasons)) ? 'active' : '' ?>" data-season="<?= $s['season'] ?>"><?= $s['season'] == 0 ? '特别篇' : '第 ' . $s['season'] . ' 季' ?></button>
            <?php endforeach; ?>
        </div>
        <?php foreach ($seasons as $s): ?>
            <div class="episode-list" id="season-<?= $s['season'] ?>" style="display:<?= $s['season'] == array_key_first($seasons) ? 'block' : 'none' ?>;">
                <div class="scroll-row" id="season-<?= $s['season'] ?>-row">
                    <?php foreach ($s['episodes'] as $ep): 
                        $epNum = $ep['episode_number'] ?: (array_search($ep, $s['episodes']) + 1);
                        $dur = $ep['duration'] ? floor($ep['duration']/60).':'.str_pad($ep['duration']%60,2,'0',STR_PAD_LEFT) : '';
                    ?>
                        <div class="ep-card" onclick="playFile(<?= $ep['id'] ?>)">
                            <div class="ep-card-thumb">
                                <?php if ($item['poster_path']): ?><img src="/api/image.php?size=w500&path=<?= urlencode($item['poster_path']) ?>" loading="lazy"><?php endif; ?>
                                <span class="ep-card-num">第 <?= $epNum ?> 集</span>
                                <?php if ($dur): ?><span class="ep-card-dur"><?= $dur ?></span><?php endif; ?>
                            </div>
                            <div class="ep-card-info">
                                <div class="ep-card-title" id="epTitle_<?= $item['tmdb_id'] ?>_<?= $s['season'] ?>_<?= $epNum ?>">第 <?= $epNum ?> 集</div>
                                <div class="ep-card-fn" style="font-size:10px;color:var(--text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= e($ep['file_name']) ?></div>
                                <div class="ep-card-over ep-over-<?= $item['tmdb_id'] ?>-<?= $s['season'] ?>-<?= $epNum ?>" id="epOver_<?= $item['tmdb_id'] ?>_<?= $s['season'] ?>_<?= $epNum ?>"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php elseif (!empty($files)): ?>
    <div class="content-section">
        <div class="scroll-row">
            <?php foreach ($files as $ep): $dur = $ep['duration'] ? floor($ep['duration']/60).':'.str_pad($ep['duration']%60,2,'0',STR_PAD_LEFT) : ''; ?>
                <div class="ep-card" onclick="playFile(<?= $ep['id'] ?>)">
                    <div class="ep-card-thumb">
                        <?php if ($item['poster_path']): ?><img src="/api/image.php?size=w500&path=<?= urlencode($item['poster_path']) ?>" loading="lazy"><?php endif; ?>
                        <?php if ($dur): ?><span class="ep-card-dur"><?= $dur ?></span><?php endif; ?>
                    </div>
                    <div class="ep-card-info"><div class="ep-card-title"><?= e($ep['file_name']) ?></div></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="content-section" id="castSection">
        <div class="section-title-row"><h2>演职人员</h2></div>
        <div class="scroll-row" id="castRow"><div style="color:var(--text-muted);padding:20px;font-size:13px;">加载中...</div></div>
    </div>

    <div class="content-section" id="similarSection">
        <div class="section-title-row"><h2>更多类似</h2></div>
        <div class="scroll-row" id="similarRow"><div style="color:var(--text-muted);padding:20px;font-size:13px;">加载中...</div></div>
    </div>

    <div class="content-section">
        <div class="section-title-row"><h2>详细信息</h2></div>
        <div class="info-grid">
            <?php if ($genres): ?><div class="info-item"><div class="il">流派</div><div class="iv"><?= e(implode('、', $genres)) ?></div></div><?php endif; ?>
            <?php if ($item['type']): ?><div class="info-item"><div class="il">类型</div><div class="iv"><?= $item['type'] === 'tv' ? '剧集' : '电影' ?></div></div><?php endif; ?>
            <?php if ($item['language']): ?><div class="info-item"><div class="il">语言</div><div class="iv"><?= e($item['language']) ?></div></div><?php endif; ?>
            <?php if ($item['country']): ?><div class="info-item"><div class="il">地区</div><div class="iv"><?= e($item['country']) ?></div></div><?php endif; ?>
            <?php if ($item['release_date']): ?><div class="info-item"><div class="il">上映日期</div><div class="iv"><?= e($item['release_date']) ?></div></div><?php endif; ?>
            <?php if ($item['rating']): ?><div class="info-item"><div class="il">评分</div><div class="iv">★ <?= number_format($item['rating'], 1) ?> / <?= $item['vote_count'] ?? 0 ?>票</div></div><?php endif; ?>
            <div class="info-item"><div class="il">文件数</div><div class="iv"><?= count($files) ?></div></div>
        </div>
    </div>
</div>

<script>
const MEDIA_ID = <?= $id ?>;
const MEDIA_TYPE = '<?= $item['type'] ?>';
const TMDB_ID = <?= (int)($item['tmdb_id'] ?? 0) ?>;

function playFile(fid) { if(fid) window.open('/player.php?file='+fid, '_blank'); }

async function toggleFavorite() {
    const r=await fetch('/api/media.php?action=toggle_favorite',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({media_id:MEDIA_ID})});
    const d=await r.json();
    const b=document.getElementById('favBtn');
    if(d.favorited){b.textContent='♥ 已收藏';b.classList.add('active');}
    else{b.textContent='♡ 收藏';b.classList.remove('active');}
}

async function togglePlayed() {
    const r=await fetch('/api/media.php?action=toggle_played',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({media_id:MEDIA_ID})});
    const d=await r.json();
    const b=document.getElementById('stateBtn');
    if(d.played){b.innerHTML='<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> 已看';b.classList.add('played');}
    else{b.innerHTML='<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> 未看';b.classList.remove('played');}
}

function toggleMore() {
    document.getElementById('moreDropdown').classList.toggle('show');
}
document.addEventListener('click',e=>{if(!e.target.closest('.more-menu'))document.getElementById('moreDropdown')?.classList.remove('show');});

async function openTrailer() {
    if(!TMDB_ID) return;
    try {
        const r = await fetch('/api/media.php?action=show_trailer&id='+MEDIA_ID);
        const videos = await r.json();
        const trailer = Array.isArray(videos) && videos.find(v=>v.type==='Trailer'&&v.site==='YouTube') || (Array.isArray(videos) && videos[0]);
        if(trailer) {
            document.getElementById('trailerFrame').src='https://www.youtube.com/embed/'+trailer.key+'?autoplay=1&rel=0';
            document.getElementById('trailerOverlay').classList.add('show');
        } else { alert('暂无预告片'); }
    } catch(e) { alert('暂无预告片'); }
}

<?php if ($item['tmdb_id'] && $item['type'] === 'tv'): ?>
// Load episode overviews from TMDB
(async function(){
    <?php foreach ($seasons as $s): ?>
    try{
        const r<?= $s['season'] ?>=await fetch('/api/media.php?action=show_season&id='+MEDIA_ID+'&season=<?= $s['season'] ?>');
        const d<?= $s['season'] ?>=await r<?= $s['season'] ?>.json();
        if(d<?= $s['season'] ?>&&d<?= $s['season'] ?>.episodes){
            d<?= $s['season'] ?>.episodes.forEach(ep=>{
                const titleEl=document.getElementById('epTitle_<?= $item['tmdb_id'] ?>_<?= $s['season'] ?>_'+ep.episode_number);
                if(titleEl&&ep.name)titleEl.textContent='第 '+ep.episode_number+' 集 · '+ep.name;
                const overEl=document.getElementById('epOver_<?= $item['tmdb_id'] ?>_<?= $s['season'] ?>_'+ep.episode_number);
                if(overEl&&ep.overview)overEl.textContent=ep.overview;
            });
        }
    }catch(e){}
    <?php endforeach; ?>
})();
<?php endif; ?>

// Load cast
(async function(){
    try{
        const r=await fetch('/api/media.php?action=show_credits&id='+MEDIA_ID);
        const cast=await r.json();
        const row=document.getElementById('castRow');
        if(!cast||!cast.length){row.innerHTML='<div style="color:var(--text-muted);padding:8px;font-size:13px;">暂无双人数据</div>';return;}
        row.innerHTML=cast.map(c=>`
            <div class="cast-card" onclick="window.location.href='/actor.php?name='+encodeURIComponent(c.name)" style="cursor:pointer;" title="查看 ${esc(c.name)} 的作品">
                ${c.profile_path ? '<img src="/api/image.php?size=w185&path='+encodeURIComponent(c.profile_path)+'" loading="lazy" onerror="this.style.display=\'none\'">' : '<div style="width:100%;aspect-ratio:2/3;background:var(--bg-hover);display:flex;align-items:center;justify-content:center;font-size:24px;color:var(--text-muted);">👤</div>'}
                <div class="cast-name">${esc(c.name)}</div>
                <div class="cast-role">${esc(c.character||'')}</div>
            </div>
        `).join('');
    }catch(e){document.getElementById('castRow').innerHTML='<div style="color:var(--text-muted);padding:8px;font-size:13px;">加载失败</div>';}
})();

// Load similar
(async function(){
    try{
        const r=await fetch('/api/media.php?action=show_similar&id='+MEDIA_ID);
        const sim=await r.json();
        const row=document.getElementById('similarRow');
        if(!sim||!sim.length){row.innerHTML='<div style="color:var(--text-muted);padding:8px;font-size:13px;">暂无推荐</div>';return;}
        row.innerHTML=sim.filter(s=>s.id>0||s.external).map(s=>{
            const href = s.id > 0 ? `/show.php?id=${s.id}` : '#';
            const target = s.id > 0 ? '' : ' target="_blank"';
            return `<a href="${href}" class="sim-card"${target}><img src="/api/image.php?size=w342&path=${encodeURIComponent(s.poster_path||'')}" onerror="this.style.display='none'" loading="lazy"><div class="sim-title">${esc(s.title)} ${s.year||''}</div></a>`;
        }).join('');
    }catch(e){document.getElementById('similarRow').innerHTML='<div style="color:var(--text-muted);padding:8px;font-size:13px;">加载失败</div>';}
})();

function esc(s){const d=document.createElement('div');d.textContent=s||'';return d.innerHTML;}

document.querySelectorAll('.season-tab').forEach(tab=>{
    tab.addEventListener('click',()=>{
        document.querySelectorAll('.season-tab').forEach(t=>t.classList.remove('active'));
        tab.classList.add('active');
        document.querySelectorAll('.episode-list').forEach(l=>l.style.display='none');
        document.getElementById('season-'+tab.dataset.season).style.display='block';
    });
});

async function showAddToCollection() {
    const collections = <?= json_encode($collections) ?>;
    if(!collections.length){alert('暂无合集，请先创建合集');return;}
    const html = collections.map(c=>`<option value="${c.id}">${esc(c.name)}</option>`).join('');
    const name = prompt('输入新合集名称（留空则选已有合集）：');
    if(name){
        const r=await fetch('/api/media.php?action=create_collection',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({name,media_id:MEDIA_ID})});
        const d=await r.json();
        alert(d.success?'已创建合集':'创建失败');
        return;
    }
    const cid=prompt('已有合集：\n'+collections.map(c=>c.id+' - '+c.name).join('\n')+'\n\n输入合集ID：');
    if(!cid)return;
    const r=await fetch('/api/media.php?action=add_to_collection',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({collection_id:parseInt(cid),media_id:MEDIA_ID})});
    const d=await r.json();
    alert(d.success?'已添加到合集':(d.error||'添加失败'));
}

async function refreshMetadata(){
    if(!confirm('确定刷新元数据？将从 TMDB 重新获取此媒体的详细信息。'))return;
    try {
        const r=await fetch('/api/scan.php?action=refresh_meta',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({media_id:MEDIA_ID})});
        const d=await r.json();
        if(d.success){alert('刷新成功');setTimeout(()=>location.reload(),1000);}
        else alert('刷新失败: ' + (d.error || '未知错误'));
    } catch(e) { alert('刷新失败: 网络错误 - ' + e.message); }
}

async function scanMediaFiles(){
    if(!confirm('确定扫描此媒体的文件？'))return;
    const r=await fetch('/api/media.php?action=scan_media',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({media_id:MEDIA_ID})});
    const d=await r.json();
    alert(d.success?'扫描完成':'失败:'+(d.error||''));
}

async function scrapeMetadata(){
    if(!confirm('确定刮削元数据？将从 TMDB 搜索匹配并重新获取完整元数据。'))return;
    try {
        const r=await fetch('/api/media.php?action=scrape_meta',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({media_id:MEDIA_ID})});
        const d=await r.json();
        if(d.success){alert('刮削成功，页面即将刷新');setTimeout(()=>location.reload(),1000);}
        else alert('刮削失败: ' + (d.error || '未知错误'));
    } catch(e) { alert('刮削失败: 网络错误 - ' + e.message); }
}

document.getElementById('logoutBtn')?.addEventListener('click',async e=>{e.preventDefault();await fetch('/api/auth.php?action=logout');location.href='/index.php';});

function openEditMetaOnShow() {
    const d = document.createElement('div');
    d.className = 'modal-overlay';
    d.style.cssText = 'display:flex;z-index:3000;';
    d.innerHTML = `<div class="modal-content" style="max-width:500px;background:rgba(20,20,40,0.98);border:1px solid rgba(255,255,255,0.12);border-radius:12px;padding:24px;max-height:90vh;overflow-y:auto;">
        <button class="modal-close" onclick="this.closest('.modal-overlay').remove()" style="position:static;float:right;">&times;</button>
        <h3 style="margin-bottom:16px;">编辑元数据</h3>
        <form id="showEditMetaForm">
            <input type="hidden" name="media_id" value="<?= $item['id'] ?>">
            <div class="form-group" style="margin-bottom:12px;">
                <label style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px;">标题</label>
                <input type="text" name="title" value="<?= e($item['title'] ?? '') ?>" style="width:100%;padding:8px 12px;background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.15);border-radius:6px;color:#fff;font-size:13px;outline:none;">
            </div>
            <div class="form-group" style="margin-bottom:12px;">
                <label style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px;">原始标题</label>
                <input type="text" name="original_title" value="<?= e($item['original_title'] ?? '') ?>" style="width:100%;padding:8px 12px;background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.15);border-radius:6px;color:#fff;font-size:13px;outline:none;">
            </div>
            <div style="display:flex;gap:12px;margin-bottom:12px;">
                <div class="form-group" style="flex:1;">
                    <label style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px;">年份</label>
                    <input type="number" name="year" value="<?= $item['year'] ?? '' ?>" style="width:100%;padding:8px 12px;background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.15);border-radius:6px;color:#fff;font-size:13px;outline:none;">
                </div>
                <div class="form-group" style="flex:1;">
                    <label style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px;">类型</label>
                    <select name="type" style="width:100%;padding:8px 12px;background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.15);border-radius:6px;color:#fff;font-size:13px;outline:none;">
                        <option value="movie" <?= ($item['type'] ?? '') === 'movie' ? 'selected' : '' ?>>电影</option>
                        <option value="tv" <?= ($item['type'] ?? '') === 'tv' ? 'selected' : '' ?>>剧集</option>
                        <option value="other" <?= ($item['type'] ?? '') === 'other' ? 'selected' : '' ?>>其他</option>
                    </select>
                </div>
            </div>
            <div class="form-group" style="margin-bottom:12px;">
                <label style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px;">简介</label>
                <textarea name="overview" rows="3" style="width:100%;padding:8px 12px;background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.15);border-radius:6px;color:#fff;font-size:13px;outline:none;resize:vertical;"><?= e($item['overview'] ?? '') ?></textarea>
            </div>
            <div class="form-group" style="margin-bottom:12px;">
                <label style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px;">类型标签 (逗号分隔)</label>
                <input type="text" name="genres" value="<?= e($item['genres'] ?? '') ?>" style="width:100%;padding:8px 12px;background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.15);border-radius:6px;color:#fff;font-size:13px;outline:none;">
            </div>
            <div style="display:flex;gap:12px;margin-bottom:16px;">
                <div class="form-group" style="flex:1;">
                    <label style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px;">TMDB ID <a href="https://www.themoviedb.org/search?query=<?= urlencode($item['title'] ?? '') ?>" target="_blank" style="color:#e50914;font-size:11px;margin-left:4px;">搜索 ↗</a></label>
                    <input type="number" name="tmdb_id" value="<?= $item['tmdb_id'] ?? '' ?>" style="width:100%;padding:8px 12px;background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.15);border-radius:6px;color:#fff;font-size:13px;outline:none;">
                </div>
                <div class="form-group" style="display:flex;align-items:flex-end;gap:6px;">
                    <label style="display:flex;align-items:center;gap:6px;font-size:13px;color:#fff;cursor:pointer;">
                        <input type="checkbox" name="vip_only" value="1" <?= ($item['vip_only'] ?? 0) ? 'checked' : '' ?>> VIP专属
                    </label>
                </div>
            </div>
            <div style="display:flex;gap:8px;justify-content:flex-end;">
                <button type="button" class="btn btn-outline" onclick="this.closest('.modal-overlay').remove()" style="padding:8px 20px;">取消</button>
                <button type="submit" class="btn btn-primary" style="padding:8px 20px;">保存</button>
            </div>
        </form>
    </div>`;
    document.body.appendChild(d);

    d.querySelector('form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(e.target);
        const body = {
            media_id: parseInt(fd.get('media_id')),
            title: fd.get('title').trim(),
            original_title: fd.get('original_title').trim(),
            year: parseInt(fd.get('year')) || null,
            type: fd.get('type'),
            overview: fd.get('overview').trim(),
            genres: fd.get('genres').trim(),
            tmdb_id: parseInt(fd.get('tmdb_id')) || null,
            vip_only: fd.get('vip_only') == '1' ? 1 : 0,
        };
        try {
            const r = await fetch('/api/media.php?action=update_metadata',{
                method:'POST',
                headers:{'Content-Type':'application/json'},
                body:JSON.stringify(body),
            });
            const data = await r.json();
            if(data.success){ d.remove(); location.reload(); }
            else alert('保存失败: ' + (data.error||''));
        } catch(err){ alert('网络错误'); }
    });

    d.addEventListener('click', (ev) => { if(ev.target===d) d.remove(); });
}
</script>

<div class="trailer-overlay" id="trailerOverlay">
    <div class="trailer-inner">
        <button class="trailer-close" onclick="document.getElementById('trailerOverlay').classList.remove('show');document.getElementById('trailerFrame').src='';">✕</button>
        <iframe id="trailerFrame" allowfullscreen allow="autoplay;encrypted-media"></iframe>
    </div>
</div>

<script src="/assets/js/notify.js"></script>
</body>
</html>
