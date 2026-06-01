<?php
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/FFmpeg.php';
require_once __DIR__ . '/includes/Auth.php';
require_once __DIR__ . '/includes/session.php';

$fileId = (int)($_GET['file'] ?? 0);
$mediaId = (int)($_GET['media'] ?? 0);

if (!$fileId && !$mediaId) { header('Location: /index.php'); exit; }

if (!$fileId && $mediaId) {
    $file = db()->fetchOne('SELECT * FROM media_files WHERE media_id = ? ORDER BY file_name LIMIT 1', [$mediaId]);
    if ($file) $fileId = $file['id'];
}

$file = db()->fetchOne(
    'SELECT mf.*, mi.title, mi.year, mi.poster_path, mi.overview, mi.genres, mi.rating, mi.type as media_type, mi.id as media_item_id
     FROM media_files mf
     LEFT JOIN media_items mi ON mf.media_id = mi.id
     WHERE mf.id = ?',
    [$fileId]
);

if (!$file) { header('Location: /index.php'); exit; }

$playbackError = '';
$user = auth()->getUser();
if ($user) {
    $group = db()->fetchOne('SELECT * FROM user_groups WHERE id = ?', [$user['group_id'] ?? 1]);
    $permissions = $group ? json_decode($group['permissions'] ?? '{}', true) : [];
    $canSeeAll = $permissions['can_see_all'] ?? true;
    $episodeLimit = (int)($permissions['episode_limit'] ?? 0);
    $movieMinutesLimit = (int)($permissions['movie_minutes_limit'] ?? 0);

    $mediaItem = db()->fetchOne('SELECT * FROM media_items WHERE id = ?', [$file['media_item_id'] ?? 0]);
    $isVipOnly = $mediaItem && ($mediaItem['vip_only'] ?? 0);

    if ($isVipOnly && !$canSeeAll) {
        $playbackError = '该内容仅限 VIP 用户观看';
    }

    if (!$playbackError && $file['media_type'] === 'tv' && $episodeLimit > 0) {
        $seasonNum = $file['season_number'] ?? 1;
        $watchedCount = db()->fetchColumn(
            "SELECT COUNT(DISTINCT mf.id) FROM play_history ph
             JOIN media_files mf ON ph.file_id = mf.id
             WHERE ph.user_id = ? AND mf.media_id = ? AND mf.season_number = ?
             AND mf.id != ? AND ph.position > 60",
            [$user['id'], $file['media_item_id'] ?? 0, $seasonNum, $fileId]
        );
        if ($watchedCount >= $episodeLimit) {
            $playbackError = "您已达到本季观看上限（{$episodeLimit}集）";
        }
    }

    if (!$playbackError && $file['media_type'] !== 'tv' && $movieMinutesLimit > 0) {
        $lastPlay = db()->fetchOne(
            "SELECT * FROM play_history WHERE user_id = ? AND file_id = ? ORDER BY played_at DESC LIMIT 1",
            [$user['id'], $fileId]
        );
        $currentPosition = $lastPlay ? (int)$lastPlay['position'] : 0;
        if ($currentPosition >= $movieMinutesLimit * 60) {
            $playbackError = "您已达到观看上限（{$movieMinutesLimit}分钟）";
        }
    }
}

$allFiles = [];
$nextFile = null;
$prevFile = null;
$currentIndex = 0;
if ($file['media_id']) {
    $allFiles = db()->fetchAll(
        'SELECT * FROM media_files WHERE media_id = ? ORDER BY file_name',
        [$file['media_id']]
    );
    foreach ($allFiles as $i => $f) {
        if ($f['id'] == $fileId) {
            $currentIndex = $i;
            if (isset($allFiles[$i + 1])) $nextFile = $allFiles[$i + 1];
            if ($i > 0) $prevFile = $allFiles[$i - 1];
            break;
        }
    }
}

$audioTracks = db()->fetchAll('SELECT * FROM audio_tracks WHERE file_id = ? ORDER BY stream_index', [$fileId]);
$subTracks = db()->fetchAll('SELECT * FROM subtitle_tracks WHERE file_id = ? ORDER BY source, stream_index', [$fileId]);

$skipSegments = [];
if ($file['media_id']) {
    $skipSegments = db()->fetchAll('SELECT * FROM skip_segments WHERE media_id = ?', [$file['media_id']]);
}

$ffmpeg = new FFmpeg();
$qualities = $ffmpeg->getAvailableQualities($fileId);

$user = auth()->getUser();
$speedPref = $user ? (float)$user['speed_pref'] : 1.0;
$siteName = getSetting('site_name', 'NAS影库');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($file['title'] ?: $file['file_name']) ?> - <?= e($siteName) ?></title>
    <link rel="icon" type="image/png" href="/live_icon_cut.png">
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/player.css">
</head>
<body class="<?= themeClass() ?> player-page">
    <?php if ($playbackError): ?>
        <div style="display:flex;align-items:center;justify-content:center;height:100vh;background:var(--bg-primary);">
            <div style="text-align:center;padding:40px;">
                <h2 style="color:var(--accent);margin-bottom:12px;">访问受限</h2>
                <p style="color:var(--text-secondary);margin-bottom:24px;"><?= e($playbackError) ?></p>
                <a href="/index.php" class="btn btn-primary">返回首页</a>
            </div>
        </div>
    <?php else: ?>
    <div class="player-container" id="playerContainer">
        <!-- 顶部栏 -->
        <div class="player-topbar" id="playerTopbar">
            <a href="#" class="back-btn" onclick="document.getElementById('videoPlayer')?.pause();window.location.href='/index.php';return false;">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>
            </a>
            <h1 class="player-title"><?= e($file['title'] ?: $file['file_name']) ?></h1>
            <div class="topbar-right"></div>
        </div>

        <!-- 视频区 -->
        <div class="video-area" id="videoArea">
            <video id="videoPlayer" preload="auto">
                <source src="/api/stream.php?id=<?= $fileId ?>" type="<?= getVideoMimeType($file['file_type']) ?>">
            </video>

            <!-- 字符层 -->
            <div class="subtitle-overlay" id="subtitleOverlay"></div>

            <!-- 选集面板 -->
            <?php if (count($allFiles) > 1): ?>
            <div class="episode-panel" id="episodePanel">
                <div class="ep-panel-header">
                    <span>选集</span>
                    <button class="ep-panel-close" id="epPanelClose">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>
                <div class="ep-panel-list">
                    <?php foreach ($allFiles as $i => $f): ?>
                        <div class="ep-panel-item <?= $f['id'] == $fileId ? 'active' : '' ?>" data-file-id="<?= $f['id'] ?>">
                            <span class="ep-panel-num"><?= $i + 1 ?></span>
                            <span class="ep-panel-name"><?= e($f['file_name']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- 跳过片头/片尾提示 -->
            <div class="skip-overlay" id="skipOverlay" style="display:none;">
                <button class="skip-btn" id="skipBtn">
                    <span id="skipText">跳过片头</span>
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><polygon points="5 4 15 12 5 20 5 4"/><polygon points="19 4 19 20" /></svg>
                </button>
            </div>

            <!-- 中央播放/暂停按钮 -->
            <div class="center-play-btn" id="centerPlayBtn" style="display:none;">
                <svg width="64" height="64" viewBox="0 0 24 24" fill="white"><polygon points="5 3 19 12 5 21 5 3"/></svg>
            </div>

            <!-- 快进/快退指示 -->
            <div class="seek-indicator" id="seekIndicator" style="display:none;">
                <div class="seek-icon" id="seekIcon"></div>
                <div class="seek-time" id="seekTime"></div>
            </div>

            <!-- 底部控制栏 -->
            <div class="player-controls" id="playerControls">
                <!-- 进度条 -->
                <div class="progress-container" id="progressContainer">
                    <div class="progress-buffer" id="progressBuffer"></div>
                    <div class="progress-bar" id="progressBar">
                        <div class="progress-thumb" id="progressThumb"></div>
                    </div>
                    <div class="progress-tooltip" id="progressTooltip">00:00</div>
                    <!-- 片头片尾标记 -->
                    <?php foreach ($skipSegments as $seg): ?>
                        <div class="skip-marker skip-marker-<?= $seg['type'] ?>"
                             style="left: 0; width: 0;"
                             data-start="<?= $seg['start_time'] ?>"
                             data-end="<?= $seg['end_time'] ?>"
                             data-type="<?= $seg['type'] ?>">
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- 控制按钮 -->
                <div class="controls-row">
                    <div class="controls-left">
                        <!-- 播放/暂停 -->
                        <button class="ctrl-btn" id="playPauseBtn" title="播放 (空格)">
                            <svg class="icon-play" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                            <svg class="icon-pause" width="24" height="24" viewBox="0 0 24 24" fill="currentColor" style="display:none"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>
                        </button>

                        <!-- 上一集/下一集 -->
                        <?php if ($prevFile): ?>
                            <button class="ctrl-btn" id="prevEpBtn" title="上一集">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><polygon points="19 20 9 12 19 4 19 20"/><rect x="5" y="4" width="2" height="16"/></svg>
                            </button>
                        <?php endif; ?>
                        <?php if ($nextFile): ?>
                            <button class="ctrl-btn" id="nextEpBtn" title="下一集">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><polygon points="5 4 15 12 5 20 5 4"/><rect x="17" y="4" width="2" height="16"/></svg>
                            </button>
                        <?php endif; ?>

                        <?php if (count($allFiles) > 1): ?>
                        <button class="ctrl-btn" id="toggleEpBtn" title="选集">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                        </button>
                        <?php endif; ?>

                        <!-- 音量 -->
                        <div class="volume-control">
                            <button class="ctrl-btn" id="volumeBtn" title="静音 (M)">
                                <svg class="icon-vol" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14M15.54 8.46a5 5 0 0 1 0 7.07"/></svg>
                                <svg class="icon-mute" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:none"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><line x1="23" y1="9" x2="17" y2="15"/><line x1="17" y1="9" x2="23" y2="15"/></svg>
                            </button>
                            <div class="volume-slider-wrap">
                                <input type="range" id="volumeSlider" class="volume-slider" min="0" max="1" step="0.01" value="1">
                            </div>
                        </div>

                        <!-- 时间 -->
                        <span class="time-display">
                            <span id="currentTime">00:00</span>
                            <span class="time-sep">/</span>
                            <span id="totalTime">00:00</span>
                        </span>
                    </div>

                    <div class="controls-right">
                        <!-- 倍速 -->
                        <div class="speed-control">
                            <button class="ctrl-btn" id="speedBtn" title="倍速">
                                <span id="speedLabel"><?= $speedPref == 1.0 ? '倍速' : $speedPref . 'x' ?></span>
                            </button>
                            <div class="speed-menu" id="speedMenu">
                                <div class="speed-option" data-speed="0.5">0.5x</div>
                                <div class="speed-option" data-speed="0.75">0.75x</div>
                                <div class="speed-option active" data-speed="1">1x</div>
                                <div class="speed-option" data-speed="1.25">1.25x</div>
                                <div class="speed-option" data-speed="1.5">1.5x</div>
                                <div class="speed-option" data-speed="2">2x</div>
                                <div class="speed-option" data-speed="3">3x</div>
                            </div>
                        </div>

                        <!-- 音轨选择 -->
                        <?php if (count($audioTracks) > 0): ?>
                            <div class="audio-control">
                                <button class="ctrl-btn" id="audioBtn" title="音轨">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/></svg>
                                </button>
                                <div class="audio-menu" id="audioMenu">
                                    <?php foreach ($audioTracks as $at): ?>
                                        <div class="audio-option <?= $at['is_default'] ? 'active' : '' ?>"
                                             data-track-id="<?= $at['id'] ?>"
                                             data-stream="<?= $at['stream_index'] ?>">
                                            <?= e($at['title'] ?: ($at['language'] ?: '音轨 ' . ($at['stream_index'] + 1))) ?>
                                            <?php if ($at['channels']): ?>(<?= $at['channels'] ?>ch)<?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- 字幕选择 -->
                        <div class="sub-control">
                            <button class="ctrl-btn" id="subBtn" title="字幕">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="14" x2="23" y2="14"/></svg>
                            </button>
                            <div class="sub-menu" id="subMenu">
                                <div class="sub-option active" data-track-id="off">关闭</div>
                                <?php foreach ($subTracks as $st): ?>
                                    <div class="sub-option"
                                         data-track-id="<?= $st['id'] ?>"
                                         data-source="<?= $st['source'] ?>">
                                        <?= e($st['title'] ?: ($st['language'] ?: '字幕')) ?>
                                        <span class="sub-source"><?= $st['source'] === 'external' ? '外挂' : '内封' ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- 画质选择 -->
                        <div class="quality-control">
                            <button class="ctrl-btn" id="qualityBtn" title="画质">
                                <span id="qualityLabel">原画</span>
                            </button>
                            <div class="quality-menu" id="qualityMenu">
                                <?php foreach ($qualities as $q): ?>
                                    <div class="quality-option <?= $q['label'] === '原画' ? 'active' : '' ?>"
                                         data-quality="<?= $q['label'] ?>"
                                         data-status="<?= $q['status'] ?>"
                                         data-hls="<?= $q['hls'] ?? '' ?>">
                                        <?= $q['label'] ?>
                                        <?php if ($q['status'] === 'running'): ?>
                                            <span class="q-status">转码中 <?= $q['progress'] ?>%</span>
                                        <?php elseif ($q['status'] === 'pending'): ?>
                                            <span class="q-status">等待中</span>
                                        <?php elseif ($q['status'] === 'failed'): ?>
                                            <span class="q-status">失败</span>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- 画中画 -->
                        <button class="ctrl-btn" id="pipBtn" title="画中画">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><rect x="12" y="9" width="8" height="7" rx="1" fill="currentColor" opacity="0.5"/></svg>
                        </button>

                        <!-- 全屏 -->
                        <button class="ctrl-btn" id="fullscreenBtn" title="全屏 (F)">
                            <svg class="icon-fs" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/></svg>
                            <svg class="icon-fs-exit" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:none"><polyline points="4 14 10 14 10 20"/><polyline points="20 10 14 10 14 4"/><line x1="14" y1="10" x2="21" y2="3"/><line x1="3" y1="21" x2="10" y2="14"/></svg>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    window.__PLAYER_DATA__ = {
        fileId: <?= $fileId ?>,
        mediaId: <?= $file['media_item_id'] ?? 'null' ?>,
        mediaType: '<?= $file['media_type'] ?? 'movie' ?>',
        nextFileId: <?= $nextFile ? $nextFile['id'] : 'null' ?>,
        prevFileId: <?= $prevFile ? $prevFile['id'] : 'null' ?>,
        skipSegments: <?= json_encode($skipSegments) ?>,
        defaultSpeed: <?= $speedPref ?>,
        userId: <?= $_SESSION['user_id'] ?? 'null' ?>,
        posterPath: '<?= addslashes($file['poster_path'] ?? '') ?>',
        title: '<?= addslashes($file['title'] ?: $file['file_name']) ?>',
        fileName: '<?= addslashes($file['file_name']) ?>',
    };
    </script>
    <script src="/assets/js/player.js"></script>
    <script src="/assets/js/notify.js"></script>
    <script>
    (function() {
        const PD = window.__PLAYER_DATA__;
        if (!PD.userId || !PD.mediaId) return;
        const video = document.getElementById('videoPlayer');
        let hbTimer = null;
        function sendHeartbeat() {
            if (!video || video.paused) return;
            fetch('/api/activity.php?action=heartbeat', {
                method: 'POST',
                headers: {'Content-Type':'application/json'},
                body: JSON.stringify({
                    media_id: PD.mediaId,
                    file_id: PD.fileId,
                    position: Math.floor(video.currentTime),
                    duration: Math.floor(video.duration || 0),
                    title: PD.title,
                    file_name: PD.fileName,
                    poster_path: PD.posterPath,
                }),
            }).catch(()=>{});
        }
        video.addEventListener('play', () => { hbTimer = setInterval(sendHeartbeat, 15000); sendHeartbeat(); });
        video.addEventListener('pause', () => { clearInterval(hbTimer); });
        video.addEventListener('ended', () => { clearInterval(hbTimer); });
        window.addEventListener('beforeunload', () => {
            fetch('/api/activity.php?action=stop', {method:'POST',keepalive:true});
        });
    })();
    </script>
    <?php endif; ?>
</body>
</html>
