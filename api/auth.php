<?php
require_once __DIR__ . '/bootstrap.php';

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($action) {
        case 'login':
            if ($method !== 'POST') jsonResponse(['error' => '方法不允许'], 405);
            $input = json_decode(file_get_contents('php://input'), true);
            $username = $input['username'] ?? '';
            $password = $input['password'] ?? '';

            if (!$username || !$password) jsonResponse(['error' => '请输入用户名和密码'], 400);

            $user = auth()->login($username, $password);
            if (!$user) jsonResponse(['error' => '用户名或密码错误'], 401);

            jsonResponse(['success' => true, 'user' => $user]);
            break;

        case 'register':
            if ($method !== 'POST') jsonResponse(['error' => '方法不允许'], 405);

            $allowRegister = getSetting('allow_register', '1');
            if (!$allowRegister) jsonResponse(['error' => '注册已关闭'], 403);

            $input = json_decode(file_get_contents('php://input'), true);
            $username = trim($input['username'] ?? '');
            $password = $input['password'] ?? '';
            $displayName = trim($input['display_name'] ?? '');
            $email = trim($input['email'] ?? '');

            if (!$username || !$password) jsonResponse(['error' => '用户名和密码不能为空'], 400);
            if (strlen($username) < 3) jsonResponse(['error' => '用户名至少3个字符'], 400);
            if (strlen($password) < 6) jsonResponse(['error' => '密码至少6位'], 400);

            $userId = auth()->register($username, $password, $displayName);
            if ($email) {
                db()->update('users', ['email' => $email], 'id = ?', [$userId]);
            }
            $defaultGroup = (int) getSetting('default_user_group', '1');
            $groupInput = (int)($input['group_id'] ?? 0);
            db()->update('users', ['group_id' => $groupInput > 0 ? $groupInput : $defaultGroup], 'id = ?', [$userId]);

            $user = auth()->login($username, $password);
            jsonResponse(['success' => true, 'user' => $user]);
            break;

        case 'logout':
            auth()->logout();
            jsonResponse(['success' => true]);
            break;

        case 'me':
            $user = auth()->getUser();
            if (!$user) jsonResponse(['error' => '未登录'], 401);
            $full = db()->fetchOne('SELECT * FROM users WHERE id = ?', [$user['id']]);
            unset($full['password']);
            jsonResponse($full);
            break;

        case 'change_password':
            if ($method !== 'POST') jsonResponse(['error' => '方法不允许'], 405);
            $userId = $_SESSION['user_id'] ?? 0;
            if (!$userId) jsonResponse(['error' => '请先登录'], 401);

            $input = json_decode(file_get_contents('php://input'), true);
            $oldPwd = $input['old_password'] ?? '';
            $newPwd = $input['new_password'] ?? '';

            if (!$oldPwd || !$newPwd) jsonResponse(['error' => '请输入旧密码和新密码'], 400);
            if (strlen($newPwd) < 6) jsonResponse(['error' => '新密码至少6位'], 400);

            $success = auth()->changePassword($userId, $oldPwd, $newPwd);
            if (!$success) jsonResponse(['error' => '旧密码错误'], 400);

            jsonResponse(['success' => true]);
            break;

        case 'update_pref':
            if ($method !== 'POST') jsonResponse(['error' => '方法不允许'], 405);
            $userId = $_SESSION['user_id'] ?? 0;
            if (!$userId) jsonResponse(['error' => '请先登录'], 401);

            $input = json_decode(file_get_contents('php://input'), true);
            $key = $input['key'] ?? '';
            $value = $input['value'] ?? '';

            $allowed = ['language', 'subtitle_pref', 'audio_pref', 'quality_pref', 'speed_pref'];
            if (!in_array($key, $allowed)) jsonResponse(['error' => '不允许的设置项'], 400);

            db()->update('users', [$key => $value], 'id = ?', [$userId]);
            $_SESSION['user'][$key] = $value;
            jsonResponse(['success' => true]);
            break;

        case 'list_users':
            auth()->requireAdmin();
            $users = db()->fetchAll(
                'SELECT id, username, display_name, email, role, group_id, last_login, created_at FROM users ORDER BY id'
            );
            jsonResponse($users);
            break;

        case 'create_user':
            if ($method !== 'POST') jsonResponse(['error' => '方法不允许'], 405);
            auth()->requireAdmin();

            $input = json_decode(file_get_contents('php://input'), true);
            $username = trim($input['username'] ?? '');
            $password = $input['password'] ?? '';
            $role = $input['role'] ?? 'user';
            $displayName = trim($input['display_name'] ?? '');

            if (!$username || !$password) jsonResponse(['error' => '用户名和密码不能为空'], 400);

            $userId = auth()->register($username, $password, $displayName);
            db()->update('users', ['role' => $role], 'id = ?', [$userId]);
            jsonResponse(['success' => true, 'id' => $userId]);
            break;

        case 'update_user':
            if ($method !== 'POST') jsonResponse(['error' => '方法不允许'], 405);
            auth()->requireAdmin();

            $input = json_decode(file_get_contents('php://input'), true);
            $userId = (int)($input['id'] ?? 0);
            if (!$userId) jsonResponse(['error' => '缺少用户ID'], 400);

            $update = [];
            if (isset($input['display_name'])) $update['display_name'] = $input['display_name'];
            if (isset($input['email'])) $update['email'] = $input['email'];
            if (isset($input['role'])) $update['role'] = $input['role'];
            if (isset($input['group_id'])) $update['group_id'] = (int)$input['group_id'];
            if (!empty($input['password'])) $update['password'] = password_hash($input['password'], PASSWORD_DEFAULT);

            if ($update) {
                db()->update('users', $update, 'id = ?', [$userId]);
            }
            jsonResponse(['success' => true]);
            break;

        case 'delete_user':
            if ($method !== 'POST') jsonResponse(['error' => '方法不允许'], 405);
            auth()->requireAdmin();

            $input = json_decode(file_get_contents('php://input'), true);
            $userId = (int)($input['id'] ?? 0);
            if (!$userId) jsonResponse(['error' => '缺少用户ID'], 400);
            if ($userId == ($_SESSION['user_id'] ?? 0)) jsonResponse(['error' => '不能删除自己'], 400);

            db()->delete('users', 'id = ?', [$userId]);
            db()->delete('play_history', 'user_id = ?', [$userId]);
            db()->delete('favorites', 'user_id = ?', [$userId]);
            jsonResponse(['success' => true]);
            break;

        case 'list_groups':
            auth()->requireAdmin();
            $groups = db()->fetchAll('SELECT * FROM user_groups ORDER BY id');
            jsonResponse($groups);
            break;

        case 'create_group':
            if ($method !== 'POST') jsonResponse(['error' => '方法不允许'], 405);
            auth()->requireAdmin();
            $input = json_decode(file_get_contents('php://input'), true);
            $name = trim($input['name'] ?? '');
            if (!$name) jsonResponse(['error' => '组名不能为空'], 400);
            if ($input['is_default'] ?? false) {
                db()->query('UPDATE user_groups SET is_default = 0');
            }
            $id = db()->insert('user_groups', [
                'name'        => $name,
                'permissions' => $input['permissions'] ?? '{}',
                'is_default'  => ($input['is_default'] ?? false) ? 1 : 0,
            ]);
            jsonResponse(['success' => true, 'id' => $id]);
            break;

        case 'update_group':
            if ($method !== 'POST') jsonResponse(['error' => '方法不允许'], 405);
            auth()->requireAdmin();
            $input = json_decode(file_get_contents('php://input'), true);
            $id = (int)($input['id'] ?? 0);
            if (!$id) jsonResponse(['error' => '缺少组ID'], 400);
            if ($input['is_default'] ?? false) {
                db()->query('UPDATE user_groups SET is_default = 0 WHERE id != ?', [$id]);
            }
            db()->update('user_groups', [
                'name'        => trim($input['name'] ?? ''),
                'permissions' => $input['permissions'] ?? '{}',
                'is_default'  => ($input['is_default'] ?? false) ? 1 : 0,
            ], 'id = ?', [$id]);
            jsonResponse(['success' => true]);
            break;

        case 'delete_group':
            if ($method !== 'POST') jsonResponse(['error' => '方法不允许'], 405);
            auth()->requireAdmin();
            $input = json_decode(file_get_contents('php://input'), true);
            $id = (int)($input['id'] ?? 0);
            if (!$id || $id <= 1) jsonResponse(['error' => '无法删除默认组'], 400);
            db()->update('users', ['group_id' => 1], 'group_id = ?', [$id]);
            db()->delete('user_groups', 'id = ?', [$id]);
            jsonResponse(['success' => true]);
            break;

        default:
            jsonResponse(['error' => '未知操作'], 400);
    }
} catch (Exception $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}
