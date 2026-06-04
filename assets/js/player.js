(() => {
    'use strict';

    try {

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

    if (video) {
        video.muted = false;
        video.volume = 1;
    }
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
        if (!iconVol || !iconMute) return;
        if (video.muted || video.volume === 0) {
            iconVol.style.display = 'none';
            iconMute.style.display = '';
        } else {
            iconVol.style.display = '';
            iconMute.style.display = 'none';
        }
        volumeSlider.value = video.muted ? 0 : video.volume;
    }
    updateVolumeIcon();

    const unmuteHint = $('#unmuteHint');
    if (unmuteHint) {
        unmuteHint.style.display = 'none';
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
    let pendingAudioIdx = -1;

    function switchAudioTrack(streamIndex) {
        currentAudioIdx = streamIndex;
        if (hlsInstance && multiHlsReady) {
            try {
                hlsInstance.audioTrack = streamIndex;
            } catch (err) { /* ignore */ }
        } else if (PD.audioCount >= 1 && !multiHlsReady) {
            pendingAudioIdx = streamIndex;
        }
    }

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
                switchAudioTrack(streamIndex);
            });
        });

        if (PD.audioCount >= 1) {
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
            const trackId = opt.dataset.trackId;

            if (trackId === 'off') {
                $$('.sub-option').forEach(o => o.classList.remove('active'));
                opt.classList.add('active');
                $('#subMenu').classList.remove('show');
                currentSubTrack = null;
                currentSubContent = null;
                subtitleOverlay.innerHTML = '';
                disableNativeSubtitles();
                return;
            }

            const ok = await loadSubtitle(trackId);
            if (ok) {
                $$('.sub-option').forEach(o => o.classList.remove('active'));
                opt.classList.add('active');
                $('#subMenu').classList.remove('show');
            }
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
            return true;
        } catch (e) {
            console.error('字幕加载失败:', e);
            alert('字幕加载失败，请尝试其他字幕或搜索下载字幕');
            return false;
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

        const wasMuted = video.muted;

        hlsInstance = new Hls({ startPosition: startTime });
        hlsInstance.loadSource(playlistUrl);
        hlsInstance.attachMedia(video);
        hlsInstance.on(Hls.Events.MANIFEST_PARSED, () => {
            multiHlsReady = true;
            video.muted = wasMuted;
            if (wasPlaying) video.play().catch(() => {});

            if (pendingAudioIdx >= 0) {
                try { hlsInstance.audioTrack = pendingAudioIdx; } catch (err) {}
                pendingAudioIdx = -1;
            } else if (currentAudioIdx >= 0) {
                try { hlsInstance.audioTrack = currentAudioIdx; } catch (err) {}
            }
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
            btn.textContent = orig;
            btn.disabled = false;
        }
    });

    // ===== 弹幕系统 =====
    const danmakuCanvas = $('#danmakuCanvas');
    const danmakuBar = $('#danmakuBar');
    const danmakuText = $('#danmakuText');
    const danmakuSend = $('#danmakuSend');
    const danmakuColor = $('#danmakuColor');
    const danmakuType = $('#danmakuType');
    const danmakuBtn = $('#danmakuBtn');
    const danmakuLabel = $('#danmakuLabel');

    let danmakuEnabled = false;
    let danmakuData = [];
    let danmakuPool = [];
    let danmakuCtx = null;
    let danmakuAnimId = null;
    const DANMAKU_COLORS = ['#ffffff','#ff4444','#44ff44','#4444ff','#ffff44','#ff44ff'];
    const CANVAS_FONT_SIZE = 18;

    function initDanmakuCanvas() {
        if (!danmakuCanvas) return;
        const videoArea = $('#videoArea');
        danmakuCanvas.width = videoArea.offsetWidth;
        danmakuCanvas.height = videoArea.offsetHeight;
        danmakuCtx = danmakuCanvas.getContext('2d');
    }

    async function loadDanmaku() {
        try {
            const res = await fetch(`/api/danmaku.php?action=list&file_id=${PD.fileId}`);
            danmakuData = await res.json();
            if (!Array.isArray(danmakuData)) danmakuData = [];
        } catch (e) { danmakuData = []; }
    }

    function renderDanmakuFrame() {
        if (!danmakuEnabled || !danmakuCtx) return;
        const ctx = danmakuCtx;
        const w = danmakuCanvas.width;
        const h = danmakuCanvas.height;
        const t = video.currentTime * 1000;

        ctx.clearRect(0, 0, w, h);

        danmakuPool = danmakuPool.filter(d => d.x > -d.width - 50 || d.opacity > 0);

        const lanes = new Array(Math.floor(h / 32)).fill(0);

        for (const d of danmakuData) {
            const dt = d.time_pos * 1000;
            if (Math.abs(t - dt) < 200 && !d._spawned) {
                d._spawned = true;
                const text = d.content;
                ctx.font = (d.font_size || CANVAS_FONT_SIZE) + 'px sans-serif';
                const m = ctx.measureText(text);

                let lane = 0;
                let minTime = Infinity;
                for (let i = 0; i < lanes.length; i++) {
                    if (lanes[i] < minTime) { minTime = lanes[i]; lane = i; }
                }
                lanes[lane] = t + 6000;

                const y = 50 + lane * 32 + Math.random() * 10;

                danmakuPool.push({
                    text: text,
                    color: d.color || '#ffffff',
                    type: d.type || 'scroll',
                    x: w,
                    y: y,
                    width: m.width,
                    opacity: 1,
                    fontSize: d.font_size || CANVAS_FONT_SIZE,
                });
            }
        }

        for (const d of danmakuPool) {
            ctx.globalAlpha = d.opacity;
            ctx.fillStyle = d.color;
            ctx.font = d.fontSize + 'px sans-serif';
            ctx.shadowColor = 'rgba(0,0,0,0.8)';
            ctx.shadowBlur = 2;
            ctx.fillText(d.text, d.x, d.y);
            ctx.shadowBlur = 0;

            if (d.type === 'scroll') {
                d.x -= 2;
            } else if (d.type === 'top' || d.type === 'bottom') {
                d.opacity -= 0.003;
            }
        }

        danmakuAnimId = requestAnimationFrame(renderDanmakuFrame);
    }

    function toggleDanmaku() {
        danmakuEnabled = !danmakuEnabled;
        if (danmakuEnabled) {
            danmakuLabel.style.color = '#fb7299';
            danmakuCanvas.style.display = 'block';
            danmakuData.forEach(d => d._spawned = false);
            danmakuPool = [];
            if (!danmakuAnimId) renderDanmakuFrame();
            if (!danmakuLoadTimer) startDanmakuPolling();
        } else {
            danmakuLabel.style.color = '';
            danmakuCanvas.style.display = 'none';
            if (danmakuAnimId) { cancelAnimationFrame(danmakuAnimId); danmakuAnimId = null; }
            if (danmakuCtx) danmakuCtx.clearRect(0, 0, danmakuCanvas.width, danmakuCanvas.height);
            stopDanmakuPolling();
        }
    }

    let danmakuLoadTimer = null;
    function startDanmakuPolling() {
        stopDanmakuPolling();
        danmakuLoadTimer = setInterval(loadDanmaku, 5000);
    }
    function stopDanmakuPolling() {
        if (danmakuLoadTimer) { clearInterval(danmakuLoadTimer); danmakuLoadTimer = null; }
    }

    async function sendDanmaku() {
        const content = danmakuText.value.trim();
        if (!content) return;
        danmakuSend.disabled = true;
        danmakuSend.textContent = '...';
        try {
            const res = await fetch('/api/danmaku.php?action=send', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    file_id: PD.fileId,
                    content: content,
                    time_pos: video.currentTime,
                    color: danmakuColor.value,
                    type: danmakuType.value,
                }),
            });
            const data = await res.json();
            if (data.success && data.danmaku) {
                danmakuData.push(data.danmaku);
                danmakuText.value = '';
                danmakuText.focus();
            } else {
                showDanmakuError(data.error || '发送失败');
            }
        } catch (e) {
            showDanmakuError('网络错误');
        }
        danmakuSend.disabled = false;
        danmakuSend.textContent = '发送';
    }

    function showDanmakuError(msg) {
        const tip = document.createElement('div');
        tip.textContent = msg;
        tip.style.cssText = 'position:absolute;bottom:100%;left:50%;transform:translateX(-50%);margin-bottom:6px;padding:4px 10px;background:#e50914;color:#fff;font-size:11px;border-radius:4px;white-space:nowrap;z-index:999;';
        danmakuBar.querySelector('.danmaku-bar-row').appendChild(tip);
        setTimeout(() => tip.remove(), 3000);
    }

    if (danmakuCanvas) {
        initDanmakuCanvas();
        loadDanmaku();
        window.addEventListener('resize', initDanmakuCanvas);

        if (danmakuBtn) danmakuBtn.addEventListener('click', toggleDanmaku);
        if (danmakuSend) danmakuSend.addEventListener('click', sendDanmaku);
        if (danmakuText) danmakuText.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.isComposing) { e.preventDefault(); sendDanmaku(); }
        });

        const bilibiliImportBtn = $('#bilibiliImportBtn');
        const bilibiliUrl = $('#bilibiliUrl');
        const danmakuImportToggle = $('#danmakuImportToggle');
        if (danmakuImportToggle) {
            danmakuImportToggle.addEventListener('click', () => {
                const bar = $('#danmakuBarImport');
                if (bar) bar.style.display = bar.style.display === 'none' ? 'flex' : 'none';
            });
        }
        if (bilibiliImportBtn && bilibiliUrl) {
            bilibiliImportBtn.addEventListener('click', () => bilibiliImport(bilibiliUrl.value.trim()));
            bilibiliUrl.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && !e.isComposing) { e.preventDefault(); bilibiliImport(bilibiliUrl.value.trim()); }
            });
        }

        video.addEventListener('seeked', () => {
            danmakuData.forEach(d => d._spawned = false);
            danmakuPool = [];
        });
    }

    // ===== B站弹幕导入（支持视频链接自动解析） =====
    async function bilibiliImport(input) {
        if (!input) return;
        try {
            const cid = parseBilibiliUrl(input);
            if (!cid) { alert('无法识别该链接，请使用B站视频页面链接（如 https://www.bilibili.com/video/BV...）'); return; }

            const btn = $('#bilibiliImportBtn');
            if (btn) { btn.disabled = true; btn.textContent = '导入中...'; }

            const res = await fetch('/api/danmaku.php?action=bilibili_import', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ file_id: PD.fileId, cid: String(cid) }),
            });
            const data = await res.json();
            if (data.success) {
                alert('成功导入 ' + data.count + ' 条弹幕');
                danmakuData = [];
                loadDanmaku();
                if (bilibiliUrl) bilibiliUrl.value = '';
            } else {
                alert('导入失败: ' + (data.error || '未知错误'));
            }
            if (btn) { btn.disabled = false; btn.textContent = '导入弹幕'; }
        } catch (e) {
            alert('导入失败: 网络错误');
            const btn = $('#bilibiliImportBtn');
            if (btn) { btn.disabled = false; btn.textContent = '导入弹幕'; }
        }
    }

    function parseBilibiliUrl(url) {
        url = url.trim();

        // Already a CID (numeric string)
        if (/^\d+$/.test(url)) return url;

        // Extract BV/av/ep from URL
        let m;

        // video/BV1xx411c7mD
        m = url.match(/bilibili\.com\/video\/(BV[a-zA-Z0-9]+)/);
        if (m) return fetchCidFromApi({ bvid: m[1] });

        // video/av170001
        m = url.match(/bilibili\.com\/video\/av(\d+)/);
        if (m) return fetchCidFromApi({ aid: m[1] });

        // bangumi/play/ep12345
        m = url.match(/bilibili\.com\/bangumi\/play\/ep(\d+)/);
        if (m) return fetchCidFromApi({ ep_id: m[1] });

        // bangumi/play/ss12345
        m = url.match(/bilibili\.com\/bangumi\/play\/ss(\d+)/);
        if (m) return fetchCidFromSeason(m[1]);

        // BV号直接输入
        m = url.match(/^(BV[a-zA-Z0-9]+)$/);
        if (m) return fetchCidFromApi({ bvid: m[1] });

        return null;
    }

    // 同步获取CID（用于parseBilibiliUrl内，此处返回null让异步处理）
    // 改为：解析后异步请求，由调用者bilibiliImport处理
    function parseBilibiliUrlSync(url) {
        url = url.trim();
        if (/^\d+$/.test(url)) return { type: 'cid', value: url };

        let m;
        m = url.match(/bilibili\.com\/video\/(BV[a-zA-Z0-9]+)/);
        if (m) return { type: 'bvid', value: m[1] };

        m = url.match(/bilibili\.com\/video\/av(\d+)/);
        if (m) return { type: 'aid', value: m[1] };

        m = url.match(/bilibili\.com\/bangumi\/play\/ep(\d+)/);
        if (m) return { type: 'ep', value: m[1] };

        m = url.match(/bilibili\.com\/bangumi\/play\/ss(\d+)/);
        if (m) return { type: 'ss', value: m[1] };

        m = url.match(/^(BV[a-zA-Z0-9]+)$/);
        if (m) return { type: 'bvid', value: m[1] };

        return null;
    }

    async function resolveCid(info) {
        if (!info) return null;
        if (info.type === 'cid') return info.value;

        try {
            let apiUrl = '';
            if (info.type === 'bvid') apiUrl = 'https://api.bilibili.com/x/player/pagelist?bvid=' + info.value;
            else if (info.type === 'aid') apiUrl = 'https://api.bilibili.com/x/player/pagelist?aid=' + info.value;
            else if (info.type === 'ep') apiUrl = 'https://api.bilibili.com/pgc/view/web/season?ep_id=' + info.value;
            else if (info.type === 'ss') apiUrl = 'https://api.bilibili.com/pgc/view/web/season?season_id=' + info.value;
            else return null;

            const res = await fetch(apiUrl);
            const data = await res.json();

            if (data.code === 0 && data.data) {
                if (info.type === 'bvid' || info.type === 'aid') {
                    // pagelist response
                    if (Array.isArray(data.data) && data.data.length > 0) {
                        return String(data.data[0].cid);
                    }
                } else if (info.type === 'ep' || info.type === 'ss') {
                    // season response - first episode's CID
                    const eps = data.data.episodes || [];
                    if (eps.length > 0) return String(eps[0].cid);
                }
            }
        } catch (e) { /* fall through */ }

        return null;
    }

    // Rewrite bilibiliImport to be async with URL parsing
    async function bilibiliImportV2(input) {
        if (!input) return;
        const btn = $('#bilibiliImportBtn');
        try {
            const info = parseBilibiliUrlSync(input);
            if (!info) { alert('无法识别该链接，请使用B站视频页面链接'); return; }

            if (btn) { btn.disabled = true; btn.textContent = '解析中...'; }

            const cid = await resolveCid(info);
            if (!cid) { alert('获取视频信息失败，请检查链接是否正确'); if (btn) { btn.disabled = false; btn.textContent = '导入弹幕'; } return; }

            if (btn) btn.textContent = '导入中...';

            const res = await fetch('/api/danmaku.php?action=bilibili_import', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ file_id: PD.fileId, cid: String(cid) }),
            });
            const data = await res.json();
            if (data.success) {
                alert('成功导入 ' + data.count + ' 条弹幕');
                danmakuData = [];
                loadDanmaku();
                const bilibiliUrl = $('#bilibiliUrl');
                if (bilibiliUrl) bilibiliUrl.value = '';
            } else {
                alert('导入失败: ' + (data.error || '未知错误'));
            }
        } catch (e) {
            alert('导入失败: 网络错误');
        }
        if (btn) { btn.disabled = false; btn.textContent = '导入弹幕'; }
    }

    // Override old function
    window.bilibiliImport = bilibiliImportV2;

    // Expose parse function for debugging
    window.bilibiliResolveCid = resolveCid;

    // ===== 一起看（同步观影） =====
    let watchRoomId = null;
    let watchRoomCode = null;
    let watchIsHost = false;
    let watchPollTimer = null;
    let watchSyncEnabled = false;
    let watchLastRemoteTime = 0;

    const watchModal = $('#watchModal');
    const watchModalContent = $('#watchModalContent');

    if ($('#watchTogetherBtn')) {
        if (!PD.watchHost && !PD.watchJoin) {
            $('#watchTogetherBtn').style.display = 'none';
        } else {
            $('#watchTogetherBtn').addEventListener('click', () => {
                if (!PD.watchHost && watchRoomId) {
                    showWatchRoomPanel();
                } else if (!PD.watchHost) {
                    showWatchJoinPanel();
                } else {
                    if (watchRoomId) {
                        showWatchRoomPanel();
                    } else {
                        showWatchJoinPanel();
                    }
                }
                watchModal.style.display = 'flex';
            });
        }
    }

    window.closeWatchModal = function() {
        if (watchModal) watchModal.style.display = 'none';
    };

    // 点击遮罩关闭
    if (watchModal) {
        watchModal.addEventListener('click', (e) => {
            if (e.target === watchModal) watchModal.style.display = 'none';
        });
    }

    function showWatchJoinPanel() {
        if (!watchModalContent) return;
        watchModalContent.innerHTML = `
            <h3 style="margin-bottom:16px;font-size:18px;">一起看</h3>
            <div style="margin-bottom:20px;">
                ${PD.watchHost ? '<button class="btn btn-primary" id="watchCreateBtn" style="width:100%;margin-bottom:12px;">创建房间</button>' : ''}
                <div style="display:flex;gap:8px;">
                    <input type="text" id="watchCodeInput" placeholder="输入分享码" maxlength="4" autocomplete="off"
                           style="flex:1;padding:10px 14px;background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.15);border-radius:8px;color:#fff;font-size:16px;text-transform:uppercase;text-align:center;letter-spacing:4px;outline:none;">
                    <button class="btn btn-outline" id="watchJoinBtn">加入</button>
                </div>
            </div>`;

        if (PD.watchHost) {
            const createBtn = $('#watchCreateBtn');
            if (createBtn) createBtn.addEventListener('click', createWatchRoom);
        }

        const joinBtn = $('#watchJoinBtn');
        if (joinBtn) {
            joinBtn.addEventListener('click', () => {
                const code = ($('#watchCodeInput')?.value || '').trim().toUpperCase();
                if (!code) return;
                joinWatchRoom(code);
            });
        }

        const codeInput = $('#watchCodeInput');
        if (codeInput) {
            codeInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && !e.isComposing) {
                    const code = (e.target.value || '').trim().toUpperCase();
                    if (code) joinWatchRoom(code);
                }
            });
        }
    }

    function showWatchRoomPanel() {
        if (!watchModalContent) return;
        watchModalContent.innerHTML = '<div style="text-align:center;padding:10px 0;color:var(--text-muted);">加载中...</div>';
        loadWatchRoomInfo();
    }

    async function loadWatchRoomInfo() {
        if (!watchRoomId || !watchModalContent) return;
        try {
            const res = await fetch(`/api/watch.php?action=room_info&room_id=${watchRoomId}`);
            const data = await res.json();
            if (!data.room || watchRoomId !== data.room.id) {
                watchRoomId = null;
                showWatchJoinPanel();
                return;
            }

            const room = data.room;
            const members = data.members || [];
            const hostName = room.host_name || '未知';
            const isHost = (PD.userId === room.host_user_id);
            const memberNames = members.map(m => m.display_name || m.username).join('、');

            watchModalContent.innerHTML = `
                <h3 style="margin-bottom:16px;font-size:18px;">${isHost ? '我的房间' : '一起看'}</h3>
                <div style="background:rgba(255,255,255,0.04);border-radius:8px;padding:12px 16px;margin-bottom:16px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                        <span style="font-size:13px;color:var(--text-muted);">分享码</span>
                        <span style="font-size:22px;font-weight:700;letter-spacing:6px;color:#e50914;">${room.code}</span>
                    </div>
                    <div style="font-size:12px;color:var(--text-muted);">房主: ${hostName}</div>
                    <div style="font-size:12px;color:var(--text-muted);">播放: ${room.file_name || ''}</div>
                </div>
                <div style="margin-bottom:16px;">
                    <div style="font-size:12px;color:var(--text-muted);margin-bottom:6px;">在线成员 (${members.length})</div>
                    <div style="display:flex;flex-wrap:wrap;gap:6px;" id="watchMembers">
                        ${members.map(m => `<span style="background:rgba(255,255,255,0.06);padding:4px 10px;border-radius:12px;font-size:12px;">${m.display_name || m.username}${m.id === room.host_user_id ? ' 👑' : ''}</span>`).join('')}
                    </div>
                </div>
                <div style="display:flex;gap:8px;">
                    ${isHost ? '<button class="btn btn-primary" id="watchSyncBtn" style="flex:1;">同步播放</button>' : ''}
                    <button class="btn btn-outline" id="watchLeaveBtn" style="flex:1;">退出房间</button>
                </div>`;

            $('#watchLeaveBtn')?.addEventListener('click', leaveWatchRoom);
            if (isHost && $('#watchSyncBtn')) {
                $('#watchSyncBtn').addEventListener('click', () => {
                    syncWatchState(true);
                    if (watchModal) watchModal.style.display = 'none';
                });
            }
        } catch (e) {
            if (watchModalContent) watchModalContent.innerHTML = '<div style="color:#ff6b6b;text-align:center;">加载失败</div>';
        }
    }

    async function createWatchRoom() {
        try {
            const res = await fetch('/api/watch.php?action=create_room', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ file_id: PD.fileId }),
            });
            const data = await res.json();
            if (data.success) {
                watchRoomId = data.room_id;
                watchRoomCode = data.code;
                watchIsHost = true;
                watchSyncEnabled = true;
                startWatchPolling();
                showWatchRoomPanel();
            } else {
                alert('创建失败: ' + (data.error || '未知错误'));
            }
        } catch (e) {
            alert('创建失败: 网络错误');
        }
    }

    async function joinWatchRoom(code) {
        try {
            const res = await fetch('/api/watch.php?action=join_room', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ code }),
            });
            const data = await res.json();
            if (data.success) {
                watchRoomId = data.room.id;
                watchRoomCode = data.room.code;
                watchIsHost = (PD.userId === data.room.host_user_id);

                // 如果当前文件和房间文件不一样，跳转过去
                if (data.room.file_id !== PD.fileId) {
                    window.location.href = '/player.php?file=' + data.room.file_id + '&watch=' + data.room.code;
                    return;
                }

                watchSyncEnabled = true;
                startWatchPolling();
                showWatchRoomPanel();

                // 初始同步位置
                if (!watchIsHost && data.room.current_time > 0) {
                    video.currentTime = data.room.current_time;
                    if (data.room.is_playing) video.play();
                }
            } else {
                alert('加入失败: ' + (data.error || '未知错误'));
            }
        } catch (e) {
            alert('加入失败: 网络错误');
        }
    }

    async function leaveWatchRoom() {
        if (!watchRoomId) return;
        try {
            await fetch('/api/watch.php?action=leave_room', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ room_id: watchRoomId }),
            });
        } catch (e) { /* ignore */ }
        stopWatchPolling();
        watchRoomId = null;
        watchRoomCode = null;
        watchIsHost = false;
        watchSyncEnabled = false;
        if (watchModal) watchModal.style.display = 'none';
    }

    async function syncWatchState(force) {
        if (!watchRoomId || !watchIsHost) return;
        try {
            await fetch('/api/watch.php?action=sync_state', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    room_id: watchRoomId,
                    current_time: video.currentTime,
                    is_playing: video.paused ? 0 : 1,
                }),
            });
        } catch (e) { /* ignore */ }
    }

    // 房主定时上报
    function startWatchPolling() {
        stopWatchPolling();
        watchSyncEnabled = true;
        watchPollTimer = setInterval(() => {
            if (!watchRoomId) { stopWatchPolling(); return; }

            if (watchIsHost) {
                // 房主上报
                syncWatchState(false);
            } else {
                // 成员拉取状态
                pollWatchState();
            }
        }, 2500);
    }

    function stopWatchPolling() {
        if (watchPollTimer) { clearInterval(watchPollTimer); watchPollTimer = null; }
    }

    async function pollWatchState() {
        if (!watchRoomId || watchIsHost || !watchSyncEnabled) return;
        try {
            const res = await fetch(`/api/watch.php?action=poll_state&room_id=${watchRoomId}`);
            const data = await res.json();
            if (!data || data.error) return;

            const remoteTime = data.current_time;
            const diff = Math.abs(remoteTime - video.currentTime);

            // 只在偏差 > 2秒时同步
            if (diff > 2) {
                watchLastRemoteTime = remoteTime;
                video.currentTime = remoteTime;
            }

            // 同步播放/暂停
            if (data.is_playing && video.paused) {
                video.play().catch(() => {});
            } else if (!data.is_playing && !video.paused) {
                video.pause();
            }
        } catch (e) { /* ignore */ }
    }

    // 房主端：视频事件自动上报
    video.addEventListener('play', () => { if (watchIsHost && watchSyncEnabled) syncWatchState(true); });
    video.addEventListener('pause', () => { if (watchIsHost && watchSyncEnabled) syncWatchState(true); });
    video.addEventListener('seeked', () => { if (watchIsHost && watchSyncEnabled) syncWatchState(true); });

    // 页面离开时退出房间
    window.addEventListener('beforeunload', () => {
        if (watchRoomId) {
            navigator.sendBeacon('/api/watch.php?action=leave_room', JSON.stringify({ room_id: watchRoomId }));
        }
    });

    // 检查URL中是否有watch参数（从邀请链接进入）
    (function checkWatchParam() {
        const params = new URLSearchParams(window.location.search);
        const watchCode = params.get('watch');
        if (watchCode && PD.userId) {
            setTimeout(() => {
                const wm = $('#watchModal');
                if (wm) wm.style.display = 'flex';
                joinWatchRoom(watchCode.trim().toUpperCase());
            }, 1500);
        }
    })();

    } catch (e) {
        console.error('播放器初始化错误:', e);
        alert('播放器加载失败: ' + e.message + '\n请刷新页面重试');
    }

})();
