<?php
require_once __DIR__ . '/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    $userId = $_SESSION['user_id'] ?? 0;
    if (!$userId) jsonResponse(['error' => '请先登录'], 401);

    switch ($action) {

        // 创建房间
        case 'create_room':
            if ($method !== 'POST') jsonResponse(['error' => '方法不允许'], 405);
            $input = json_decode(file_get_contents('php://input'), true);
            $fileId = (int)($input['file_id'] ?? 0);
            if (!$fileId) jsonResponse(['error' => '缺少file_id'], 400);

            $file = db()->fetchOne('SELECT * FROM media_files WHERE id = ?', [$fileId]);
            if (!$file) jsonResponse(['error' => '文件不存在'], 404);

            $perms = getUserGroupPermissions($userId);
            if (!$perms['watch_can_host']) jsonResponse(['error' => '你所在的用户组无权创建观影房间'], 403);

            $isVipOnly = $file['vip_only'] ?? 0;
            if ($isVipOnly && !$perms['can_see_all']) jsonResponse(['error' => '该内容仅限VIP观看，无法创建房间'], 403);

            db()->query('DELETE FROM watch_rooms WHERE host_user_id = ? AND updated_at < DATE_SUB(NOW(), INTERVAL 3 HOUR)', [$userId]);

            $code = generateRoomCode();
            $roomId = db()->insert('watch_rooms', [
                'code' => $code,
                'host_user_id' => $userId,
                'file_id' => $fileId,
                'current_time' => 0,
                'is_playing' => 0,
            ]);

            db()->insert('watch_room_members', [
                'room_id' => $roomId,
                'user_id' => $userId,
            ]);

            jsonResponse([
                'success' => true,
                'room_id' => $roomId,
                'code' => $code,
                'file_id' => $fileId,
            ]);
            break;

        // 加入房间
        case 'join_room':
            if ($method !== 'POST') jsonResponse(['error' => '方法不允许'], 405);
            $input = json_decode(file_get_contents('php://input'), true);
            $code = strtoupper(trim($input['code'] ?? ''));
            if (!$code) jsonResponse(['error' => '请输入分享码'], 400);

            $room = db()->fetchOne(
                'SELECT r.*, mf.vip_only, u.display_name, u.username, mf.file_name
                 FROM watch_rooms r
                 JOIN users u ON r.host_user_id = u.id
                 JOIN media_files mf ON r.file_id = mf.id
                 WHERE r.code = ? AND r.updated_at > DATE_SUB(NOW(), INTERVAL 3 HOUR)',
                [$code]
            );
            if (!$room) jsonResponse(['error' => '房间不存在或已过期'], 404);

            $perms = getUserGroupPermissions($userId);
            if (!$perms['watch_can_join']) jsonResponse(['error' => '你所在的用户组无权加入观影房间'], 403);

            $isVipOnly = $room['vip_only'] ?? 0;
            if ($isVipOnly && !$perms['can_see_all']) jsonResponse(['error' => '该内容仅限VIP观看，无法加入房间'], 403);

            // 检查房间人数上限（以房主所在组的 watch_max_guests 为准）
            $hostPerms = getUserGroupPermissions($room['host_user_id']);
            $maxGuests = (int)($hostPerms['watch_max_guests'] ?? 0);
            if ($maxGuests > 0) {
                $guestCount = db()->fetchColumn(
                    'SELECT COUNT(*) FROM watch_room_members WHERE room_id = ? AND user_id != ?',
                    [$room['id'], $room['host_user_id']]
                );
                if ($guestCount >= $maxGuests) jsonResponse(['error' => "房间已满（上限 {$maxGuests} 人）"], 403);
            }

            // 检查是否已在房间中
            $existing = db()->fetchOne(
                'SELECT id FROM watch_room_members WHERE room_id = ? AND user_id = ?',
                [$room['id'], $userId]
            );
            if (!$existing) {
                db()->insert('watch_room_members', [
                    'room_id' => $room['id'],
                    'user_id' => $userId,
                ]);
            }

            // 获取成员列表
            $members = db()->fetchAll(
                'SELECT u.id, u.username, u.display_name
                 FROM watch_room_members wrm
                 JOIN users u ON wrm.user_id = u.id
                 WHERE wrm.room_id = ?
                 ORDER BY wrm.joined_at',
                [$room['id']]
            );

            jsonResponse([
                'success' => true,
                'room' => [
                    'id' => $room['id'],
                    'code' => $room['code'],
                    'host_user_id' => $room['host_user_id'],
                    'host_name' => $room['display_name'] ?? $room['username'],
                    'file_id' => $room['file_id'],
                    'file_name' => $room['file_name'],
                    'current_time' => (float)$room['current_time'],
                    'is_playing' => (int)$room['is_playing'],
                ],
                'members' => $members,
            ]);
            break;

        // 退出房间
        case 'leave_room':
            if ($method !== 'POST') jsonResponse(['error' => '方法不允许'], 405);
            $input = json_decode(file_get_contents('php://input'), true);
            $roomId = (int)($input['room_id'] ?? 0);
            if (!$roomId) jsonResponse(['error' => '缺少room_id'], 400);

            db()->query('DELETE FROM watch_room_members WHERE room_id = ? AND user_id = ?', [$roomId, $userId]);

            // Check remaining members
            $count = db()->fetchColumn('SELECT COUNT(*) FROM watch_room_members WHERE room_id = ?', [$roomId]);
            if ($count == 0) {
                db()->query('DELETE FROM watch_rooms WHERE id = ?', [$roomId]);
            }

            jsonResponse(['success' => true]);
            break;

        // 房主同步播放状态
        case 'sync_state':
            if ($method !== 'POST') jsonResponse(['error' => '方法不允许'], 405);
            $input = json_decode(file_get_contents('php://input'), true);
            $roomId = (int)($input['room_id'] ?? 0);
            if (!$roomId) jsonResponse(['error' => '缺少room_id'], 400);

            $room = db()->fetchOne('SELECT * FROM watch_rooms WHERE id = ? AND host_user_id = ?', [$roomId, $userId]);
            if (!$room) jsonResponse(['error' => '只有房主可以同步'], 403);

            $data = ['updated_at' => date('Y-m-d H:i:s')];
            if (isset($input['current_time'])) $data['current_time'] = (float)$input['current_time'];
            if (isset($input['is_playing'])) $data['is_playing'] = (int)$input['is_playing'];

            db()->update('watch_rooms', $data, 'id = ?', [$roomId]);
            jsonResponse(['success' => true]);
            break;

        // 成员轮询获取状态
        case 'poll_state':
            $roomId = (int)($_GET['room_id'] ?? 0);
            if (!$roomId) jsonResponse(['error' => '缺少room_id'], 400);

            // Verify member
            $member = db()->fetchOne(
                'SELECT id FROM watch_room_members WHERE room_id = ? AND user_id = ?',
                [$roomId, $userId]
            );
            if (!$member) jsonResponse(['error' => '你不在该房间中'], 403);

            $room = db()->fetchOne(
                'SELECT current_time, is_playing, updated_at FROM watch_rooms WHERE id = ?',
                [$roomId]
            );
            if (!$room) jsonResponse(['error' => '房间已关闭'], 404);

            jsonResponse([
                'current_time' => (float)$room['current_time'],
                'is_playing' => (int)$room['is_playing'],
                'updated_at' => $room['updated_at'],
            ]);
            break;

        // 获取房间信息
        case 'room_info':
            $roomId = (int)($_GET['room_id'] ?? 0);
            if (!$roomId) jsonResponse(['error' => '缺少room_id'], 400);

            $room = db()->fetchOne(
                'SELECT r.*, u.display_name, u.username, mf.file_name
                 FROM watch_rooms r
                 JOIN users u ON r.host_user_id = u.id
                 JOIN media_files mf ON r.file_id = mf.id
                 WHERE r.id = ?',
                [$roomId]
            );
            if (!$room) jsonResponse(['error' => '房间不存在'], 404);

            $members = db()->fetchAll(
                'SELECT u.id, u.username, u.display_name
                 FROM watch_room_members wrm
                 JOIN users u ON wrm.user_id = u.id
                 WHERE wrm.room_id = ?
                 ORDER BY wrm.joined_at',
                [$roomId]
            );

            jsonResponse([
                'room' => [
                    'id' => $room['id'],
                    'code' => $room['code'],
                    'host_user_id' => $room['host_user_id'],
                    'host_name' => $room['display_name'] ?? $room['username'],
                    'file_id' => $room['file_id'],
                    'file_name' => $room['file_name'],
                    'current_time' => (float)$room['current_time'],
                    'is_playing' => (int)$room['is_playing'],
                ],
                'members' => $members,
            ]);
            break;

        default:
            jsonResponse(['error' => '未知操作'], 400);
    }
} catch (Exception $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}

function getUserGroupPermissions(int $userId): array
{
    $user = db()->fetchOne('SELECT group_id FROM users WHERE id = ?', [$userId]);
    $group = $user ? db()->fetchOne('SELECT permissions FROM user_groups WHERE id = ?', [$user['group_id']]) : null;
    $perms = $group ? json_decode($group['permissions'] ?? '{}', true) : [];
    return [
        'can_see_all'         => $perms['can_see_all'] ?? true,
        'episode_limit'       => (int)($perms['episode_limit'] ?? 0),
        'movie_minutes_limit' => (int)($perms['movie_minutes_limit'] ?? 0),
        'watch_can_host'      => $perms['watch_can_host'] ?? false,
        'watch_can_join'      => $perms['watch_can_join'] ?? false,
        'watch_max_guests'    => (int)($perms['watch_max_guests'] ?? 0),
    ];
}

function generateRoomCode(): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $db = db();
    for ($i = 0; $i < 10; $i++) {
        $code = '';
        for ($j = 0; $j < 4; $j++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $exists = $db->fetchColumn('SELECT COUNT(*) FROM watch_rooms WHERE code = ?', [$code]);
        if (!$exists) return $code;
    }
    return substr(bin2hex(random_bytes(2)), 0, 4);
}
