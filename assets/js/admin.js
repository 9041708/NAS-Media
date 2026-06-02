(() => {
    'use strict';

    try {

    const $ = (sel) => document.querySelector(sel);
    const $$ = (sel) => document.querySelectorAll(sel);

    function posterUrl(path, size) {
        if (!path) return '';
        return '/api/image.php?size=' + (size || 'w500') + '&path=' + encodeURIComponent(path);
    }

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
            list.innerHTML = libs.map((lib, idx) => `
                <div class="library-card" data-id="${lib.id}">
                    <div class="lib-info">
                        <div class="lib-name">
                            <span class="lib-order-badge" title="拖拽排序">${idx + 1}</span>
                            ${escHtml(lib.name)}
                        </div>
                        <div class="lib-path">${escHtml(lib.path)}</div>
                        <div class="lib-meta">
                            <span>类型: ${lib.type === 'movie' ? '电影' : lib.type === 'tv' ? '剧集' : '其他'}</span>
                            <span>文件: ${lib.file_count || 0}</span>
                            <span>上次扫描: ${lib.last_scan || '从未'}</span>
                        </div>
                    </div>
                    <div class="lib-actions">
                        <button class="btn btn-xs btn-outline move-up-btn" data-id="${lib.id}" title="上移" ${idx === 0 ? 'disabled' : ''}>▲</button>
                        <button class="btn btn-xs btn-outline move-down-btn" data-id="${lib.id}" title="下移" ${idx === libs.length - 1 ? 'disabled' : ''}>▼</button>
                        <button class="btn btn-sm btn-primary scan-lib-btn" data-id="${lib.id}">扫描</button>
                        <button class="btn btn-sm btn-outline delete-lib-btn" data-id="${lib.id}">删除</button>
                    </div>
                </div>
            `).join('');

            $$('.move-up-btn').forEach(btn => {
                btn.addEventListener('click', async () => {
                    await reorderLibrary(parseInt(btn.dataset.id), 'up');
                });
            });
            $$('.move-down-btn').forEach(btn => {
                btn.addEventListener('click', async () => {
                    await reorderLibrary(parseInt(btn.dataset.id), 'down');
                });
            });

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

    async function reorderLibrary(libId, direction) {
        try {
            const res = await fetch('/api/scan.php?action=list_libraries');
            const libs = await res.json();
            if (!Array.isArray(libs)) return;

            const idx = libs.findIndex(l => parseInt(l.id) === libId);
            if (idx < 0) return;
            const swapIdx = direction === 'up' ? idx - 1 : idx + 1;
            if (swapIdx < 0 || swapIdx >= libs.length) return;

            for (let i = 0; i < libs.length; i++) {
                libs[i].sort_order = i;
            }

            const swap = libs[swapIdx];
            const target = libs[idx];
            const temp = target.sort_order;
            target.sort_order = swap.sort_order;
            swap.sort_order = temp;

            const orders = [
                { id: parseInt(target.id), order: target.sort_order },
                { id: parseInt(swap.id), order: swap.sort_order },
            ];

            await api('/api/scan.php?action=reorder_libraries', {
                method: 'POST',
                body: JSON.stringify({ orders }),
            });
            loadLibraries();
        } catch (e) {
            console.error('排序失败:', e);
            toast('排序失败', 'error');
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

    // ===== 元数据管理（树形结构） =====
    let metaData = null;
    let metaExpanded = {};

    async function loadMetadataTree(search = '', libType = '') {
        try {
            $('#metadataTree').innerHTML = '<div class="loading-spinner"><div class="spinner"></div><p>加载中...</p></div>';
            metaShowingUnmatched = false;
            metaExpanded = {};
            let url = '/api/media.php?action=media_tree';
            if (search) url += '&search=' + encodeURIComponent(search);
            if (libType) url += '&lib_type=' + encodeURIComponent(libType);
            metaData = await api(url);
            if (!metaData || metaData.length === 0 || metaData.every(l => l.children.length === 0 && l.unmatched.length === 0)) {
                $('#metadataTree').innerHTML = '<div class="empty-state"><h3>暂无媒体数据</h3><p>请先在"扫描管理"中扫描媒体库以获取元数据，或点击"未匹配文件"查看未关联的文件</p></div>';
                return;
            }
            renderMetadataTree();
        } catch (e) {
            console.error('加载元数据树失败:', e);
            $('#metadataTree').innerHTML = '<div class="error">加载失败: ' + escHtml(e.message || '') + '</div>';
        }
    }

    async function loadUnmatchedTree() {
        try {
            $('#metadataTree').innerHTML = '<div class="loading-spinner"><div class="spinner"></div><p>加载中...</p></div>';
            metaShowingUnmatched = true;
            metaExpanded = {};
            const groups = await api('/api/media.php?action=media_tree_unmatched');
            if (!groups || groups.length === 0) {
                $('#metadataTree').innerHTML = '<div class="empty-state"><h3>所有文件已匹配</h3><p>没有未匹配的文件</p></div>';
                return;
            }
            metaData = groups.map(g => ({
                id: 'unmatched_' + g.library_id,
                name: g.library_name + (g.bareItems ? ' (无元数据)' : ' (未匹配)'),
                path: '',
                type: 'unmatched',
                children: [],
                unmatched: g.files || [],
                bareItems: g.bareItems || [],
                _unmatchedGroup: true,
            }));
            renderMetadataTree();
        } catch (e) {
            console.error('加载未匹配文件失败:', e);
            $('#metadataTree').innerHTML = '<div class="error">加载失败: ' + escHtml(e.message || '') + '</div>';
        }
    }

    function renderMetadataTree() {
        let html = '';
        metaData.forEach(lib => {
            const libKey = 'lib_' + lib.id;
            const isLibExp = metaExpanded[libKey] === true;
            const totalFiles = (lib.children || []).reduce((sum, c) => sum + (c.seasons ? c.seasons.reduce((s, se) => s + (se.episodes || []).length, 0) : (c.files || []).length), 0) + (lib.unmatched || []).length;

            html += `<div class="meta-tree-lib">
                <div class="meta-tree-node meta-tree-root" data-key="${libKey}" onclick="toggleMetaNode('${libKey}')">
                    <span class="meta-tree-arrow">${isLibExp ? '▼' : '▶'}</span>
                    <span class="meta-tree-icon">📁</span>
                    <span class="meta-tree-label">${escHtml(lib.name)}</span>
                    <span class="meta-tree-badge">${lib.type === 'tv' ? '剧集' : lib.type === 'movie' ? '电影' : '其他'}</span>
                    <span class="meta-tree-count">${totalFiles} 文件</span>
                </div>
                <div class="meta-tree-children" style="${isLibExp ? '' : 'display:none'}">`;

            if (lib._unmatchedGroup) {
                html += renderUnmatchedFiles(lib.unmatched, libKey);
                if (lib.bareItems && lib.bareItems.length > 0) {
                    const bk = libKey + '_bare';
                    const isBareExp = metaExpanded[bk] === true;
                    html += `<div class="meta-tree-node meta-unmatched" data-key="${bk}" onclick="toggleMetaNode('${bk}')" style="background:rgba(245,158,11,0.06);">
                        <span class="meta-tree-arrow">${isBareExp ? '▼' : '▶'}</span>
                        <span class="meta-tree-icon">📭</span>
                        <span class="meta-tree-label">无海报/元数据的影视</span>
                        <span class="meta-tree-count">${lib.bareItems.length} 部</span>
                    </div>
                    <div class="meta-tree-children" style="${isBareExp ? '' : 'display:none'}">
                        ${lib.bareItems.map(b => `
                            <div class="meta-tree-node" style="margin-left:20px;padding:6px 12px;font-size:13px;display:flex;align-items:center;gap:8px;">
                                <span>🎬</span>
                                <span>${escHtml(b.title)} ${b.year ? '(' + b.year + ')' : ''}</span>
                                <span class="meta-tree-badge">${b.type === 'tv' ? '剧集' : '电影'}</span>
                                <span class="meta-tree-actions" onclick="event.stopPropagation()">
                                    <button class="btn btn-xs btn-primary" onclick="refreshMetaInline(${b.id})" title="刷新元数据">🔄</button>
                                    <a href="/show.php?id=${b.id}" class="btn btn-xs btn-outline" title="详情页" target="_blank">🔗</a>
                                </span>
                            </div>`).join('')}
                    </div>`;
                }
            } else {
                lib.children.forEach(child => {
                    const childKey = libKey + '_child_' + child.id;
                    const isChildExp = metaExpanded[childKey] === true;
                    let childIcon = child.poster_path ? `<img src="/api/image.php?size=w92&path=${encodeURIComponent(child.poster_path)}" class="meta-tree-poster" alt="">` : '<span class="meta-tree-icon">🎬</span>';

                    html += `<div class="meta-tree-node" data-key="${childKey}" onclick="toggleMetaNode('${childKey}')">
                        <span class="meta-tree-arrow">${isChildExp ? '▼' : '▶'}</span>
                        ${childIcon}
                        <span class="meta-tree-label">${escHtml(child.title)}</span>
                        ${child.year ? `<span class="meta-tree-year">(${child.year})</span>` : ''}
                        ${child.vip_only ? '<span class="meta-tree-vip">VIP</span>' : ''}
                        <span class="meta-tree-actions" onclick="event.stopPropagation()">
                            <button class="btn btn-xs btn-outline" onclick="editMetaInline(${child.id}, '${escAttr(child.title)}', '${escAttr(child.overview || '')}', '${escAttr(child.genres || '')}', '${child.year || ''}', '${child.tmdb_id || ''}', ${child.vip_only || 0})" title="编辑">✏️</button>
                            <button class="btn btn-xs btn-primary" onclick="refreshMetaInline(${child.id})" title="刷新元数据">🔄</button>
                            <a href="/show.php?id=${child.id}" class="btn btn-xs btn-outline" title="详情页" target="_blank">🔗</a>
                        </span>
                    </div>
                    <div class="meta-tree-children" style="${isChildExp ? '' : 'display:none'}">`;

                    if (child.seasons) {
                        child.seasons.forEach(season => {
                            html += `<div class="meta-tree-season">📂 ${season.num == 0 ? '特别篇 / 番外' : '第 ' + season.num + ' 季'} (${season.episodes.length} 集)</div>`;
                            html += renderEpisodeFiles(season.episodes);
                        });
                    } else if (child.files) {
                        html += renderMovieFiles(child.files);
                    }

                    html += '</div>';
                });

                if (lib.unmatched.length > 0) {
                    html += renderUnmatchedFiles(lib.unmatched, libKey);
                }
            }

            html += '</div></div>';
        });

        $('#metadataTree').innerHTML = html;
    }

    function renderMovieFiles(files) {
        return `<div class="meta-tree-files">${files.map(f => `
            <div class="meta-tree-file">
                <span class="meta-tree-file-name">📄 ${escHtml(f.file_name)}</span>
                <span class="meta-tree-file-info">${f.resolution || ''} ${formatDurationForMeta(f.duration)} ${formatSizeForMeta(f.file_size)}</span>
                <span class="meta-tree-actions">
                    <button class="btn btn-xs btn-outline" onclick="matchFileInline(${f.id}, '${escAttr(f.file_name)}')" title="匹配到TMDB">🔍</button>
                </span>
            </div>`).join('')}</div>`;
    }

    function renderEpisodeFiles(episodes) {
        return `<div class="meta-tree-files">${episodes.map(f => `
            <div class="meta-tree-file">
                <span class="meta-tree-ep">E${f.episode_number || '?'}</span>
                <span class="meta-tree-file-name">📄 ${escHtml(f.file_name)}</span>
                <span class="meta-tree-file-info">${f.resolution || ''} ${formatDurationForMeta(f.duration)}</span>
                <span class="meta-tree-actions">
                    <button class="btn btn-xs btn-outline" onclick="matchFileInline(${f.id}, '${escAttr(f.file_name)}')" title="匹配到TMDB">🔍</button>
                </span>
            </div>`).join('')}</div>`;
    }

    function renderUnmatchedFiles(files, parentKey) {
        const uk = parentKey + '_unmatched';
        const isExp = metaExpanded[uk] === true;
        return `<div class="meta-tree-node meta-unmatched" data-key="${uk}" onclick="toggleMetaNode('${uk}')">
            <span class="meta-tree-arrow">${isExp ? '▼' : '▶'}</span>
            <span class="meta-tree-icon">⚠️</span>
            <span class="meta-tree-label">未匹配文件</span>
            <span class="meta-tree-count">${files.length} 个</span>
        </div>
        <div class="meta-tree-children" style="${isExp ? '' : 'display:none'}">${renderMovieFiles(files)}</div>`;
    }

    window.toggleMetaNode = function(key) {
        metaExpanded[key] = metaExpanded[key] === false ? true : false;
        const node = document.querySelector(`[data-key="${key}"]`);
        if (!node) return;
        const children = node.nextElementSibling;
        if (!children || !children.classList.contains('meta-tree-children')) return;
        const arrow = node.querySelector('.meta-tree-arrow');
        const isVisible = children.style.display !== 'none';
        if (isVisible) {
            children.style.display = 'none';
            if (arrow) arrow.textContent = '▶';
            metaExpanded[key] = false;
        } else {
            children.style.display = '';
            if (arrow) arrow.textContent = '▼';
            metaExpanded[key] = true;
        }
    };

    window.editMetaInline = function(mediaId, title, overview, genres, year, tmdbId, vip) {
        openEditMetaModal({
            dataset: {
                id: mediaId,
                title: title,
                otitle: '',
                year: year || '',
                type: 'movie',
                overview: overview || '',
                genres: genres || '',
                tmdb: tmdbId || '',
                vip: vip || '0',
            }
        });
    };

    window.refreshMetaInline = async function(mediaId) {
        if (!confirm('确定要从 TMDB 重新获取此媒体的元数据？')) return;
        try {
            const res = await api('/api/scan.php?action=refresh_meta', {
                method: 'POST',
                body: JSON.stringify({ media_id: mediaId }),
            });
            if (res.success) {
                alert('元数据已刷新，请重新加载列表');
            } else {
                alert('刷新失败: ' + (res.error || '未知错误'));
            }
        } catch (e) {
            alert('刷新失败: 网络错误');
        }
        loadMetadataTree($('#metadataSearch').value.trim(), $('#metadataLibType').value);
    };

    window.matchFileInline = function(fileId, fileName) {
        openMatchModal(fileId, fileName);
    };

    function formatDurationForMeta(sec) {
        if (!sec || sec <= 0) return '';
        const m = Math.floor(sec / 60);
        if (m >= 60) return Math.floor(m / 60) + 'h' + (m % 60) + 'm';
        return m + 'min';
    }

    function formatSizeForMeta(bytes) {
        if (!bytes) return '';
        if (bytes < 1024) return bytes + 'B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + 'KB';
        if (bytes < 1073741824) return (bytes / 1048576).toFixed(1) + 'MB';
        return (bytes / 1073741824).toFixed(2) + 'GB';
    }

    // Edit meta modal (reuse existing)
    function openEditMetaModal(btn) {
        const d = btn.dataset;
        $('#editMetaId').value = d.id;
        $('#editMetaTitle').value = d.title || '';
        $('#editMetaOriginalTitle').value = d.otitle || '';
        $('#editMetaYear').value = d.year || '';
        $('#editMetaType').value = d.type || 'movie';
        $('#editMetaOverview').value = d.overview || '';
        $('#editMetaGenres').value = d.genres || '';
        $('#editMetaTmdbId').value = d.tmdb || '';
        $('#editMetaVip').checked = (d.vip == '1');
        const tmdbLink = $('#editMetaTmdbLink');
        if (tmdbLink) tmdbLink.href = 'https://www.themoviedb.org/search?query=' + encodeURIComponent(d.title || '');
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
        loadMetadataTree($('#metadataSearch').value.trim(), $('#metadataLibType').value);
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
                if (metaShowingUnmatched) {
                    loadUnmatchedTree();
                } else {
                    loadMetadataTree($('#metadataSearch').value.trim(), $('#metadataLibType').value);
                }
            } else {
                alert('保存失败: ' + (res.error || '未知错误'));
            }
        } catch (e) {
            alert('保存失败: 网络错误');
        }
    });

    $('#metadataSearchBtn').addEventListener('click', () => {
        loadMetadataTree($('#metadataSearch').value.trim(), $('#metadataLibType').value);
    });
    $('#metadataSearch').addEventListener('keyup', (e) => {
        if (e.key === 'Enter') loadMetadataTree($('#metadataSearch').value.trim(), $('#metadataLibType').value);
    });
    $('#metadataUnmatchedBtn').addEventListener('click', () => {
        loadUnmatchedTree();
    });
    $('#metadataRefreshBtn').addEventListener('click', () => {
        if (metaShowingUnmatched) loadUnmatchedTree();
        else loadMetadataTree($('#metadataSearch').value.trim(), $('#metadataLibType').value);
    });

    // ===== TMDB 匹配 =====
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
                    <img src="${r.poster_path ? '/api/image.php?size=w92&path=' + encodeURIComponent(r.poster_path) : '/assets/images/no-poster.svg'}" alt="">
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
            loadMetadataTree($('#metadataSearch').value.trim(), $('#metadataLibType').value);
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
            if ($('#settingRemoteAccess')) $('#settingRemoteAccess').value = settings.remote_access_enabled || '0';
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

    async function loadAbout() {
        try {
            const stats = await api('/api/media.php?action=stats');
            const settings = await api('/api/scan.php?action=get_settings');

            let version = settings?.app_version || '3.1.0';
            let siteName = settings?.site_name || 'NAS影库';

            let tmdbKeyStatus = settings?.tmdb_api_key ? '<span style="color:#10b981;">已配置</span>' : '<span style="color:#f59e0b;">未配置</span>';
            let ffmpegStatus = '<span style="color:var(--text-muted);">检测中...</span>';

            try {
                const res = await api('/api/scan.php?action=detect_ffmpeg');
                if (res.found) {
                    ffmpegStatus = `<span style="color:#10b981;">已找到</span> v${res.version || '?'}`;
                } else {
                    ffmpegStatus = '<span style="color:#e50914;">未找到</span>';
                }
            } catch(e) {}

            let libsHtml = '';
            if (stats?.libraries) {
                libsHtml = stats.libraries.map(l => `
                    <div style="display:flex;justify-content:space-between;padding:8px 12px;background:rgba(255,255,255,0.03);border-radius:6px;margin-bottom:4px;">
                        <span>${escHtml(l.name)} <span style="color:var(--text-muted);font-size:12px;">(${l.type === 'tv' ? '剧集' : l.type === 'movie' ? '电影' : '其他'})</span></span>
                        <span style="color:var(--text-muted);font-size:12px;">${l.path}</span>
                    </div>
                `).join('');
            }

            $('#aboutContent').innerHTML = `
                <div style="max-width:800px;">
                    <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(160px, 1fr));gap:16px;margin-bottom:24px;">
                        <div class="stat-card" style="text-align:center;padding:20px;">
                            <div style="font-size:32px;font-weight:700;color:var(--accent);">${stats?.total_movies || 0}</div>
                            <div style="font-size:13px;color:var(--text-muted);margin-top:4px;">电影</div>
                        </div>
                        <div class="stat-card" style="text-align:center;padding:20px;">
                            <div style="font-size:32px;font-weight:700;color:var(--accent);">${stats?.total_tv || 0}</div>
                            <div style="font-size:13px;color:var(--text-muted);margin-top:4px;">剧集</div>
                        </div>
                        <div class="stat-card" style="text-align:center;padding:20px;">
                            <div style="font-size:32px;font-weight:700;color:var(--accent);">${stats?.total_files || 0}</div>
                            <div style="font-size:13px;color:var(--text-muted);margin-top:4px;">文件</div>
                        </div>
                        <div class="stat-card" style="text-align:center;padding:20px;">
                            <div style="font-size:32px;font-weight:700;color:var(--accent);">${formatBytesForAbout(stats?.total_size || 0)}</div>
                            <div style="font-size:13px;color:var(--text-muted);margin-top:4px;">总大小</div>
                        </div>
                        <div class="stat-card" style="text-align:center;padding:20px;">
                            <div style="font-size:32px;font-weight:700;color:var(--accent);">${stats?.total_plays || 0}</div>
                            <div style="font-size:13px;color:var(--text-muted);margin-top:4px;">播放次数</div>
                        </div>
                    </div>

                    <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:20px;margin-bottom:16px;">
                        <h3 style="font-size:16px;margin-bottom:16px;padding-bottom:8px;border-bottom:1px solid var(--border);">系统信息</h3>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;font-size:13px;">
                            <div><span style="color:var(--text-muted);">站点名称:</span> ${escHtml(siteName)}</div>
                            <div><span style="color:var(--text-muted);">版本:</span> v${escHtml(version)}</div>
                            <div><span style="color:var(--text-muted);">PHP版本:</span> ${escHtml('<?= phpversion() ?: "N/A" ?>') || 'N/A'}</div>
                            <div><span style="color:var(--text-muted);">TMDB API:</span> ${tmdbKeyStatus}</div>
                            <div><span style="color:var(--text-muted);">FFmpeg:</span> ${ffmpegStatus}</div>
                            <div><span style="color:var(--text-muted);">媒体库数量:</span> ${(stats?.libraries || []).length}</div>
                            <div><span style="color:var(--text-muted);">转码:</span> ${settings?.transcode_enabled == '1' ? '<span style="color:#10b981;">已启用</span>' : '<span style="color:var(--text-muted);">未启用</span>'}</div>
                            <div><span style="color:var(--text-muted);">注册:</span> ${settings?.allow_register == '1' ? '<span style="color:#10b981;">允许</span>' : '<span style="color:var(--text-muted);">禁止</span>'}</div>
                        </div>
                    </div>

                    <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:20px;margin-bottom:16px;">
                        <h3 style="font-size:16px;margin-bottom:12px;padding-bottom:8px;border-bottom:1px solid var(--border);">媒体库列表</h3>
                        ${libsHtml || '<p style="color:var(--text-muted);font-size:13px;">暂无媒体库</p>'}
                    </div>

                    <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:20px;margin-bottom:16px;">
                        <h3 style="font-size:16px;margin-bottom:12px;padding-bottom:8px;border-bottom:1px solid var(--border);">关于</h3>
                        <p style="font-size:13px;color:var(--text-secondary);line-height:1.8;">
                            <strong>NAS影库</strong> 是一套自建的媒体服务器系统，支持电影和剧集的播放、元数据管理、转码、字幕搜索等功能。
                        </p>
                        <div style="margin-top:12px;display:flex;gap:12px;flex-wrap:wrap;">
                            <a href="/about.php" target="_blank" class="btn btn-outline btn-sm">查看公开关于页面</a>
                            <a href="https://github.com" target="_blank" class="btn btn-outline btn-sm">GitHub</a>
                            <a href="https://www.themoviedb.org/" target="_blank" class="btn btn-outline btn-sm">TMDB</a>
                        </div>
                    </div>
                </div>
            `;
        } catch (e) {
            $('#aboutContent').innerHTML = '<p style="color:#e50914;">加载失败: ' + escHtml(e.message || '') + '</p>';
        }
    }

    function formatBytesForAbout(bytes) {
        if (!bytes) return '0 B';
        const n = parseInt(bytes);
        if (n < 1024) return n + ' B';
        if (n < 1048576) return (n / 1024).toFixed(1) + ' KB';
        if (n < 1073741824) return (n / 1048576).toFixed(1) + ' MB';
        if (n < 1099511627776) return (n / 1073741824).toFixed(2) + ' GB';
        return (n / 1099511627776).toFixed(2) + ' TB';
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

    if ($('#settingRemoteAccess')) {
        $('#settingRemoteAccess').addEventListener('change', (e) => {
            if (e.target.value === '1') {
                if (!confirm('⚠️ 风险提示\n\n外网访问需要具备相关资质，且涉及影视作品版权问题。\n\n开启外网访问意味着您的媒体库将通过互联网公开，请确保：\n1. 您拥有合法的影视资源使用资质\n2. 您了解并愿意承担相关版权法律风险\n3. 已配置好防火墙和安全策略\n\n点击"确定"继续开启，点击"取消"放弃。')) {
                    e.target.value = '0';
                }
            }
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
            remote_access_enabled: $('#settingRemoteAccess')?.value || '0',
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
                        <button class="btn btn-sm btn-outline perm-user-btn" data-id="${u.id}" data-username="${escHtml(u.username)}" data-groupid="${u.group_id}">权限</button>
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

            $$('.perm-user-btn').forEach(btn => {
                btn.addEventListener('click', async () => {
                    const userId = parseInt(btn.dataset.id);
                    const username = btn.dataset.username;
                    const curGroupId = parseInt(btn.dataset.groupid);
                    const groupOptions = groups.map(g => `<option value="${g.id}" ${g.id === curGroupId ? 'selected' : ''}>${escHtml(g.name)}</option>`).join('');

                    const overlay = document.createElement('div');
                    overlay.className = 'modal-overlay';
                    overlay.style.cssText = 'display:flex;z-index:3000;';
                    overlay.innerHTML = `<div class="modal-content" style="max-width:400px;padding:24px;">
                        <div class="modal-header" style="margin-bottom:16px;">
                            <h3>调整 ${escHtml(username)} 的权限</h3>
                            <button class="modal-close" style="position:static;float:right;" onclick="this.closest('.modal-overlay').remove()">&times;</button>
                        </div>
                        <div class="form-group">
                            <label>权限组</label>
                            <select id="quickPermGroup" style="width:100%;padding:10px 14px;background:rgba(255,255,255,0.06);border:1px solid var(--border);border-radius:8px;color:var(--text-primary);font-size:14px;outline:none;">${groupOptions}</select>
                        </div>
                        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;">
                            <button class="btn btn-outline" onclick="this.closest('.modal-overlay').remove()">取消</button>
                            <button class="btn btn-primary" id="saveQuickPerm">保存</button>
                        </div>
                    </div>`;
                    document.body.appendChild(overlay);

                    overlay.querySelector('#saveQuickPerm').addEventListener('click', async () => {
                        const newGroupId = parseInt(overlay.querySelector('#quickPermGroup').value);
                        await api('/api/auth.php?action=update_user', { method: 'POST', body: JSON.stringify({ id: userId, group_id: newGroupId }) });
                        toast('权限已更新');
                        overlay.remove();
                        loadUsers();
                    });

                    overlay.addEventListener('click', (e) => { if (e.target === overlay) overlay.remove(); });
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
                const poster = m.poster_path ? `<img src="/api/image.php?size=w92&path=${encodeURIComponent(m.poster_path)}" style="width:36px;height:52px;object-fit:cover;border-radius:3px;">` : '<div style="width:36px;height:52px;background:var(--bg-hover);border-radius:3px;"></div>';
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

    function escAttr(str) {
        return (str || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
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
                    ${s.poster_path ? `<img src="/api/image.php?size=w92&path=${encodeURIComponent(s.poster_path)}" style="width:56px;border-radius:6px;flex-shrink:0;">` : ''}
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
            if (tab === 'metadata') loadMetadataTree();
            if (tab === 'users') { loadUsers(); loadUserGroupDropdown(); initUserSubtabs(); }
            if (tab === 'activity') { loadActivity(); clearInterval(activityTimer); activityTimer = setInterval(loadActivity, 10000); }
            if (tab === 'notify') loadNotifyUsers();
            if (tab === 'settings') loadSettings();
            if (tab === 'about') loadAbout();
            if (tab === 'vip') loadVipPage();
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

    // ===== VIP管理 =====
    let vipMediaData = [];

    async function loadVipPage() {
        const libs = await api('/api/scan.php?action=list_libraries');
        const sel = $('#vipLibrarySelect');
        if (!sel) return;
        sel.innerHTML = '<option value="">-- 选择媒体库 --</option>';
        libs.forEach(l => {
            sel.innerHTML += `<option value="${l.id}">${escHtml(l.name)} (${l.type === 'tv' ? '剧集' : l.type === 'movie' ? '电影' : '其他'})</option>`;
        });
    }

    async function loadVipMedia() {
        const libId = parseInt($('#vipLibrarySelect')?.value || 0);
        if (!libId) { toast('请先选择媒体库', 'error'); return; }

        const container = $('#vipMediaList');
        if (!container) return;
        container.innerHTML = '<div class="loading-spinner"><div class="spinner"></div><p>加载中...</p></div>';

        try {
            const tree = await api('/api/media.php?action=media_tree_vip&library_id=' + libId);
            vipMediaData = tree || [];
            renderVipList();
        } catch (e) {
            container.innerHTML = '<div style="color:#ff6b6b;padding:20px;">加载失败: ' + escHtml(e.message || '') + '</div>';
        }
    }

    function renderVipList() {
        const container = $('#vipMediaList');
        if (!container) return;

        if (!vipMediaData || vipMediaData.length === 0) {
            container.innerHTML = '<div style="color:var(--text-muted);padding:20px;text-align:center;font-size:13px;">该媒体库暂无内容</div>';
            $('#vipCount').textContent = '';
            return;
        }

        let html = '';
        vipMediaData.forEach(item => {
            const isVip = item.vip_only == 1;
            const posterImg = item.poster_path
                ? `<img src="/api/image.php?size=w92&path=${encodeURIComponent(item.poster_path)}" style="width:40px;height:56px;object-fit:cover;border-radius:4px;flex-shrink:0;">`
                : '<div style="width:40px;height:56px;background:var(--bg-hover);border-radius:4px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:18px;color:var(--text-muted);">🎬</div>';
            html += `
                <div class="vip-media-item" data-id="${item.id}" style="display:flex;align-items:center;gap:12px;padding:10px 12px;border-radius:8px;margin-bottom:4px;background:${isVip ? 'rgba(229,9,20,0.08)' : 'rgba(255,255,255,0.02)'};border:1px solid ${isVip ? 'rgba(229,9,20,0.3)' : 'var(--border)'};cursor:pointer;" onclick="toggleVipItem(this)">
                    <input type="checkbox" class="vip-check" data-id="${item.id}" ${isVip ? 'checked' : ''} onclick="event.stopPropagation();" style="cursor:pointer;">
                    ${posterImg}
                    <div style="flex:1;min-width:0;">
                        <div style="font-size:13px;color:var(--text-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${escHtml(item.title)}</div>
                        <div style="font-size:11px;color:var(--text-muted);">${item.type === 'tv' ? '剧集' : '电影'} · ${item.year || ''} ${isVip ? '<span style="color:#e50914;">· VIP</span>' : ''}</div>
                    </div>
                </div>`;
        });

        container.innerHTML = html;
        const vipCount = vipMediaData.filter(i => i.vip_only == 1).length;
        $('#vipCount').textContent = `共 ${vipMediaData.length} 部，其中 VIP: ${vipCount} 部`;
    }

    window.toggleVipItem = function(el) {
        const cb = el.querySelector('.vip-check');
        if (cb) cb.checked = !cb.checked;
    };

    if ($('#vipSelectAll')) {
        $('#vipSelectAll').addEventListener('change', (e) => {
            document.querySelectorAll('#vipMediaList .vip-check').forEach(cb => {
                cb.checked = e.target.checked;
            });
        });
    }

    if ($('#vipLoadBtn')) {
        $('#vipLoadBtn').addEventListener('click', loadVipMedia);
    }

    if ($('#vipApplyBtn')) {
        $('#vipApplyBtn').addEventListener('click', async () => {
            const libId = parseInt($('#vipLibrarySelect')?.value || 0);
            if (!libId) { toast('请先选择媒体库', 'error'); return; }

            const action = $('#vipActionSelect')?.value || 'set';
            const checks = document.querySelectorAll('#vipMediaList .vip-check');
            const selectedIds = [];
            checks.forEach(cb => {
                if (cb.checked) selectedIds.push(parseInt(cb.dataset.id));
            });

            if (selectedIds.length === 0) {
                if (!confirm('没有勾选任何影片，将对当前媒体库全部影片执行"' + (action === 'set' ? '设为VIP' : '取消VIP') + '"操作？')) return;
                vipMediaData.forEach(item => selectedIds.push(item.id));
            }

            if (!confirm('确定对选中的 ' + selectedIds.length + ' 部影片执行"' + (action === 'set' ? '设为VIP' : '取消VIP') + '"操作？')) return;

            showVipProgress('正在批量更新...', 0, selectedIds.length);
            let completed = 0;
            const batchSize = 50;

            try {
                for (let i = 0; i < selectedIds.length; i += batchSize) {
                    const batch = selectedIds.slice(i, i + batchSize);
                    await api('/api/media.php?action=batch_vip', {
                        method: 'POST',
                        body: JSON.stringify({
                            media_ids: batch,
                            vip_only: action === 'set' ? 1 : 0,
                        }),
                    });
                    completed += batch.length;
                    showVipProgress('正在批量更新...', completed, selectedIds.length);
                }
                toast('已更新 ' + completed + ' 部影片');
                hideVipProgress();
                loadVipMedia();
            } catch (e) {
                toast('操作失败: 网络错误', 'error');
                hideVipProgress();
            }
        });
    }

    function showVipProgress(text, current, total) {
        let bar = $('#vipProgressBar');
        if (!bar) {
            const container = document.getElementById('vipMediaList')?.parentElement;
            if (!container) return;
            bar = document.createElement('div');
            bar.id = 'vipProgressBar';
            bar.innerHTML = `
                <div style="margin:12px 0;background:rgba(255,255,255,0.04);border:1px solid var(--border);border-radius:8px;padding:16px;">
                    <div style="display:flex;justify-content:space-between;margin-bottom:6px;font-size:13px;">
                        <span id="vipProgressText">${text}</span>
                        <span id="vipProgressPercent" style="color:#e50914;font-weight:600;">0%</span>
                    </div>
                    <div style="height:6px;background:rgba(255,255,255,0.08);border-radius:3px;overflow:hidden;">
                        <div id="vipProgressFill" style="height:100%;background:#e50914;border-radius:3px;width:0%;transition:width 0.3s;"></div>
                    </div>
                </div>`;
            container.insertBefore(bar, container.firstChild);
        }
        const pct = total > 0 ? Math.round(current / total * 100) : 0;
        const textEl = $('#vipProgressText');
        const pctEl = $('#vipProgressPercent');
        const fillEl = $('#vipProgressFill');
        if (textEl) textEl.textContent = text;
        if (pctEl) pctEl.textContent = pct + '%';
        if (fillEl) fillEl.style.width = pct + '%';
    }

    function hideVipProgress() {
        const bar = $('#vipProgressBar');
        if (bar) bar.remove();
    }

    // Init
    loadDashboard();

    } catch (e) {
        console.error('admin.js 初始化错误:', e);
        const grid = document.getElementById('statsGrid');
        if (grid) grid.innerHTML = '<div style="color:#ff6b6b;padding:20px;">初始化错误: ' + e.message + ' (行' + (e.lineNumber || '') + ')</div>';
    }
})();
