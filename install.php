<?php
$lockFile = __DIR__ . '/install.lock';
$configFile = __DIR__ . '/config.php';
if (file_exists($lockFile) && !isset($_GET['force'])) {
    header('Location: /index.php');
    exit;
}
if (!file_exists($lockFile) && file_exists($configFile) && !isset($_GET['force'])) {
    @file_put_contents($lockFile, date('Y-m-d H:i:s') . ' - auto-repaired');
    header('Location: /index.php');
    exit;
}

$step = (int)($_GET['step'] ?? 1);
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'test_db') {
        $host = $_POST['db_host'] ?? '127.0.0.1';
        $port = (int)($_POST['db_port'] ?? 3306);
        $user = $_POST['db_user'] ?? 'root';
        $pass = $_POST['db_pass'] ?? '';
        $name = $_POST['db_name'] ?? 'nas_media';

        try {
            $pdo = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);

            $pdo->exec("CREATE DATABASE IF NOT EXISTS `$name` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `$name`");

            $sqlFile = __DIR__ . '/database.sql';

            if (!file_exists($sqlFile)) {
                throw new Exception('缺少 database.sql 文件');
            }

            $pdo->exec(file_get_contents($sqlFile));

            $success = '数据库创建并初始化成功！';
            $step = 2;
        } catch (PDOException $e) {
            $error = '数据库连接失败: ' . $e->getMessage();
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }

    if ($action === 'save_config') {
        $host = $_POST['db_host'] ?? '127.0.0.1';
        $port = (int)($_POST['db_port'] ?? 3306);
        $user = $_POST['db_user'] ?? 'root';
        $pass = $_POST['db_pass'] ?? '';
        $name = $_POST['db_name'] ?? 'nas_media';
        $siteName = $_POST['site_name'] ?? 'NAS影库';
        $tmdbKey = $_POST['tmdb_key'] ?? '';
        $adminUsername = trim($_POST['admin_username'] ?? '');
        $adminPass = $_POST['admin_pass'] ?? '';

        $configContent = "<?php\nreturn [\n";
        $configContent .= "    'db' => [\n";
        $configContent .= "        'host'     => " . var_export($host, true) . ",\n";
        $configContent .= "        'port'     => $port,\n";
        $configContent .= "        'dbname'   => " . var_export($name, true) . ",\n";
        $configContent .= "        'username' => " . var_export($user, true) . ",\n";
        $configContent .= "        'password' => " . var_export($pass, true) . ",\n";
        $configContent .= "        'charset'  => 'utf8mb4',\n";
        $configContent .= "    ],\n";
        $configContent .= "    'app' => [\n";
        $configContent .= "        'name'        => " . var_export($siteName, true) . ",\n";
        $configContent .= "        'version'     => '2.0.0',\n";
        $configContent .= "        'debug'       => false,\n";
        $configContent .= "        'timezone'    => 'Asia/Shanghai',\n";
        $configContent .= "        'secret_key'  => " . var_export(bin2hex(random_bytes(32)), true) . ",\n";
        $configContent .= "        'cache_dir'   => __DIR__ . '/cache',\n";
        $configContent .= "        'poster_lang' => 'zh-CN',\n";
        $configContent .= "    ],\n";
        $configContent .= "    'tmdb' => [\n";
        $configContent .= "        'api_key'  => " . var_export($tmdbKey, true) . ",\n";
        $configContent .= "        'language' => 'zh-CN',\n";
        $configContent .= "        'base_url' => 'https://api.themoviedb.org/3',\n";
        $configContent .= "        'img_base' => 'https://image.tmdb.org/t/p/',\n";
        $configContent .= "    ],\n";
        $configContent .= "    'video' => [\n";
        $configContent .= "        'extensions' => ['mp4', 'mkv', 'avi', 'wmv', 'flv', 'mov', 'm4v', 'ts', 'rmvb', 'rm', 'mpg', 'mpeg', 'webm'],\n";
        $configContent .= "        'subtitle_extensions' => ['srt', 'ass', 'ssa', 'vtt', 'sub'],\n";
        $configContent .= "    ],\n";
        $configContent .= "    'scan' => [\n";
        $configContent .= "        'batch_size'      => 50,\n";
        $configContent .= "        'auto_metadata'   => true,\n";
        $configContent .= "        'skip_existing'   => true,\n";
        $configContent .= "    ],\n";
        $configContent .= "];\n";

        if (file_put_contents(__DIR__ . '/config.php', $configContent) === false) {
            $error = '无法写入 config.php，请检查目录权限';
        } else {
            try {
                require __DIR__ . '/config.php';

                $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
                    $host, $port, $name, 'utf8mb4');
                $pdo = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]);

                $adminUsername = $adminUsername ?: 'admin';
                $adminPass = $adminPass ?: 'admin123';
                $hashedPass = password_hash($adminPass, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (username, password, display_name, role) VALUES (?, ?, ?, 'admin')");
                $stmt->execute([$adminUsername, $hashedPass, $adminUsername]);

                $stmt = $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'site_name'");
                $stmt->execute([$siteName]);

                if ($tmdbKey) {
                    $stmt = $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'tmdb_api_key'");
                    $stmt->execute([$tmdbKey]);
                }

                require_once __DIR__ . '/includes/FFmpegFinder.php';
                $ffmpegPath = FFmpegFinder::find();
                $ffprobePath = FFmpegFinder::findProbe();
                if ($ffmpegPath) {
                    $stmt = $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'ffmpeg_path'");
                    $stmt->execute([$ffmpegPath]);
                }
                if ($ffprobePath) {
                    $stmt = $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'ffprobe_path'");
                    $stmt->execute([$ffprobePath]);
                }

                function getSettingFromDb($pdo, $key, $default = '') {
                    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
                    $stmt->execute([$key]);
                    $row = $stmt->fetch();
                    return $row ? $row['setting_value'] : $default;
                }

                $step = 3;
                $success = '安装完成！';

                @file_put_contents($lockFile, date('Y-m-d H:i:s') . ' - installed');
            } catch (Exception $e) {
                $error = '初始化失败: ' . $e->getMessage();
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
    <title>安装 - NAS影库</title>
    <link rel="icon" type="image/png" href="/live_icon_cut.png">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'PingFang SC', 'Microsoft YaHei', sans-serif;
            background: linear-gradient(135deg, #0f0f23 0%, #1a1a2e 50%, #16213e 100%);
            color: #e8e8e8;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        .install-card {
            background: rgba(255,255,255,0.05);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 16px;
            padding: 40px;
            width: 100%;
            max-width: 520px;
        }
        .install-card h1 { font-size: 28px; margin-bottom: 8px; color: #e50914; }
        .install-card .subtitle { color: rgba(255,255,255,0.5); margin-bottom: 32px; }
        .steps { display: flex; gap: 8px; margin-bottom: 32px; }
        .step { flex: 1; height: 4px; border-radius: 2px; background: rgba(255,255,255,0.1); }
        .step.active { background: #e50914; }
        .step.done { background: #10b981; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-size: 14px; color: rgba(255,255,255,0.7); }
        .form-group input { width: 100%; padding: 12px 16px; background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.15); border-radius: 8px; color: #fff; font-size: 15px; outline: none; }
        .form-group input:focus { border-color: #e50914; }
        .form-group small { display: block; margin-top: 6px; font-size: 12px; color: rgba(255,255,255,0.4); }
        .form-group small a { color: #e50914; }
        .btn { display: inline-flex; align-items: center; justify-content: center; padding: 12px 24px; border-radius: 8px; font-size: 15px; cursor: pointer; border: none; transition: all 0.2s; width: 100%; }
        .btn-primary { background: #e50914; color: #fff; }
        .btn-primary:hover { background: #f40612; }
        .btn-outline { background: transparent; border: 1px solid rgba(255,255,255,0.2); color: #e8e8e8; }
        .error { background: rgba(229,9,20,0.15); border: 1px solid rgba(229,9,20,0.3); color: #ff6b6b; padding: 10px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; }
        .success-msg { background: rgba(16,185,129,0.15); border: 1px solid rgba(16,185,129,0.3); color: #6ee7b7; padding: 20px; border-radius: 8px; text-align: center; margin-bottom: 20px; }
        .success-msg h3 { margin-bottom: 12px; font-size: 20px; }
        .info-list { text-align: left; margin: 16px 0; }
        .info-list li { padding: 6px 0; font-size: 14px; color: rgba(255,255,255,0.7); list-style: none; }
        .form-row { display: flex; gap: 12px; }
        .form-row .form-group { flex: 1; }
        .install-btns { display: flex; gap: 12px; }
        .install-btns .btn { flex: 1; }
    </style>
</head>
<body>
    <div class="install-card">
        <img src="/live_icon_cut.png" alt="NAS影库" height="48" style="margin-bottom:12px;">
        <p class="subtitle">安装向导 v2.0.0</p>

        <div class="steps">
            <div class="step <?= $step >= 1 ? ($step > 1 ? 'done' : 'active') : '' ?>"></div>
            <div class="step <?= $step >= 2 ? ($step > 2 ? 'done' : 'active') : '' ?>"></div>
            <div class="step <?= $step >= 3 ? 'done' : '' ?>"></div>
        </div>

        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($step === 1): ?>
            <h3 style="margin-bottom:20px;">步骤 1：数据库配置</h3>
            <form method="POST">
                <input type="hidden" name="action" value="test_db">
                <div class="form-row">
                    <div class="form-group">
                        <label>数据库主机</label>
                        <input type="text" name="db_host" value="127.0.0.1" required>
                    </div>
                    <div class="form-group">
                        <label>端口</label>
                        <input type="number" name="db_port" value="3306" required>
                    </div>
                </div>
                <div class="form-group">
                    <label>用户名</label>
                    <input type="text" name="db_user" value="root" required>
                    <small>群晖 MariaDB 默认用户名为 root</small>
                </div>
                <div class="form-group">
                    <label>密码</label>
                    <input type="password" name="db_pass" placeholder="MariaDB 的 root 密码">
                    <small>在 群晖 > MariaDB 10 > 设置 中查看或修改</small>
                </div>
                <div class="form-group">
                    <label>数据库名</label>
                    <input type="text" name="db_name" value="nas_media" required>
                    <small>如不存在会自动创建</small>
                </div>
                <button type="submit" class="btn btn-primary">测试连接并初始化数据库</button>
            </form>

        <?php elseif ($step === 2): ?>
            <?php if ($success): ?>
                <div class="error" style="background:rgba(16,185,129,0.15);border-color:rgba(16,185,129,0.3);color:#6ee7b7;">
                    <?= htmlspecialchars($success) ?>
                </div>
            <?php endif; ?>
            <h3 style="margin-bottom:20px;">步骤 2：站点配置</h3>
            <form method="POST">
                <input type="hidden" name="action" value="save_config">
                <input type="hidden" name="db_host" value="<?= htmlspecialchars($_POST['db_host'] ?? '127.0.0.1') ?>">
                <input type="hidden" name="db_port" value="<?= htmlspecialchars($_POST['db_port'] ?? '3306') ?>">
                <input type="hidden" name="db_user" value="<?= htmlspecialchars($_POST['db_user'] ?? 'root') ?>">
                <input type="hidden" name="db_pass" value="<?= htmlspecialchars($_POST['db_pass'] ?? '') ?>">
                <input type="hidden" name="db_name" value="<?= htmlspecialchars($_POST['db_name'] ?? 'nas_media') ?>">

                <div class="form-group">
                    <label>站点名称</label>
                    <input type="text" name="site_name" value="NAS影库">
                </div>
                <div class="form-group">
                    <label>TMDB API Key</label>
                    <input type="text" name="tmdb_key" placeholder="可选，用于自动获取海报和元数据">
                    <small>在 <a href="https://www.themoviedb.org/settings/api" target="_blank">themoviedb.org</a> 免费申请</small>
                </div>
                <div class="form-group">
                    <label>管理员用户名</label>
                    <input type="text" name="admin_username" placeholder="admin" minlength="3">
                </div>
                <div class="form-group">
                    <label>管理员密码</label>
                    <input type="password" name="admin_pass" placeholder="留空则默认 admin123" minlength="6">
                </div>
                <button type="submit" class="btn btn-primary">完成安装</button>
            </form>

        <?php elseif ($step === 3): ?>
            <div class="success-msg">
                <h3>安装成功</h3>
                <p>NAS影库 v2.0.0 已就绪</p>
                <ul class="info-list">
                    <li>管理员用户名：<strong><?= htmlspecialchars($adminUsername ?: 'admin') ?></strong></li>
                    <li>密码：您在上一步设置的密码（或默认 admin123）</li>
                    <li>进入管理后台添加媒体库目录后即可使用</li>
                    <?php
                    $ffPath = getSettingFromDb($pdo, 'ffmpeg_path', '');
                    if ($ffPath): ?>
                        <li style="color:#6ee7b7;">FFmpeg 已自动识别：<?= htmlspecialchars($ffPath) ?></li>
                    <?php else: ?>
                        <li style="color:#fbbf24;">FFmpeg 未检测到 — 可在管理后台设置中手动配置（不影响基本播放）</li>
                    <?php endif; ?>
                </ul>
            </div>
            <div class="install-btns">
                <a href="/index.php" class="btn btn-primary" style="text-decoration:none;">进入首页</a>
                <a href="/admin/index.php" class="btn btn-outline" style="text-decoration:none;">管理后台</a>
            </div>
            <div style="margin-top:24px;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.1);border-radius:8px;padding:16px;">
                <p style="font-size:14px;color:var(--text-secondary);margin-bottom:8px;">安全提示</p>
                <p style="font-size:13px;color:rgba(255,255,255,0.5);">安装向导已自动锁定。如需重新安装，请删除项目根目录下的 <code style="background:rgba(255,255,255,0.1);padding:2px 6px;border-radius:3px;">install.lock</code> 文件后再次访问此页面，或在 URL 后添加 <code style="background:rgba(255,255,255,0.1);padding:2px 6px;border-radius:3px;">?force=1</code></p>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
