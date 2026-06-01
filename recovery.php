<?php
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/Auth.php';
require_once __DIR__ . '/includes/SmtpMailer.php';
require_once __DIR__ . '/includes/session.php';

$siteName = getSetting('site_name', 'NAS影库');
$action = $_GET['action'] ?? ($_POST['action'] ?? 'request');
$error = '';
$success = '';

if ($action === 'request') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $email = trim($_POST['email'] ?? '');

        if (!$email) {
            $error = '请输入邮箱地址';
        } else {
            $user = db()->fetchOne('SELECT * FROM users WHERE email = ?', [$email]);
            if ($user) {
                $token = bin2hex(random_bytes(32));
                db()->delete('password_resets', 'user_id = ?', [$user['id']]);
                db()->insert('password_resets', [
                    'user_id'    => $user['id'],
                    'token'      => $token,
                    'expires_at' => date('Y-m-d H:i:s', time() + 1800),
                ]);

                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $siteUrl = $protocol . '://' . $_SERVER['HTTP_HOST'];

                try {
                    SmtpMailer::sendPasswordReset($email, $user['username'], $token, $siteUrl);
                    $success = '重置链接已发送到您的邮箱，请在 30 分钟内查看';
                } catch (Exception $e) {
                    $error = '邮件发送失败: ' . $e->getMessage();
                }
            } else {
                $success = '如果该邮箱已注册，重置链接将会发送到您的邮箱';
            }
        }
    }
}

if ($action === 'reset') {
    $token = $_GET['token'] ?? '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['token'] ?? '';
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm'] ?? '';

        if (!$password || strlen($password) < 6) {
            $error = '密码至少 6 位';
        } elseif ($password !== $confirm) {
            $error = '两次密码不一致';
        } else {
            $reset = db()->fetchOne(
                'SELECT * FROM password_resets WHERE token = ? AND used = 0 AND expires_at > NOW()',
                [$token]
            );

            if ($reset) {
                db()->update('users', [
                    'password' => password_hash($password, PASSWORD_DEFAULT),
                ], 'id = ?', [$reset['user_id']]);
                db()->update('password_resets', ['used' => 1], 'id = ?', [$reset['id']]);
                $success = '密码已重置，请重新登录';
                $action = 'done';
            } else {
                $error = '链接无效或已过期';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>找回密码 - <?= e($siteName) ?></title>
    <link rel="icon" type="image/png" href="/live_icon_cut.png">
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        .recovery-page { display:flex; justify-content:center; align-items:center; min-height:100vh; background:var(--bg-primary); }
        .recovery-card { background:var(--bg-card); border:1px solid var(--border); border-radius:16px; padding:40px; width:100%; max-width:420px; }
        .recovery-card h2 { text-align:center; margin-bottom:24px; }
        .recovery-card .form-group { margin-bottom:18px; }
        .recovery-card .form-group label { display:block; margin-bottom:6px; color:var(--text-secondary); font-size:14px; }
        .recovery-card .form-group input { width:100%; padding:12px 16px; background:rgba(255,255,255,0.08); border:1px solid var(--border); border-radius:8px; color:var(--text-primary); font-size:15px; outline:none; }
        .recovery-card .form-group input:focus { border-color:#e50914; }
        .recovery-card .btn-recovery { width:100%; padding:14px; background:#e50914; color:#fff; border:none; border-radius:8px; font-size:16px; cursor:pointer; margin-top:8px; }
        .recovery-card .btn-recovery:hover { background:#f40612; }
        .recovery-card .error-msg { background:rgba(229,9,20,0.15); border:1px solid rgba(229,9,20,0.3); color:#ff6b6b; padding:10px 16px; border-radius:8px; margin-bottom:16px; font-size:14px; }
        .recovery-card .success-msg { background:rgba(16,185,129,0.15); border:1px solid rgba(16,185,129,0.3); color:#6ee7b7; padding:10px 16px; border-radius:8px; margin-bottom:16px; font-size:14px; }
        .recovery-card .links { text-align:center; margin-top:16px; font-size:13px; }
        .recovery-card .links a { color:#e50914; }
    </style>
</head>
<body class="<?= themeClass() ?>">
    <div class="recovery-page">
        <div class="recovery-card">
            <img src="/live_icon_cut.png" alt="" height="48" style="display:block;margin:0 auto 16px;">
            <h2><?= $action === 'request' ? '找回密码' : ($action === 'reset' ? '重置密码' : '完成') ?></h2>

            <?php if ($error): ?>
                <div class="error-msg"><?= e($error) ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="success-msg"><?= e($success) ?></div>
            <?php endif; ?>

            <?php if ($action === 'request'): ?>
                <form method="POST">
                    <input type="hidden" name="action" value="request">
                    <div class="form-group">
                        <label>注册邮箱</label>
                        <input type="email" name="email" required placeholder="请输入注册时的邮箱" autofocus>
                    </div>
                    <button type="submit" class="btn-recovery">发送重置链接</button>
                    <div class="links">
                        <a href="/login.php">返回登录</a>
                    </div>
                </form>

            <?php elseif ($action === 'reset'): ?>
                <form method="POST">
                    <input type="hidden" name="action" value="reset">
                    <input type="hidden" name="token" value="<?= e($token) ?>">
                    <div class="form-group">
                        <label>新密码</label>
                        <input type="password" name="password" required minlength="6" placeholder="至少 6 位">
                    </div>
                    <div class="form-group">
                        <label>确认新密码</label>
                        <input type="password" name="confirm" required minlength="6" placeholder="再次输入密码">
                    </div>
                    <button type="submit" class="btn-recovery">重置密码</button>
                </form>

            <?php elseif ($action === 'done'): ?>
                <div class="links">
                    <a href="/login.php">去登录</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
