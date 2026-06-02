<?php
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/Auth.php';
require_once __DIR__ . '/includes/session.php';

if (auth()->isLoggedIn()) { header('Location: /index.php'); exit; }

$siteName = getSetting('site_name', 'NAS影库');
$allowRegister = getSetting('allow_register', '1');
$redirect = $_GET['redirect'] ?? '';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>登录 - <?= e($siteName) ?></title>
    <link rel="icon" type="image/png" href="/live_icon_cut.png">
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        .auth-page { display: flex; justify-content: center; align-items: center; min-height: 100vh; background: linear-gradient(135deg, #0f0f23 0%, #1a1a2e 50%, #16213e 100%); }
        .auth-card { background: rgba(255,255,255,0.05); backdrop-filter: blur(20px); border: 1px solid rgba(255,255,255,0.1); border-radius: 16px; padding: 48px 40px; width: 100%; max-width: 420px; }
        .auth-card h1 { font-size: 28px; margin-bottom: 8px; color: #fff; text-align: center; }
        .auth-card .subtitle { color: rgba(255,255,255,0.5); margin-bottom: 32px; text-align: center; }
        .auth-tabs { display: flex; margin-bottom: 28px; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .auth-tab { flex: 1; text-align: center; padding: 10px; cursor: pointer; color: rgba(255,255,255,0.5); font-size: 15px; transition: all 0.2s; border-bottom: 2px solid transparent; }
        .auth-tab.active { color: #e50914; border-bottom-color: #e50914; }
        .auth-form { display: none; }
        .auth-form.active { display: block; }
        .form-group { margin-bottom: 18px; text-align: left; }
        .form-group label { display: block; margin-bottom: 6px; color: rgba(255,255,255,0.7); font-size: 14px; }
        .form-group input { width: 100%; padding: 12px 16px; background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.15); border-radius: 8px; color: #fff; font-size: 15px; outline: none; transition: border-color 0.2s; }
        .form-group input:focus { border-color: #e50914; }
        .btn-auth { width: 100%; padding: 14px; background: #e50914; color: #fff; border: none; border-radius: 8px; font-size: 16px; cursor: pointer; margin-top: 8px; transition: background 0.2s; }
        .btn-auth:hover { background: #f40612; }
        .auth-error { background: rgba(229,9,20,0.15); border: 1px solid rgba(229,9,20,0.3); color: #ff6b6b; padding: 10px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; display: none; }
        .auth-error.show { display: block; }
    </style>
</head>
<body class="<?= themeClass() ?>">
    <div class="auth-page">
        <div class="auth-card">
            <img src="/live_icon_cut.png" alt="<?= e($siteName) ?>" height="48" style="margin-bottom:12px;">

            <div class="auth-tabs">
                <div class="auth-tab active" data-tab="login">登录</div>
                <?php if ($allowRegister): ?>
                    <div class="auth-tab" data-tab="register">注册</div>
                <?php endif; ?>
            </div>

            <div class="auth-error" id="authError"></div>

            <form class="auth-form active" id="loginForm">
                <div class="form-group">
                    <label>用户名</label>
                    <input type="text" id="loginUsername" required autofocus>
                </div>
                <div class="form-group">
                    <label>密码</label>
                    <input type="password" id="loginPassword" required>
                </div>
                <button type="submit" class="btn-auth">登 录</button>
                <?php if (getSetting('allow_password_reset', '1')): ?>
                    <p style="text-align:center;margin-top:12px;font-size:13px;">
                        <a href="/recovery.php" style="color:rgba(255,255,255,0.5);">忘记密码？</a>
                    </p>
                <?php endif; ?>
            </form>

            <?php if ($allowRegister): ?>
            <?php $requireEmail = getSetting('require_email_register', '1'); ?>
            <form class="auth-form" id="registerForm">
                <div class="form-group">
                    <label>用户名</label>
                    <input type="text" id="regUsername" required minlength="3">
                </div>
                <div class="form-group">
                    <label>显示名称</label>
                    <input type="text" id="regDisplayName">
                </div>
                <div class="form-group">
                    <label>邮箱<?= $requireEmail ? '' : ' (可选)' ?></label>
                    <input type="email" id="regEmail" <?= $requireEmail ? 'required' : '' ?>>
                    <?php if ($requireEmail): ?>
                        <small style="color:rgba(255,255,255,0.4);font-size:12px;">用于找回密码</small>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label>密码</label>
                    <input type="password" id="regPassword" required minlength="6">
                </div>
                <button type="submit" class="btn-auth">注 册</button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <script>
    document.querySelectorAll('.auth-tab').forEach(tab => {
        tab.addEventListener('click', () => {
            document.querySelectorAll('.auth-tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.auth-form').forEach(f => f.classList.remove('active'));
            tab.classList.add('active');
            document.getElementById(tab.dataset.tab + 'Form').classList.add('active');
            document.getElementById('authError').classList.remove('show');
        });
    });

    function showError(msg) {
        const el = document.getElementById('authError');
        el.textContent = msg;
        el.classList.add('show');
    }

    document.getElementById('loginForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        try {
            const res = await fetch('/api/auth.php?action=login', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    username: document.getElementById('loginUsername').value,
                    password: document.getElementById('loginPassword').value,
                }),
            });
            const data = await res.json();
            if (data.success) {
                const redirect = new URLSearchParams(window.location.search).get('redirect');
                window.location.href = redirect || '/';
            } else {
                showError(data.error || '登录失败');
            }
        } catch (err) {
            showError('网络错误');
        }
    });

    const regForm = document.getElementById('registerForm');
    if (regForm) {
        regForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            try {
                const res = await fetch('/api/auth.php?action=register', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        username: document.getElementById('regUsername').value,
                        password: document.getElementById('regPassword').value,
                        display_name: document.getElementById('regDisplayName').value,
                        email: document.getElementById('regEmail').value,
                    }),
                });
                const data = await res.json();
                if (data.success) {
                    window.location.href = '/';
                } else {
                    showError(data.error || '注册失败');
                }
            } catch (err) {
                showError('网络错误');
            }
        });
    }
    </script>
</body>
</html>
