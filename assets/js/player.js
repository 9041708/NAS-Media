(() => {
    'use strict';

    const PD = window.__PLAYER_DATA__;
    const $ = (s) => document.querySelector(s);
    const $$ = (s) => document.querySelectorAll(s);

    const video = $('#videoPlayer');
    const container = $('#playerContainer');
    const videoArea = $('#videoArea');
    const controls = $('#playerControls');
    const topbar = $('#playerTopbar');
    const progressBar = $('#progressBar');
    const progressContainer = $('#progressContainer');
    const progressBuffer = $('#progressBuffer');
    const progressThumb = $('#progressThumb');
    const progressTooltip = $('#progressTooltip');
    const currentTimeEl = $('#currentTime');
    const totalTimeEl = $('#totalTime');
    const volumeSlider = $('#volumeSlider');
    const subtitleOverlay = $('#subtitleOverlay');
    const skipOverlay = $('#skipOverlay');
    const skipBtn = $('#skipBtn');
    const skipText = $('#skipText');
    const seekIndicator = $('#seekIndicator');
    const seekIcon = $('#seekIcon');
    const seekTime = $('#seekTime');
    const centerPlayBtn = $('#centerPlayBtn');

    let hideControlsTimer = null;
    let isSeeking = false;
    let isPlaying = false;
    let currentSubTrack = null;
    let currentSubContent = null;
    let hlsInstance = null;
    let seekIndicatorTimer = null;
    let lastTapTime = 0;
    let savePositionTimer = null;
    let currentAudioIdx = -1;
    let multiHlsReady = false;

    // ===== 工具函数 =====
    function formatTime(sec) {
        if (isNaN(sec) || sec < 0) sec = 0;
        const h = Math.floor(sec / 3600);
        const m = Math.floor((sec % 3600) / 60);
        const s = Math.floor(sec % 60);
        if (h > 0) return `${h}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;
        return `${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;
    }

    function showElement(el, duration = 300) {
        el.style.display = '';
        el.style.opacity = '0';
        requestAnimationFrame(() => {
            el.style.transition = `opacity ${duration}ms`;
            el.style.opacity = '1';
        });
    }

    function hideElement(el, duration = 300) {
        el.style.transition = `opacity ${duration}ms`;
        el.style.opacity = '0';
        setTimeout(() => { el.style.display = 'none'; }, duration);
    }

    // ===== 播放/暂停 =====
    function togglePlay() {
        if (video.paused) {
            video.play();
        } else {
            video.pause();
        }
    }

    function updatePlayBtn() {
        const iconPlay = $('#playPauseBtn .icon-play');
        const iconPause = $('#playPauseBtn .icon-pause');
        if (video.paused) {
            iconPlay.style.display = '';
            iconPause.style.display = 'none';
            showElement(centerPlayBtn);
            centerPlayBtn.innerHTML = '<svg width="64" height="64" viewBox="0 0 24 24" fill="white"><polygon points="5 3 19 12 5 21 5 3"/></svg>';
        } else {
            iconPlay.style.display = 'none';
            iconPause.style.display = '';
            hideElement(centerPlayBtn);
        }
    }

    video.addEventListener('play', () => { isPlaying = true; updatePlayBtn(); });
    video.addEventListener('pause', () => { isPlaying = false; updatePlayBtn(); });

    $('#playPauseBtn').addEventListener('click', togglePlay);
    videoArea.addEventListener('click', (e) => {
        if (e.target === video || e.target === videoArea || e.target === subtitleOverlay) {
            const now = Date.now();
            if (now - lastTapTime < 300) {
                toggleFullscreen();
                lastTapTime = 0;
                return;
            }
            lastTapTime = now;
            togglePlay();
        }
    });

    // ===== 进度条 =====
    function updateProgress() {
        if (isSeeking) return;
        const pct = video.duration ? (video.currentTime / video.duration * 100) : 0;
        progressBar.style.width = pct + '%';
        progressThumb.style.left = pct + '%';
        currentTimeEl.textContent = formatTime(video.currentTime);
        totalTimeEl.textContent = formatTime(video.duration);

        if (video.buffered.length > 0) {
            const buffEnd = video.buffered.end(video.buffered.length - 1);
            progressBuffer.style.width = (buffEnd / video.duration * 100) + '%';
        }
    }

    video.addEventListener('timeupdate', updateProgress);
    video.addEventListener('loadedmetadata', () => {
        totalTimeEl.textContent = formatTime(video.duration);
        updateSkipMarkers();
        restorePosition();
    });

    function seekFromEvent(e) {
        const rect = progressContainer.getBoundingClientRect();
        const pct = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width));
        video.currentTime = pct * video.duration;
        progressBar.style.width = (pct * 100) + '%';
        progressThumb.style.left = (pct * 100) + '%';
    }

    progressContainer.addEventListener('mousedown', (e) => {
        isSeeking = true;
        seekFromEvent(e);
    });
    document.addEventListener('mousemove', (e) => {
        if (isSeeking) seekFromEvent(e);

        const rect = progressContainer.getBoundingClientRect();
        if (e.clientX >= rect.left && e.clientX <= rect.right &&
            e.clientY >= rect.top && e.clientY <= rect.bottom) {
            const pct = (e.clientX - rect.left) / rect.width;
            const time = pct * (video.duration || 0);
            progressTooltip.textContent = formatTime(time);
            progressTooltip.style.left = (pct * 100) + '%';
            progressTooltip.style.display = 'block';
        }
    });
    document.addEventListener('mouseup', () => { isSeeking = false; });

    // Touch support
    progressContainer.addEventListener('touchstart', (e) => {
        isSeeking = true;
        seekFromEvent(e.touches[0]);
    });
    progressContainer.addEventListener('touchmove', (e) => {
        if (isSeeking) { e.preventDefault(); seekFromEvent(e.touches[0]); }
    });
    progressContainer.addEventListener('touchend', () => { isSeeking = false; });

    // ===== 快进/快退 =====
    function seekRelative(seconds) {
        video.currentTime = Math.max(0, Math.min(video.duration, video.currentTime + seconds));
        showSeekIndicator(seconds);
    }

    function showSeekIndicator(seconds) {
        seekIcon.innerHTML = seconds > 0
            ? '<svg width="32" height="32" viewBox="0 0 24 24" fill="white"><polygon points="5 4 15 12 5 20 5 4"/><polygon points="19 4 19 20"/></svg>'
            : '<svg width="32" height="32" viewBox="0 0 24 24" fill="white"><polygon points="19 20 9 12 19 4 19 20"/><polygon points="5 4 5 20"/></svg>';
        seekTime.textContent = (seconds > 0 ? '+' : '') + seconds + 's';
        showElement(seekIndicator);
        clearTimeout(seekIndicatorTimer);
        seekIndicatorTimer = setTimeout(() => hideElement(seekIndicator), 800);
    }

    // ===== 音量 =====
    volumeSlider.addEventListener('input', (e) => {
        video.volume = parseFloat(e.target.value);
        video.muted = false;
        updateVolumeIcon();
    });

    $('#volumeBtn').addEventListener('click', () => {
        video.muted = !video.muted;
        updateVolumeIcon();
    });

    function updateVolumeIcon() {
        const iconVol = $('#volumeBtn .icon-vol');
        const iconMute = $('#volumeBtn .icon-mute');
        if (video.muted || video.volume === 0) {
            iconVol.style.display = 'none';
            iconMute.style.display = '';
        } else {
            iconVol.style.display = '';
            iconMute.style.display = 'none';
        }
        volumeSlider.value = video.muted ? 0 : video.volume;
    }

    // ===== 倍速 =====
    $('#speedBtn').addEventListener('click', (e) => {
        e.stopPropagation();
        $('#speedMenu').classList.toggle('show');
    });

    $$('.speed-option').forEach(opt => {
        opt.addEventListener('click', (e) => {
            e.stopPropagation();
            const speed = parseFloat(opt.dataset.speed);
            video.playbackRate = speed;
            $$('.speed-option').forEach(o => o.classList.remove('active'));
            opt.classList.add('active');
            $('#speedLabel').textContent = speed === 1 ? '倍速' : speed + 'x';
            $('#speedMenu').classList.remove('show');
            saveUserPref('speed_pref', speed);
        });
    });

    if (PD.defaultSpeed !== 1) {
        video.playbackRate = PD.defaultSpeed;
        $$('.speed-option').forEach(o => {
            if (parseFloat(o.dataset.speed) === PD.defaultSpeed) {
                o.classList.add('active');
                $$('.speed-option').forEach(x => { if (x !== o) x.classList.remove('active'); });
            }
        });
    }

    // ===== 音轨切换 =====
    if ($('#audioBtn')) {
        $('#audioBtn').addEventListener('click', (e) => {
            e.stopPropagation();
            $('#audioMenu').classList.toggle('show');
        });

        $$('.audio-option').forEach(opt => {
            opt.addEventListener('click', (e) => {
                e.stopPropagation();
                $$('.audio-option').forEach(o => o.classList.remove('active'));
                opt.classList.add('active');
                $('#audioMenu').classList.remove('show');

                const streamIndex = parseInt(opt.dataset.stream);
                currentAudioIdx = streamIndex;

                if (hlsInstance) {
                    try {
                        hlsInstance.audioTrack = streamIndex;
                    } catch (err) {}
                }

                if (multiHlsReady && hlsInstance) {
                    try {
                        hlsInstance.audioTrack = streamIndex;
                    } catch (err) {}
                }
            });
        });

        if (PD.audioCount > 1) {
            initMultiTrackHls();
        }
    }

    // ===== 字幕切换 =====
    $('#subBtn').addEventListener('click', (e) => {
        e.stopPropagation();
        $('#subMenu').classList.toggle('show');
    });

    $$('.sub-option').forEach(opt => {
        opt.addEventListener('click', async (e) => {
            e.stopPropagation();
            $$('.sub-option').forEach(o => o.classList.remove('active'));
            opt.classList.add('active');
            $('#subMenu').classList.remove('show');

            const trackId = opt.dataset.trackId;
            if (trackId === 'off') {
                currentSubTrack = null;
                currentSubContent = null;
                subtitleOverlay.innerHTML = '';
                disableNativeSubtitles();
                return;
            }

            await loadSubtitle(trackId);
        });
    });

    async function loadSubtitle(trackId) {
        try {
            const res = await fetch(`/api/subtitle.php?action=serve&track_id=${trackId}`);
            if (!res.ok) throw new Error('加载失败');
            const text = await res.text();
            currentSubContent = parseVtt(text);
            currentSubTrack = trackId;
            disableNativeSubtitles();
        } catch (e) {
            console.error('字幕加载失败:', e);
        }
    }

    function disableNativeSubtitles() {
        for (let i = 0; i < video.textTracks.length; i++) {
            video.textTracks[i].mode = 'disabled';
        }
    }

    function parseVtt(vttText) {
        const cues = [];
        const lines = vttText.replace(/\r\n/g, '\n').split('\n');
        let i = 0;

        if (lines[0] && lines[0].includes('WEBVTT')) i = 1;

        while (i < lines.length) {
            if (lines[i].trim() === '' || /^\d+$/.test(lines[i].trim())) {
                i++;
                continue;
            }

            const timeMatch = lines[i].match(
                /(\d{1,2}:)?(\d{2}):(\d{2})[.,](\d{3})\s*-->\s*(\d{1,2}:)?(\d{2}):(\d{2})[.,](\d{3})/
            );
            if (timeMatch) {
                const start = parseTime(timeMatch);
                const end = parseTime(timeMatch, 4);
                i++;
                let text = '';
                while (i < lines.length && lines[i].trim() !== '') {
                    text += (text ? '\n' : '') + lines[i];
                    i++;
                }
                cues.push({ start, end, text: text.replace(/<[^>]*>/g, '') });
            } else {
                i++;
            }
        }
        return cues;
    }

    function parseTime(match, offset = 0) {
        const h = match[1 + offset] ? parseInt(match[1 + offset]) : 0;
        const m = parseInt(match[2 + offset]);
        const s = parseInt(match[3 + offset]);
        const ms = parseInt(match[4 + offset]);
        return h * 3600 + m * 60 + s + ms / 1000;
    }

    function renderSubtitles() {
        if (!currentSubContent) {
            subtitleOverlay.innerHTML = '';
            return;
        }
        const t = video.currentTime;
        const active = currentSubContent.filter(c => t >= c.start && t <= c.end);
        subtitleOverlay.innerHTML = active.map(c =>
            `<span class="sub-line">${c.text.replace(/\n/g, '<br>')}</span>`
        ).join('');
    }

    video.addEventListener('timeupdate', renderSubtitles);

    // ===== 画质切换 =====
    $('#qualityBtn').addEventListener('click', (e) => {
        e.stopPropagation();
        $('#qualityMenu').classList.toggle('show');
    });

    $$('.quality-option').forEach(opt => {
        opt.addEventListener('click', (e) => {
            e.stopPropagation();
            const quality = opt.dataset.quality;
            const status = opt.dataset.status;
            const hls = opt.dataset.hls;

            if (quality === '原画') {
                switchToOriginal();
                setActiveQuality(opt);
                return;
            }

            if (status === 'completed' && hls) {
                switchToHls(hls);
                setActiveQuality(opt);
            } else if (status === 'none' || status === 'failed') {
                startTranscode(quality, opt);
            }

            $('#qualityMenu').classList.remove('show');
        });
    });

    function setActiveQuality(opt) {
        $$('.quality-option').forEach(o => o.classList.remove('active'));
        opt.classList.add('active');
        $('#qualityLabel').textContent = opt.dataset.quality;
    }

    function switchToOriginal() {
        if (hlsInstance) {
            hlsInstance.destroy();
            hlsInstance = null;
        }
        const currentTime = video.currentTime;
        const wasPlaying = !video.paused;
        video.src = `/api/stream.php?id=${PD.fileId}`;
        video.currentTime = currentTime;
        if (wasPlaying) video.play();
    }

    function switchToHls(playlistUrl) {
        const currentTime = video.currentTime;
        const wasPlaying = !video.paused;

        if (typeof Hls === 'undefined') {
            const script = document.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/hls.js@latest';
            script.onload = () => initHls(playlistUrl, currentTime, wasPlaying);
            document.head.appendChild(script);
        } else {
            initHls(playlistUrl, currentTime, wasPlaying);
        }
    }

    function initHls(playlistUrl, startTime, wasPlaying) {
        if (hlsInstance) hlsInstance.destroy();
        if (Hls.isSupported()) {
            hlsInstance = new Hls({ startPosition: startTime });
            hlsInstance.loadSource(playlistUrl);
            hlsInstance.attachMedia(video);
            hlsInstance.on(Hls.Events.MANIFEST_PARSED, () => {
                if (wasPlaying) video.play();
            });
        }
    }

    async function startTranscode(quality, opt) {
        opt.querySelector('.q-status')?.remove();
        const statusEl = document.createElement('span');
        statusEl.className = 'q-status';
        statusEl.textContent = '启动中...';
        opt.appendChild(statusEl);

        try {
            const res = await fetch('/api/transcode.php?action=start', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ file_id: PD.fileId, quality }),
            });
            const data = await res.json();
            if (data.success) {
                opt.dataset.status = data.job.status;
                opt.dataset.jobId = data.job.id;
                if (data.job.status === 'completed') {
                    opt.dataset.hls = data.job.hls_playlist;
                    statusEl.textContent = '就绪';
                } else {
                    statusEl.textContent = '转码中...';
                    pollTranscodeStatus(data.job.id, opt, statusEl);
                }
            }
        } catch (e) {
            statusEl.textContent = '失败';
        }
    }

    function pollTranscodeStatus(jobId, opt, statusEl) {
        const interval = setInterval(async () => {
            try {
                const res = await fetch(`/api/transcode.php?action=status&job_id=${jobId}`);
                const job = await res.json();
                statusEl.textContent = `转码中 ${job.progress}%`;
                if (job.status === 'completed') {
                    clearInterval(interval);
                    opt.dataset.status = 'completed';
                    opt.dataset.hls = job.hls_playlist;
                    statusEl.textContent = '就绪';
                } else if (job.status === 'failed') {
                    clearInterval(interval);
                    opt.dataset.status = 'failed';
                    statusEl.textContent = '失败';
                }
            } catch (e) {
                clearInterval(interval);
            }
        }, 3000);
    }

    // ===== 片头片尾跳过 =====
    function updateSkipMarkers() {
        if (!video.duration || !PD.skipSegments.length) return;

        PD.skipSegments.forEach(seg => {
            const markers = $$(`.skip-marker[data-start="${seg.start_time}"]`);
            markers.forEach(marker => {
                marker.style.left = (seg.start_time / video.duration * 100) + '%';
                marker.style.width = ((seg.end_time - seg.start_time) / video.duration * 100) + '%';
            });
        });
    }

    video.addEventListener('timeupdate', () => {
        if (!PD.skipSegments.length) return;
        const t = video.currentTime;

        for (const seg of PD.skipSegments) {
            if (t >= seg.start_time && t < seg.end_time) {
                skipOverlay.style.display = '';
                const remain = Math.ceil(seg.end_time - t);
                const label = seg.type === 'intro' ? '跳过片头' : seg.type === 'outro' ? '跳过片尾' : '跳过';
                skipText.textContent = `${label} (${remain}s)`;
                skipBtn.onclick = () => { video.currentTime = seg.end_time; skipOverlay.style.display = 'none'; };
                return;
            }
        }
        skipOverlay.style.display = 'none';
    });

    // ===== 画中画 =====
    $('#pipBtn').addEventListener('click', async () => {
        try {
            if (document.pictureInPictureElement) {
                await document.exitPictureInPicture();
            } else {
                await video.requestPictureInPicture();
            }
        } catch (e) {
            console.error('PiP失败:', e);
        }
    });

    // ===== 全屏 =====
    function toggleFullscreen() {
        if (document.fullscreenElement) {
            document.exitFullscreen();
        } else {
            container.requestFullscreen();
        }
    }

    $('#fullscreenBtn').addEventListener('click', toggleFullscreen);

    document.addEventListener('fullscreenchange', () => {
        const isFs = !!document.fullscreenElement;
        $('.icon-fs').style.display = isFs ? 'none' : '';
        $('.icon-fs-exit').style.display = isFs ? '' : 'none';
    });

    // ===== 上一集/下一集 =====
    if ($('#nextEpBtn')) {
        $('#nextEpBtn').addEventListener('click', () => {
            if (PD.nextFileId) {
                savePosition();
                window.location.href = `/player.php?file=${PD.nextFileId}`;
            }
        });
    }
    if ($('#prevEpBtn')) {
        $('#prevEpBtn').addEventListener('click', () => {
            if (PD.prevFileId) {
                savePosition();
                window.location.href = `/player.php?file=${PD.prevFileId}`;
            }
        });
    }

    if ($('#toggleEpBtn')) {
        $('#toggleEpBtn').addEventListener('click', () => {
            const panel = $('#episodePanel');
            if (panel) panel.classList.toggle('show');
        });
    }

    if ($('#epPanelClose')) {
        $('#epPanelClose').addEventListener('click', () => {
            $('#episodePanel')?.classList.remove('show');
        });
    }

    $$('.ep-panel-item').forEach(item => {
        item.addEventListener('click', () => {
            const fileId = item.dataset.fileId;
            if (fileId) {
                savePosition();
                window.location.href = `/player.php?file=${fileId}`;
            }
        });
    });

    if ($('#videoArea')) {
        $('#videoArea').addEventListener('click', (e) => {
            if (e.target.tagName === 'VIDEO' || e.target.closest('#playerControls')) {
                $('#episodePanel')?.classList.remove('show');
            }
        });
    }

    // 自动下一集
    video.addEventListener('ended', () => {
        recordPlay();
        if (PD.nextFileId) {
            showNextEpOverlay();
        }
    });

    function showNextEpOverlay() {
        const overlay = document.createElement('div');
        overlay.className = 'next-ep-overlay';
        overlay.innerHTML = `
            <div class="next-ep-card">
                <p>即将播放下一集</p>
                <div class="next-ep-countdown" id="nextEpCountdown">5</div>
                <div class="next-ep-actions">
                    <button class="btn btn-primary" id="nextEpNow">立即播放</button>
                    <button class="btn btn-outline" id="nextEpCancel">取消</button>
                </div>
            </div>
        `;
        videoArea.appendChild(overlay);

        let countdown = 5;
        const timer = setInterval(() => {
            countdown--;
            const el = $('#nextEpCountdown');
            if (el) el.textContent = countdown;
            if (countdown <= 0) {
                clearInterval(timer);
                window.location.href = `/player.php?file=${PD.nextFileId}`;
            }
        }, 1000);

        $('#nextEpNow').addEventListener('click', () => {
            clearInterval(timer);
            window.location.href = `/player.php?file=${PD.nextFileId}`;
        });
        $('#nextEpCancel').addEventListener('click', () => {
            clearInterval(timer);
            overlay.remove();
        });
    }

    // ===== 控制栏显示/隐藏 =====
    function showControls() {
        controls.style.opacity = '1';
        topbar.style.opacity = '1';
        container.style.cursor = '';
        clearTimeout(hideControlsTimer);
        hideControlsTimer = setTimeout(hideControls, 3000);
    }

    function hideControls() {
        if (isPlaying && !isSeeking) {
            controls.style.opacity = '0';
            topbar.style.opacity = '0';
            container.style.cursor = 'none';
        }
    }

    videoArea.addEventListener('mousemove', showControls);
    videoArea.addEventListener('touchstart', showControls);
    controls.addEventListener('mouseenter', () => clearTimeout(hideControlsTimer));

    // ===== 键盘快捷键 =====
    document.addEventListener('keydown', (e) => {
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;

        switch (e.key) {
            case ' ':
            case 'k':
                e.preventDefault();
                togglePlay();
                break;
            case 'ArrowRight':
                e.preventDefault();
                seekRelative(e.shiftKey ? 30 : 10);
                break;
            case 'ArrowLeft':
                e.preventDefault();
                seekRelative(e.shiftKey ? -30 : -10);
                break;
            case 'ArrowUp':
                e.preventDefault();
                video.volume = Math.min(1, video.volume + 0.05);
                volumeSlider.value = video.volume;
                updateVolumeIcon();
                break;
            case 'ArrowDown':
                e.preventDefault();
                video.volume = Math.max(0, video.volume - 0.05);
                volumeSlider.value = video.volume;
                updateVolumeIcon();
                break;
            case 'f':
                e.preventDefault();
                toggleFullscreen();
                break;
            case 'm':
                e.preventDefault();
                video.muted = !video.muted;
                updateVolumeIcon();
                break;
            case 'j':
                e.preventDefault();
                seekRelative(-10);
                break;
            case 'l':
                e.preventDefault();
                seekRelative(10);
                break;
            case 'n':
                if (PD.nextFileId) {
                    e.preventDefault();
                    savePosition();
                    window.location.href = `/player.php?file=${PD.nextFileId}`;
                }
                break;
            case 'p':
                if (PD.prevFileId) {
                    e.preventDefault();
                    savePosition();
                    window.location.href = `/player.php?file=${PD.prevFileId}`;
                }
                break;
            case '[':
                changeSpeed(-0.25);
                break;
            case ']':
                changeSpeed(0.25);
                break;
        }
    });

    function changeSpeed(delta) {
        const newSpeed = Math.max(0.25, Math.min(3, video.playbackRate + delta));
        video.playbackRate = newSpeed;
        $$('.speed-option').forEach(o => {
            o.classList.toggle('active', parseFloat(o.dataset.speed) === newSpeed);
        });
        $('#speedLabel').textContent = newSpeed === 1 ? '倍速' : newSpeed + 'x';
    }

    // ===== 位置记忆 =====
    function restorePosition() {
        const saved = localStorage.getItem('playpos_' + PD.fileId);
        if (saved && parseFloat(saved) > 5) {
            video.currentTime = parseFloat(saved);
        }
    }

    function savePosition() {
        if (video.currentTime > 0 && video.duration) {
            localStorage.setItem('playpos_' + PD.fileId, video.currentTime);
        }
    }

    video.addEventListener('timeupdate', () => {
        clearTimeout(savePositionTimer);
        savePositionTimer = setTimeout(savePosition, 5000);
    });

    // ===== 播放记录 =====
    let hasRecorded = false;
    async function recordPlay() {
        if (hasRecorded || !PD.userId || !PD.mediaId) return;
        hasRecorded = true;
        try {
            await fetch('/api/media.php?action=record_play', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    media_id: PD.mediaId,
                    file_id: PD.fileId,
                    position: Math.floor(video.currentTime),
                    duration: Math.floor(video.duration),
                }),
            });
        } catch (e) { /* ignore */ }
    }

    video.addEventListener('play', () => {
        if (!hasRecorded) setTimeout(recordPlay, 10000);
    });

    // ===== 用户偏好保存 =====
    async function saveUserPref(key, value) {
        if (!PD.userId) return;
        try {
            await fetch('/api/auth.php?action=update_pref', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ key, value }),
            });
        } catch (e) { /* ignore */ }
    }

    // ===== 关闭弹出菜单 =====
    document.addEventListener('click', (e) => {
        if (!e.target.closest('.speed-control')) $('#speedMenu')?.classList.remove('show');
        if (!e.target.closest('.audio-control')) $('#audioMenu')?.classList.remove('show');
        if (!e.target.closest('.sub-control')) $('#subMenu')?.classList.remove('show');
        if (!e.target.closest('.quality-control')) $('#qualityMenu')?.classList.remove('show');
    });

    // ===== 初始化 =====
    video.addEventListener('loadeddata', () => {
        showControls();
    });

    // 页面关闭前保存
    window.addEventListener('beforeunload', () => {
        savePosition();
    });

    window.goBack = function() {
        if (hlsInstance) hlsInstance.destroy();
        savePosition();
        if (PD.userId) {
            navigator.sendBeacon('/api/activity.php?action=stop', JSON.stringify({ user_id: PD.userId }));
        }
        location.replace('/index.php');
    };

    // ===== 多音轨HLS初始化 =====
    async function initMultiTrackHls() {
        try {
            const res = await fetch(`/api/transcode.php?action=multi_hls&file_id=${PD.fileId}`);
            const data = await res.json();
            if (data.error || !data.multi_track) return;

            const currentTime = video.currentTime || 0;
            const wasPlaying = !video.paused;

            if (typeof Hls === 'undefined') {
                const script = document.createElement('script');
                script.src = 'https://cdn.jsdelivr.net/npm/hls.js@latest';
                script.onload = () => startMultiHls(data.playlist, currentTime, wasPlaying);
                document.head.appendChild(script);
            } else {
                startMultiHls(data.playlist, currentTime, wasPlaying);
            }
        } catch (e) {
            console.error('多音轨HLS初始化失败:', e);
        }
    }

    function startMultiHls(playlistUrl, startTime, wasPlaying) {
        if (hlsInstance) hlsInstance.destroy();
        if (typeof Hls === 'undefined' || !Hls.isSupported()) return;

        hlsInstance = new Hls({ startPosition: startTime });
        hlsInstance.loadSource(playlistUrl);
        hlsInstance.attachMedia(video);
        hlsInstance.on(Hls.Events.MANIFEST_PARSED, () => {
            multiHlsReady = true;
            if (wasPlaying) video.play();
            $$('.audio-option').forEach(opt => {
                opt.addEventListener('click', (e) => {
                    if (hlsInstance && multiHlsReady) {
                        try { hlsInstance.audioTrack = parseInt(opt.dataset.stream); } catch (err) {}
                    }
                }, { once: false });
            });
        });
    }

    // ===== 字幕搜索 =====
    const subSearchBtn = $('#subSearchBtn');
    const subSearchModal = $('#subSearchModal');
    const subSearchResults = $('#subSearchResults');
    const closeSubSearch = $('#closeSubSearch');

    if (subSearchBtn) {
        subSearchBtn.addEventListener('click', async () => {
            subSearchModal.classList.add('active');
            await doSubSearch();
        });
    }
    if (closeSubSearch) {
        closeSubSearch.addEventListener('click', () => subSearchModal.classList.remove('active'));
    }
    if (subSearchModal) {
        subSearchModal.addEventListener('click', (e) => {
            if (e.target === subSearchModal) subSearchModal.classList.remove('active');
        });
    }

    $('#subSearchDoBtn')?.addEventListener('click', doSubSearch);

    async function doSubSearch() {
        const lang = $('#subSearchLang')?.value || 'zh';
        subSearchResults.innerHTML = '<div class="loading-spinner"><div class="spinner"></div><p>搜索中...</p></div>';
        try {
            const res = await fetch(`/api/subtitle.php?action=search&file_id=${PD.fileId}&lang=${lang}`);
            const data = await res.json();
            if (data.error) {
                subSearchResults.innerHTML = `<p style="color:var(--text-muted);text-align:center;">${data.error}</p>`;
                return;
            }
            const results = data.results || [];
            if (results.length === 0) {
                subSearchResults.innerHTML = '<p style="color:var(--text-muted);text-align:center;">未找到字幕，请尝试其他语言或手动下载</p>';
                return;
            }
            subSearchResults.innerHTML = results.map((r, i) => `
                <div class="sub-search-item" style="display:flex;align-items:center;gap:12px;padding:10px 12px;background:rgba(255,255,255,0.04);border-radius:6px;margin-bottom:8px;">
                    <div style="flex:1;min-width:0;">
                        <div style="font-size:13px;color:var(--text-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${escHtmlP(r.name)}</div>
                        <div style="font-size:11px;color:var(--text-muted);">${escHtmlP(r.source)} | ${r.lang || '?'}</div>
                    </div>
                    <button class="btn btn-xs btn-primary sub-dl-btn" data-url="${escAttrP(r.url)}" data-name="${escAttrP(r.name)}" data-lang="${escAttrP(r.lang || 'zh')}" data-local="${escAttrP(r.local_path || '')}" data-type="${r.type || 'link'}">
                        ${r.type === 'download' ? '下载' : r.type === 'local' ? '添加' : '打开'}
                    </button>
                </div>
            `).join('');

            $$('.sub-dl-btn').forEach(btn => {
                btn.addEventListener('click', () => downloadSubtitle(btn));
            });
        } catch (e) {
            subSearchResults.innerHTML = '<p style="color:#e50914;text-align:center;">搜索失败: ' + e.message + '</p>';
        }
    }

    async function downloadSubtitle(btn) {
        const url = btn.dataset.url;
        const name = btn.dataset.name;
        const lang = btn.dataset.lang;
        const localPath = btn.dataset.local;
        const type = btn.dataset.type;

        if (type === 'link') {
            window.open(url, '_blank');
            return;
        }

        btn.textContent = '下载中...';
        btn.disabled = true;
        try {
            const res = await fetch('/api/subtitle.php?action=download', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    file_id: PD.fileId,
                    url: url,
                    name: name,
                    lang: lang,
                    local_path: localPath,
                }),
            });
            const data = await res.json();
            if (data.success) {
                btn.textContent = '已添加';
                btn.style.background = '#10b981';
                setTimeout(() => subSearchModal.classList.remove('active'), 1000);
                setTimeout(() => location.reload(), 1500);
            } else {
                btn.textContent = '失败';
                btn.style.background = '#e50914';
                btn.disabled = false;
            }
        } catch (e) {
            btn.textContent = '失败';
            btn.style.background = '#e50914';
            btn.disabled = false;
        }
    }

    $('#subDirectDownloadBtn')?.addEventListener('click', async () => {
        const url = $('#subDirectUrl')?.value.trim();
        if (!url) return;
        const btn = $('#subDirectDownloadBtn');
        const orig = btn.textContent;
        btn.textContent = '下载中...';
        btn.disabled = true;
        try {
            const res = await fetch('/api/subtitle.php?action=download', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    file_id: PD.fileId,
                    url: url,
                    name: '手动下载字幕',
                    lang: $('#subSearchLang')?.value || 'zh',
                }),
            });
            const data = await res.json();
            if (data.success) {
                btn.textContent = '已添加';
                btn.style.background = '#10b981';
                setTimeout(() => subSearchModal.classList.remove('active'), 1000);
                setTimeout(() => location.reload(), 1500);
            } else {
                btn.textContent = '失败';
                btn.disabled = false;
            }
        } catch (e) {
            btn.textContent = '失败';
            btn.disabled = false;
        }
    });

    function escHtmlP(str) {
        const div = document.createElement('div');
        div.textContent = str || '';
        return div.innerHTML;
    }

    function escAttrP(str) {
        return (str || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

})();
