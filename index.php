<?php
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/TmdbApi.php';
require_once __DIR__ . '/includes/Auth.php';
require_once __DIR__ . '/includes/session.php';

$siteName = getSetting('site_name', 'NAS影库');
$user = auth()->getUser();

$recentHistory = [];
if ($user) {
    $recentHistory = db()->fetchAll(
        "SELECT ph.*, mi.title, mi.type, mi.poster_path,
            mf.file_name, mf.id as file_id
         FROM play_history ph
         JOIN media_items mi ON ph.media_id = mi.id
         JOIN media_files mf ON ph.file_id = mf.id
         WHERE ph.user_id = ?
         ORDER BY ph.played_at DESC
         LIMIT 6",
        [$user['id']]
    );
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($siteName) ?></title>
    <link rel="icon" type="image/png" href="/live_icon_cut.png">
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/poster.css">
</head>
<body class="<?= themeClass() ?>">
    <nav class="top-nav">
        <div class="nav-left">
            <a href="/index.php" class="logo"><img src="/live_icon_cut.png" alt="<?= e($siteName) ?>" height="32"></a>
            <div class="nav-links">
                <a href="/index.php" class="active" data-section="all">全部</a>
                <a href="#" data-section="movie">电影</a>
                <a href="#" data-section="tv">剧集</a>
                <a href="#" data-section="favorites">收藏</a>
                <a href="#" data-section="collections">合集</a>
            </div>
        </div>
        <div class="nav-right">
            <div class="search-box">
                <input type="text" id="searchInput" placeholder="搜索影片...">
                <button id="searchBtn" class="btn-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                </button>
            </div>
            <div class="nav-actions">
                <button id="viewToggle" class="btn-icon" title="切换视图">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
                </button>
                <?php if ($user): ?>
                    <div class="history-dropdown-wrap">
                        <a href="/history.php" class="btn-icon" title="播放记录" id="historyBtn">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        </a>
                        <div class="history-dropdown" id="historyDropdown" style="display:none;">
                            <div class="hd-header">
                                <span>最近播放</span>
                                <a href="/history.php" class="hd-view-all">查看全部</a>
                            </div>
                            <?php if (!empty($recentHistory)): ?>
                                <?php foreach ($recentHistory as $h): 
                                    $pct = $h['duration'] > 0 ? min(100, round($h['position'] / $h['duration'] * 100)) : 0;
                                    $posterUrl = $h['poster_path'] ? 'https://image.tmdb.org/t/p/w92' . $h['poster_path'] : '';
                                ?>
                                    <a href="/player.php?file=<?= $h['file_id'] ?>" class="hd-item">
                                        <div class="hd-poster">
                                            <?php if ($posterUrl): ?><img src="<?= $posterUrl ?>" alt="" loading="lazy"><?php endif; ?>
                                        </div>
                                        <div class="hd-info">
                                            <div class="hd-title"><?= e($h['title']) ?></div>
                                            <div class="hd-meta"><?= e($h['file_name']) ?></div>
                                            <div class="hd-progress-bar"><div class="hd-progress-fill" style="width:<?= $pct ?>%"></div></div>
                                        </div>
                                        <div class="hd-time"><?= date('m-d H:i', strtotime($h['played_at'])) ?></div>
                                    </a>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div style="padding:24px;text-align:center;color:var(--text-muted);font-size:13px;">暂无播放记录</div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="user-menu">
                        <span class="user-name"><?= e($user['display_name'] ?? $user['username']) ?></span>
                        <div class="dropdown">
                            <a href="/user/index.php">个人中心</a>
                            <?php if ($user['role'] === 'admin'): ?>
                                <a href="/admin/index.php">管理后台</a>
                            <?php endif; ?>
                            <a href="/about.php">关于</a>
                            <a href="#" id="logoutBtn">退出登录</a>
                        </div>
                    </div>
                <?php else: ?>
                    <a href="#" class="btn btn-sm" id="loginBtn">登录</a>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <main class="main-content">
        <div id="activeFilter" style="display:none;max-width:1400px;margin:0 auto;padding:6px 24px;">
            <span style="background:var(--bg-card);border:1px solid var(--border);border-radius:8px;padding:6px 16px;display:inline-flex;align-items:center;gap:10px;font-size:13px;color:var(--text-secondary);">
                <span id="filterText"></span>
                <button onclick="clearFilter()" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:16px;padding:0 2px;" title="清除筛选">&times;</button>
            </span>
        </div>

        <!-- Hero / 轮播区 -->
        <section class="hero-section" id="heroSection">
            <div class="hero-slider" id="heroSlider"></div>
        </section>

        <!-- 继续观看 -->
        <section class="continue-section" id="continueSection" style="display:none;">
            <h3 class="section-title">继续观看</h3>
            <div class="continue-row" id="continueRow"></div>
        </section>

        <!-- 筛选栏 -->
        <section class="filter-bar" id="filterBar">
            <div class="filter-group">
                <select id="sortSelect" class="filter-select">
                    <option value="title">按标题</option>
                    <option value="year">按年份</option>
                    <option value="rating">按评分</option>
                    <option value="added">最近添加</option>
                    <option value="played">最多播放</option>
                </select>
                <select id="genreSelect" class="filter-select">
                    <option value="">所有类型</option>
                </select>
            </div>
            <div class="filter-info">
                <span id="mediaCount">0 部影片</span>
            </div>
        </section>

        <!-- 海报墙 -->
        <section class="poster-wall" id="posterWall">
            <div class="poster-grid" id="posterGrid"></div>
            <div class="loading-spinner" id="loadingSpinner">
                <div class="spinner"></div>
                <p>加载中...</p>
            </div>
            <div class="empty-state" id="emptyState" style="display:none;">
                <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="2" width="20" height="20" rx="2.18" ry="2.18"/><path d="m7 2v20l5-3 5 3V2"/></svg>
                <h3>暂无影片</h3>
                <p>请在管理后台添加媒体库并扫描</p>
            </div>
            <div class="load-more" id="loadMore" style="display:none;">
                <button class="btn btn-outline" id="loadMoreBtn">加载更多</button>
            </div>
        </section>
    </main>

    <!-- 详情弹窗 -->
    <div class="modal-overlay" id="detailModal">
        <div class="modal-content detail-modal">
            <button class="modal-close" id="closeDetail">&times;</button>
            <div class="detail-backdrop" id="detailBackdrop"></div>
            <div class="detail-body">
                <div class="detail-poster">
                    <img id="detailPoster" src="" alt="">
                </div>
                <div class="detail-info">
                    <h2 id="detailTitle"></h2>
                    <div class="detail-meta">
                        <span id="detailYear" class="meta-badge"></span>
                        <span id="detailRating" class="meta-rating"></span>
                        <span id="detailRuntime" class="meta-badge"></span>
                        <span id="detailGenres" class="meta-genres"></span>
                    </div>
                    <p id="detailOverview" class="detail-overview"></p>
                    <div class="detail-extra">
                        <div id="detailDirector" class="detail-field"></div>
                        <div id="detailCast" class="detail-field"></div>
                    </div>
                    <div class="detail-actions">
                        <button class="btn btn-primary" id="playBtn">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                            播放
                        </button>
                        <button class="btn btn-outline" id="favBtn">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                            收藏
                        </button>
                    </div>
                    <div class="detail-files" id="detailFiles"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- 登录弹窗 -->
    <div class="modal-overlay" id="loginModal">
        <div class="modal-content" style="max-width:400px;">
            <button class="modal-close" id="closeLogin">&times;</button>
            <div style="padding:40px;">
                <div style="text-align:center;margin-bottom:24px;">
                    <img src="/live_icon_cut.png" alt="" height="48" style="margin-bottom:12px;">
                    <h3>登录</h3>
                </div>
                <div class="login-error" id="loginError" style="display:none;background:rgba(229,9,20,0.15);border:1px solid rgba(229,9,20,0.3);color:#ff6b6b;padding:10px 16px;border-radius:8px;margin-bottom:16px;font-size:14px;"></div>
                <form id="loginForm">
                    <div style="margin-bottom:16px;">
                        <input type="text" id="loginUsername" placeholder="用户名" required style="width:100%;padding:12px 16px;background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.15);border-radius:8px;color:#fff;font-size:15px;outline:none;">
                    </div>
                    <div style="margin-bottom:20px;">
                        <input type="password" id="loginPassword" placeholder="密码" required style="width:100%;padding:12px 16px;background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.15);border-radius:8px;color:#fff;font-size:15px;outline:none;">
                    </div>
                    <button type="submit" class="btn btn-primary" style="width:100%;padding:12px;">登 录</button>
                </form>
                <p style="text-align:center;margin-top:16px;font-size:13px;color:rgba(255,255,255,0.4);">
                    没有账号？<a href="/login.php" style="color:#e50914;">去注册</a>
                </p>
            </div>
        </div>
    </div>

    <script>
    window.__USER__ = {
        id: <?= $user ? (int)$user['id'] : 'null' ?>,
        role: <?= $user ? json_encode($user['role']) : 'null' ?>,
    };
    </script>
    <script src="/assets/js/app.js"></script>
    <script src="/assets/js/notify.js"></script>
</body>
</html>
