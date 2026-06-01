<?php
error_reporting(0);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

ob_start();

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        ob_clean();
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'error' => 'PHP错误: ' . basename($error['file']) . ':' . $error['line'] . ' - ' . $error['message']
        ], JSON_UNESCAPED_UNICODE);
    }
});

set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$base = __DIR__ . '/../includes/';

$files = ['helpers.php', 'Database.php', 'TmdbApi.php', 'FFmpegFinder.php', 'FFmpeg.php', 'MediaScanner.php', 'Auth.php'];

foreach ($files as $file) {
    $path = $base . $file;
    if (file_exists($path)) {
        include_once $path;
    }
}

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/../includes/session.php';
}

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}
