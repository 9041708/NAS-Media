(() => {
    'use strict';

    try {

    const $ = (sel) => document.querySelector(sel);
    const $$ = (sel) => document.querySelectorAll(sel);

    async function api(url, options = {}) {
        try {
            const res = await fetch(url, {
                headers: { 'Content-Type': 'application/json', ...options.headers },
                ...options,
            });
            const text = await res.text();
            try {
                return JSON.parse(text);
            } catch (e) {
                console.error('API 非JSON响应:', url, text.substring(0, 500));
                const preview = text.substring(0, 200).replace(/</g, '&lt;');
                return { error: '服务器错误: ' + preview };
            }
        } catch (e) {
            console.error('API 请求失败:', e);
            return { error: e.message };
        }
    }

    function toast(msg, type = 'success') {
        const el = document.createElement('div');
        el.className = `toast ${type}`;
        el.textContent = msg;
        document.body.appendChild(el);
        setTimeout(() => el.classList.add('show'), 10);
        setTimeout(() => {
            el.classList.remove('show');
            setTimeout(() => el.remove(), 300);
        }, 3000);
    }

    function formatBytes(bytes) {
        bytes = parseFloat(bytes) || 0;
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        let i = 0;
        while (bytes >= 1024 && i < units.length - 1) {
            bytes /= 1024;
            i++;
        }
        return bytes.toFixed(2) + ' ' + units[i];
    }

    // Dashboard
    async function loadDashboard() {
        const grid = $('#statsGrid');
        if (!grid) return;

        try {
            const res = await fetch('/api/media.php?action=stats');
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const stats = await res.json();
            if (stats.error) throw new Error(stats.error);

            grid.innerHTML = `
                <div class="stat-card">
                    <div class="stat-icon" style="background:rgba(229,9,20,0.15);color:#e50914;">🎬</div>
                    <div class="stat-value">${stats.total_movies || 0}</div>
                    <div class="stat-label">电影</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background:rgba(59,130,246,0.15);color:#3b82f6;">📺</div>
                    <div class="stat-value">${stats.total_tv || 0}</div>
                    <div class="stat-label">剧集</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background:rgba(16,185,129,0.15);color:#10b981;">📁</div>
                    <div class="stat-value">${stats.total_files || 0}</div>
                    <div class="stat-label">文件数</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background:rgba(245,158,11,0.15);color:#f59e0b;">💾</div>
                    <div class="stat-value">${formatBytes(stats.total_size || 0)}</div>
                    <div class="stat-label">总大小</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background:rgba(168,85,247,0.15);color:#a855f7;">▶️</div>
                    <div class="stat-value">${stats.total_plays || 0}</div>
                    <div class="stat-label">播放次数</div>
                </div>
            `;

            try {
                const actRes = await fetch('/api/activity.php?action=stats');
                if (actRes.ok) {
                    const actData = await actRes.json();
                    grid.innerHTML += `
                        <div class="stat-card" style="border:1px solid rgba(16,185,129,0.3);">
                            <div class="stat-icon" style="background:rgba(16,185,129,0.15);color:#10b981;">👁</div>
                            <div class="stat-value" style="color:#10b981;">${actData.active || 0}</div>
                            <div class="stat-label">正在观看</div>
                        </div>
                    `;
                }
            } catch (e) { /* ignore */ }

        } catch (e) {
            console.error('加载仪表盘失败:', e);
            grid.innerHTML = `
                <div class="stat-card">
                    <div class="stat-icon" style="background:rgba(229,9,20,0.15);color:#e50914;">🎬</div>
                    <div class="stat-value">0</div>
                    <div class="stat-label">电影</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background:rgba(59,130,246,0.15);color:#3b82f6;">📺</div>
                    <div class="stat-value">0</div>
                    <div class="stat-label">剧集</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background:rgba(16,185,129,0.15);color:#10b981;">📁</div>
                    <div class="stat-value">0</div>
                    <div class="stat-label">文件数</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background:rgba(245,158,11,0.15);color:#f59e0b;">💾</div>
                    <div class="stat-value">0 B</div>
                    <div class="stat-label">总大小</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background:rgba(168,85,247,0.15);color:#a855f7;">▶️</div>
                    <div class="stat-value">0</div>
                    <div class="stat-label">播放次数</div>
                </div>
            `;
        }
    }

    // Libraries
    async function loadLibraries() {
        try {
            const res = await fetch('/api/scan.php?action=list_libraries');
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const libs = await res.json();
            if (libs.error) throw new Error(libs.error);

            const list = $('#libraryList');
            if (!list) return;

            if (!Array.isArray(libs) || libs.length === 0) {
                list.innerHTML = '<div class="empty-state"><h3>暂无媒体库</h3><p>点击"添加媒体库"开始</p></div>';
                return;
            }
            list.innerHTML = libs.map(lib => `
                <div class="library-card" data-id="${lib.id}">
                    <div class="lib-info">
                        <div class="lib-name">${escHtml(lib.name)}</div>
                        <div class="lib-path">${escHtml(lib.path)}</div>
                        <div class="lib-meta">
                            <span>类型: ${lib.type === 'movie' ? '电影' : lib.type === 'tv' ? '剧集' : '其他'}</span>
                            <span>文件: ${lib.file_count || 0}</span>
                            <span>上次扫描: ${lib.last_scan || '从未'}</span>
                        </div>
                    </div>
                    <div class="lib-actions">
                        <button class="btn btn-sm btn-primary scan-lib-btn" data-id="${lib.id}">扫描</button>
                        <button class="btn btn-sm btn-outline delete-lib-btn" data-id="${lib.id}">删除</button>
                    </div>
                </div>
            `).join('');

            $$('.scan-lib-btn').forEach(btn => {
                btn.addEventListener('click', () => scanLibrary(btn.dataset.id));
            });
            $$('.delete-lib-btn').forEach(btn => {
                btn.addEventListener('click', () => deleteLibrary(btn.dataset.id));
            });
        } catch (e) {
            console.error('加载媒体库失败:', e);
            const list = $('#libraryList');
            if (list) list.innerHTML = '<div class="error">加载失败: ' + escHtml(e.message) + '</div>';
        }
    }

    // Add library
    $('#addLibraryBtn').addEventListener('click', () => {
        $('#libraryModal').classList.add('active');
    });

    $('#addLibraryForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const form = new FormData(e.target);
        try {
            await api('/api/scan.php?action=add_library', {
                method: 'POST',
                body: JSON.stringify({
                    name: form.get('name'),
                    path: form.get('path'),
                    type: form.get('type'),
                }),
            });
            toast('媒体库添加成功');
            $('#libraryModal').classList.remove('active');
            e.target.reset();
            loadLibraries();
        } catch (err) {
            toast('添加失败: ' + err.message, 'error');
        }
    });

    async function scanLibrary(id) {
        // 切换到扫描页面
        $$('.nav-item').forEach(i => i.classList.remove('active'));
        const scanTab = $('[data-tab="scan"]');
        if (scanTab) scanTab.classList.add('active');
        $$('.admin-section').forEach(s => s.classList.remove('active'));
        const scanSection = $('#tab-scan');
        if (scanSection) scanSection.classList.add('active');

        const log = $('#scanOutput');
        const progressContainer = $('#scanProgress');

        // 创建进度条（如果没有）
        if (!progressContainer) {
            const logParent = log?.parentElement;
            if (logParent) {
                logParent.insertAdjacentHTML('afterbegin', `
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
                `);
            }
        }

        const progress = $('#scanProgress');
        const bar = $('#scanBar');
        const status = $('#scanStatus');
        const percent = $('#scanPercent');
        const fileEl = $('#scanFile');

        if (progress) progress.style.display = 'block';
        if (bar) bar.style.width = '0%';
        if (status) status.textContent = '扫描中...';
        if (percent) percent.textContent = '0%';
        if (log) log.textContent = '正在扫描...\n';

        // 轮询进度
        let polling = true;
        const pollProgress = async () => {
            while (polling) {
                try {
                    const res = await fetch(`/api/scan.php?action=scan_progress&library_id=${id}`);
                    if (res.ok) {
                        const p = await res.json();
                        if (p.status === 'scanning' || p.status === 'completed' || p.status === 'failed') {
                            if (bar) bar.style.width = (p.percent || 0) + '%';
                            if (percent) percent.textContent = (p.percent || 0) + '%';
                            if (status) status.textContent = p.status === 'scanning' ? `扫描中 (${p.current}/${p.total})` : p.status === 'completed' ? '扫描完成' : '扫描失败';
                            if (fileEl) fileEl.textContent = p.file_name || '';
                        }
                    }
                } catch (e) { /* ignore */ }
                await new Promise(r => setTimeout(r, 1500));
            }
        };
        pollProgress();

        try {
            const result = await api(`/api/scan.php?action=start&library_id=${id}`, { method: 'POST' });
            polling = false;

            if (result.error) {
                if (log) log.textContent += `扫描失败: ${result.error}\n`;
                if (status) status.textContent = '扫描失败';
                if (bar) bar.style.background = '#e50914';
                toast('扫描失败: ' + result.error, 'error');
                return;
            }

            const r = result.result || {};
            if (bar) bar.style.width = '100%';
            if (percent) percent.textContent = '100%';
            if (status) status.textContent = '扫描完成';

            if (log) {
                log.textContent += `扫描完成！\n`;
                log.textContent += `总计: ${r.total || 0} 个文件\n`;
                log.textContent += `新增: ${r.new || 0}\n`;
                log.textContent += `更新: ${r.updated || 0}\n`;
                if (r.errors && r.errors.length > 0) {
                    log.textContent += `\n错误 (${r.errors.length}):\n`;
                    r.errors.forEach(err => { log.textContent += `  - ${err}\n`; });
                }
            }
            toast('扫描完成');
            loadLibraries();
        } catch (e) {
            polling = false;
            if (log) log.textContent += `扫描失败: ${e.message}\n`;
            if (status) status.textContent = '扫描失败';
            toast('扫描失败', 'error');
        }
    }

    async function deleteLibrary(id) {
        if (!confirm('确定删除此媒体库？关联的文件记录也会被删除。')) return;
        try {
            await api('/api/scan.php?action=delete_library', {
                method: 'POST',
                body: JSON.stringify({ id: parseInt(id) }),
            });
            toast('已删除');
            loadLibraries();
        } catch (e) {
            toast('删除失败', 'error');
        }
    }

    // Scan controls
    async function loadScanControls() {
        try {
            const libs = await api('/api/scan.php?action=list_libraries');
            const controls = $('#scanControls');
            controls.innerHTML = libs.map(lib => `
                <button class="btn btn-primary scan-all-btn" data-id="${lib.id}">
                    扫描: ${escHtml(lib.name)}
                </button>
            `).join('') + `
                <button class="btn btn-outline" id="scanAllBtn">全部扫描</button>
            `;

            $$('.scan-all-btn').forEach(btn => {
                btn.addEventListener('click', () => scanLibrary(btn.dataset.id));
            });
            $('#scanAllBtn').addEventListener('click', async () => {
                for (const lib of libs) {
                    await scanLibrary(lib.id);
                }
            });
        } catch (e) {
            console.error('加载扫描控件失败:', e);
        }
    }

    // Metadata Management
    async function loadMetadata(search = '', type = '') {
        try {
            let url = '/api/media.php?action=metadata_list';
            if (search) url += '&search=' + encodeURIComponent(search);
            if (type) url += '&type=' + encodeURIComponent(type);
            const items = await api(url);
            const list = $('#metadataList');
            if (!items || items.length === 0) {
                list.innerHTML = '<div class="empty-state"><h3>没有匹配的媒体</h3><p>请先扫描媒体库以获取元数据</p></div>';
                return;
            }
            list.innerHTML = `
                <table class="meta-table">
                    <thead><tr>
                        <th style="width:60px;"></th>
                        <th>标题</th>
                        <th style="width:70px;">年份</th>
                        <th style="width:60px;">类型</th>
                        <th style="width:60px;">文件</th>
                        <th style="width:140px;">操作</th>
                    </tr></thead>
                    <tbody>${items.map(m => {
                        const poster = m.poster_path ? `<img src="https://image.tmdb.org/t/p/w92${m.poster_path}" style="width:44px;height:66px;object-fit:cover;border-radius:4px;">` : '';
                        return `<tr>
                            <td>${poster}</td>
                            <td>
                                <div class="meta-title">${escHtml(m.title)}</div>
                                ${m.original_title && m.original_title !== m.title ? `<div class="meta-sub">${escHtml(m.original_title)}</div>` : ''}
                            </td>
                            <td>${m.year || '-'}</td>
                            <td>${m.type === 'tv' ? '剧集' : m.type === 'movie' ? '电影' : '其他'}</td>
                            <td>${m.file_count || 0}</td>
                            <td>
                                <div class="meta-actions">
                                    <button class="btn btn-sm btn-outline edit-meta-btn" data-id="${m.id}" data-title="${escAttr(m.title)}" data-otitle="${escAttr(m.original_title)}" data-year="${m.year || ''}" data-type="${m.type || 'movie'}" data-genres="${escAttr(m.genres)}" data-tmdb="${m.tmdb_id || ''}" data-overview="${escAttr(m.overview)}" data-vip="${m.vip_only || 0}">编辑</button>
                                    <button class="btn btn-sm btn-primary refresh-meta-btn" data-id="${m.id}">刷新</button>
                                </div>
                            </td>
                        </tr>`;
                    }).join('')}</tbody>
                </table>
            `;

            $$('.edit-meta-btn').forEach(btn => {
                btn.addEventListener('click', () => openEditMetaModal(btn));
            });
            $$('.refresh-meta-btn').forEach(btn => {
                btn.addEventListener('click', () => refreshMetadata(btn.dataset.id));
            });
        } catch (e) {
            console.error('加载元数据失败:', e);
        }
    }

    function escAttr(str) {
        return (str || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function openEditMetaModal(btn) {
        $('#editMetaId').value = btn.dataset.id;
        $('#editMetaTitle').value = btn.dataset.title || '';
        $('#editMetaOriginalTitle').value = btn.dataset.otitle || '';
        $('#editMetaYear').value = btn.dataset.year || '';
        $('#editMetaType').value = btn.dataset.type || 'movie';
        $('#editMetaOverview').value = btn.dataset.overview || '';
        $('#editMetaGenres').value = btn.dataset.genres || '';
        $('#editMetaTmdbId').value = btn.dataset.tmdb || '';
        $('#editMetaVip').checked = (btn.dataset.vip == '1');
        $('#editMetaModal').classList.add('active');
    }

    async function refreshMetadata(mediaId) {
        if (!confirm('确定要从 TMDB 重新获取此媒体的元数据？')) return;
        try {
            const res = await api('/api/scan.php?action=refresh_meta', {
                method: 'POST',
                body: JSON.stringify({ media_id: mediaId }),
            });
            if (res.success) {
                alert('元数据已刷新');
            } else {
                alert('刷新失败: ' + (res.error || '未知错误'));
            }
        } catch (e) {
            alert('刷新失败: 网络错误');
        }
        loadMetadata();
    }

    $('#editMetaForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        try {
            const res = await api('/api/media.php?action=update_metadata', {
                method: 'POST',
                body: JSON.stringify({
                    media_id: parseInt($('#editMetaId').value),
                    title: $('#editMetaTitle').value.trim(),
                    original_title: $('#editMetaOriginalTitle').value.trim(),
                    year: parseInt($('#editMetaYear').value) || null,
                    type: $('#editMetaType').value,
                    overview: $('#editMetaOverview').value.trim(),
                    genres: $('#editMetaGenres').value.trim(),
                    tmdb_id: parseInt($('#editMetaTmdbId').value) || null,
                    vip_only: $('#editMetaVip').checked ? 1 : 0,
                }),
            });
            if (res.success) {
                $('#editMetaModal').classList.remove('active');
                loadMetadata();
            } else {
                alert('保存失败: ' + (res.error || '未知错误'));
            }
        } catch (e) {
            alert('保存失败: 网络错误');
        }
    });

    $('#metadataSearchBtn').addEventListener('click', () => {
        loadMetadata($('#metadataSearch').value.trim(), $('#metadataType').value);
    });
    $('#metadataSearch').addEventListener('keyup', (e) => {
        if (e.key === 'Enter') loadMetadata($('#metadataSearch').value.trim(), $('#metadataType').value);
    });

    let currentMatchFileId = null;

    function openMatchModal(fileId, fileName) {
        currentMatchFileId = fileId;
        $('#matchSearchInput').value = fileName.replace(/\.\w+$/, '').replace(/[\.\-_]/g, ' ');
        $('#matchResults').innerHTML = '';
        $('#matchModal').classList.add('active');
    }

    $('#matchSearchBtn').addEventListener('click', searchTmdb);
    $('#matchSearchInput').addEventListener('keyup', (e) => {
        if (e.key === 'Enter') searchTmdb();
    });

    async function searchTmdb() {
        const query = $('#matchSearchInput').value.trim();
        if (!query) return;
        try {
            const results = await api(`/api/media.php?action=search_tmdb&q=${encodeURIComponent(query)}`);
            const container = $('#matchResults');
            if (results.length === 0) {
                container.innerHTML = '<p style="color:var(--text-muted);padding:16px;">未找到结果</p>';
                return;
            }
            container.innerHTML = results.map(r => `
                <div class="match-result-item" data-tmdb="${r.tmdb_id}">
                    <img src="${r.poster_path ? 'https://image.tmdb.org/t/p/w92' + r.poster_path : '/assets/images/no-poster.svg'}" alt="">
                    <div>
                        <div class="match-title">${escHtml(r.title)} ${r.year ? `(${r.year})` : ''}</div>
                        <div class="match-meta">评分: ${r.rating} | ${escHtml(r.overview || '').substring(0, 80)}</div>
                    </div>
                </div>
            `).join('');

            $$('.match-result-item').forEach(item => {
                item.addEventListener('click', () => matchMedia(item.dataset.tmdb));
            });
        } catch (e) {
            toast('搜索失败', 'error');
        }
    }

    async function matchMedia(tmdbId) {
        if (!currentMatchFileId) return;
        try {
            await api('/api/media.php?action=match_media', {
                method: 'POST',
                body: JSON.stringify({
                    file_id: parseInt(currentMatchFileId),
                    tmdb_id: parseInt(tmdbId),
                }),
            });
            toast('匹配成功');
            $('#matchModal').classList.remove('active');
            loadUnmatched();
        } catch (e) {
            toast('匹配失败', 'error');
        }
    }

    // Settings
    async function loadSettings() {
        try {
            const settings = await api('/api/scan.php?action=get_settings');
            if (!settings || settings.error) { console.error('加载设置失败:', settings?.error); return; }
            if ($('#settingSiteName')) $('#settingSiteName').value = settings.site_name || '';
            if ($('#settingTmdbKey')) $('#settingTmdbKey').value = settings.tmdb_api_key || '';
            if ($('#settingPosterLang')) $('#settingPosterLang').value = settings.poster_lang || 'zh-CN';
            if ($('#settingScanInterval')) $('#settingScanInterval').value = settings.scan_interval || '3600';
            if ($('#settingTheme')) $('#settingTheme').value = settings.theme || 'dark';
            if ($('#settingFfmpegPath')) $('#settingFfmpegPath').value = settings.ffmpeg_path || 'ffmpeg';
            if ($('#settingFfprobePath')) $('#settingFfprobePath').value = settings.ffprobe_path || 'ffprobe';
            if ($('#settingTranscodeEnabled')) $('#settingTranscodeEnabled').value = settings.transcode_enabled || '0';
            if ($('#settingHlsDir')) $('#settingHlsDir').value = settings.hls_output_dir || '';
            if ($('#settingIntroDuration')) $('#settingIntroDuration').value = settings.default_intro_duration || '90';
            if ($('#settingOutroDuration')) $('#settingOutroDuration').value = settings.default_outro_duration || '60';
            if ($('#settingAllowRegister')) $('#settingAllowRegister').value = settings.allow_register || '1';
            if ($('#settingRequireEmail')) $('#settingRequireEmail').value = settings.require_email_register || '1';
            if ($('#settingAllowReset')) $('#settingAllowReset').value = settings.allow_password_reset || '1';
            if ($('#settingSmtpEnabled')) $('#settingSmtpEnabled').value = settings.smtp_enabled || '0';
            if ($('#settingSmtpHost')) $('#settingSmtpHost').value = settings.smtp_host || '';
            if ($('#settingSmtpPort')) $('#settingSmtpPort').value = settings.smtp_port || '465';
            if ($('#settingSmtpEncryption')) $('#settingSmtpEncryption').value = settings.smtp_encryption || 'ssl';
            if ($('#settingSmtpUsername')) $('#settingSmtpUsername').value = settings.smtp_username || '';
            if ($('#settingSmtpFromEmail')) $('#settingSmtpFromEmail').value = settings.smtp_from_email || '';
            if ($('#settingSmtpFromName')) $('#settingSmtpFromName').value = settings.smtp_from_name || 'NAS影视库';

            // 应用已保存的主题
            applyTheme(settings.theme || 'dark');
        } catch (e) {
            console.error('加载设置失败:', e);
        }
        checkFfmpegStatus();
    }

    // 主题即时预览
    function applyTheme(theme) {
        document.body.classList.remove('dark-theme', 'light-theme');
        document.body.classList.add(theme === 'light' ? 'light-theme' : 'dark-theme');
    }

    if ($('#settingTheme')) {
        $('#settingTheme').addEventListener('change', (e) => {
            applyTheme(e.target.value);
        });
    }

    // 页面加载时应用已保存的主题
    setTimeout(async () => {
        try {
            const settings = await api('/api/scan.php?action=get_settings');
            if (settings && settings.theme) applyTheme(settings.theme);
        } catch (e) { /* ignore */ }
    }, 0);

    async function checkFfmpegStatus() {
        const el = $('#ffmpegStatusText');
        if (!el) return;
        try {
            const settings = await api('/api/scan.php?action=get_settings');
            const ffmpegPath = settings.ffmpeg_path || '';
            const ffprobePath = settings.ffprobe_path || '';
            if (ffmpegPath && ffprobePath) {
                el.innerHTML = `<span style="color:#10b981;">已找到</span> FFmpeg: ${escHtml(ffmpegPath)}`;
                if ($('#settingFfmpegPath') && !$('#settingFfmpegPath').value) $('#settingFfmpegPath').value = ffmpegPath;
                if ($('#settingFfprobePath') && !$('#settingFfprobePath').value) $('#settingFfprobePath').value = ffprobePath;
            } else {
                el.innerHTML = '<span style="color:#f59e0b;">未检测到</span> 点击"自动检测"或手动填入路径';
            }
        } catch (e) {
            el.textContent = '检测失败';
        }
    }

    if ($('#detectFfmpegBtn')) {
        $('#detectFfmpegBtn').addEventListener('click', async () => {
            const btn = $('#detectFfmpegBtn');
            const el = $('#ffmpegStatusText');
            btn.disabled = true;
            btn.textContent = '检测中...';
            try {
                const res = await api('/api/scan.php?action=detect_ffmpeg');
                if (res.found) {
                    el.innerHTML = `<span style="color:#10b981;">已找到</span> ${escHtml(res.ffmpeg)} ${res.version ? '(v' + res.version + ')' : ''}`;
                    if ($('#settingFfmpegPath')) $('#settingFfmpegPath').value = res.ffmpeg || '';
                    if ($('#settingFfprobePath')) $('#settingFfprobePath').value = res.ffprobe || '';
                    toast('FFmpeg 已自动识别');
                } else {
                    el.innerHTML = '<span style="color:#e50914;">未找到</span> 请安装 FFmpeg 或手动填入路径';
                    toast('未找到 FFmpeg', 'error');
                }
            } catch (e) {
                el.textContent = '检测失败: ' + e.message;
            }
            btn.disabled = false;
            btn.textContent = '自动检测';
        });
    }

    $('#settingsForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const data = {
            site_name: $('#settingSiteName').value,
            tmdb_api_key: $('#settingTmdbKey').value,
            poster_lang: $('#settingPosterLang').value,
            scan_interval: $('#settingScanInterval').value,
            theme: $('#settingTheme').value,
        };
        if ($('#settingFfmpegPath')) data.ffmpeg_path = $('#settingFfmpegPath').value;
        if ($('#settingFfprobePath')) data.ffprobe_path = $('#settingFfprobePath').value;
        if ($('#settingTranscodeEnabled')) data.transcode_enabled = $('#settingTranscodeEnabled').value;
        if ($('#settingHlsDir')) data.hls_output_dir = $('#settingHlsDir').value;
        if ($('#settingIntroDuration')) data.default_intro_duration = $('#settingIntroDuration').value;
        if ($('#settingOutroDuration')) data.default_outro_duration = $('#settingOutroDuration').value;
        if ($('#settingAllowRegister')) data.allow_register = $('#settingAllowRegister').value;
        if ($('#settingRequireEmail')) data.require_email_register = $('#settingRequireEmail').value;
        if ($('#settingAllowReset')) data.allow_password_reset = $('#settingAllowReset').value;
        if ($('#settingSmtpEnabled')) data.smtp_enabled = $('#settingSmtpEnabled').value;
        if ($('#settingSmtpHost')) data.smtp_host = $('#settingSmtpHost').value;
        if ($('#settingSmtpPort')) data.smtp_port = $('#settingSmtpPort').value;
        if ($('#settingSmtpEncryption')) data.smtp_encryption = $('#settingSmtpEncryption').value;
        if ($('#settingSmtpUsername')) data.smtp_username = $('#settingSmtpUsername').value;
        if ($('#settingSmtpPassword')) data.smtp_password = $('#settingSmtpPassword').value;
        if ($('#settingSmtpFromEmail')) data.smtp_from_email = $('#settingSmtpFromEmail').value;
        if ($('#settingSmtpFromName')) data.smtp_from_name = $('#settingSmtpFromName').value;
        try {
            await api('/api/scan.php?action=update_settings', {
                method: 'POST',
                body: JSON.stringify(data),
            });
            toast('设置已保存');
        } catch (e) {
            toast('保存失败', 'error');
        }
    });

    // ===== 用户管理 =====
    async function loadUsers() {
        try {
            const users = await api('/api/auth.php?action=list_users');
            const list = $('#userList');
            const groups = await api('/api/auth.php?action=list_groups');
            const groupMap = {};
            groups.forEach(g => groupMap[g.id] = g.name);

            list.innerHTML = users.map(u => {
                const groupName = groupMap[u.group_id] || '普通用户';
                return `<div class="library-card" data-id="${u.id}">
                    <div class="lib-info">
                        <div class="lib-name">${escHtml(u.display_name || u.username)} ${u.role === 'admin' ? '<span class="admin-badge" style="font-size:11px;margin-left:8px;">管理员</span>' : ''}</div>
                        <div class="lib-meta">
                            <span>用户名: ${escHtml(u.username)}</span>
                            <span>权限组: ${escHtml(groupName)}</span>
                            ${u.email ? `<span>邮箱: ${escHtml(u.email)}</span>` : ''}
                            <span>上次登录: ${u.last_login || '从未'}</span>
                        </div>
                    </div>
                    <div class="lib-actions">
                        <button class="btn btn-sm btn-outline edit-user-btn" data-id="${u.id}" data-name="${escHtml(u.username)}" data-role="${u.role}" data-groupid="${u.group_id}" data-display="${escHtml(u.display_name || '')}">编辑</button>
                        <button class="btn btn-sm btn-outline delete-user-btn" data-id="${u.id}">删除</button>
                    </div>
                </div>`;
            }).join('');

            $$('.delete-user-btn').forEach(btn => {
                btn.addEventListener('click', async () => {
                    if (!confirm('确定删除此用户？')) return;
                    try {
                        await api('/api/auth.php?action=delete_user', { method: 'POST', body: JSON.stringify({ id: parseInt(btn.dataset.id) }) });
                        toast('用户已删除');
                        loadUsers();
                    } catch (e) { toast('删除失败: ' + e.message, 'error'); }
                });
            });

            $$('.edit-user-btn').forEach(btn => {
                btn.addEventListener('click', async () => {
                    const userId = parseInt(btn.dataset.id);
                    const curRole = btn.dataset.role;
                    const curGroupId = parseInt(btn.dataset.groupid);
                    const groupOptions = groups.map(g => `<option value="${g.id}" ${g.id === curGroupId ? 'selected' : ''}>${escHtml(g.name)}</option>`).join('');
                    const roleOptions = `<option value="user" ${curRole === 'user' ? 'selected' : ''}>普通用户</option><option value="admin" ${curRole === 'admin' ? 'selected' : ''}>管理员</option>`;
                    const html = `<div style="padding:20px;"><div class="form-group"><label>角色</label><select id="editUserRole">${roleOptions}</select></div><div class="form-group"><label>权限组</label><select id="editUserGroup">${groupOptions}</select></div><button class="btn btn-primary" id="saveUserEdit">保存</button></div>`;

                    const wrapper = document.createElement('div');
                    wrapper.innerHTML = html;
                    wrapper.querySelector('#saveUserEdit').addEventListener('click', async () => {
                        const newRole = wrapper.querySelector('#editUserRole').value;
                        const newGroupId = parseInt(wrapper.querySelector('#editUserGroup').value);
                        await api('/api/auth.php?action=update_user', { method: 'POST', body: JSON.stringify({ id: userId, role: newRole, group_id: newGroupId }) });
                        toast('已更新');
                        loadUsers();
                    });
                    const card = btn.closest('.library-card');
                    card.after(wrapper);
                });
            });
        } catch (e) {
            console.error('加载用户失败:', e);
        }
    }

    // ===== 权限组管理 =====
    async function loadGroups() {
        try {
            const groups = await api('/api/auth.php?action=list_groups');
            const list = $('#groupList');
            list.innerHTML = groups.map(g => {
                let perms = {};
                try { perms = JSON.parse(g.permissions || '{}'); } catch (e) {}
                return `<div class="library-card" data-id="${g.id}">
                    <div class="lib-info">
                        <div class="lib-name">${escHtml(g.name)} ${g.is_default == 1 ? '<span class="admin-badge" style="font-size:11px;margin-left:8px;">默认</span>' : ''}</div>
                        <div class="lib-meta">
                            <span>可看全部: ${perms.can_see_all ? '是' : '仅非VIP'}</span>
                            <span>剧集限制: ${perms.episode_limit > 0 ? '每季' + perms.episode_limit + '集' : '无'}</span>
                            <span>电影限制: ${perms.movie_minutes_limit > 0 ? perms.movie_minutes_limit + '分钟' : '无'}</span>
                        </div>
                    </div>
                    <div class="lib-actions">
                        <button class="btn btn-sm btn-outline edit-group-btn" data-id="${g.id}">编辑</button>
                        <button class="btn btn-sm btn-outline delete-group-btn" data-id="${g.id}">删除</button>
                    </div>
                </div>`;
            }).join('');

            $$('.edit-group-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    const g = groups.find(x => x.id == btn.dataset.id);
                    if (!g) return;
                    let perms = {};
                    try { perms = JSON.parse(g.permissions || '{}'); } catch (e) {}
                    $('#groupEditId').value = g.id;
                    $('#groupName').value = g.name;
                    $('#groupCanSeeAll').checked = perms.can_see_all !== false;
                    $('#groupEpisodeLimit').value = perms.episode_limit || 0;
                    $('#groupMovieLimit').value = perms.movie_minutes_limit || 0;
                    $('#groupIsDefault').checked = g.is_default == 1;
                    $('#groupModalTitle').textContent = '编辑权限组';
                    $('#groupModal').classList.add('active');
                });
            });

            $$('.delete-group-btn').forEach(btn => {
                btn.addEventListener('click', async () => {
                    if (!confirm('确定删除此权限组？组内用户将变为普通用户组。')) return;
                    await api('/api/auth.php?action=delete_group', { method: 'POST', body: JSON.stringify({ id: parseInt(btn.dataset.id) }) });
                    toast('已删除');
                    loadGroups();
                });
            });
        } catch (e) {
            console.error('加载权限组失败:', e);
        }
    }

    if ($('#groupForm')) {
        $('#groupForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const id = parseInt($('#groupEditId').value) || null;
            const data = {
                name: $('#groupName').value.trim(),
                permissions: JSON.stringify({
                    can_see_all: $('#groupCanSeeAll').checked,
                    episode_limit: parseInt($('#groupEpisodeLimit').value) || 0,
                    movie_minutes_limit: parseInt($('#groupMovieLimit').value) || 0,
                }),
                is_default: $('#groupIsDefault').checked ? 1 : 0,
            };
            if (id) data.id = id;
            const action = id ? 'update_group' : 'create_group';
            const res = await api('/api/auth.php?action=' + action, { method: 'POST', body: JSON.stringify(data) });
            if (res.success) {
                $('#groupModal').classList.remove('active');
                loadGroups();
            } else {
                toast(res.error || '保存失败', 'error');
            }
        });
    }

    if ($('#addGroupBtn')) {
        $('#addGroupBtn').addEventListener('click', () => {
            $('#groupEditId').value = '';
            $('#groupName').value = '';
            $('#groupCanSeeAll').checked = true;
            $('#groupEpisodeLimit').value = '0';
            $('#groupMovieLimit').value = '0';
            $('#groupIsDefault').checked = false;
            $('#groupModalTitle').textContent = '新建权限组';
            $('#groupModal').classList.add('active');
        });
    }

    if ($('#addUserForm')) {
        $('#addUserForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            try {
                const formData = new FormData(e.target);
                const data = Object.fromEntries(formData);
                data.group_id = parseInt(data.group_id) || 1;
                await api('/api/auth.php?action=register', { method: 'POST', body: JSON.stringify(data) });
                $('#userModal').classList.remove('active');
                e.target.reset();
                toast('用户已创建');
                loadUsers();
            } catch (e) { toast('创建失败: ' + e.message, 'error'); }
        });
    }

    if ($('#addUserBtn')) {
        $('#addUserBtn').addEventListener('click', () => {
            loadUserGroupDropdown();
            $('#userModal').classList.add('active');
        });
    }

    // ===== 转码管理 =====
    if ($('#transcodeSearchBtn')) {
        $('#transcodeSearchBtn').addEventListener('click', () => {
            const query = $('#transcodeMediaSearch').value.trim();
            if (!query) { toast('请输入搜索词', 'error'); return; }
            searchTranscodeMedia(query);
        });
    }

    if ($('#transcodeMediaSearch')) {
        $('#transcodeMediaSearch').addEventListener('keyup', (e) => {
            if (e.key === 'Enter') {
                const query = e.target.value.trim();
                if (query) searchTranscodeMedia(query);
            }
        });
    }

    async function searchTranscodeMedia(query) {
        try {
            const items = await api('/api/media.php?action=metadata_list&search=' + encodeURIComponent(query));
            const list = $('#transcodeMediaList');
            if (!items || items.length === 0) {
                list.innerHTML = '<div style="padding:12px;color:var(--text-muted);font-size:13px;">未找到匹配的影片</div>';
                return;
            }
            list.innerHTML = items.map(m => {
                const poster = m.poster_path ? `<img src="https://image.tmdb.org/t/p/w92${m.poster_path}" style="width:36px;height:52px;object-fit:cover;border-radius:3px;">` : '<div style="width:36px;height:52px;background:var(--bg-hover);border-radius:3px;"></div>';
                return `<div class="tc-media-item" data-id="${m.id}" data-title="${escHtml(m.title)}">${poster}<div style="flex:1;min-width:0;"><div style="font-size:13px;color:var(--text-primary);">${escHtml(m.title)}</div><div style="font-size:11px;color:var(--text-muted);">${m.type === 'tv' ? '剧集' : '电影'} · ${m.file_count || 0} 个文件</div></div></div>`;
            }).join('');

            $$('#transcodeMediaList .tc-media-item').forEach(div => {
                div.addEventListener('click', async () => {
                    const mediaId = div.dataset.id;
                    document.querySelectorAll('#transcodeMediaList .tc-media-item').forEach(d => d.style.background = '');
                    div.style.background = 'rgba(229,9,20,0.15)';
                    await loadTranscodeFiles(mediaId);
                });
            });
        } catch (e) {
            console.error('搜索转码媒体失败:', e);
        }
    }

    async function loadTranscodeFiles(mediaId) {
        try {
            const data = await api('/api/media.php?action=detail&id=' + mediaId);
            const files = data.files || (data.seasons ? data.seasons.flatMap(s => s.episodes) : []);
            const group = $('#transcodeFileGroup');
            const select = $('#transcodeFileSelect');
            select.innerHTML = files.map(f => `<option value="${f.id}">${escHtml(f.file_name)} ${f.resolution ? '(' + f.resolution + ')' : ''}</option>`).join('');
            group.style.display = 'block';
            select.dataset.mediaId = mediaId;
        } catch (e) {
            console.error('加载文件列表失败:', e);
        }
    }

    if ($('#startTranscodeBtn')) {
        $('#startTranscodeBtn').addEventListener('click', async () => {
            const fileId = parseInt($('#transcodeFileSelect').value);
            const quality = $('#transcodeQuality').value;
            if (!fileId) { toast('请先选择影片和文件', 'error'); return; }

            const output = $('#transcodeOutput');
            output.textContent = `开始转码: 文件#${fileId} -> ${quality}\n`;

            try {
                const res = await api('/api/transcode.php?action=start', {
                    method: 'POST',
                    body: JSON.stringify({ file_id: fileId, quality }),
                });
                output.textContent += `任务ID: ${res.job.id}\n状态: ${res.job.status}\n`;

                if (res.job.status === 'running') {
                    output.textContent += '转码中，轮询进度...\n';
                    pollJob(res.job.id, output);
                }
            } catch (e) {
                output.textContent += `失败: ${e.message}\n`;
            }
        });
    }

    function pollJob(jobId, output) {
        const interval = setInterval(async () => {
            try {
                const job = await api(`/api/transcode.php?action=status&job_id=${jobId}`);
                output.textContent += `进度: ${job.progress}% [${job.status}]\n`;
                if (job.status === 'completed' || job.status === 'failed') {
                    clearInterval(interval);
                    output.textContent += job.status === 'completed' ? '转码完成！\n' : `转码失败: ${job.error_message || '未知错误'}\n`;
                }
            } catch (e) {
                clearInterval(interval);
            }
        }, 5000);
    }

    function escHtml(str) {
        const div = document.createElement('div');
        div.textContent = str || '';
        return div.innerHTML;
    }

    // ===== 活跃会话 =====
    let activityTimer = null;

    async function loadActivity() {
        try {
            const sessions = await api('/api/activity.php?action=list');
            const container = $('#activityList');
            if (!container) return;

            if (sessions.length === 0) {
                container.innerHTML = '<div class="empty-state"><h3>当前无人在看</h3><p>用户开始播放后会在这里显示</p></div>';
                return;
            }

            container.innerHTML = sessions.map(s => {
                const pct = s.duration > 0 ? Math.round(s.position / s.duration * 100) : 0;
                const posStr = formatTime(s.position);
                const durStr = formatTime(s.duration);
                const ago = getTimeAgo(s.last_heartbeat);
                return `
                <div class="library-card" style="gap:16px;">
                    ${s.poster_path ? `<img src="https://image.tmdb.org/t/p/w92${s.poster_path}" style="width:56px;border-radius:6px;flex-shrink:0;">` : ''}
                    <div class="lib-info" style="flex:1;">
                        <div class="lib-name">${escHtml(s.display_name || s.username)}</div>
                        <div style="font-size:14px;margin:4px 0;">${escHtml(s.media_title || '未知')}</div>
                        <div style="display:flex;align-items:center;gap:8px;margin:6px 0;">
                            <div style="flex:1;height:4px;background:rgba(255,255,255,0.1);border-radius:2px;overflow:hidden;">
                                <div style="width:${pct}%;height:100%;background:#e50914;border-radius:2px;"></div>
                            </div>
                            <span style="font-size:12px;color:var(--text-muted);">${posStr} / ${durStr}</span>
                        </div>
                        <div class="lib-meta">
                            <span>${escHtml(s.file_name || '')}</span>
                            <span>${ago}</span>
                            <span>${escHtml(s.ip_address || '')}</span>
                        </div>
                    </div>
                </div>`;
            }).join('');
        } catch (e) {
            console.error('加载活跃会话失败:', e);
        }
    }

    function formatTime(sec) {
        if (!sec || sec < 0) return '0:00';
        const h = Math.floor(sec / 3600);
        const m = Math.floor((sec % 3600) / 60);
        const s = Math.floor(sec % 60);
        if (h > 0) return `${h}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;
        return `${m}:${String(s).padStart(2,'0')}`;
    }

    function getTimeAgo(dateStr) {
        const diff = (Date.now() - new Date(dateStr).getTime()) / 1000;
        if (diff < 10) return '刚刚';
        if (diff < 60) return Math.floor(diff) + '秒前';
        if (diff < 3600) return Math.floor(diff / 60) + '分钟前';
        return Math.floor(diff / 3600) + '小时前';
    }

    if ($('#refreshActivityBtn')) {
        $('#refreshActivityBtn').addEventListener('click', loadActivity);
    }

    // ===== 消息推送 =====
    async function loadNotifyUsers() {
        try {
            const users = await api('/notifications.php?action=users');
            const sel = $('#notifyTarget');
            if (!sel) return;
            sel.innerHTML = '<option value="all">所有在线用户</option>';
            users.forEach(u => {
                sel.innerHTML += `<option value="${u.id}">${escHtml(u.display_name || u.username)}</option>`;
            });
        } catch (e) { /* ignore */ }
    }

    if ($('#sendNotifyBtn')) {
        $('#sendNotifyBtn').addEventListener('click', async () => {
            const msg = ($('#notifyMessage')?.value || '').trim();
            if (!msg) { toast('请输入消息内容', 'error'); return; }
            try {
                await api('/notifications.php?action=send', {
                    method: 'POST',
                    body: JSON.stringify({
                        message: msg,
                        target_user_id: $('#notifyTarget')?.value || 'all',
                        type: $('#notifyType')?.value || 'info',
                    }),
                });
                toast('消息已发送');
                if ($('#notifyMessage')) $('#notifyMessage').value = '';
            } catch (e) {
                toast('发送失败: ' + e.message, 'error');
            }
        });
    }

    // ===== 目录浏览器 =====
    let browseCurrentPath = '';
    let browseParentPath = '';

    if ($('#browseBtn')) {
        $('#browseBtn').addEventListener('click', () => {
            const browser = $('#fileBrowser');
            if (browser.style.display === 'none') {
                browser.style.display = 'block';
                loadDirectory('');
            } else {
                browser.style.display = 'none';
            }
        });
    }

    if ($('#browseUp')) {
        $('#browseUp').addEventListener('click', () => {
            if (browseParentPath) {
                loadDirectory(browseParentPath);
            }
        });
    }

    async function loadDirectory(path) {
        try {
            const url = path ? `/api/browse.php?path=${encodeURIComponent(path)}` : '/api/browse.php';
            const res = await fetch(url);
            const data = await res.json();
            if (data.error) { toast(data.error, 'error'); return; }

            browseCurrentPath = data.current;
            browseParentPath = data.parent || '';

            const header = $('#browseHeader');
            const list = $('#browseList');
            if (!list) return;

            // 路径显示 + 选择按钮
            if (header) {
                header.innerHTML = `
                    <span id="browseUp" style="color:#e50914;cursor:pointer;flex-shrink:0;" title="返回上级">⬆ 上级</span>
                    <span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--text-muted);font-size:13px;">${escHtml(data.current)}</span>
                    <button type="button" id="selectCurrentDir" style="background:#e50914;color:#fff;border:none;padding:4px 12px;border-radius:4px;font-size:12px;cursor:pointer;flex-shrink:0;">选择此目录</button>
                `;
                // 重新绑定上级按钮
                const upBtn = header.querySelector('#browseUp');
                if (upBtn) {
                    upBtn.addEventListener('click', () => {
                        if (browseParentPath) loadDirectory(browseParentPath);
                    });
                }
                // 绑定选择按钮
                const selectBtn = header.querySelector('#selectCurrentDir');
                if (selectBtn) {
                    selectBtn.addEventListener('click', () => {
                        if ($('#libraryPath')) $('#libraryPath').value = browseCurrentPath;
                        toast('已选择: ' + browseCurrentPath);
                    });
                }
            }

            // 目录列表
            let html = '';
            const permHint = $('#browsePermHint');
            if (!data.items || data.items.length === 0) {
                if (permHint) permHint.style.display = 'block';
                html = `<div style="padding:16px;text-align:center;color:var(--text-muted);font-size:13px;">
                    <p>空文件夹或无读取权限</p>
                    <p style="margin-top:8px;font-size:12px;">仍可选择此目录作为媒体库路径</p>
                </div>`;
            } else {
                if (permHint) permHint.style.display = 'none';
                data.items.forEach(item => {
                    html += `<div class="browse-folder" data-path="${escHtml(item.path)}" style="padding:8px 12px;cursor:pointer;display:flex;align-items:center;gap:8px;border-radius:4px;font-size:14px;" onmouseover="this.style.background='rgba(255,255,255,0.06)'" onmouseout="this.style.background=''">
                        <span style="font-size:16px;">📁</span> ${escHtml(item.name)}
                        <span style="margin-left:auto;font-size:11px;color:var(--text-muted);">进入</span>
                    </div>`;
                });
            }

            list.innerHTML = html;

            list.querySelectorAll('.browse-folder').forEach(item => {
                item.addEventListener('click', () => {
                    loadDirectory(item.dataset.path);
                });
            });
        } catch (e) {
            toast('加载目录失败: ' + e.message, 'error');
        }
    }

    // ===== SMTP 测试 =====
    if ($('#testSmtpBtn')) {
        $('#testSmtpBtn').addEventListener('click', async () => {
            const btn = $('#testSmtpBtn');
            btn.disabled = true;
            btn.textContent = '测试中...';
            // 先保存当前设置
            const data = {
                smtp_host: $('#settingSmtpHost')?.value || '',
                smtp_port: $('#settingSmtpPort')?.value || '465',
                smtp_encryption: $('#settingSmtpEncryption')?.value || 'ssl',
                smtp_username: $('#settingSmtpUsername')?.value || '',
                smtp_password: $('#settingSmtpPassword')?.value || '',
                smtp_from_email: $('#settingSmtpFromEmail')?.value || '',
                smtp_from_name: $('#settingSmtpFromName')?.value || 'NAS影视库',
                smtp_enabled: $('#settingSmtpEnabled')?.value || '0',
            };
            try {
                await api('/api/scan.php?action=update_settings', {
                    method: 'POST',
                    body: JSON.stringify(data),
                });
                // 这里不做真正的SMTP测试，只提示配置已保存
                toast('SMTP 配置已保存，请通过找回密码功能测试');
            } catch (e) {
                toast('保存失败: ' + e.message, 'error');
            }
            btn.disabled = false;
            btn.textContent = '测试连接';
        });
    }

    // ===== Tab 切换时加载数据 =====
    $$('.nav-item').forEach(item => {
        item.addEventListener('click', (e) => {
            e.preventDefault();
            const tab = item.dataset.tab;
            $$('.nav-item').forEach(i => i.classList.remove('active'));
            item.classList.add('active');
            $$('.admin-section').forEach(s => s.classList.remove('active'));
            $(`#tab-${tab}`).classList.add('active');

            if (tab === 'dashboard') loadDashboard();
            if (tab === 'libraries') loadLibraries();
            if (tab === 'scan') loadScanControls();
            if (tab === 'metadata') loadMetadata();
            if (tab === 'users') { loadUsers(); loadUserGroupDropdown(); initUserSubtabs(); }
            if (tab === 'activity') { loadActivity(); clearInterval(activityTimer); activityTimer = setInterval(loadActivity, 10000); }
            if (tab === 'notify') loadNotifyUsers();
            if (tab === 'settings') loadSettings();
        });
    });

    function initUserSubtabs() {
        $$('#tab-users .user-tab-btn').forEach(btn => {
            if (btn._subtabBound) return;
            btn._subtabBound = true;
            btn.addEventListener('click', () => {
                $$('#tab-users .user-tab-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                const subtab = btn.dataset.subtab;
                const userSection = $('#userListSection');
                const groupSection = $('#groupsSection');
                if (userSection) userSection.style.display = subtab === 'user-list' ? '' : 'none';
                if (groupSection) groupSection.style.display = subtab === 'groups' ? '' : 'none';
                if (subtab === 'groups') loadGroups();
            });
        });
    }

    async function loadUserGroupDropdown() {
        try {
            const groups = await api('/api/auth.php?action=list_groups');
            const sel = $('#userGroupSelect');
            if (!sel) return;
            sel.innerHTML = groups.map(g => `<option value="${g.id}">${escHtml(g.name)}</option>`).join('');
        } catch (e) {}
    }

    // Init
    loadDashboard();

    } catch (e) {
        console.error('admin.js 初始化错误:', e);
        const grid = document.getElementById('statsGrid');
        if (grid) grid.innerHTML = '<div style="color:#ff6b6b;padding:20px;">初始化错误: ' + e.message + ' (行' + (e.lineNumber || '') + ')</div>';
    }
})();
