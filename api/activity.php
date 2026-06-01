<?php
require_once __DIR__ . '/bootstrap.php';

try {
    $action = $_GET['action'] ?? '';

    switch ($action) {
        case 'list':
            auth()->requireAdmin();

            $sessions = db()->fetchAll(
                "SELECT s.*, u.username, u.display_name
                 FROM active_sessions s
                 JOIN users u ON s.user_id = u.id
                 WHERE s.last_heartbeat > DATE_SUB(NOW(), INTERVAL 2 MINUTE)
                 ORDER BY s.last_heartbeat DESC"
            );
            jsonResponse($sessions);
            break;

        case 'heartbeat':
            $userId = $_SESSION['user_id'] ?? 0;
            if (!$userId) jsonResponse(['error' => '未登录'], 401);

            $input = json_decode(file_get_contents('php://input'), true);
            $mediaId = (int)($input['media_id'] ?? 0);
            $fileId = (int)($input['file_id'] ?? 0);
            $position = (int)($input['position'] ?? 0);
            $duration = (int)($input['duration'] ?? 0);
            $title = $input['title'] ?? '';
            $fileName = $input['file_name'] ?? '';
            $poster = $input['poster_path'] ?? '';

            if (!$mediaId || !$fileId) {
                db()->delete('active_sessions', 'user_id = ?', [$userId]);
                jsonResponse(['success' => true]);
                break;
            }

            $existing = db()->fetchOne('SELECT id FROM active_sessions WHERE user_id = ?', [$userId]);

            $data = [
                'media_id'     => $mediaId,
                'file_id'      => $fileId,
                'media_title'  => $title,
                'file_name'    => $fileName,
                'poster_path'  => $poster,
                'position'     => $position,
                'duration'     => $duration,
                'device'       => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '',
                'last_heartbeat' => date('Y-m-d H:i:s'),
            ];

            if ($existing) {
                db()->update('active_sessions', $data, 'user_id = ?', [$userId]);
            } else {
                $data['user_id'] = $userId;
                db()->insert('active_sessions', $data);
            }

            jsonResponse(['success' => true]);
            break;

        case 'stop':
            $userId = $_SESSION['user_id'] ?? 0;
            if ($userId) {
                db()->delete('active_sessions', 'user_id = ?', [$userId]);
            }
            jsonResponse(['success' => true]);
            break;

        case 'stats':
            auth()->requireAdmin();
            $total = db()->fetchColumn(
                "SELECT COUNT(*) FROM active_sessions WHERE last_heartbeat > DATE_SUB(NOW(), INTERVAL 2 MINUTE)"
            );
            jsonResponse(['active' => (int)$total]);
            break;

        default:
            jsonResponse(['error' => '未知操作'], 400);
    }
} catch (Exception $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}
