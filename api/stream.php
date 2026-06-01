<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/session.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    http_response_code(400);
    die('缺少文件ID');
}

$file = db()->fetchOne('SELECT * FROM media_files WHERE id = ?', [$id]);
if (!$file) {
    http_response_code(404);
    die('文件不存在');
}

$filePath = $file['file_path'];
if (!file_exists($filePath)) {
    http_response_code(404);
    die('文件已丢失');
}

$fileSize = filesize($filePath);
$mimeType = getVideoMimeType($file['file_type']);

$range = $_SERVER['HTTP_RANGE'] ?? '';

if ($range) {
    preg_match('/bytes=(\d+)-(\d*)/', $range, $matches);
    $start = (int)$matches[1];
    $end = $matches[2] !== '' ? (int)$matches[2] : $fileSize - 1;
    $length = $end - $start + 1;

    http_response_code(206);
    header("Content-Type: $mimeType");
    header("Content-Range: bytes $start-$end/$fileSize");
    header("Content-Length: $length");
    header("Accept-Ranges: bytes");
    header("Cache-Control: no-cache");

    $fp = fopen($filePath, 'rb');
    fseek($fp, $start);
    $bufferSize = 8192;
    $remaining = $length;

    while ($remaining > 0 && !feof($fp)) {
        $readSize = min($bufferSize, $remaining);
        echo fread($fp, $readSize);
        $remaining -= $readSize;
        flush();
    }
    fclose($fp);
} else {
    header("Content-Type: $mimeType");
    header("Content-Length: $fileSize");
    header("Accept-Ranges: bytes");
    header("Cache-Control: no-cache");

    readfile($filePath);
}
