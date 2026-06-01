(() => {
    if (!document.cookie.includes('PHPSESSID') && !document.querySelector('[id="logoutBtn"]')) return;

    const POLL_INTERVAL = 8000;
    const TOAST_DURATION = 5000;

    function showToast(msg, type = 'info') {
        const el = document.createElement('div');
        el.className = 'notify-toast notify-' + type;
        el.innerHTML = `
            <div class="notify-content">
                <span class="notify-icon">${type === 'success' ? '✓' : type === 'warning' ? '⚠' : 'ℹ'}</span>
                <span class="notify-text">${msg}</span>
            </div>
            <button class="notify-close">&times;</button>
        `;

        let container = document.getElementById('notifyContainer');
        if (!container) {
            container = document.createElement('div');
            container.id = 'notifyContainer';
            container.style.cssText = 'position:fixed;top:20px;right:20px;z-index:99999;display:flex;flex-direction:column;gap:8px;max-width:400px;';
            document.body.appendChild(container);
        }

        container.appendChild(el);

        requestAnimationFrame(() => {
            el.style.opacity = '1';
            el.style.transform = 'translateX(0)';
        });

        el.querySelector('.notify-close').addEventListener('click', () => removeToast(el));
        setTimeout(() => removeToast(el), TOAST_DURATION);
    }

    function removeToast(el) {
        el.style.opacity = '0';
        el.style.transform = 'translateX(100px)';
        setTimeout(() => el.remove(), 300);
    }

    const style = document.createElement('style');
    style.textContent = `
        .notify-toast {
            background: rgba(20,20,40,0.95);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 10px;
            padding: 14px 18px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            opacity: 0;
            transform: translateX(100px);
            transition: all 0.3s ease;
            box-shadow: 0 8px 32px rgba(0,0,0,0.4);
            font-size: 14px;
            color: #e8e8e8;
        }
        .notify-info { border-left: 4px solid #3b82f6; }
        .notify-success { border-left: 4px solid #10b981; }
        .notify-warning { border-left: 4px solid #f59e0b; }
        .notify-content { display: flex; align-items: center; gap: 10px; flex: 1; }
        .notify-icon { font-size: 18px; }
        .notify-warning .notify-icon { color: #f59e0b; }
        .notify-success .notify-icon { color: #10b981; }
        .notify-info .notify-icon { color: #3b82f6; }
        .notify-close {
            background: none; border: none; color: #888;
            font-size: 20px; cursor: pointer; padding: 0 4px;
            line-height: 1;
        }
        .notify-close:hover { color: #fff; }
    `;
    document.head.appendChild(style);

    async function pollNotifications() {
        try {
            const res = await fetch('/notifications.php?action=poll');
            if (!res.ok) return;
            const messages = await res.json();
            if (Array.isArray(messages)) {
                messages.forEach(m => showToast(m.message, m.type));
            }
        } catch (e) { /* ignore */ }
    }

    pollNotifications();
    setInterval(pollNotifications, POLL_INTERVAL);
})();
