<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/TmdbApi.php';
require_once __DIR__ . '/../includes/MediaScanner.php';
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/session.php';

auth()->requireAdmin();
$siteName = getSetting('site_name', 'NAS影库');
$user = auth()->getUser();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理后台 - <?= e($siteName) ?></title>
    <link rel="icon" type="image/png" href="/live_icon_cut.png">
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body class="<?= themeClass() ?>">
    <div class="admin-layout">
        <aside class="admin-sidebar">
            <div class="sidebar-header">
                <a href="/index.php" class="logo"><img src="/live_icon_cut.png" alt="<?= e($siteName) ?>" height="28"></a>
                <span class="admin-badge">管理</span>
            </div>
            <nav class="sidebar-nav">
                <a href="#dashboard" class="nav-item active" data-tab="dashboard">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
                    仪表盘
                </a>
                <a href="#libraries" class="nav-item" data-tab="libraries">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
                    媒体库
                </a>
                <a href="#scan" class="nav-item" data-tab="scan">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                    扫描管理
                </a>
                <a href="#metadata" class="nav-item" data-tab="metadata">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                    元数据管理
                </a>
                <a href="#users" class="nav-item" data-tab="users">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    用户管理
                </a>
                <a href="#transcode" class="nav-item" data-tab="transcode">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="20" rx="2.18" ry="2.18"/><path d="m10 8 6 4-6 4V8z"/></svg>
                    转码管理
                </a>
                <a href="#vip" class="nav-item" data-tab="vip">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                    VIP管理
                </a>
                <a href="#activity" class="nav-item" data-tab="activity">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    活跃会话
                </a>
                <a href="#notify" class="nav-item" data-tab="notify">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                    消息推送
                </a>
                <a href="#about" class="nav-item" data-tab="about">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                    关于
                </a>
                <a href="#settings" class="nav-item" data-tab="settings">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                    设置
                </a>
            </nav>
            <div class="sidebar-footer">
                <a href="/update.php">系统更新</a>
                <a href="/index.php">返回首页</a>
                <span><?= e($user['display_name'] ?? $user['username']) ?></span>
            </div>
        </aside>

        <main class="admin-main">
            <!-- 仪表盘 -->
            <section class="admin-section active" id="tab-dashboard">
                <h2>仪表盘</h2>
                <div class="stats-grid" id="statsGrid"></div>
            </section>

            <!-- 媒体库管理 -->
            <section class="admin-section" id="tab-libraries">
                <div class="section-header">
                    <h2>媒体库管理</h2>
                    <button class="btn btn-primary" id="addLibraryBtn">添加媒体库</button>
                </div>
                <div class="library-list" id="libraryList"></div>
            </section>

            <!-- 扫描管理 -->
            <section class="admin-section" id="tab-scan">
                <h2>扫描管理</h2>
                <div class="scan-controls" id="scanControls"></div>
                <div id="scanProgress" style="display:none;margin-bottom:16px;">
                    <div style="display:flex;justify-content:space-between;margin-bottom:6px;font-size:13px;">
                        <span id="scanStatus">扫描中...</span>
                        <span id="scanPercent">0%</span>
                    </div>
                    <div style="height:8px;background:rgba(255,255,255,0.1);border-radius:4px;overflow:hidden;">
                        <div id="scanBar" style="height:100%;background:#e50914;border-radius:4px;width:0%;transition:width 0.3s;"></div>
                    </div>
                    <div id="scanFile" style="margin-top:4px;font-size:12px;color:var(--text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"></div>
                </div>
                <div class="scan-log" id="scanLog">
                    <pre id="scanOutput">等待扫描...</pre>
                </div>
            </section>

            <!-- 元数据管理 -->
            <section class="admin-section" id="tab-metadata">
                <div class="section-header">
                    <h2>元数据管理</h2>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <input type="text" id="metadataSearch" placeholder="搜索标题或文件名..." style="width:240px;padding:8px 12px;background:rgba(255,255,255,0.06);border:1px solid var(--border);border-radius:8px;color:var(--text-primary);font-size:13px;outline:none;">
                        <select id="metadataLibType" style="padding:8px 12px;background:rgba(255,255,255,0.06);border:1px solid var(--border);border-radius:8px;color:var(--text-primary);font-size:13px;outline:none;">
                            <option value="">全部类型</option>
                            <option value="tv">剧集</option>
                            <option value="movie">电影</option>
                            <option value="other">其他</option>
                        </select>
                        <button class="btn btn-sm btn-primary" id="metadataSearchBtn">搜索</button>
                        <button class="btn btn-sm btn-outline" id="metadataUnmatchedBtn">未匹配文件</button>
                        <button class="btn btn-sm btn-outline" id="metadataRefreshBtn" title="刷新列表">&#x21bb;</button>
                    </div>
                </div>
                <div id="metadataTree" style="margin-top:16px;"></div>
            </section>

            <!-- 用户管理 -->
            <section class="admin-section" id="tab-users">
                <div style="display:flex;gap:4px;margin-bottom:20px;border-bottom:1px solid var(--border);padding-bottom:8px;">
                    <button class="btn btn-sm user-tab-btn active" data-subtab="user-list">用户列表</button>
                    <button class="btn btn-sm user-tab-btn" data-subtab="groups">权限组</button>
                </div>
                <div id="userListSection">
                    <div class="section-header">
                        <h2>用户列表</h2>
                        <button class="btn btn-primary" id="addUserBtn">添加用户</button>
                    </div>
                    <div class="user-list" id="userList"></div>
                </div>
                <div id="groupsSection" style="display:none;">
                    <div class="section-header">
                        <h2>权限组</h2>
                        <button class="btn btn-primary" id="addGroupBtn">新建组</button>
                    </div>
                    <div id="groupList"></div>
                </div>
            </section>

            <!-- 转码管理 -->
            <section class="admin-section" id="tab-transcode">
                <h2>转码管理</h2>
                <p class="section-desc">选择媒体库中的影片，将其转码为不同画质的 HLS 流</p>
                <div class="transcode-jobs">
                    <div class="form-group">
                        <label>选择影片</label>
                        <div style="display:flex;gap:8px;">
                            <input type="text" id="transcodeMediaSearch" placeholder="搜索影片标题..." style="flex:1;padding:10px 14px;background:rgba(255,255,255,0.06);border:1px solid var(--border);border-radius:8px;color:var(--text-primary);font-size:14px;outline:none;">
                            <button class="btn btn-outline" id="transcodeSearchBtn">搜索</button>
                        </div>
                        <div id="transcodeMediaList" style="margin-top:8px;max-height:200px;overflow-y:auto;"></div>
                    </div>
                    <div class="form-group" id="transcodeFileGroup" style="display:none;">
                        <label>选择文件</label>
                        <select id="transcodeFileSelect" style="width:100%;padding:10px 14px;background:rgba(255,255,255,0.06);border:1px solid var(--border);border-radius:8px;color:var(--text-primary);font-size:14px;outline:none;"></select>
                    </div>
                    <div class="form-group">
                        <label>目标画质</label>
                        <select id="transcodeQuality">
                            <option value="360p">360p</option>
                            <option value="480p">480p</option>
                            <option value="720p" selected>720p</option>
                            <option value="1080p">1080p</option>
                        </select>
                    </div>
                    <button class="btn btn-primary" id="startTranscodeBtn">开始转码</button>
                    <div class="scan-log" style="margin-top:20px;">
                        <pre id="transcodeOutput">等待操作...</pre>
                    </div>
                </div>
            </section>

            <!-- VIP管理 -->
            <section class="admin-section" id="tab-vip">
                <h2>VIP管理</h2>
                <p class="section-desc">批量设置媒体资源的VIP权限，可按媒体库或权限组批量操作</p>
                <div style="max-width:900px;">
                    <div style="display:flex;gap:12px;margin-bottom:20px;flex-wrap:wrap;align-items:flex-end;">
                        <div class="form-group" style="flex:1;min-width:180px;">
                            <label>选择媒体库</label>
                            <select id="vipLibrarySelect" style="width:100%;padding:10px 14px;background:rgba(255,255,255,0.06);border:1px solid var(--border);border-radius:8px;color:var(--text-primary);font-size:14px;outline:none;">
                                <option value="">-- 选择媒体库 --</option>
                            </select>
                        </div>
                        <div class="form-group" style="flex:1;min-width:180px;">
                            <label>操作类型</label>
                            <select id="vipActionSelect" style="width:100%;padding:10px 14px;background:rgba(255,255,255,0.06);border:1px solid var(--border);border-radius:8px;color:var(--text-primary);font-size:14px;outline:none;">
                                <option value="set">设为VIP</option>
                                <option value="unset">取消VIP</option>
                            </select>
                        </div>
                        <button class="btn btn-primary" id="vipApplyBtn">批量应用</button>
                        <button class="btn btn-outline" id="vipLoadBtn">加载列表</button>
                    </div>

                    <div style="margin-bottom:12px;display:flex;gap:12px;align-items:center;">
                        <label style="cursor:pointer;font-size:13px;color:var(--text-secondary);display:flex;align-items:center;gap:6px;">
                            <input type="checkbox" id="vipSelectAll"> 全选/取消全选
                        </label>
                        <span style="font-size:13px;color:var(--text-muted);" id="vipCount"></span>
                    </div>

                    <div id="vipMediaList" style="max-height:500px;overflow-y:auto;border:1px solid var(--border);border-radius:8px;padding:8px;">
                        <div style="color:var(--text-muted);padding:20px;text-align:center;font-size:13px;">请选择媒体库后点击"加载列表"</div>
                    </div>
                </div>
            </section>

            <!-- 活跃会话 -->
            <section class="admin-section" id="tab-activity">
                <div class="section-header">
                    <h2>活跃会话</h2>
                    <button class="btn btn-sm btn-outline" id="refreshActivityBtn">刷新</button>
                </div>
                <p class="section-desc">实时查看谁在看什么</p>
                <div id="activityList"></div>
            </section>

            <!-- 消息推送 -->
            <section class="admin-section" id="tab-notify">
                <h2>消息推送</h2>
                <p class="section-desc">发送弹窗消息给在线用户，5 秒后自动消失</p>
                <div style="max-width:600px;">
                    <div class="form-group">
                        <label>发送对象</label>
                        <select id="notifyTarget" style="width:100%;padding:10px 14px;background:rgba(255,255,255,0.06);border:1px solid var(--border);border-radius:8px;color:var(--text-primary);font-size:14px;">
                            <option value="all">所有在线用户</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>消息类型</label>
                        <select id="notifyType" style="width:100%;padding:10px 14px;background:rgba(255,255,255,0.06);border:1px solid var(--border);border-radius:8px;color:var(--text-primary);font-size:14px;">
                            <option value="info">通知</option>
                            <option value="success">成功</option>
                            <option value="warning">警告</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>消息内容</label>
                        <textarea id="notifyMessage" rows="3" placeholder="输入要发送的消息..." style="width:100%;padding:10px 14px;background:rgba(255,255,255,0.06);border:1px solid var(--border);border-radius:8px;color:var(--text-primary);font-size:14px;resize:vertical;outline:none;"></textarea>
                    </div>
                    <button class="btn btn-primary" id="sendNotifyBtn">发送</button>
                </div>
            </section>

            <!-- 设置 -->
            <section class="admin-section" id="tab-settings">
                <h2>系统设置</h2>
                <form id="settingsForm" class="settings-form">

                    <h3 style="margin:0 0 20px;padding-bottom:8px;border-bottom:1px solid var(--border);">网络设定</h3>

                    <div class="form-group">
                        <label>允许外网访问</label>
                        <select name="remote_access_enabled" id="settingRemoteAccess">
                            <option value="0">关闭</option>
                            <option value="1">开启</option>
                        </select>
                        <small style="color:#f59e0b;display:block;margin-top:6px;">开启前请确认：外网访问需具备相关资质，涉及影视版权问题请注意风险，自行承担相关法律责任</small>
                    </div>

                    <h3 style="margin:32px 0 20px;padding-top:20px;border-top:1px solid var(--border);">基本设置</h3>
                    <div class="form-group">
                        <label>站点名称</label>
                        <input type="text" name="site_name" id="settingSiteName">
                    </div>
                    <div class="form-group">
                        <label>TMDB API Key</label>
                        <input type="text" name="tmdb_api_key" id="settingTmdbKey" placeholder="在 themoviedb.org 申请">
                        <small>用于自动获取电影海报、简介等元数据</small>
                    </div>
                    <div class="form-group">
                        <label>海报语言</label>
                        <select name="poster_lang" id="settingPosterLang">
                            <option value="zh-CN">中文</option>
                            <option value="en">英文</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>扫描间隔（秒）</label>
                        <input type="number" name="scan_interval" id="settingScanInterval" min="60">
                    </div>
                    <div class="form-group">
                        <label>主题</label>
                        <select name="theme" id="settingTheme">
                            <option value="dark">暗色</option>
                            <option value="light">亮色</option>
                        </select>
                        <small>选择后即时预览，确认效果后再点保存</small>
                    </div>

                    <h3 style="margin:32px 0 20px;padding-top:20px;border-top:1px solid var(--border);">FFmpeg / 转码</h3>

                    <div id="ffmpegStatus" style="background:rgba(255,255,255,0.04);border:1px solid var(--border);border-radius:8px;padding:16px;margin-bottom:20px;">
                        <div style="display:flex;align-items:center;justify-content:space-between;">
                            <span id="ffmpegStatusText" style="color:var(--text-muted);">检测中...</span>
                            <button type="button" class="btn btn-sm btn-primary" id="detectFfmpegBtn">自动检测</button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>FFmpeg 路径</label>
                        <input type="text" name="ffmpeg_path" id="settingFfmpegPath" placeholder="ffmpeg">
                        <small>留空则自动检测，支持 SynoCommunity / Entware / 群晖自带</small>
                    </div>
                    <div class="form-group">
                        <label>FFprobe 路径</label>
                        <input type="text" name="ffprobe_path" id="settingFfprobePath" placeholder="ffprobe">
                    </div>
                    <div class="form-group">
                        <label>启用转码</label>
                        <select name="transcode_enabled" id="settingTranscodeEnabled">
                            <option value="0">关闭</option>
                            <option value="1">开启</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>HLS 输出目录</label>
                        <input type="text" name="hls_output_dir" id="settingHlsDir" placeholder="留空自动使用系统临时目录">
                        <small>转码后的视频切片存放位置，需要较大空间。留空即可，程序会自动管理</small>
                    </div>
                    <div class="form-group">
                        <label>默认片头时长 (秒)</label>
                        <input type="number" name="default_intro_duration" id="settingIntroDuration" min="0">
                    </div>
                    <div class="form-group">
                        <label>默认片尾时长 (秒)</label>
                        <input type="number" name="default_outro_duration" id="settingOutroDuration" min="0">
                    </div>

                    <h3 style="margin:32px 0 20px;padding-top:20px;border-top:1px solid var(--border);">用户注册</h3>

                    <div class="form-group">
                        <label>允许注册</label>
                        <select name="allow_register" id="settingAllowRegister">
                            <option value="1">允许</option>
                            <option value="0">关闭</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>注册时必须填邮箱</label>
                        <select name="require_email_register" id="settingRequireEmail">
                            <option value="1">是</option>
                            <option value="0">否</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>允许邮箱找回密码</label>
                        <select name="allow_password_reset" id="settingAllowReset">
                            <option value="1">允许</option>
                            <option value="0">关闭</option>
                        </select>
                    </div>

                    <h3 style="margin:32px 0 20px;padding-top:20px;border-top:1px solid var(--border);">邮箱 SMTP 配置</h3>
                    <p style="font-size:13px;color:var(--text-muted);margin-bottom:16px;">用于发送密码找回邮件。填写后建议点"测试连接"验证配置</p>

                    <div class="form-group">
                        <label>启用 SMTP</label>
                        <select name="smtp_enabled" id="settingSmtpEnabled">
                            <option value="0">关闭</option>
                            <option value="1">开启</option>
                        </select>
                    </div>
                    <div style="display:flex;gap:12px;">
                        <div class="form-group" style="flex:2;">
                            <label>SMTP 服务器</label>
                            <input type="text" name="smtp_host" id="settingSmtpHost" placeholder="smtp.exmail.qq.com">
                        </div>
                        <div class="form-group" style="flex:1;">
                            <label>端口</label>
                            <input type="number" name="smtp_port" id="settingSmtpPort" placeholder="465">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>加密方式</label>
                        <select name="smtp_encryption" id="settingSmtpEncryption">
                            <option value="ssl">SSL</option>
                            <option value="tls">TLS</option>
                            <option value="none">无</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>邮箱账号</label>
                        <input type="text" name="smtp_username" id="settingSmtpUsername" placeholder="your@email.com">
                    </div>
                    <div class="form-group">
                        <label>邮箱授权码</label>
                        <input type="password" name="smtp_password" id="settingSmtpPassword" placeholder="邮箱授权码（非登录密码）">
                    </div>
                    <div style="display:flex;gap:12px;">
                        <div class="form-group" style="flex:2;">
                            <label>发件人邮箱</label>
                            <input type="text" name="smtp_from_email" id="settingSmtpFromEmail" placeholder="your@email.com">
                        </div>
                        <div class="form-group" style="flex:1;">
                            <label>发件人名称</label>
                            <input type="text" name="smtp_from_name" id="settingSmtpFromName" placeholder="NAS影视库">
                        </div>
                    </div>
                    <div style="display:flex;gap:12px;margin-bottom:20px;">
                        <button type="button" class="btn btn-outline" id="testSmtpBtn">测试连接</button>
                    </div>

                    <button type="submit" class="btn btn-primary">保存设置</button>
                </form>
            </section>

            <!-- 关于 -->
            <section class="admin-section" id="tab-about">
                <h2>关于</h2>
                <div id="aboutContent"></div>
            </section>
        </main>
    </div>

    <!-- 添加媒体库弹窗 -->
    <div class="modal-overlay" id="libraryModal">
        <div class="modal-content" style="max-width:600px;">
            <div class="modal-header">
                <h3>添加媒体库</h3>
                <button class="modal-close" onclick="document.getElementById('libraryModal').classList.remove('active')">&times;</button>
            </div>
            <form id="addLibraryForm" style="padding:24px;">
                <div class="form-group">
                    <label>名称</label>
                    <input type="text" name="name" required placeholder="如：我的电影">
                </div>
                <div class="form-group">
                    <label>选择文件夹</label>
                    <div style="display:flex;gap:8px;margin-bottom:8px;">
                        <input type="text" name="path" id="libraryPath" required placeholder="点击浏览选择或手动输入" style="flex:1;">
                        <button type="button" class="btn btn-outline" id="browseBtn">浏览</button>
                    </div>
                    <div id="fileBrowser" style="display:none;background:rgba(0,0,0,0.2);border:1px solid var(--border);border-radius:8px;max-height:320px;overflow-y:auto;">
                        <div id="browseHeader" style="padding:8px 12px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px;font-size:13px;">
                            <span style="color:var(--text-muted);">点击文件夹进入，点"选择此目录"确认</span>
                        </div>
                        <div id="browseList" style="padding:4px;"></div>
                    </div>
                    <small>支持中文文件夹名，如 /volume1/video/大陆电视剧</small>
                    <div id="browsePermHint" style="display:none;margin-top:8px;padding:10px 14px;background:rgba(245,158,11,0.1);border:1px solid rgba(245,158,11,0.3);border-radius:6px;font-size:12px;color:#fbbf24;">
                        <strong>群晖用户注意：</strong>如果子目录显示为空，请到 <strong>控制面板 → 共享文件夹 → 编辑 → 权限</strong> 中给 <strong>http</strong> 用户添加读取权限。
                    </div>
                </div>
                <div class="form-group">
                    <label>类型</label>
                    <select name="type">
                        <option value="movie">电影</option>
                        <option value="tv">剧集</option>
                        <option value="other">其他</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">添加</button>
            </form>
        </div>
    </div>

    <!-- 手动匹配弹窗 -->
    <div class="modal-overlay" id="matchModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>手动匹配</h3>
                <button class="modal-close" onclick="document.getElementById('matchModal').classList.remove('active')">&times;</button>
            </div>
            <div class="match-search">
                <input type="text" id="matchSearchInput" placeholder="搜索影片名称...">
                <button class="btn btn-primary" id="matchSearchBtn">搜索</button>
            </div>
            <div class="match-results" id="matchResults"></div>
        </div>
    </div>

    <!-- 编辑元数据弹窗 -->
    <div class="modal-overlay" id="editMetaModal">
        <div class="modal-content" style="max-width:560px;">
            <div class="modal-header">
                <h3>编辑元数据</h3>
                <button class="modal-close" onclick="document.getElementById('editMetaModal').classList.remove('active')">&times;</button>
            </div>
            <form id="editMetaForm" style="padding:24px;">
                <input type="hidden" id="editMetaId">
                <div class="form-group">
                    <label>标题</label>
                    <input type="text" id="editMetaTitle" required>
                </div>
                <div class="form-group">
                    <label>原始标题</label>
                    <input type="text" id="editMetaOriginalTitle">
                </div>
                <div style="display:flex;gap:12px;">
                    <div class="form-group" style="flex:1;">
                        <label>年份</label>
                        <input type="number" id="editMetaYear" min="1900" max="2099">
                    </div>
                    <div class="form-group" style="flex:2;">
                        <label>类型</label>
                        <select id="editMetaType">
                            <option value="movie">电影</option>
                            <option value="tv">剧集</option>
                            <option value="other">其他</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label>简介</label>
                    <textarea id="editMetaOverview" rows="4" style="width:100%;padding:10px 14px;background:rgba(255,255,255,0.06);border:1px solid var(--border);border-radius:8px;color:var(--text-primary);font-size:14px;resize:vertical;outline:none;"></textarea>
                </div>
                <div class="form-group">
                    <label>类型标签 (逗号分隔)</label>
                    <input type="text" id="editMetaGenres" placeholder="如: 剧情, 历史, 家庭">
                </div>
                <div class="form-group">
                    <label>TMDB ID <a href="#" id="editMetaTmdbLink" target="_blank" style="color:#e50914;font-size:12px;margin-left:4px;">搜索 ↗</a></label>
                    <input type="number" id="editMetaTmdbId">
                </div>
                <div class="form-group">
                    <label>
                        <input type="checkbox" id="editMetaVip"> VIP 专属内容
                    </label>
                    <small style="color:var(--text-muted);display:block;margin-top:4px;">开启后仅VIP权限组用户可观看</small>
                </div>
                <button type="submit" class="btn btn-primary">保存</button>
            </form>
        </div>
    </div>

    <!-- 权限组弹窗 -->
    <div class="modal-overlay" id="groupModal">
        <div class="modal-content" style="max-width:500px;">
            <div class="modal-header">
                <h3 id="groupModalTitle">新建权限组</h3>
                <button class="modal-close" onclick="document.getElementById('groupModal').classList.remove('active')">&times;</button>
            </div>
            <form id="groupForm" style="padding:24px;">
                <input type="hidden" id="groupEditId">
                <div class="form-group">
                    <label>组名</label>
                    <input type="text" id="groupName" required>
                </div>
                <div class="form-group">
                    <label>
                        <input type="checkbox" id="groupCanSeeAll" checked> 可查看全部影片
                    </label>
                    <small style="color:var(--text-muted);display:block;margin-top:4px;">关闭后只能看非VIP影片（需先设置影片VIP标记）</small>
                </div>
                <div class="form-group">
                    <label>剧集每季最多集数（0=不限制）</label>
                    <input type="number" id="groupEpisodeLimit" value="0" min="0">
                </div>
                <div class="form-group">
                    <label>电影最多播放分钟数（0=不限制）</label>
                    <input type="number" id="groupMovieLimit" value="0" min="0">
                </div>
                <div class="form-group">
                    <label>
                        <input type="checkbox" id="groupIsDefault"> 设为默认组（新注册用户自动加入）
                    </label>
                </div>
                <button type="submit" class="btn btn-primary">保存</button>
            </form>
        </div>
    </div>

    <!-- 添加用户弹窗 -->
    <div class="modal-overlay" id="userModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>添加用户</h3>
                <button class="modal-close" onclick="document.getElementById('userModal').classList.remove('active')">&times;</button>
            </div>
            <form id="addUserForm">
                <div class="form-group">
                    <label>用户名</label>
                    <input type="text" name="username" required minlength="3">
                </div>
                <div class="form-group">
                    <label>显示名称</label>
                    <input type="text" name="display_name">
                </div>
                <div class="form-group">
                    <label>密码</label>
                    <input type="password" name="password" required minlength="6">
                </div>
                <div class="form-group">
                    <label>角色</label>
                    <select name="role">
                        <option value="user">普通用户</option>
                        <option value="admin">管理员</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>权限组</label>
                    <select name="group_id" id="userGroupSelect">
                        <option value="1">普通用户</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">创建</button>
            </form>
        </div>
    </div>

    <script>
    window.onerror = function(msg, url, line) {
        document.getElementById('statsGrid').innerHTML = '<div style="color:#ff6b6b;padding:20px;">JS错误: ' + msg + ' (行' + line + ')</div>';
    };
    </script>
    <script src="/assets/js/admin.js"></script>
</body>
</html>
