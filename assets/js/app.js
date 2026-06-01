(() => {
    'use strict';

    const state = {
        items: [],
        page: 1,
        limit: 20,
        total: 0,
        pages: 0,
        type: 'all',
        libraryId: 0,
        libraries: [],
        genre: '',
        sort: 'added',
        search: '',
        viewMode: 'grid',
        cast: '',
        currentDetail: null,
    };

    const $ = (sel) => document.querySelector(sel);
    const $$ = (sel) => document.querySelectorAll(sel);

    async function api(url, options = {}) {
        const res = await fetch(url, {
            headers: { 'Content-Type': 'application/json', ...options.headers },
            ...options,
        });
        return res.json();
    }

    async function loadMedia(reset = false) {
        if (reset) {
            state.page = 1;
            state.items = [];
        }

        const typeParam = (state.type === 'all' || state.type === 'favorites' || state.type === 'collections' || state.type === 'library') ? 'all' : state.type;

        const params = new URLSearchParams({
            action: 'list',
            page: state.page,
            limit: state.limit,
            type: typeParam,
            genre: state.genre,
            sort: state.sort,
            search: state.search,
        });
        if (state.cast) params.append('cast', state.cast);
        if (state.libraryId > 0) params.append('library_id', state.libraryId);

        $('#loadingSpinner').style.display = 'flex';
        $('#emptyState').style.display = 'none';
        $('#loadMore').style.display = 'none';

        try {
            const data = await api(`/api/media.php?${params}`);

            if (reset) {
                state.items = data.items;
            } else {
                state.items = [...state.items, ...data.items];
            }
            state.total = data.total;
            state.pages = data.pages;
            state.page = data.page;

            renderGrid();
            $('#mediaCount').textContent = `${state.total} 部影片`;

            if (state.page < state.pages) {
                $('#loadMore').style.display = 'block';
            }
        } catch (e) {
            console.error('加载失败:', e);
        } finally {
            $('#loadingSpinner').style.display = 'none';
            if (state.items.length === 0) {
                $('#emptyState').style.display = 'block';
            }
        }
    }

    function renderGrid() {
        const grid = $('#posterGrid');

        if (state.libraryId === 0 && state.type !== 'favorites' && state.type !== 'collections' && state.search === '') {
            renderGroupedSections();
            return;
        }

        grid.innerHTML = '';
        grid.className = 'poster-grid';
        if (state.viewMode === 'list') grid.classList.add('list-view');

        state.items.forEach(item => {
            const card = createPosterCard(item);
            grid.appendChild(card);
        });

        lazyLoadImages();
    }

    function createPosterCard(item) {
        const card = document.createElement('div');
        card.className = `poster-card${state.viewMode === 'list' ? ' list-item' : ''}`;
        card.dataset.id = item.id;

        const posterUrl = item.poster_path
            ? `https://image.tmdb.org/t/p/w500${item.poster_path}`
            : null;

        const rating = item.rating ? parseFloat(item.rating).toFixed(1) : '';
        const year = item.year || '';
        const typeLabel = item.type === 'tv' ? '剧集' : '电影';

        if (state.viewMode === 'list') {
            card.innerHTML = `
                ${posterUrl
                    ? `<img class="poster-img" data-src="${posterUrl}" alt="${escHtml(item.title)}">`
                    : `<div class="no-poster"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="2" width="20" height="20" rx="2.18" ry="2.18"/><path d="m7 2v20l5-3 5 3V2"/></svg></div>`
                }
                <div class="poster-overlay">
                    <div class="poster-title">${escHtml(item.title)}</div>
                    <div class="poster-meta">
                        ${year ? `<span>${year}</span>` : ''}
                        ${rating ? `<span class="poster-rating">★ ${rating}</span>` : ''}
                        <span>${typeLabel}</span>
                    </div>
                </div>
            `;
        } else {
            card.innerHTML = `
                ${posterUrl
                    ? `<img class="poster-img" data-src="${posterUrl}" alt="${escHtml(item.title)}">`
                    : `<div class="no-poster"><svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="2" width="20" height="20" rx="2.18" ry="2.18"/><path d="m7 2v20l5-3 5 3V2"/></svg><span>${escHtml(item.title)}</span></div>`
                }
                ${year ? `<span class="poster-year-badge">${year}</span>` : ''}
                <span class="poster-type-badge">${typeLabel}</span>
                <div class="poster-play"><svg width="24" height="24" viewBox="0 0 24 24" fill="#fff"><polygon points="5 3 19 12 5 21 5 3"/></svg></div>
                <div class="poster-overlay">
                    <div class="poster-title">${escHtml(item.title)}</div>
                    <div class="poster-meta">
                        ${rating ? `<span class="poster-rating">★ ${rating}</span>` : ''}
                        ${item.genres ? `<span>${item.genres.split(',')[0]}</span>` : ''}
                    </div>
                </div>
            `;
        }

        card.addEventListener('click', () => {
            window.location.href = '/show.php?id=' + item.id;
        });
        return card;
    }

    async function renderGroupedSections() {
        const grid = $('#posterGrid');
        grid.innerHTML = '';
        grid.className = '';

        let libs = state.libraries;
        if (libs.length === 0) {
            try {
                libs = await api('/api/scan.php?action=list_libraries');
                state.libraries = libs || [];
            } catch (e) {
                grid.innerHTML = '<div class="empty-state"><h3>暂无媒体库</h3><p>请在管理后台添加媒体库</p></div>';
                return;
            }
        }

        if (!libs || libs.length === 0) {
            grid.innerHTML = '<div class="empty-state"><h3>暂无媒体库</h3><p>请在管理后台添加媒体库</p></div>';
            return;
        }

        let hasContent = false;
        for (const lib of libs) {
            try {
                const params = new URLSearchParams({
                    action: 'list',
                    page: 1,
                    limit: 12,
                    library_id: lib.id,
                    sort: 'added',
                });
                const data = await api(`/api/media.php?${params}`);
                if (!data.items || data.items.length === 0) continue;
                hasContent = true;

                const section = document.createElement('div');
                section.className = 'lib-section';

                const header = document.createElement('div');
                header.className = 'lib-section-header';
                header.innerHTML = `<h3 class="lib-section-title">${escHtml(lib.name)}</h3>`;
                section.appendChild(header);

                const row = document.createElement('div');
                row.className = 'lib-section-row';
                row.style.cssText = 'display:flex;gap:12px;overflow-x:auto;padding:0 24px 16px;scroll-snap-type:x mandatory;';

                data.items.forEach(item => {
                    const card = document.createElement('div');
                    card.className = 'lib-section-card';
                    card.style.cssText = 'flex-shrink:0;width:150px;cursor:pointer;transition:transform 0.2s;scroll-snap-align:start;';
                    card.dataset.id = item.id;

                    const posterUrl = item.poster_path
                        ? `https://image.tmdb.org/t/p/w300${item.poster_path}`
                        : '';

                    card.innerHTML = `
                        <div class="lib-card-poster" style="position:relative;width:100%;aspect-ratio:2/3;border-radius:8px;overflow:hidden;background:var(--bg-card);">
                            ${posterUrl ? `<img src="${posterUrl}" alt="" style="width:100%;height:100%;object-fit:cover;" loading="lazy">` : '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:var(--text-muted);">N/A</div>'}
                            <div class="poster-play" style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:48px;height:48px;background:rgba(229,9,20,0.9);border-radius:50%;display:flex;align-items:center;justify-content:center;opacity:0;transition:opacity 0.3s;"><svg width="20" height="20" viewBox="0 0 24 24" fill="#fff"><polygon points="5 3 19 12 5 21 5 3"/></svg></div>
                        </div>
                        <div style="font-size:13px;color:var(--text-primary);margin-top:6px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${escHtml(item.title)}</div>
                        <div style="font-size:11px;color:var(--text-muted);">${item.year || ''} ${item.type === 'tv' ? '剧集' : '电影'}</div>
                    `;

                    card.addEventListener('mouseenter', () => {
                        card.style.transform = 'translateY(-4px)';
                        const playBtn = card.querySelector('.poster-play');
                        if (playBtn) playBtn.style.opacity = '1';
                    });
                    card.addEventListener('mouseleave', () => {
                        card.style.transform = '';
                        const playBtn = card.querySelector('.poster-play');
                        if (playBtn) playBtn.style.opacity = '0';
                    });
                    card.addEventListener('click', () => {
                        window.location.href = '/show.php?id=' + item.id;
                    });

                    row.appendChild(card);
                });

                section.appendChild(row);
                grid.appendChild(section);
            } catch (e) {
                console.error(`加载媒体库 ${lib.name} 失败:`, e);
            }
        }

        if (!hasContent) {
            grid.innerHTML = '<div class="empty-state" style="padding:60px;"><h3>所有媒体库暂无内容</h3><p>请先扫描媒体库以获取媒体信息</p></div>';
        }
    }

    function lazyLoadImages() {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const img = entry.target;
                    img.src = img.dataset.src;
                    img.onload = () => img.classList.add('loaded');
                    observer.unobserve(img);
                }
            });
        }, { rootMargin: '200px' });

        $$('.poster-img[data-src]').forEach(img => observer.observe(img));
    }

    async function showDetail(mediaId) {
        try {
            const data = await api(`/api/media.php?action=detail&id=${mediaId}`);
            state.currentDetail = data;

            const modal = $('#detailModal');
            const backdrop = data.backdrop_path
                ? `https://image.tmdb.org/t/p/original${data.backdrop_path}`
                : '';

            $('#detailBackdrop').style.backgroundImage = backdrop ? `url(${backdrop})` : 'none';
            $('#detailPoster').src = data.poster_path
                ? `https://image.tmdb.org/t/p/w500${data.poster_path}`
                : '/assets/images/no-poster.svg';
            $('#detailTitle').textContent = data.title || data.original_title;
            $('#detailYear').textContent = data.year || '';
            $('#detailYear').style.display = data.year ? '' : 'none';
            $('#detailRating').textContent = data.rating ? `★ ${parseFloat(data.rating).toFixed(1)}` : '';
            $('#detailRating').style.display = data.rating ? '' : 'none';
            $('#detailRuntime').textContent = data.runtime ? `${data.runtime}分钟` : '';
            $('#detailRuntime').style.display = data.runtime ? '' : 'none';
            $('#detailGenres').textContent = data.genres || '';
            $('#detailOverview').textContent = data.overview || '暂无简介';

            $('#detailDirector').innerHTML = data.director ? `<strong>导演：</strong>${escHtml(data.director)}` : '';
            $('#detailCast').innerHTML = data.cast_list ? `<strong>主演：</strong>${escHtml(data.cast_list)}` : '';

            const filesContainer = $('#detailFiles');
            if (data.files && data.files.length > 0) {
                filesContainer.innerHTML = `<h4>文件列表 (${data.files.length})</h4>` +
                    data.files.map(f => `
                        <div class="file-item" onclick="window.location.href='/player.php?file=${f.id}'">
                            <span class="file-name">${escHtml(f.file_name)}</span>
                            <span class="file-size">${formatBytes(f.file_size)}</span>
                        </div>
                    `).join('');
            } else {
                filesContainer.innerHTML = '';
            }

            modal.classList.add('active');
        } catch (e) {
            console.error('加载详情失败:', e);
        }
    }

    async function loadGenres() {
        try {
            const genres = await api('/api/media.php?action=genres');
            const select = $('#genreSelect');
            genres.forEach(g => {
                const opt = document.createElement('option');
                opt.value = g;
                opt.textContent = g;
                select.appendChild(opt);
            });
        } catch (e) {
            console.error('加载类型失败:', e);
        }
    }

    async function loadHeroSection() {
        try {
            const data = await api('/api/media.php?action=recent&limit=5');
            if (!data || data.length === 0) return;

            const slider = $('#heroSlider');
            slider.innerHTML = '';

            data.forEach((item, i) => {
                const slide = document.createElement('div');
                slide.className = `hero-slide${i === 0 ? ' active' : ''}`;

                const backdropUrl = item.backdrop_path
                    ? `https://image.tmdb.org/t/p/original${item.backdrop_path}`
                    : item.poster_path
                        ? `https://image.tmdb.org/t/p/original${item.poster_path}`
                        : '';

                const detailUrl = item.type === 'tv' ? `/show.php?id=${item.id}` : 'javascript:;';
                slide.innerHTML = `
                    ${backdropUrl ? `<img class="backdrop" src="${backdropUrl}" alt="">` : '<div class="backdrop" style="background:var(--bg-card)"></div>'}
                    <div class="gradient-overlay"></div>
                    <div class="hero-info">
                        <h2>${escHtml(item.title)}</h2>
                        <div class="hero-meta">
                            ${item.year ? `<span>${item.year}</span>` : ''}
                            ${item.rating ? `<span class="rating">★ ${parseFloat(item.rating).toFixed(1)}</span>` : ''}
                            ${item.genres ? `<span>${item.genres.split(',').slice(0, 3).join(' / ')}</span>` : ''}
                        </div>
                        <p>${escHtml(item.overview || '')}</p>
                        <div class="hero-actions">
                            <button class="btn btn-primary hero-play-btn" data-media-id="${item.id}">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                                播放
                            </button>
                            <button class="btn btn-outline hero-detail-btn" data-media-id="${item.id}" data-type="${item.type}">
                                详情
                            </button>
                        </div>
                    </div>
                `;

                slider.appendChild(slide);
            });

            slider.querySelectorAll('.hero-detail-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    const mediaId = btn.dataset.mediaId;
                    window.location.href = '/show.php?id=' + mediaId;
                });
            });

            slider.querySelectorAll('.hero-play-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    window.location.href = '/player.php?media=' + btn.dataset.mediaId;
                });
            });

            if (data.length > 1) {
                let current = 0;
                setInterval(() => {
                    const slides = $$('.hero-slide');
                    slides[current].classList.remove('active');
                    current = (current + 1) % slides.length;
                    slides[current].classList.add('active');
                }, 6000);
            }
        } catch (e) {
            console.error('加载Hero失败:', e);
        }
    }

    async function loadContinueWatching() {
        const user = window.__USER__;
        if (!user || !user.id) return;

        try {
            const items = await api('/api/media.php?action=continue_watching');
            if (!items || items.length === 0) return;

            const section = $('#continueSection');
            const row = $('#continueRow');
            row.innerHTML = '';

            items.forEach(item => {
                const pct = item.duration > 0 ? Math.min(100, Math.round(item.position / item.duration * 100)) : 0;
                const posterUrl = item.poster_path
                    ? `https://image.tmdb.org/t/p/w300${item.poster_path}`
                    : '';

                const card = document.createElement('div');
                card.className = 'continue-card';
                card.onclick = () => {
                    window.location.href = `/player.php?file=${item.file_id}`;
                };

                card.innerHTML = `
                    <div class="continue-poster">
                        ${posterUrl ? `<img src="${posterUrl}" alt="${escHtml(item.title)}" loading="lazy">` : '<div class="no-poster-sm"></div>'}
                        <div class="continue-progress-bar"><div class="continue-progress-fill" style="width:${pct}%"></div></div>
                    </div>
                    <div class="continue-title">${escHtml(item.title)}</div>
                    <div class="continue-meta">已看 ${Math.floor(item.position / 60)} 分钟</div>
                `;
                row.appendChild(card);
            });

            section.style.display = 'block';
        } catch (e) {
            console.error('加载继续观看失败:', e);
        }
    }

    function escHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function formatBytes(bytes) {
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        let i = 0;
        while (bytes >= 1024 && i < units.length - 1) {
            bytes /= 1024;
            i++;
        }
        return bytes.toFixed(2) + ' ' + units[i];
    }

    function on(id, event, handler) {
        const el = typeof id === 'string' ? $(id) : id;
        if (el) el.addEventListener(event, handler);
    }

    function initContextMenu() {
        let menu = $('#ctxMenu');
        if (!menu) {
            menu = document.createElement('div');
            menu.id = 'ctxMenu';
            menu.className = 'context-menu';
            document.body.appendChild(menu);
        }

        const grid = $('#posterGrid');
        if (!grid) return;

        grid.addEventListener('contextmenu', (e) => {
            const card = e.target.closest('.poster-card');
            if (!card) { hideContextMenu(); return; }

            const user = window.__USER__;
            if (!user || user.role !== 'admin') return;

            e.preventDefault();
            const mediaId = parseInt(card.dataset.id);
            if (!mediaId) return;

            state.contextMediaId = mediaId;

            menu.innerHTML = `
                <div class="ctx-item" data-action="refresh">刷新元数据</div>
                <div class="ctx-item" data-action="rematch">重新匹配 TMDB</div>
            `;

            menu.querySelectorAll('.ctx-item').forEach(item => {
                item.addEventListener('click', () => {
                    const action = item.dataset.action;
                    handleContextAction(action, mediaId);
                    hideContextMenu();
                });
            });

            let x = e.clientX;
            let y = e.clientY;
            if (x + 180 > window.innerWidth) x = window.innerWidth - 190;
            if (y + 80 > window.innerHeight) y = window.innerHeight - 90;
            menu.style.left = x + 'px';
            menu.style.top = y + 'px';
            menu.classList.add('show');
        });

        document.addEventListener('click', (e) => {
            if (!e.target.closest('.context-menu') && !e.target.closest('.poster-card')) {
                hideContextMenu();
            }
        });
    }

    function hideContextMenu() {
        const menu = $('#ctxMenu');
        if (menu) menu.classList.remove('show');
    }

    async function handleContextAction(action, mediaId) {
        switch (action) {
            case 'refresh':
                try {
                    const res = await api('/api/scan.php?action=refresh_meta', {
                        method: 'POST',
                        body: JSON.stringify({ media_id: mediaId }),
                    });
                    if (res.success) {
                        alert('元数据已刷新');
                        window.location.reload();
                    } else {
                        alert('刷新失败: ' + (res.error || '未知错误'));
                    }
                } catch (e) {
                    alert('刷新失败: 网络错误');
                }
                break;
            case 'rematch':
                const tmdbId = prompt('请输入 TMDB ID（在 themoviedb.org 找到剧集/电影，URL 中的数字即 ID）：');
                if (!tmdbId) return;
                try {
                    const res = await api('/api/media.php?action=match_media', {
                        method: 'POST',
                        body: JSON.stringify({ file_id: 0, media_id: mediaId, tmdb_id: parseInt(tmdbId) }),
                    });
                    if (res.success) {
                        alert('匹配成功');
                        window.location.reload();
                    } else {
                        alert('匹配失败: ' + (res.error || '未知错误'));
                    }
                } catch (e) {
                    alert('匹配失败: 网络错误');
                }
                break;
        }
    }

    function initEvents() {
        $$('.nav-links a').forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                $$('.nav-links a').forEach(l => l.classList.remove('active'));
                link.classList.add('active');
                state.type = link.dataset.section;
                state.libraryId = parseInt(link.dataset.libId) || 0;
                state.page = 1;
                state.items = [];
                if (state.type === 'favorites') {
                    loadFavorites();
                } else if (state.type === 'collections') {
                    loadCollections();
                } else {
                    loadMedia(true);
                }
            });
        });

        on('#sortSelect', 'change', (e) => { state.sort = e.target.value; loadMedia(true); });
        on('#genreSelect', 'change', (e) => { state.genre = e.target.value; loadMedia(true); });

        on('#searchInput', 'keyup', (e) => {
            if (e.key === 'Enter') { state.search = e.target.value.trim(); loadMedia(true); }
        });
        on('#searchBtn', 'click', () => { state.search = ($('#searchInput')?.value || '').trim(); loadMedia(true); });

        on('#viewToggle', 'click', () => {
            state.viewMode = state.viewMode === 'grid' ? 'list' : 'grid';
            const grid = $('#posterGrid');
            if (grid) grid.classList.toggle('list-view', state.viewMode === 'list');
            const btn = $('#viewToggle');
            if (btn) btn.title = state.viewMode === 'grid' ? '切换列表视图' : '切换网格视图';
            renderGrid();
        });

        on('#loadMoreBtn', 'click', () => { state.page++; loadMedia(false); });
        on('#closeDetail', 'click', () => { $('#detailModal')?.classList.remove('active'); });

        on('#detailModal', 'click', (e) => {
            if (e.target === $('#detailModal')) $('#detailModal').classList.remove('active');
        });

        on('#playBtn', 'click', () => {
            if (state.currentDetail?.files?.length > 0) {
                window.location.href = `/player.php?file=${state.currentDetail.files[0].id}`;
            }
        });

        on('#favBtn', 'click', async () => {
            if (!state.currentDetail) return;
            try {
                const res = await api('/api/media.php?action=toggle_favorite', {
                    method: 'POST',
                    body: JSON.stringify({ media_id: state.currentDetail.id }),
                });
                const btn = $('#favBtn');
                if (!btn) return;
                if (res.favorited) {
                    btn.innerHTML = `<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg> 已收藏`;
                } else {
                    btn.innerHTML = `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg> 收藏`;
                }
            } catch (e) {
                console.error('收藏失败:', e);
            }
        });

        const logoutBtn = $('#logoutBtn');
        if (logoutBtn) {
            logoutBtn.addEventListener('click', async (e) => {
                e.preventDefault();
                await api('/api/auth.php?action=logout');
                window.location.reload();
            });
        }

        initContextMenu();

        // 登录弹窗
        const loginBtn = $('#loginBtn');
        if (loginBtn) {
            loginBtn.addEventListener('click', (e) => {
                e.preventDefault();
                $('#loginModal').classList.add('active');
                $('#loginUsername').focus();
            });
        }

        const closeLogin = $('#closeLogin');
        if (closeLogin) {
            closeLogin.addEventListener('click', () => {
                $('#loginModal').classList.remove('active');
                $('#loginError').style.display = 'none';
            });
        }

        const loginModal = $('#loginModal');
        if (loginModal) {
            loginModal.addEventListener('click', (e) => {
                if (e.target === loginModal) {
                    loginModal.classList.remove('active');
                    $('#loginError').style.display = 'none';
                }
            });
        }

        const loginForm = $('#loginForm');
        if (loginForm) {
            loginForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                const errEl = $('#loginError');
                errEl.style.display = 'none';
                try {
                    const res = await api('/api/auth.php?action=login', {
                        method: 'POST',
                        body: JSON.stringify({
                            username: $('#loginUsername').value,
                            password: $('#loginPassword').value,
                        }),
                    });
                    if (res.success) {
                        window.location.reload();
                    } else {
                        errEl.textContent = res.error || '登录失败';
                        errEl.style.display = 'block';
                    }
                } catch (err) {
                    errEl.textContent = '网络错误';
                    errEl.style.display = 'block';
                }
            });
        }
    }

    async function loadFavorites() {
        try {
            const items = await api('/api/media.php?action=favorites');
            state.items = items;
            state.total = items.length;
            state.pages = 1;
            renderGrid();
            $('#mediaCount').textContent = `${items.length} 部收藏`;
            $('#loadMore').style.display = 'none';
            if (items.length === 0) {
                $('#emptyState').style.display = 'block';
                $('#emptyState').querySelector('h3').textContent = '暂无收藏';
                $('#emptyState').querySelector('p').textContent = '点击影片详情页的心形按钮收藏';
            }
        } catch (e) {
            console.error('加载收藏失败:', e);
        }
    }

    async function loadCollections() {
        try {
            const collections = await api('/api/media.php?action=list_collections');
            const grid = $('#posterGrid');
            grid.innerHTML = '';
            $('#emptyState').style.display = 'none';
            $('#loadMore').style.display = 'none';
            $('#mediaCount').textContent = `${collections.length} 个合集`;

            if (collections.length === 0) {
                $('#emptyState').style.display = 'block';
                $('#emptyState').querySelector('h3').textContent = '暂无合集';
                $('#emptyState').querySelector('p').textContent = '在影片详情页的更多菜单中创建合集';
                return;
            }

            collections.forEach(col => {
                const posterUrl = col.first_poster
                    ? `https://image.tmdb.org/t/p/w300${col.first_poster}`
                    : col.poster_path
                        ? `https://image.tmdb.org/t/p/w300${col.poster_path}`
                        : '';
                const card = document.createElement('div');
                card.className = 'poster-card';
                card.dataset.id = col.id;
                card.dataset.collection = 'true';
                card.innerHTML = `
                    <div class="poster-wrapper">
                        ${posterUrl
                            ? `<img src="${posterUrl}" alt="${escHtml(col.name)}" loading="lazy">`
                            : `<div class="no-poster"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><rect x="7" y="7" width="10" height="10" rx="1"/></svg><span>${escHtml(col.name)}</span></div>`}
                        <div class="poster-badge">合集</div>
                    </div>
                    <div class="poster-title">${escHtml(col.name)}</div>
                    <div class="poster-sub">${col.item_count} 部影片</div>
                `;
                card.addEventListener('click', async () => {
                    const items = await api(`/api/media.php?action=collection_items&id=${col.id}`);
                    state.type = 'collections';
                    state.items = items;
                    state.total = items.length;
                    state.pages = 1;
                    renderGrid();
                    $('#mediaCount').textContent = `${col.name} · ${items.length} 部`;
                    $('#loadMore').style.display = 'none';
                });
                grid.appendChild(card);
            });
        } catch (e) {
            console.error('加载合集失败:', e);
        }
    }

    function init() {
        const urlParams = new URLSearchParams(window.location.search);
        const castParam = urlParams.get('cast');
        if (castParam) {
            state.cast = castParam;
            state.type = 'all';
            showActiveFilter(`演员：${castParam}`);
        }
        initEvents();
        loadGenres();
        loadHeroSection();
        loadContinueWatching();
        loadMedia(true);
    }

    function showActiveFilter(text) {
        const bar = document.getElementById('activeFilter');
        const textEl = document.getElementById('filterText');
        if (bar && textEl) {
            textEl.textContent = text;
            bar.style.display = 'block';
        }
    }

    window.clearFilter = function() {
        state.cast = '';
        document.getElementById('activeFilter').style.display = 'none';
        const url = new URL(window.location);
        url.searchParams.delete('cast');
        window.history.replaceState({}, '', url);
        loadMedia(true);
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
