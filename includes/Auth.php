<?php
class Auth
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function login(string $username, string $password): ?array
    {
        $user = $this->db->fetchOne('SELECT * FROM users WHERE username = ?', [$username]);
        if (!$user || !password_verify($password, $user['password'])) {
            return null;
        }

        $this->db->update('users', ['last_login' => date('Y-m-d H:i:s')], 'id = ?', [$user['id']]);
        unset($user['password']);

        $_SESSION['user'] = $user;
        $_SESSION['user_id'] = $user['id'];

        session_regenerate_id(true);
        $_SESSION['_regenerated_at'] = time();

        return $user;
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    public function isLoggedIn(): bool
    {
        return !empty($_SESSION['user_id']);
    }

    public function getUser(): ?array
    {
        if (!$this->isLoggedIn()) return null;
        return $_SESSION['user'] ?? null;
    }

    public function isAdmin(): bool
    {
        $user = $this->getUser();
        return $user && $user['role'] === 'admin';
    }

    public function requireLogin(): void
    {
        if (!$this->isLoggedIn()) {
            header('Location: /login.php');
            exit;
        }
    }

    public function requireAdmin(): void
    {
        $this->requireLogin();
        if (!$this->isAdmin()) {
            http_response_code(403);
            die('权限不足');
        }
    }

    public function register(string $username, string $password, string $displayName = ''): int
    {
        $existing = $this->db->fetchOne('SELECT id FROM users WHERE username = ?', [$username]);
        if ($existing) {
            throw new Exception('用户名已存在');
        }

        return $this->db->insert('users', [
            'username'     => $username,
            'password'     => password_hash($password, PASSWORD_DEFAULT),
            'display_name' => $displayName ?: $username,
            'role'         => 'user',
        ]);
    }

    public function changePassword(int $userId, string $oldPassword, string $newPassword): bool
    {
        $user = $this->db->fetchOne('SELECT password FROM users WHERE id = ?', [$userId]);
        if (!$user || !password_verify($oldPassword, $user['password'])) {
            return false;
        }

        $this->db->update('users', [
            'password' => password_hash($newPassword, PASSWORD_DEFAULT),
        ], 'id = ?', [$userId]);

        return true;
    }

    public function generateInstallPassword(): string
    {
        $password = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 12);
        $this->db->update('users', [
            'password' => password_hash($password, PASSWORD_DEFAULT),
        ], 'username = ?', ['admin']);
        return $password;
    }
}
