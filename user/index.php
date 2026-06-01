<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/session.php';

auth()->requireLogin();
$user = auth()->getUser();
$fullUser = db()->fetchOne('SELECT * FROM users WHERE id = ?', [$user['id']]);
$siteName = getSetting('site_name', 'NAS影库');

$history = db()->fetchAll(
    'SELECT ph.*, mi.title, mi.poster_path, mi.year, mf.file_name
     FROM play_history ph
     JOIN media_items mi ON ph.media_id = mi.id
     JOIN media_files mf ON ph.file_id = mf.id
     WHERE ph.user_id = ?
     ORDER BY ph.played_at DESC LIMIT 50',
    [$user['id']]
);

$favorites = db()->fetchAll(
    'SELECT mi.*, f.created_at as favorited_at
     FROM favorites f
     JOIN media_items mi ON f.media_id = mi.id
     WHERE f.user_id = ?
     ORDER BY f.created_at DESC',
    [$user['id']]
);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>个人中心 - <?= e($siteName) ?></title>
    <link rel="icon" type="image/png" href="/live_icon_cut.png">
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body class="<?= themeClass() ?>">
    <nav class="top-nav">
        <div class="nav-left">
            <a href="/index.php" class="logo"><img src="/live_icon_cut.png" alt="<?= e($siteName) ?>" height="32"></a>
            <span style="color:var(--text-muted);font-size:14px;margin-left:16px;">个人中心</span>
        </div>
        <div class="nav-right">
            <a href="/index.php" class="btn btn-sm btn-outline">返回首页</a>
        </div>
    </nav>

    <main class="main-content" style="padding-top:80px;max-width:900px;margin:0 auto;">
        <!-- 用户信息卡 -->
        <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:12px;padding:24px;margin-bottom:24px;display:flex;align-items:center;gap:20px;">
            <div style="width:64px;height:64px;border-radius:50%;background:linear-gradient(135deg,#e50914,#ff6b6b);display:flex;align-items:center;justify-content:center;font-size:28px;color:#fff;font-weight:700;">
                <?= strtoupper(mb_substr($fullUser['display_name'] ?: $fullUser['username'], 0, 1)) ?>
            </div>
            <div style="flex:1;">
                <h2 style="margin-bottom:4px;"><?= e($fullUser['display_name'] ?: $fullUser['username']) ?></h2>
                <p style="color:var(--text-muted);font-size:14px;">
                    @<?= e($fullUser['username']) ?>
                    <?php if ($fullUser['email']): ?> · <?= e($fullUser['email']) ?><?php endif; ?>
                    · 注册于 <?= date('Y-m-d', strtotime($fullUser['created_at'])) ?>
                </p>
            </div>
            <div style="text-align:right;">
                <div style="font-size:24px;font-weight:700;color:#e50914;"><?= count($favorites) ?></div>
                <div style="font-size:13px;color:var(--text-muted);">收藏</div>
            </div>
            <div style="text-align:right;">
                <div style="font-size:24px;font-weight:700;color:#3b82f6;"><?= count($history) ?></div>
                <div style="font-size:13px;color:var(--text-muted);">播放</div>
            </div>
        </div>

        <!-- Tab 切换 -->
        <div style="display:flex;gap:4px;margin-bottom:24px;border-bottom:1px solid var(--border);padding-bottom:12px;">
            <button class="btn btn-sm user-tab active" data-tab="favorites">我的收藏</button>
            <button class="btn btn-sm user-tab" data-tab="history">播放记录</button>
            <button class="btn btn-sm user-tab" data-tab="settings">偏好设置</button>
            <button class="btn btn-sm user-tab" data-tab="password">修改密码</button>
        </div>

        <!-- 收藏 -->
        <section class="user-section active" id="sec-favorites">
            <?php if (empty($favorites)): ?>
                <div style="text-align:center;padding:60px;color:var(--text-muted);">
                    <p>暂无收藏</p>
                    <p style="font-size:13px;margin-top:8px;">在影片详情页点击心形按钮收藏</p>
                </div>
            <?php else: ?>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:16px;">
                    <?php foreach ($favorites as $fav): ?>
                        <a href="/player.php?media=<?= $fav['id'] ?>" style="text-decoration:none;">
                            <div style="border-radius:8px;overflow:hidden;background:var(--bg-card);transition:transform 0.2s;" onmouseover="this.style.transform='translateY(-4px)'" onmouseout="this.style.transform=''">
                                <?php if ($fav['poster_path']): ?>
                                    <img src="https://image.tmdb.org/t/p/w300<?= $fav['poster_path'] ?>" style="width:100%;aspect-ratio:2/3;object-fit:cover;display:block;">
                                <?php else: ?>
                                    <div style="width:100%;aspect-ratio:2/3;background:var(--bg-hover);display:flex;align-items:center;justify-content:center;color:var(--text-muted);font-size:13px;"><?= e($fav['title']) ?></div>
                                <?php endif; ?>
                                <div style="padding:8px;">
                                    <div style="font-size:13px;color:var(--text-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= e($fav['title']) ?></div>
                                    <div style="font-size:12px;color:var(--text-muted);"><?= $fav['year'] ?? '' ?></div>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <!-- 播放记录 -->
        <section class="user-section" id="sec-history" style="display:none;">
            <?php if (empty($history)): ?>
                <div style="text-align:center;padding:60px;color:var(--text-muted);">
                    <p>暂无播放记录</p>
                </div>
            <?php else: ?>
                <div style="display:flex;flex-direction:column;gap:8px;">
                    <?php foreach ($history as $h): ?>
                        <a href="/player.php?file=<?= $h['file_id'] ?>" style="text-decoration:none;color:inherit;">
                            <div style="display:flex;align-items:center;gap:16px;padding:12px 16px;background:var(--bg-card);border:1px solid var(--border);border-radius:8px;transition:background 0.2s;" onmouseover="this.style.background='var(--bg-hover)'" onmouseout="this.style.background='var(--bg-card)'">
                                <?php if ($h['poster_path']): ?>
                                    <img src="https://image.tmdb.org/t/p/w92<?= $h['poster_path'] ?>" style="width:48px;border-radius:4px;flex-shrink:0;">
                                <?php endif; ?>
                                <div style="flex:1;min-width:0;">
                                    <div style="font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= e($h['title']) ?></div>
                                    <div style="font-size:12px;color:var(--text-muted);"><?= e($h['file_name']) ?></div>
                                </div>
                                <div style="text-align:right;flex-shrink:0;">
                                    <div style="font-size:12px;color:var(--text-muted);"><?= date('m-d H:i', strtotime($h['played_at'])) ?></div>
                                    <?php if ($h['completed']): ?>
                                        <span style="font-size:11px;color:#10b981;">看完</span>
                                    <?php else: ?>
                                        <span style="font-size:11px;color:#f59e0b;"><?= floor($h['position'] / 60) ?>分钟</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <!-- 偏好设置 -->
        <section class="user-section" id="sec-settings" style="display:none;">
            <form id="prefForm" style="max-width:500px;">
                <div class="form-group">
                    <label>字幕语言偏好</label>
                    <select id="prefSub" style="width:100%;padding:10px 14px;background:rgba(255,255,255,0.06);border:1px solid var(--border);border-radius:8px;color:var(--text-primary);font-size:14px;">
                        <option value="zh" <?= ($fullUser['subtitle_pref'] ?? '') === 'zh' ? 'selected' : '' ?>>中文</option>
                        <option value="en" <?= ($fullUser['subtitle_pref'] ?? '') === 'en' ? 'selected' : '' ?>>英文</option>
                        <option value="ja" <?= ($fullUser['subtitle_pref'] ?? '') === 'ja' ? 'selected' : '' ?>>日文</option>
                        <option value="off" <?= ($fullUser['subtitle_pref'] ?? '') === 'off' ? 'selected' : '' ?>>关闭</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>音轨语言偏好</label>
                    <select id="prefAudio" style="width:100%;padding:10px 14px;background:rgba(255,255,255,0.06);border:1px solid var(--border);border-radius:8px;color:var(--text-primary);font-size:14px;">
                        <option value="zh" <?= ($fullUser['audio_pref'] ?? '') === 'zh' ? 'selected' : '' ?>>中文</option>
                        <option value="en" <?= ($fullUser['audio_pref'] ?? '') === 'en' ? 'selected' : '' ?>>英文</option>
                        <option value="ja" <?= ($fullUser['audio_pref'] ?? '') === 'ja' ? 'selected' : '' ?>>日文</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>默认画质</label>
                    <select id="prefQuality" style="width:100%;padding:10px 14px;background:rgba(255,255,255,0.06);border:1px solid var(--border);border-radius:8px;color:var(--text-primary);font-size:14px;">
                        <option value="auto" <?= ($fullUser['quality_pref'] ?? '') === 'auto' ? 'selected' : '' ?>>自动</option>
                        <option value="360p" <?= ($fullUser['quality_pref'] ?? '') === '360p' ? 'selected' : '' ?>>360p</option>
                        <option value="480p" <?= ($fullUser['quality_pref'] ?? '') === '480p' ? 'selected' : '' ?>>480p</option>
                        <option value="720p" <?= ($fullUser['quality_pref'] ?? '') === '720p' ? 'selected' : '' ?>>720p</option>
                        <option value="1080p" <?= ($fullUser['quality_pref'] ?? '') === '1080p' ? 'selected' : '' ?>>1080p</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>默认倍速</label>
                    <select id="prefSpeed" style="width:100%;padding:10px 14px;background:rgba(255,255,255,0.06);border:1px solid var(--border);border-radius:8px;color:var(--text-primary);font-size:14px;">
                        <option value="0.5" <?= ($fullUser['speed_pref'] ?? 1) == 0.5 ? 'selected' : '' ?>>0.5x</option>
                        <option value="0.75" <?= ($fullUser['speed_pref'] ?? 1) == 0.75 ? 'selected' : '' ?>>0.75x</option>
                        <option value="1" <?= ($fullUser['speed_pref'] ?? 1) == 1 ? 'selected' : '' ?>>1x</option>
                        <option value="1.25" <?= ($fullUser['speed_pref'] ?? 1) == 1.25 ? 'selected' : '' ?>>1.25x</option>
                        <option value="1.5" <?= ($fullUser['speed_pref'] ?? 1) == 1.5 ? 'selected' : '' ?>>1.5x</option>
                        <option value="2" <?= ($fullUser['speed_pref'] ?? 1) == 2 ? 'selected' : '' ?>>2x</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">保存偏好</button>
            </form>
        </section>

        <!-- 修改密码 -->
        <section class="user-section" id="sec-password" style="display:none;">
            <form id="pwdForm" style="max-width:400px;">
                <div id="pwdError" style="display:none;background:rgba(229,9,20,0.15);border:1px solid rgba(229,9,20,0.3);color:#ff6b6b;padding:10px 16px;border-radius:8px;margin-bottom:16px;font-size:14px;"></div>
                <div class="form-group">
                    <label>当前密码</label>
                    <input type="password" id="oldPwd" required style="width:100%;padding:10px 14px;background:rgba(255,255,255,0.06);border:1px solid var(--border);border-radius:8px;color:var(--text-primary);font-size:14px;outline:none;">
                </div>
                <div class="form-group">
                    <label>新密码</label>
                    <input type="password" id="newPwd" required minlength="6" style="width:100%;padding:10px 14px;background:rgba(255,255,255,0.06);border:1px solid var(--border);border-radius:8px;color:var(--text-primary);font-size:14px;outline:none;">
                </div>
                <div class="form-group">
                    <label>确认新密码</label>
                    <input type="password" id="confirmPwd" required minlength="6" style="width:100%;padding:10px 14px;background:rgba(255,255,255,0.06);border:1px solid var(--border);border-radius:8px;color:var(--text-primary);font-size:14px;outline:none;">
                </div>
                <button type="submit" class="btn btn-primary">修改密码</button>
            </form>
        </section>
    </main>

    <script>
    document.querySelectorAll('.user-tab').forEach(tab => {
        tab.addEventListener('click', () => {
            document.querySelectorAll('.user-tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.user-section').forEach(s => s.style.display = 'none');
            tab.classList.add('active');
            document.getElementById('sec-' + tab.dataset.tab).style.display = '';
        });
    });

    document.getElementById('prefForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const prefs = [
            { key: 'subtitle_pref', value: document.getElementById('prefSub').value },
            { key: 'audio_pref', value: document.getElementById('prefAudio').value },
            { key: 'quality_pref', value: document.getElementById('prefQuality').value },
            { key: 'speed_pref', value: document.getElementById('prefSpeed').value },
        ];
        try {
            for (const p of prefs) {
                await fetch('/api/auth.php?action=update_pref', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(p),
                });
            }
            alert('偏好已保存');
        } catch (e) {
            alert('保存失败');
        }
    });

    document.getElementById('pwdForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const errEl = document.getElementById('pwdError');
        errEl.style.display = 'none';
        const newPwd = document.getElementById('newPwd').value;
        const confirmPwd = document.getElementById('confirmPwd').value;
        if (newPwd !== confirmPwd) {
            errEl.textContent = '两次密码不一致';
            errEl.style.display = 'block';
            return;
        }
        try {
            const res = await fetch('/api/auth.php?action=change_password', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    old_password: document.getElementById('oldPwd').value,
                    new_password: newPwd,
                }),
            });
            const data = await res.json();
            if (data.success) {
                alert('密码已修改');
                document.getElementById('pwdForm').reset();
            } else {
                errEl.textContent = data.error || '修改失败';
                errEl.style.display = 'block';
            }
        } catch (e) {
            errEl.textContent = '网络错误';
            errEl.style.display = 'block';
        }
    });
    </script>
    <script src="/assets/js/notify.js"></script>
</body>
</html>
