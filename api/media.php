<?php
require_once __DIR__ . '/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'list':
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
            $offset = ($page - 1) * $limit;
            $type = $_GET['type'] ?? '';
            $genre = $_GET['genre'] ?? '';
            $sort = $_GET['sort'] ?? 'title';
            $search = $_GET['search'] ?? '';
            $libraryId = (int)($_GET['library_id'] ?? 0);

            $where = ['1=1', '(SELECT COUNT(*) FROM media_files mf WHERE mf.media_id = mi.id) > 0'];
            $params = [];

            if ($libraryId > 0) {
                $where[] = 'EXISTS (SELECT 1 FROM media_files mf WHERE mf.media_id = mi.id AND mf.library_id = ?)';
                $params[] = $libraryId;
            }
            if ($type && $type !== 'all') {
                $where[] = 'mi.type = ?';
                $params[] = $type;
            }
            if ($genre) {
                $where[] = 'mi.genres LIKE ?';
                $params[] = "%$genre%";
            }
            if (!empty($_GET['cast'])) {
                $castName = trim($_GET['cast']);
                $where[] = 'mi.cast_list LIKE ?';
                $params[] = "%$castName%";
            }
            if ($search) {
                $where[] = '(mi.title LIKE ? OR mi.original_title LIKE ?)';
                $params[] = "%$search%";
                $params[] = "%$search%";
            }

            $whereClause = implode(' AND ', $where);

            $sortMap = [
                'title'  => 'mi.title ASC',
                'year'   => 'mi.year DESC',
                'rating' => 'mi.rating DESC',
                'added'  => 'mi.created_at DESC',
                'played' => 'mi.play_count DESC',
            ];
            $orderBy = $sortMap[$sort] ?? 'mi.title ASC';

            $total = db()->fetchColumn(
                "SELECT COUNT(*) FROM media_items mi WHERE $whereClause",
                $params
            );

            $items = db()->fetchAll(
                "SELECT mi.*, 
                    (SELECT COUNT(*) FROM media_files mf WHERE mf.media_id = mi.id) as file_count,
                    (SELECT MIN(mf.file_path) FROM media_files mf WHERE mf.media_id = mi.id) as first_file
                FROM media_items mi 
                WHERE $whereClause 
                ORDER BY $orderBy 
                LIMIT $limit OFFSET $offset",
                $params
            );

            jsonResponse([
                'items'    => $items,
                'total'    => (int)$total,
                'page'     => $page,
                'limit'    => $limit,
                'pages'    => ceil($total / $limit),
            ]);
            break;

        case 'detail':
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) jsonResponse(['error' => '缺少ID参数'], 400);

            $item = db()->fetchOne('SELECT * FROM media_items WHERE id = ?', [$id]);
            if (!$item) jsonResponse(['error' => '未找到'], 404);

            $files = db()->fetchAll(
                'SELECT * FROM media_files WHERE media_id = ? ORDER BY season_number, episode_number, file_name',
                [$id]
            );

            if ($item['type'] === 'tv' && !empty($files)) {
                $seasons = [];
                foreach ($files as $f) {
                    $sn = (int)($f['season_number'] ?? 1);
                    if (!isset($seasons[$sn])) {
                        $seasons[$sn] = ['season' => $sn, 'episodes' => []];
                    }
                    $ep = $f;
                    $ep['episode_number'] = (int)($f['episode_number'] ?? 0);
                    $seasons[$sn]['episodes'][] = $ep;
                }
                $item['seasons'] = array_values($seasons);
            } else {
                $item['files'] = $files;
            }

            $history = db()->fetchAll(
                'SELECT * FROM play_history WHERE media_id = ? ORDER BY played_at DESC LIMIT 10',
                [$id]
            );
            $item['history'] = $history;

            jsonResponse($item);
            break;

        case 'play':
            $fileId = (int)($_GET['file_id'] ?? 0);
            if (!$fileId) jsonResponse(['error' => '缺少文件ID'], 400);

            $file = db()->fetchOne('SELECT * FROM media_files WHERE id = ?', [$fileId]);
            if (!$file) jsonResponse(['error' => '文件不存在'], 404);

            jsonResponse([
                'file'    => $file,
                'stream'  => '/api/stream.php?id=' . $fileId,
            ]);
            break;

        case 'genres':
            $items = db()->fetchAll('SELECT genres FROM media_items WHERE genres IS NOT NULL AND genres != ""');
            $genres = [];
            foreach ($items as $item) {
                foreach (explode(',', $item['genres']) as $g) {
                    $g = trim($g);
                    if ($g) $genres[$g] = ($genres[$g] ?? 0) + 1;
                }
            }
            arsort($genres);
            jsonResponse(array_keys($genres));
            break;

        case 'stats':
            $stats = [
                'total_movies'  => db()->fetchColumn("SELECT COUNT(*) FROM media_items WHERE type='movie'"),
                'total_tv'      => db()->fetchColumn("SELECT COUNT(*) FROM media_items WHERE type='tv'"),
                'total_files'   => db()->fetchColumn("SELECT COUNT(*) FROM media_files"),
                'total_size'    => db()->fetchColumn("SELECT COALESCE(SUM(file_size), 0) FROM media_files"),
                'total_plays'   => db()->fetchColumn("SELECT COALESCE(SUM(play_count), 0) FROM media_items"),
                'libraries'     => db()->fetchAll("SELECT * FROM libraries"),
            ];
            jsonResponse($stats);
            break;

        case 'continue_watching':
            $userId = $_SESSION['user_id'] ?? 0;
            if (!$userId) jsonResponse([]);

            $items = db()->fetchAll(
                "SELECT mi.id, mi.title, mi.type, mi.year, mi.poster_path, mi.backdrop_path,
                    ph.file_id, ph.position, ph.duration, ph.played_at,
                    mf.file_name
                FROM play_history ph
                JOIN media_items mi ON ph.media_id = mi.id
                JOIN media_files mf ON ph.file_id = mf.id
                WHERE ph.user_id = ? AND ph.completed = 0 AND ph.position > 60
                AND ph.id IN (
                    SELECT MAX(ph2.id) FROM play_history ph2 WHERE ph2.user_id = ? GROUP BY ph2.media_id
                )
                ORDER BY ph.played_at DESC
                LIMIT 12",
                [$userId, $userId]
            );
            jsonResponse($items);
            break;

        case 'recent_history':
            $userId = $_SESSION['user_id'] ?? 0;
            if (!$userId) jsonResponse([]);
            $limit = min(20, max(1, (int)($_GET['limit'] ?? 6)));

            $items = db()->fetchAll(
                "SELECT ph.*, mi.title, mi.type, mi.poster_path, mi.year,
                    mf.file_name, mf.id as file_id
                FROM play_history ph
                JOIN media_items mi ON ph.media_id = mi.id
                JOIN media_files mf ON ph.file_id = mf.id
                WHERE ph.user_id = ?
                ORDER BY ph.played_at DESC
                LIMIT ?",
                [$userId, $limit]
            );
            jsonResponse($items);
            break;

        case 'recent':
            $limit = min(50, max(1, (int)($_GET['limit'] ?? 12)));
            $items = db()->fetchAll(
                "SELECT mi.*, 
                    (SELECT MIN(mf.file_path) FROM media_files mf WHERE mf.media_id = mi.id) as first_file
                FROM media_items mi 
                WHERE mi.poster_path IS NOT NULL 
                ORDER BY mi.created_at DESC 
                LIMIT ?",
                [$limit]
            );
            jsonResponse($items);
            break;

        case 'top_rated':
            $limit = min(50, max(1, (int)($_GET['limit'] ?? 12)));
            $items = db()->fetchAll(
                "SELECT mi.*, 
                    (SELECT MIN(mf.file_path) FROM media_files mf WHERE mf.media_id = mi.id) as first_file
                FROM media_items mi 
                WHERE mi.rating IS NOT NULL AND mi.poster_path IS NOT NULL 
                ORDER BY mi.rating DESC 
                LIMIT ?",
                [$limit]
            );
            jsonResponse($items);
            break;

        case 'favorites':
            $userId = $_SESSION['user_id'] ?? 0;
            if (!$userId) jsonResponse(['error' => '请先登录'], 401);

            $items = db()->fetchAll(
                "SELECT mi.*, f.created_at as favorited_at
                FROM favorites f
                JOIN media_items mi ON f.media_id = mi.id
                WHERE f.user_id = ?
                ORDER BY f.created_at DESC",
                [$userId]
            );
            jsonResponse($items);
            break;

        case 'toggle_favorite':
            if ($method !== 'POST') jsonResponse(['error' => '方法不允许'], 405);
            $userId = $_SESSION['user_id'] ?? 0;
            if (!$userId) jsonResponse(['error' => '请先登录'], 401);

            $input = json_decode(file_get_contents('php://input'), true);
            $mediaId = (int)($input['media_id'] ?? 0);
            if (!$mediaId) jsonResponse(['error' => '缺少media_id'], 400);

            $existing = db()->fetchOne(
                'SELECT id FROM favorites WHERE user_id = ? AND media_id = ?',
                [$userId, $mediaId]
            );

            if ($existing) {
                db()->delete('favorites', 'id = ?', [$existing['id']]);
                jsonResponse(['favorited' => false]);
            } else {
                db()->insert('favorites', ['user_id' => $userId, 'media_id' => $mediaId]);
                jsonResponse(['favorited' => true]);
            }
            break;

        case 'record_play':
            if ($method !== 'POST') jsonResponse(['error' => '方法不允许'], 405);
            $userId = $_SESSION['user_id'] ?? 0;
            if (!$userId) jsonResponse(['error' => '请先登录'], 401);

            $input = json_decode(file_get_contents('php://input'), true);
            $mediaId = (int)($input['media_id'] ?? 0);
            $fileId = (int)($input['file_id'] ?? 0);
            $position = (int)($input['position'] ?? 0);
            $duration = (int)($input['duration'] ?? 0);

            if (!$mediaId || !$fileId) jsonResponse(['error' => '参数不完整'], 400);

            db()->insert('play_history', [
                'user_id'   => $userId,
                'media_id'  => $mediaId,
                'file_id'   => $fileId,
                'position'  => $position,
                'duration'  => $duration,
                'completed' => ($duration > 0 && $position >= $duration - 10) ? 1 : 0,
            ]);

            db()->query(
                'UPDATE media_items SET play_count = play_count + 1, last_played = NOW() WHERE id = ?',
                [$mediaId]
            );

            jsonResponse(['success' => true]);
            break;

        case 'search_tmdb':
            $query = $_GET['q'] ?? '';
            if (empty($query)) jsonResponse(['error' => '请输入搜索词'], 400);

            $results = tmdb()->searchMovie($query);
            $formatted = array_map(function($r) {
                return [
                    'tmdb_id'     => $r['id'],
                    'title'       => $r['title'] ?? '',
                    'original_title' => $r['original_title'] ?? '',
                    'year'        => !empty($r['release_date']) ? (int)substr($r['release_date'], 0, 4) : null,
                    'poster_path' => $r['poster_path'] ?? null,
                    'overview'    => $r['overview'] ?? '',
                    'rating'      => $r['vote_average'] ?? 0,
                ];
            }, array_slice($results, 0, 10));

            jsonResponse($formatted);
            break;

        case 'match_media':
            if ($method !== 'POST') jsonResponse(['error' => '方法不允许'], 405);
            $input = json_decode(file_get_contents('php://input'), true);
            $fileId = (int)($input['file_id'] ?? 0);
            $mediaId = (int)($input['media_id'] ?? 0);
            $tmdbId = (int)($input['tmdb_id'] ?? 0);

            if (!$tmdbId) jsonResponse(['error' => '缺少TMDB ID'], 400);

            if ($mediaId && !$fileId) {
                $media = db()->fetchOne('SELECT * FROM media_items WHERE id = ?', [$mediaId]);
                if (!$media) jsonResponse(['error' => '媒体不存在'], 404);

                $details = $media['type'] === 'tv'
                    ? tmdb()->getTvDetails($tmdbId)
                    : tmdb()->getMovieDetails($tmdbId);
                if (!$details) jsonResponse(['error' => 'TMDB获取失败'], 400);

                $mediaData = tmdb()->formatMovieData($details);
                db()->update('media_items', $mediaData, 'id = ?', [$mediaId]);
                jsonResponse(['success' => true, 'media_id' => $mediaId]);
                break;
            }

            if (!$fileId) jsonResponse(['error' => '缺少文件ID'], 400);

            $details = tmdb()->getMovieDetails($tmdbId);
            if (!$details) jsonResponse(['error' => 'TMDB获取失败'], 400);

            $mediaData = tmdb()->formatMovieData($details);
            $existingMedia = db()->fetchOne('SELECT id FROM media_items WHERE tmdb_id = ?', [$tmdbId]);

            if ($existingMedia) {
                db()->update('media_files', ['media_id' => $existingMedia['id']], 'id = ?', [$fileId]);
                $mediaId = $existingMedia['id'];
            } else {
                $mediaId = db()->insert('media_items', $mediaData);
                db()->update('media_files', ['media_id' => $mediaId], 'id = ?', [$fileId]);
            }

            jsonResponse(['success' => true, 'media_id' => $mediaId]);
            break;

        case 'metadata_list':
            auth()->requireAdmin();
            $search = $_GET['search'] ?? '';
            $type = $_GET['type'] ?? '';

            $where = ['1=1'];
            $params = [];

            if ($type && in_array($type, ['movie', 'tv', 'other'])) {
                $where[] = 'mi.type = ?';
                $params[] = $type;
            }
            if ($search) {
                $where[] = '(mi.title LIKE ? OR mi.original_title LIKE ?)';
                $params[] = "%$search%";
                $params[] = "%$search%";
            }

            $whereClause = implode(' AND ', $where);

            $items = db()->fetchAll(
                "SELECT mi.*, 
                    (SELECT COUNT(*) FROM media_files mf WHERE mf.media_id = mi.id) as file_count
                FROM media_items mi 
                WHERE $whereClause
                AND EXISTS (SELECT 1 FROM media_files mf WHERE mf.media_id = mi.id)
                ORDER BY mi.title ASC 
                LIMIT 200",
                $params
            );
            jsonResponse($items);
            break;

        case 'media_tree':
            auth()->requireAdmin();
            $search = $_GET['search'] ?? '';
            $libType = $_GET['lib_type'] ?? '';

            $libraries = db()->fetchAll('SELECT * FROM libraries ORDER BY name');

            $result = [];
            foreach ($libraries as $lib) {
                if ($libType && $lib['type'] !== $libType) continue;

                $libNode = [
                    'id' => (int)$lib['id'],
                    'name' => $lib['name'],
                    'path' => $lib['path'],
                    'type' => $lib['type'],
                    'children' => [],
                    'unmatched' => [],
                ];

                if ($lib['type'] === 'tv') {
                    $allFiles = db()->fetchAll(
                        "SELECT mf.*, mi.id as media_id, mi.title as media_title, mi.year,
                            mi.overview, mi.genres, mi.rating, mi.tmdb_id, mi.poster_path, mi.vip_only
                         FROM media_files mf
                         LEFT JOIN media_items mi ON mf.media_id = mi.id
                         WHERE mf.library_id = ?
                         ORDER BY mf.file_path",
                        [$lib['id']]
                    );

                    $shows = [];
                    $unmatched = [];
                    foreach ($allFiles as $f) {
                        if ($search && stripos($f['media_title'] ?? '', $search) === false
                            && stripos($f['file_name'], $search) === false) continue;

                        if (empty($f['media_id'])) {
                            $unmatched[] = formatFileNodeForTree($f);
                            continue;
                        }

                        $showDir = getShowDirFromPath($f['file_path'], $lib['path']);
                        $showKey = $showDir ?: ($f['media_title'] ?: $f['file_name']);
                        $miKey = $f['media_id'];

                        if (!isset($shows[$miKey])) {
                            $shows[$miKey] = [
                                'id' => (int)$f['media_id'],
                                'title' => $f['media_title'] ?: basename($showDir ?: dirname($f['file_path'])),
                                'year' => $f['year'],
                                'overview' => $f['overview'],
                                'genres' => $f['genres'],
                                'rating' => $f['rating'],
                                'tmdb_id' => $f['tmdb_id'],
                                'poster_path' => $f['poster_path'],
                                'vip_only' => (int)($f['vip_only'] ?? 0),
                                'seasons' => [],
                            ];
                        }

                        $sn = (int)($f['season_number'] ?? 1);
                        if (!isset($shows[$miKey]['seasons'][$sn])) {
                            $shows[$miKey]['seasons'][$sn] = ['num' => $sn, 'episodes' => []];
                        }
                        $shows[$miKey]['seasons'][$sn]['episodes'][] = formatFileNodeForTree($f);
                    }

                    ksort($shows);
                    foreach ($shows as &$show) {
                        ksort($show['seasons']);
                        $show['seasons'] = array_values($show['seasons']);
                    }
                    $libNode['children'] = array_values($shows);
                    $libNode['unmatched'] = $unmatched;
                } else {
                    $allFiles = db()->fetchAll(
                        "SELECT mf.*, mi.id as media_id, mi.title as media_title, mi.year,
                            mi.overview, mi.genres, mi.rating, mi.tmdb_id, mi.poster_path, mi.vip_only
                         FROM media_files mf
                         LEFT JOIN media_items mi ON mf.media_id = mi.id
                         WHERE mf.library_id = ?
                         ORDER BY mf.file_path",
                        [$lib['id']]
                    );

                    $matched = [];
                    $unmatched = [];
                    $grouped = [];
                    foreach ($allFiles as $f) {
                        if ($search && stripos($f['media_title'] ?? '', $search) === false
                            && stripos($f['file_name'], $search) === false) continue;

                        if (empty($f['media_id'])) {
                            $unmatched[] = formatFileNodeForTree($f);
                            continue;
                        }

                        $miKey = $f['media_id'];
                        if (!isset($grouped[$miKey])) {
                            $grouped[$miKey] = [
                                'id' => (int)$f['media_id'],
                                'title' => $f['media_title'] ?: basename(dirname($f['file_path'])),
                                'year' => $f['year'],
                                'overview' => $f['overview'],
                                'genres' => $f['genres'],
                                'rating' => $f['rating'],
                                'tmdb_id' => $f['tmdb_id'],
                                'poster_path' => $f['poster_path'],
                                'vip_only' => (int)($f['vip_only'] ?? 0),
                                'files' => [],
                            ];
                        }
                        $grouped[$miKey]['files'][] = formatFileNodeForTree($f);
                    }

                    $libNode['children'] = array_values($grouped);
                    $libNode['unmatched'] = $unmatched;
                }

                $result[] = $libNode;
            }

            jsonResponse($result);
            break;

        case 'media_tree_unmatched':
            auth()->requireAdmin();
            $files = db()->fetchAll(
                "SELECT mf.*, l.name as library_name, l.id as library_id
                 FROM media_files mf
                 JOIN libraries l ON mf.library_id = l.id
                 WHERE mf.media_id IS NULL
                 ORDER BY mf.file_path
                 LIMIT 500"
            );

            $grouped = [];
            foreach ($files as $f) {
                $libId = $f['library_id'];
                if (!isset($grouped[$libId])) {
                    $grouped[$libId] = [
                        'library_id' => (int)$libId,
                        'library_name' => $f['library_name'],
                        'files' => [],
                    ];
                }
                $grouped[$libId]['files'][] = [
                    'id' => (int)$f['id'],
                    'library_id' => (int)$f['library_id'],
                    'media_id' => null,
                    'file_name' => $f['file_name'],
                    'file_path' => $f['file_path'],
                    'file_size' => (int)$f['file_size'],
                    'file_type' => $f['file_type'],
                    'duration' => (int)$f['duration'],
                    'resolution' => $f['resolution'],
                    'season_number' => (int)($f['season_number'] ?? 0),
                    'episode_number' => (int)($f['episode_number'] ?? 0),
                ];
            }

            jsonResponse(array_values($grouped));
            break;

        case 'update_metadata':
            if ($method !== 'POST') jsonResponse(['error' => '方法不允许'], 405);
            $input = json_decode(file_get_contents('php://input'), true);
            $mediaId = (int)($input['media_id'] ?? 0);
            if (!$mediaId) jsonResponse(['error' => '缺少media_id'], 400);

            $updateData = [];
            $fields = ['title', 'original_title', 'year', 'type', 'overview', 'genres', 'tmdb_id', 'vip_only'];
            foreach ($fields as $f) {
                if (array_key_exists($f, $input)) {
                    $updateData[$f] = $input[$f] !== '' ? $input[$f] : null;
                }
            }

            if (empty($updateData)) jsonResponse(['error' => '无数据需要更新'], 400);

            db()->update('media_items', $updateData, 'id = ?', [$mediaId]);
            jsonResponse(['success' => true]);
            break;

        case 'show_credits':
            $mediaId = (int)($_GET['id'] ?? 0);
            if (!$mediaId) jsonResponse(['error' => '缺少ID'], 400);
            $media = db()->fetchOne('SELECT tmdb_id, type FROM media_items WHERE id = ?', [$mediaId]);
            if (!$media || !$media['tmdb_id']) jsonResponse([]);
            $cast = tmdb()->getCastPhotos((int)$media['tmdb_id'], $media['type']);
            jsonResponse($cast);
            break;

        case 'show_similar':
            $mediaId = (int)($_GET['id'] ?? 0);
            if (!$mediaId) jsonResponse([]);
            $media = db()->fetchOne('SELECT * FROM media_items WHERE id = ?', [$mediaId]);
            if (!$media) jsonResponse([]);

            $where = ['mi.id != ?'];
            $params = [(int)$mediaId];
            $recipe = [];

            if (!empty($media['genres'])) {
                foreach (explode(',', $media['genres']) as $g) {
                    $g = trim($g);
                    if ($g) { $where[] = 'mi.genres LIKE ?'; $params[] = "%$g%"; }
                }
                $recipe[] = '同类型';
            }
            if (!empty($media['cast_list'])) {
                $castNames = array_slice(array_map('trim', explode(',', $media['cast_list'])), 0, 3);
                foreach ($castNames as $cn) {
                    if ($cn) { $where[] = 'mi.cast_list LIKE ?'; $params[] = "%$cn%"; }
                }
                $recipe[] = '同演员';
            }

            $whereClause = implode(' OR ', $where);
            $localItems = db()->fetchAll(
                "SELECT mi.id, mi.title, mi.type, mi.poster_path, mi.year, mi.rating,
                    (SELECT COUNT(DISTINCT ci.collection_id) FROM collection_items ci WHERE ci.media_id = mi.id) as in_collections,
                    (SELECT COUNT(*) FROM (SELECT 1 FROM media_items mi2 WHERE mi2.id != ? AND (
                        " . implode(' OR ', array_slice($where, 1)) . "
                    ) LIMIT 10) sub) as score
                FROM media_items mi
                WHERE ($whereClause)
                AND EXISTS (SELECT 1 FROM media_files mf WHERE mf.media_id = mi.id)
                ORDER BY score DESC
                LIMIT 20",
                array_merge([(int)$mediaId], $params)
            );

            if (count($localItems) < 6 && $media['tmdb_id']) {
                $tmdbSimilar = tmdb()->getSimilar((int)$media['tmdb_id'], $media['type']);
                $existingTitles = array_column($localItems, 'title');
                foreach ($tmdbSimilar as $s) {
                    $title = $s['title'] ?? $s['name'] ?? '';
                    if (!in_array($title, $existingTitles)) {
                        $localItems[] = [
                            'id' => 0,
                            'title' => $title,
                            'type' => $media['type'],
                            'poster_path' => $s['poster_path'] ?? null,
                            'year' => !empty($s['release_date'] ?? $s['first_air_date'] ?? '') ? (int)substr($s['release_date'] ?? $s['first_air_date'], 0, 4) : null,
                            'rating' => $s['vote_average'] ?? 0,
                            'external' => true,
                        ];
                    }
                }
            }

            jsonResponse(array_slice($localItems, 0, 20));
            break;

        case 'show_season':
            $mediaId = (int)($_GET['id'] ?? 0);
            $seasonNum = (int)($_GET['season'] ?? 1);
            if (!$mediaId) jsonResponse(['error' => '缺少ID'], 400);
            $media = db()->fetchOne('SELECT tmdb_id FROM media_items WHERE id = ?', [$mediaId]);
            if (!$media || !$media['tmdb_id']) jsonResponse(null);
            $season = tmdb()->getSeasonDetails((int)$media['tmdb_id'], $seasonNum);
            jsonResponse($season);
            break;

        case 'toggle_played':
            if ($method !== 'POST') jsonResponse(['error' => '方法不允许'], 405);
            $input = json_decode(file_get_contents('php://input'), true);
            $mediaId = (int)($input['media_id'] ?? 0);
            if (!$mediaId) jsonResponse(['error' => '缺少media_id'], 400);

            $userId = $_SESSION['user_id'] ?? 0;
            if (!$userId) jsonResponse(['error' => '请先登录'], 403);

            $existing = db()->fetchOne(
                'SELECT id, file_id FROM play_history WHERE user_id = ? AND media_id = ? AND completed = 1 ORDER BY played_at DESC LIMIT 1',
                [$userId, $mediaId]
            );

            if ($existing) {
                db()->delete('play_history', 'id = ?', [$existing['id']]);
                jsonResponse(['played' => false]);
            } else {
                $file = db()->fetchOne(
                    'SELECT id FROM media_files WHERE media_id = ? ORDER BY season_number, episode_number LIMIT 1',
                    [$mediaId]
                );
                if ($file) {
                    db()->insert('play_history', [
                        'user_id' => $userId,
                        'media_id' => $mediaId,
                        'file_id' => $file['id'],
                        'position' => db()->fetchColumn('SELECT COALESCE(duration, 0) FROM media_files WHERE id = ?', [$file['id']]),
                        'duration' => db()->fetchColumn('SELECT COALESCE(duration, 0) FROM media_files WHERE id = ?', [$file['id']]),
                        'completed' => 1,
                    ]);
                }
                jsonResponse(['played' => true]);
            }
            break;

        case 'scan_media':
            if ($method !== 'POST') jsonResponse(['error' => '方法不允许'], 405);
            $input = json_decode(file_get_contents('php://input'), true);
            $mediaId = (int)($input['media_id'] ?? 0);
            if (!$mediaId) jsonResponse(['error' => '缺少media_id'], 400);

            $files = db()->fetchAll('SELECT id, library_id FROM media_files WHERE media_id = ?', [$mediaId]);
            if (empty($files)) jsonResponse(['error' => '无关联文件'], 400);

            $libraryId = $files[0]['library_id'];
            $scanner = new MediaScanner(require __DIR__ . '/../config.php');
            $results = $scanner->scanLibrary($libraryId);
            jsonResponse(['success' => true, 'results' => $results]);
            break;

        case 'show_trailer':
            $mediaId = (int)($_GET['id'] ?? 0);
            if (!$mediaId) jsonResponse([]);
            $media = db()->fetchOne('SELECT tmdb_id, type FROM media_items WHERE id = ?', [$mediaId]);
            if (!$media || !$media['tmdb_id']) jsonResponse([]);
            $videos = $media['type'] === 'tv'
                ? tmdb()->getTvVideos((int)$media['tmdb_id'])
                : tmdb()->getMovieVideos((int)$media['tmdb_id']);
            jsonResponse($videos);
            break;

        case 'create_collection':
            if ($method !== 'POST') jsonResponse(['error' => '方法不允许'], 405);
            $input = json_decode(file_get_contents('php://input'), true);
            $name = trim($input['name'] ?? '');
            if (!$name) jsonResponse(['error' => '名称不能为空'], 400);
            $userId = $_SESSION['user_id'] ?? 0;
            $cid = db()->insert('collections', ['name' => $name, 'user_id' => $userId]);
            if (!empty($input['media_id'])) {
                db()->insert('collection_items', ['collection_id' => $cid, 'media_id' => (int)$input['media_id']]);
            }
            jsonResponse(['success' => true, 'id' => $cid]);
            break;

        case 'add_to_collection':
            if ($method !== 'POST') jsonResponse(['error' => '方法不允许'], 405);
            $input = json_decode(file_get_contents('php://input'), true);
            $cid = (int)($input['collection_id'] ?? 0);
            $mid = (int)($input['media_id'] ?? 0);
            if (!$cid || !$mid) jsonResponse(['error' => '参数不完整'], 400);
            $ex = db()->fetchOne('SELECT id FROM collection_items WHERE collection_id=? AND media_id=?', [$cid, $mid]);
            if ($ex) jsonResponse(['error' => '已在合集中'], 400);
            db()->insert('collection_items', ['collection_id' => $cid, 'media_id' => $mid]);
            jsonResponse(['success' => true]);
            break;

        case 'list_collections':
            $userId = $_SESSION['user_id'] ?? 0;
            $items = db()->fetchAll(
                'SELECT c.id, c.name, c.overview, c.poster_path,
                    (SELECT COUNT(*) FROM collection_items ci WHERE ci.collection_id = c.id) as item_count,
                    (SELECT mi.poster_path FROM collection_items ci2 JOIN media_items mi ON ci2.media_id = mi.id WHERE ci2.collection_id = c.id ORDER BY ci2.sort_order LIMIT 1) as first_poster
                FROM collections c WHERE c.user_id = ? ORDER BY c.name',
                [$userId]
            );
            jsonResponse($items);
            break;

        case 'collection_items':
            $cid = (int)($_GET['id'] ?? 0);
            if (!$cid) jsonResponse([]);
            $items = db()->fetchAll(
                'SELECT mi.* FROM collection_items ci JOIN media_items mi ON ci.media_id = mi.id WHERE ci.collection_id = ? ORDER BY ci.sort_order',
                [$cid]
            );
            jsonResponse($items);
            break;

        default:
            jsonResponse(['error' => '未知操作'], 400);
    }
} catch (Exception $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}

function formatFileNodeForTree(array $f): array
{
    return [
        'id' => (int)$f['id'],
        'library_id' => (int)$f['library_id'],
        'media_id' => $f['media_id'] ? (int)$f['media_id'] : null,
        'file_name' => $f['file_name'],
        'file_path' => $f['file_path'],
        'file_size' => (int)$f['file_size'],
        'file_type' => $f['file_type'],
        'duration' => (int)$f['duration'],
        'resolution' => $f['resolution'],
        'season_number' => (int)($f['season_number'] ?? 0),
        'episode_number' => (int)($f['episode_number'] ?? 0),
    ];
}

function getShowDirFromPath(string $filePath, string $libraryPath): string
{
    $relPath = str_replace($libraryPath, '', $filePath);
    $relPath = ltrim(str_replace('\\', '/', $relPath), '/');
    $parts = explode('/', $relPath);
    if (count($parts) >= 2) {
        return $parts[0];
    }
    return '';
}
