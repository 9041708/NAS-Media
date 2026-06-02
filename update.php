<?php
$lockFile = __DIR__ . '/install.lock';
$configFile = __DIR__ . '/config.php';
if (!file_exists($lockFile) && !file_exists($configFile)) {
    die('系统尚未安装，请先访问 <a href="/install.php">安装向导</a>');
}
if (!file_exists($lockFile) && file_exists($configFile)) {
    @file_put_contents($lockFile, date('Y-m-d H:i:s') . ' - auto-repaired');
}

require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/Auth.php';
require_once __DIR__ . '/includes/session.php';

auth()->requireAdmin();

$migrationsDir = __DIR__ . '/migrations';
$currentVersion = (int)getSetting('db_version', '0');
$migrations = [];

if (is_dir($migrationsDir)) {
    $files = glob($migrationsDir . '/*.sql');
    foreach ($files as $file) {
        $basename = basename($file);
        if (preg_match('/^(\d+)_/', $basename, $m) && (int)$m[1] > $currentVersion) {
            $migrations[(int)$m[1]] = $file;
        }
    }
    ksort($migrations);
}

$step = isset($_GET['run']) ? 'run' : 'list';
$results = [];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run'])) {
    $pendingVersions = array_keys($migrations);
    $maxVersion = $currentVersion;

    foreach ($migrations as $version => $file) {
        try {
            $sql = file_get_contents($file);
            $queries = array_filter(array_map('trim', explode(";\n", $sql)));
            foreach ($queries as $query) {
                if (empty($query) || str_starts_with(trim($query), '--')) continue;
                db()->query($query);
            }
            $maxVersion = max($maxVersion, $version);
            $results[] = ['version' => $version, 'file' => basename($file), 'status' => 'ok'];
        } catch (Exception $e) {
            $results[] = ['version' => $version, 'file' => basename($file), 'status' => 'error', 'message' => $e->getMessage()];
            $error = '迁移 v' . $version . ' 失败: ' . $e->getMessage();
            break;
        }
    }

    if (empty($error) && $maxVersion > $currentVersion) {
        setSetting('db_version', (string)$maxVersion);
    }
}

$siteName = getSetting('site_name', 'NAS影库');
$user = auth()->getUser();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>数据库更新 - <?= e($siteName) ?></title>
    <link rel="icon" type="image/png" href="/live_icon_cut.png">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:-apple-system,BlinkMacSystemFont,'Segoe UI','PingFang SC','Microsoft YaHei',sans-serif; background:linear-gradient(135deg,#0f0f23,#1a1a2e,#16213e); color:#e8e8e8; min-height:100vh; display:flex; justify-content:center; align-items:center; padding:20px; }
        .card { background:rgba(255,255,255,0.05); backdrop-filter:blur(20px); border:1px solid rgba(255,255,255,0.1); border-radius:16px; padding:40px; width:100%; max-width:640px; }
        .card h1 { font-size:24px; margin-bottom:8px; }
        .card .sub { color:rgba(255,255,255,0.5); margin-bottom:24px; font-size:14px; }
        .info-row { display:flex; justify-content:space-between; padding:10px 0; border-bottom:1px solid rgba(255,255,255,0.06); font-size:14px; }
        .info-row .label { color:rgba(255,255,255,0.5); }
        .migration-item { padding:12px 16px; border-radius:8px; margin:4px 0; font-size:14px; display:flex; justify-content:space-between; align-items:center; }
        .migration-item.pending { background:rgba(245,158,11,0.1); border:1px solid rgba(245,158,11,0.2); }
        .migration-item.done { background:rgba(16,185,129,0.1); border:1px solid rgba(16,185,129,0.2); }
        .migration-item.error { background:rgba(229,9,20,0.1); border:1px solid rgba(229,9,20,0.2); }
        .migration-item .ver { font-family:monospace; font-size:13px; }
        .migration-item .status { font-size:12px; }
        .migration-item.pending .status { color:#f59e0b; }
        .migration-item.done .status { color:#10b981; }
        .migration-item.error .status { color:#ff6b6b; }
        .btn { display:inline-flex; align-items:center; justify-content:center; padding:12px 24px; border-radius:8px; font-size:15px; cursor:pointer; border:none; text-decoration:none; transition:all 0.2s; }
        .btn-primary { background:#e50914; color:#fff; }
        .btn-primary:hover { background:#f40612; }
        .btn-outline { background:transparent; border:1px solid rgba(255,255,255,0.2); color:#e8e8e8; }
        .btn-row { display:flex; gap:12px; margin-top:24px; }
        .error-msg { background:rgba(229,9,20,0.15); border:1px solid rgba(229,9,20,0.3); color:#ff6b6b; padding:12px 16px; border-radius:8px; margin-top:16px; font-size:14px; }
        .up-to-date { text-align:center; padding:24px; color:rgba(16,185,129,0.8); font-size:15px; }
        code { background:rgba(255,255,255,0.08); padding:2px 6px; border-radius:3px; font-size:12px; }
    </style>
</head>
<body>
<div class="card">
    <img src="/live_icon_cut.png" alt="" height="40" style="margin-bottom:16px;">
    <h1>数据库更新</h1>
    <p class="sub">当前版本: v<?= $currentVersion ?> | 最新: v<?= empty($migrations) ? $currentVersion : max(array_keys($migrations)) ?></p>

    <?php if ($error): ?>
        <div class="error-msg"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if (empty($migrations) && empty($results)): ?>
        <div class="up-to-date">数据库已是最新版本，无需更新</div>
    <?php elseif (!empty($results)): ?>
        <?php foreach ($results as $r): ?>
            <div class="migration-item <?= $r['status'] === 'ok' ? 'done' : 'error' ?>">
                <span class="ver">v<?= $r['version'] ?> <?= e($r['file']) ?></span>
                <span class="status"><?= $r['status'] === 'ok' ? '已执行' : '失败' ?></span>
            </div>
        <?php endforeach; ?>
        <div class="btn-row">
            <a href="/admin/index.php" class="btn btn-primary">进入管理后台</a>
            <a href="/index.php" class="btn btn-outline">返回首页</a>
        </div>
    <?php else: ?>
        <p style="font-size:14px;color:rgba(255,255,255,0.5);margin-bottom:16px;">以下数据库迁移脚本待执行：</p>
        <?php foreach ($migrations as $version => $file): ?>
            <div class="migration-item pending">
                <span class="ver">v<?= $version ?> — <?= e(basename($file)) ?></span>
                <span class="status">待执行</span>
            </div>
        <?php endforeach; ?>

        <form method="POST" style="margin-top:24px;">
            <input type="hidden" name="run" value="1">
            <div class="btn-row">
                <button type="submit" class="btn btn-primary">执行更新</button>
                <a href="/admin/index.php" class="btn btn-outline">返回后台</a>
            </div>
        </form>
        <p style="font-size:12px;color:rgba(255,255,255,0.3);margin-top:12px;">更新操作只执行数据库迁移，不会覆盖现有数据。建议更新前备份数据库。</p>
    <?php endif; ?>
</div>
</body>
</html>
