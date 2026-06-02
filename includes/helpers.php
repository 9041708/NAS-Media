<?php
function config(?string $key = null, $default = null)
{
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/../config.php';
    }
    if ($key === null) return $config;
    return data_get($config, $key, $default);
}

function data_get($array, $key, $default = null)
{
    if (is_null($key)) return $array;
    foreach (explode('.', $key) as $segment) {
        if (is_array($array) && array_key_exists($segment, $array)) {
            $array = $array[$segment];
        } else {
            return $default;
        }
    }
    return $array;
}

function db(): Database
{
    return Database::getInstance();
}

function auth(): Auth
{
    static $auth = null;
    if ($auth === null) {
        $auth = new Auth();
    }
    return $auth;
}

function tmdb(): TmdbApi
{
    static $tmdb = null;
    if ($tmdb === null) {
        $tmdb = new TmdbApi();
    }
    return $tmdb;
}

function scanner(): MediaScanner
{
    static $scanner = null;
    if ($scanner === null) {
        $scanner = new MediaScanner();
    }
    return $scanner;
}

function ffmpeg(): FFmpeg
{
    static $ffmpeg = null;
    if ($ffmpeg === null) {
        $ffmpeg = new FFmpeg();
    }
    return $ffmpeg;
}

function e(string $str): string
{
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

function formatSize(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 2) . ' ' . $units[$i];
}

function formatDuration(int $seconds): string
{
    $h = floor($seconds / 3600);
    $m = floor(($seconds % 3600) / 60);
    $s = $seconds % 60;
    if ($h > 0) {
        return sprintf('%d:%02d:%02d', $h, $m, $s);
    }
    return sprintf('%02d:%02d', $m, $s);
}

function jsonResponse(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function getSetting(string $key, $default = '')
{
    $result = db()->fetchOne('SELECT setting_value FROM settings WHERE setting_key = ?', [$key]);
    return $result ? $result['setting_value'] : $default;
}

function setSetting(string $key, $value): void
{
    $existing = db()->fetchOne('SELECT id FROM settings WHERE setting_key = ?', [$key]);
    if ($existing) {
        db()->update('settings', ['setting_value' => $value], 'setting_key = ?', [$key]);
    } else {
        db()->insert('settings', ['setting_key' => $key, 'setting_value' => $value]);
    }
}

function getPosterUrl(?string $path, string $size = 'w500'): string
{
    if (empty($path)) {
        return '/assets/images/no-poster.svg';
    }
    return '/api/image.php?size=' . $size . '&path=' . urlencode($path);
}

function getBackdropUrl(?string $path): string
{
    if (empty($path)) {
        return '/assets/images/no-backdrop.svg';
    }
    return '/api/image.php?size=original&path=' . urlencode($path);
}

function getVideoMimeType(string $ext): string
{
    $map = [
        'mp4'  => 'video/mp4',
        'mkv'  => 'video/x-matroska',
        'avi'  => 'video/x-msvideo',
        'wmv'  => 'video/x-ms-wmv',
        'flv'  => 'video/x-flv',
        'mov'  => 'video/quicktime',
        'm4v'  => 'video/mp4',
        'ts'   => 'video/mp2t',
        'webm' => 'video/webm',
        'mpg'  => 'video/mpeg',
        'mpeg' => 'video/mpeg',
    ];
    return $map[strtolower($ext)] ?? 'video/mp4';
}

function generateCsrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken(string $token): bool
{
    return hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

function themeClass(): string
{
    $theme = getSetting('theme', 'dark');
    return $theme === 'light' ? 'light-theme' : 'dark-theme';
}
