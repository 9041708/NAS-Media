<?php
if (session_status() === PHP_SESSION_ACTIVE) {
    return;
}

session_name('nasmedia_sid');

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

if (PHP_VERSION_ID >= 70300) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
} else {
    session_set_cookie_params(0, '/', '', $secure, true);
    ini_set('session.cookie_samesite', 'Strict');
}

session_start();

if (empty($_SESSION['_fingerprint'])) {
    $_SESSION['_fingerprint'] = hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? '') . ($_SERVER['REMOTE_ADDR'] ?? ''));
} else {
    $currentFingerprint = hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? '') . ($_SERVER['REMOTE_ADDR'] ?? ''));
    if (!hash_equals($_SESSION['_fingerprint'], $currentFingerprint)) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        session_start();
        session_regenerate_id(true);
        $_SESSION['_fingerprint'] = $currentFingerprint;
    }
}

if (!isset($_SESSION['_regenerated_at'])) {
    $_SESSION['_regenerated_at'] = time();
}

if (time() - $_SESSION['_regenerated_at'] > 1800) {
    session_regenerate_id(true);
    $_SESSION['_regenerated_at'] = time();
}
