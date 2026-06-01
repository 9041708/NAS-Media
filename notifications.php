<?php
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/Auth.php';
require_once __DIR__ . '/includes/session.php';
header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'send':
            if ($method !== 'POST') jsonResponse(['error' => '方法不允许'], 405);
            auth()->requireAdmin();

            $input = json_decode(file_get_contents('php://input'), true);
            $message = trim($input['message'] ?? '');
            $targetUserId = $input['target_user_id'] ?? null;
            $type = $input['type'] ?? 'info';

            if (!$message) jsonResponse(['error' => '消息内容不能为空'], 400);

            $data = [
                'from_user_id'  => $_SESSION['user_id'],
                'message'       => $message,
                'type'          => in_array($type, ['info', 'warning', 'success']) ? $type : 'info',
                'delivered'     => 0,
            ];

            if ($targetUserId && $targetUserId !== 'all') {
                $data['target_user_id'] = (int) $targetUserId;
            } else {
                $data['target_user_id'] = null;
            }

            db()->insert('notifications', $data);
            jsonResponse(['success' => true]);
            break;

        case 'poll':
            $userId = $_SESSION['user_id'] ?? 0;
            if (!$userId) jsonResponse(['error' => '未登录'], 401);

            $messages = db()->fetchAll(
                "SELECT id, message, type, created_at FROM notifications
                 WHERE delivered = 0
                 AND (target_user_id IS NULL OR target_user_id = ?)
                 AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
                 ORDER BY created_at DESC",
                [$userId]
            );

            if ($messages) {
                $ids = array_column($messages, 'id');
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                db()->query(
                    "UPDATE notifications SET delivered = 1 WHERE id IN ($placeholders)",
                    $ids
                );
            }

            jsonResponse($messages);
            break;

        case 'clear':
            $userId = $_SESSION['user_id'] ?? 0;
            if (!$userId) jsonResponse(['error' => '未登录'], 401);

            db()->query(
                "UPDATE notifications SET delivered = 1 WHERE target_user_id = ? OR target_user_id IS NULL",
                [$userId]
            );
            jsonResponse(['success' => true]);
            break;

        case 'users':
            auth()->requireAdmin();
            $users = db()->fetchAll('SELECT id, username, display_name FROM users ORDER BY username');
            jsonResponse($users);
            break;

        default:
            jsonResponse(['error' => '未知操作'], 400);
    }
} catch (Exception $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}
