<?php
require_once __DIR__ . '/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

$user = auth()->getUser();
$userId = $user ? (int)$user['id'] : 0;
if (!$userId) jsonResponse(['error' => '请先登录'], 401);

try {
    switch ($action) {
        case 'list':
            $fileId = (int)($_GET['file_id'] ?? 0);
            if (!$fileId) jsonResponse(['error' => '缺少file_id'], 400);

            $items = db()->fetchAll(
                "SELECT d.*, u.display_name, u.username
                 FROM danmaku d
                 LEFT JOIN users u ON d.user_id = u.id
                 WHERE d.file_id = ?
                 ORDER BY d.time_pos ASC",
                [$fileId]
            );
            jsonResponse($items);
            break;

        case 'send':
            if ($method !== 'POST') jsonResponse(['error' => '方法不允许'], 405);
            $input = json_decode(file_get_contents('php://input'), true);

            $fileId = (int)($input['file_id'] ?? 0);
            $content = trim($input['content'] ?? '');
            $timePos = (float)($input['time_pos'] ?? 0);
            $color = $input['color'] ?? '#ffffff';
            $type = in_array($input['type'] ?? '', ['scroll', 'top', 'bottom']) ? $input['type'] : 'scroll';
            $fontSize = min(36, max(12, (int)($input['font_size'] ?? 18)));

            if (!$fileId || empty($content)) jsonResponse(['error' => '参数不完整'], 400);
            if (mb_strlen($content) > 100) jsonResponse(['error' => '弹幕内容不超过100字'], 400);
            if ($timePos < 0) jsonResponse(['error' => '时间位置无效'], 400);

            $id = db()->insert('danmaku', [
                'file_id'   => $fileId,
                'user_id'   => $userId,
                'content'   => $content,
                'time_pos'  => $timePos,
                'color'     => $color,
                'type'      => $type,
                'font_size' => $fontSize,
            ]);

            $user = db()->fetchOne('SELECT display_name, username FROM users WHERE id = ?', [$userId]);
            jsonResponse([
                'success' => true,
                'id' => $id,
                'danmaku' => [
                    'id'           => $id,
                    'file_id'      => $fileId,
                    'user_id'      => $userId,
                    'content'      => $content,
                    'time_pos'     => $timePos,
                    'color'        => $color,
                    'type'         => $type,
                    'font_size'    => $fontSize,
                    'display_name' => $user['display_name'] ?? $user['username'],
                    'username'     => $user['username'],
                ],
            ]);
            break;

        case 'bilibili_import':
            if ($method !== 'POST') jsonResponse(['error' => '方法不允许'], 405);
            $input = json_decode(file_get_contents('php://input'), true);
            $fileId = (int)($input['file_id'] ?? 0);
            $cid = trim($input['cid'] ?? '');
            if (!$fileId || empty($cid)) jsonResponse(['error' => '参数不完整'], 400);

            try {
                $count = importBilibiliDanmaku($fileId, $cid, $userId);
                jsonResponse(['success' => true, 'count' => $count]);
            } catch (Exception $e) {
                jsonResponse(['error' => $e->getMessage()], 500);
            }
            break;

        default:
            jsonResponse(['error' => '未知操作'], 400);
    }
} catch (Exception $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}

function importBilibiliDanmaku($fileId, $cid, $userId) {
    $url = "https://api.bilibili.com/x/v1/dm/list.so?oid=" . urlencode($cid);
    $ctx = stream_context_create([
        'http' => ['timeout' => 10, 'user_agent' => 'Mozilla/5.0'],
        'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);

    $xml = @file_get_contents($url, false, $ctx);
    if (!$xml) throw new Exception('无法获取B站弹幕，请检查cid是否正确');

    $dom = new DOMDocument();
    @$dom->loadXML($xml);
    $elements = $dom->getElementsByTagName('d');

    $count = 0;
    db()->beginTransaction();
    try {
        $stmt = db()->query('DELETE FROM danmaku WHERE file_id = ? AND user_id = ?', [$fileId, $userId]);
        foreach ($elements as $d) {
            $attrs = explode(',', $d->getAttribute('p'));
            $timePos = round((float)($attrs[0] ?? 0), 2);
            $typeCode = (int)($attrs[1] ?? 1);
            $fontSize = (int)($attrs[2] ?? 25);
            $colorDec = (int)($attrs[3] ?? 16777215);
            $color = '#' . str_pad(dechex($colorDec), 6, '0', STR_PAD_LEFT);
            $content = $d->textContent;

            $typeMap = [1 => 'scroll', 4 => 'bottom', 5 => 'top'];
            $type = $typeMap[$typeCode] ?? 'scroll';
            $fontSize = max(14, min(36, (int)($fontSize * 0.7)));

            if (empty(trim($content))) continue;
            if ($timePos < 0) continue;

            db()->insert('danmaku', [
                'file_id'   => $fileId,
                'user_id'   => $userId,
                'content'   => mb_substr($content, 0, 500),
                'time_pos'  => $timePos,
                'color'     => $color,
                'type'      => $type,
                'font_size' => $fontSize,
            ]);
            $count++;
        }
        db()->commit();
    } catch (Exception $e) {
        db()->rollBack();
        throw $e;
    }

    return $count;
}
