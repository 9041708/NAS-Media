<?php
require_once __DIR__ . '/bootstrap.php';

$size = $_GET['size'] ?? 'w500';
$path = $_GET['path'] ?? '';

if (empty($path)) {
    http_response_code(400);
    header('Content-Type: image/svg+xml');
    readfile(__DIR__ . '/../assets/images/no-poster.svg');
    exit;
}

$path = preg_replace('/[^a-zA-Z0-9\/\-_\.]/', '', $path);
$url = 'https://image.tmdb.org/t/p/' . $size . '/' . ltrim($path, '/');

$cacheDir = __DIR__ . '/../cache/posters/' . $size;
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}

$cacheKey = md5($path);
$cacheFile = $cacheDir . '/' . $cacheKey . '.cache';
$cacheMeta = $cacheDir . '/' . $cacheKey . '.meta';

$cached = false;
if (file_exists($cacheFile) && file_exists($cacheMeta)) {
    $meta = json_decode(file_get_contents($cacheMeta), true);
    if ($meta && (time() - ($meta['ts'] ?? 0)) < 86400 * 7) {
        $cached = true;
    }
}

if ($cached) {
    $ext = pathinfo($path, PATHINFO_EXTENSION) ?: 'jpg';
    $mimes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
    $mime = $mimes[strtolower($ext)] ?? 'image/jpeg';
    header('Content-Type: ' . $mime);
    header('Cache-Control: public, max-age=604800');
    header('X-Cache: HIT');
    readfile($cacheFile);
    exit;
}

$context = stream_context_create([
    'http' => [
        'timeout' => 10,
        'user_agent' => 'NASMovie/3.1',
    ],
    'ssl' => [
        'verify_peer' => false,
        'verify_peer_name' => false,
    ],
]);

$data = @file_get_contents($url, false, $context);

if ($data === false || strlen($data) < 100) {
    http_response_code(404);
    header('Content-Type: image/svg+xml');
    readfile(__DIR__ . '/../assets/images/no-poster.svg');
    exit;
}

$ext = pathinfo($path, PATHINFO_EXTENSION) ?: 'jpg';
$mimes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
$mime = $mimes[strtolower($ext)] ?? 'image/jpeg';

file_put_contents($cacheFile, $data);
file_put_contents($cacheMeta, json_encode(['ts' => time(), 'size' => strlen($data), 'url' => $url]));

header('Content-Type: ' . $mime);
header('Cache-Control: public, max-age=604800');
header('Content-Length: ' . strlen($data));
header('X-Cache: MISS');
echo $data;
