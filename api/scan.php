<?php
require_once __DIR__ . '/bootstrap.php';

if (!auth()->isAdmin()) {
    jsonResponse(['error' => '需要管理员权限'], 403);
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'start':
            $libraryId = (int)($_GET['library_id'] ?? $_POST['library_id'] ?? 0);
            if (!$libraryId) {
                $input = json_decode(file_get_contents('php://input'), true);
                $libraryId = (int)($input['library_id'] ?? 0);
            }
            if (!$libraryId) jsonResponse(['error' => '缺少library_id'], 400);

            try {
                $result = scanner()->scanLibrary($libraryId);
                jsonResponse(['success' => true, 'result' => $result]);
            } catch (Exception $e) {
                jsonResponse(['error' => $e->getMessage()], 500);
            }
            break;

        case 'scan_progress':
            $libraryId = (int)($_GET['library_id'] ?? 0);
            $progress = MediaScanner::getProgress($libraryId);
            jsonResponse($progress ?: ['status' => 'idle', 'current' => 0, 'total' => 0, 'percent' => 0]);
            break;

        case 'refresh_meta':
            if ($method !== 'POST') jsonResponse(['error' => '方法不允许'], 405);
            $input = json_decode(file_get_contents('php://input'), true);
            $mediaId = (int)($input['media_id'] ?? 0);
            if (!$mediaId) jsonResponse(['error' => '缺少media_id'], 400);

            $success = scanner()->refreshMetadata($mediaId);
            jsonResponse(['success' => $success]);
            break;

        case 'add_library':
            if ($method !== 'POST') jsonResponse(['error' => '方法不允许'], 405);
            $input = json_decode(file_get_contents('php://input'), true);
            $name = trim($input['name'] ?? '');
            $path = trim($input['path'] ?? '');
            $type = $input['type'] ?? 'movie';

            if (!$name || !$path) jsonResponse(['error' => '名称和路径不能为空'], 400);

            $realPath = realpath($path);
            if ($realPath === false) $realPath = $path;

            if (!is_dir($realPath)) jsonResponse(['error' => '目录不存在: ' . $realPath], 400);

            $maxOrder = db()->fetchColumn('SELECT COALESCE(MAX(COALESCE(sort_order,0)), 0) FROM libraries');
            $id = db()->insert('libraries', [
                'name' => $name,
                'path' => $realPath,
                'type' => $type,
                'sort_order' => ($maxOrder + 1),
            ]);

            jsonResponse(['success' => true, 'id' => $id]);
            break;

        case 'delete_library':
            if ($method !== 'POST') jsonResponse(['error' => '方法不允许'], 405);
            $input = json_decode(file_get_contents('php://input'), true);
            $id = (int)($input['id'] ?? 0);
            if (!$id) jsonResponse(['error' => '缺少ID'], 400);

            db()->delete('libraries', 'id = ?', [$id]);
            db()->delete('media_files', 'library_id = ?', [$id]);
            db()->query(
                "DELETE mi FROM media_items mi
                 LEFT JOIN media_files mf ON mf.media_id = mi.id
                 WHERE mf.id IS NULL"
            );
            jsonResponse(['success' => true]);
            break;

        case 'list_libraries':
            $libraries = db()->fetchAll('SELECT * FROM libraries ORDER BY COALESCE(sort_order, 0) ASC, name ASC');
            foreach ($libraries as &$lib) {
                $lib['file_count'] = db()->fetchColumn(
                    'SELECT COUNT(*) FROM media_files WHERE library_id = ?',
                    [$lib['id']]
                );
            }
            jsonResponse($libraries);
            break;

        case 'reorder_libraries':
            if ($method !== 'POST') jsonResponse(['error' => '方法不允许'], 405);
            auth()->requireAdmin();
            $input = json_decode(file_get_contents('php://input'), true);
            $orders = $input['orders'] ?? [];
            foreach ($orders as $o) {
                db()->query('UPDATE libraries SET sort_order = ? WHERE id = ?', [(int)$o['order'], (int)$o['id']]);
            }
            jsonResponse(['success' => true]);
            break;

        case 'unmatched':
            $files = db()->fetchAll(
                "SELECT mf.*, l.name as library_name 
                FROM media_files mf 
                JOIN libraries l ON mf.library_id = l.id 
                WHERE mf.media_id IS NULL 
                ORDER BY mf.file_name"
            );
            jsonResponse($files);
            break;

        case 'update_settings':
            if ($method !== 'POST') jsonResponse(['error' => '方法不允许'], 405);
            $input = json_decode(file_get_contents('php://input'), true);
            foreach ($input as $key => $value) {
                setSetting($key, $value);
            }
            jsonResponse(['success' => true]);
            break;

        case 'get_settings':
            $settings = db()->fetchAll('SELECT * FROM settings');
            $result = [];
            foreach ($settings as $s) {
                $result[$s['setting_key']] = $s['setting_value'];
            }
            jsonResponse($result);
            break;

        case 'detect_ffmpeg':
            require_once __DIR__ . '/../includes/FFmpegFinder.php';
            $ffmpeg = FFmpegFinder::find();
            $ffprobe = FFmpegFinder::findProbe();
            $version = FFmpegFinder::getVersion();

            if ($ffmpeg) setSetting('ffmpeg_path', $ffmpeg);
            if ($ffprobe) setSetting('ffprobe_path', $ffprobe);

            jsonResponse([
                'found'    => $ffmpeg !== null,
                'ffmpeg'   => $ffmpeg,
                'ffprobe'  => $ffprobe,
                'version'  => $version,
            ]);
            break;

        default:
            jsonResponse(['error' => '未知操作'], 400);
    }
} catch (Exception $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}
