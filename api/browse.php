<?php
require_once __DIR__ . '/bootstrap.php';

auth()->requireAdmin();

$path = $_GET['path'] ?? '';

if (empty($path) || $path === '/') {
    $commonPaths = [
        '/volume1', '/volume2', '/volume3', '/volume4',
        '/volumeUSB1', '/volumeSATA', '/home',
    ];
    $items = [];
    foreach ($commonPaths as $p) {
        if (is_dir($p)) {
            $items[] = ['name' => basename($p), 'path' => $p, 'type' => 'directory'];
        }
    }
    if (empty($items)) {
        foreach (['/var', '/tmp', '/opt'] as $p) {
            if (is_dir($p)) {
                $items[] = ['name' => basename($p), 'path' => $p, 'type' => 'directory'];
            }
        }
    }
    jsonResponse(['current' => '/', 'parent' => null, 'items' => $items]);
}

$realPath = realpath($path);
if ($realPath === false) {
    $realPath = rtrim($path, '/');
}

if (!is_dir($realPath)) {
    jsonResponse(['error' => '目录不存在: ' . $realPath], 400);
    exit;
}

$realPath = rtrim($realPath, '/');

$items = [];
$errors = [];

$dh = @opendir($realPath . '/');
if ($dh === false) {
    jsonResponse(['error' => '无法打开目录（权限不足？）: ' . $realPath, 'items' => []]);
    exit;
}

while (($entry = readdir($dh)) !== false) {
    if ($entry === '.' || $entry === '..') continue;
    $fullPath = $realPath . '/' . $entry;
    if (@is_dir($fullPath)) {
        $items[] = [
            'name' => $entry,
            'path' => $fullPath,
            'type' => 'directory',
        ];
    }
}
closedir($dh);

usort($items, function($a, $b) {
    return strcasecmp($a['name'], $b['name']);
});

$parent = dirname($realPath);
if ($parent === $realPath) {
    $parent = '/';
}

jsonResponse([
    'current' => $realPath,
    'parent'  => $parent,
    'items'   => $items,
    'errors'  => $errors,
]);
