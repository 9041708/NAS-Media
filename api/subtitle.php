<?php
require_once __DIR__ . '/bootstrap.php';

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'list':
            $fileId = (int)($_GET['file_id'] ?? 0);
            if (!$fileId) jsonResponse(['error' => '缺少file_id'], 400);

            $embedded = db()->fetchAll(
                'SELECT id, stream_index, codec, language, title, is_default, "embedded" as source FROM subtitle_tracks WHERE file_id = ? AND source = "embedded"',
                [$fileId]
            );
            $external = db()->fetchAll(
                'SELECT id, stream_index, codec, language, title, file_path, is_default, "external" as source FROM subtitle_tracks WHERE file_id = ? AND source = "external"',
                [$fileId]
            );

            $file = db()->fetchOne('SELECT file_path FROM media_files WHERE id = ?', [$fileId]);
            if ($file) {
                $dir = dirname($file['file_path']);
                $baseName = pathinfo($file['file_path'], PATHINFO_FILENAME);
                $subExts = ['srt', 'ass', 'ssa', 'vtt', 'sub'];

                foreach ($subExts as $ext) {
                    $patterns = [
                        "$dir/$baseName.$ext",
                        "$dir/$baseName.*.$ext",
                        "$dir/subs/$baseName.$ext",
                        "$dir/Subs/$baseName.$ext",
                        "$dir/subtitles/$baseName.$ext",
                    ];
                    foreach ($patterns as $pattern) {
                        foreach (glob($pattern) as $subFile) {
                            $exists = false;
                            foreach ($external as $e) {
                                if ($e['file_path'] === $subFile) { $exists = true; break; }
                            }
                            if (!$exists && file_exists($subFile)) {
                                $lang = detectSubLang(basename($subFile));
                                $subId = db()->insert('subtitle_tracks', [
                                    'file_id'      => $fileId,
                                    'source'       => 'external',
                                    'codec'        => $ext,
                                    'language'     => $lang,
                                    'title'        => basename($subFile),
                                    'file_path'    => $subFile,
                                ]);
                                $external[] = [
                                    'id'         => $subId,
                                    'stream_index'=> null,
                                    'codec'      => $ext,
                                    'language'   => $lang,
                                    'title'      => basename($subFile),
                                    'file_path'  => $subFile,
                                    'is_default' => 0,
                                    'source'     => 'external',
                                ];
                            }
                        }
                    }
                }
            }

            jsonResponse(array_merge($embedded, $external));
            break;

        case 'serve':
            $trackId = (int)($_GET['track_id'] ?? 0);
            if (!$trackId) { http_response_code(400); die('Missing track_id'); }

            $track = db()->fetchOne('SELECT * FROM subtitle_tracks WHERE id = ?', [$trackId]);
            if (!$track) { http_response_code(404); die('Not found'); }

            if ($track['source'] === 'external' && !empty($track['file_path'])) {
                $ext = strtolower(pathinfo($track['file_path'], PATHINFO_EXTENSION));
                $mimeMap = [
                    'srt' => 'text/srt',
                    'vtt' => 'text/vtt',
                    'ass' => 'text/x-ssa',
                    'ssa' => 'text/x-ssa',
                    'sub' => 'text/plain',
                ];
                header('Content-Type: ' . ($mimeMap[$ext] ?? 'text/plain'));
                header('Access-Control-Allow-Origin: *');
                header('Cache-Control: max-age=86400');

                $content = file_get_contents($track['file_path']);

                if ($ext === 'srt') {
                    $content = srtToVtt($content);
                    header('Content-Type: text/vtt');
                }

                echo $content;
                exit;
            }

            if ($track['source'] === 'embedded') {
                $file = db()->fetchOne(
                    'SELECT file_path FROM media_files WHERE id = ?',
                    [$track['file_id']]
                );
                if (!$file) { http_response_code(404); die('File not found'); }

                $cacheDir = __DIR__ . '/../cache/subtitles';
                if (!is_dir($cacheDir)) mkdir($cacheDir, 0755, true);

                $cachedFile = $cacheDir . "/sub_{$trackId}.vtt";
                if (file_exists($cachedFile)) {
                    header('Content-Type: text/vtt');
                    header('Access-Control-Allow-Origin: *');
                    header('Cache-Control: max-age=86400');
                    readfile($cachedFile);
                    exit;
                }

                $ffmpeg = new FFmpeg();
                if ($ffmpeg->extractSubtitle($file['file_path'], $track['stream_index'], $cachedFile)) {
                    header('Content-Type: text/vtt');
                    header('Access-Control-Allow-Origin: *');
                    readfile($cachedFile);
                    exit;
                }

                http_response_code(500);
                die('Extract failed');
            }
            break;

        case 'audio_list':
            $fileId = (int)($_GET['file_id'] ?? 0);
            if (!$fileId) jsonResponse(['error' => '缺少file_id'], 400);

            $tracks = db()->fetchAll(
                'SELECT * FROM audio_tracks WHERE file_id = ? ORDER BY stream_index',
                [$fileId]
            );
            jsonResponse($tracks);
        break;

        case 'search':
            $fileId = (int)($_GET['file_id'] ?? 0);
            $lang = $_GET['lang'] ?? 'zh';
            if (!$fileId) jsonResponse(['error' => '缺少file_id'], 400);

            $file = db()->fetchOne('SELECT mf.*, mi.title, mi.year, mi.tmdb_id, mi.type, mi.imdb_id
                FROM media_files mf 
                LEFT JOIN media_items mi ON mf.media_id = mi.id
                WHERE mf.id = ?', [$fileId]);
            if (!$file) jsonResponse(['error' => '文件不存在'], 404);

            $results = searchSubtitlesOnline($file, $lang);
            jsonResponse(['results' => $results]);
        break;

        case 'download':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['error' => '方法不允许'], 405);
            $input = json_decode(file_get_contents('php://input'), true);
            $fileId = (int)($input['file_id'] ?? 0);
            $subUrl = $input['url'] ?? '';
            $subName = $input['name'] ?? 'subtitle';
            $lang = $input['lang'] ?? 'zh';
            $localPath = $input['local_path'] ?? '';

            if (!$fileId) jsonResponse(['error' => '参数不完整'], 400);

            $file = db()->fetchOne('SELECT file_path FROM media_files WHERE id = ?', [$fileId]);
            if (!$file) jsonResponse(['error' => '文件不存在'], 404);

            if ($localPath && file_exists($localPath)) {
                $ext = strtolower(pathinfo($localPath, PATHINFO_EXTENSION));
                $existing = db()->fetchOne(
                    'SELECT id FROM subtitle_tracks WHERE file_id = ? AND file_path = ?',
                    [$fileId, $localPath]
                );
                if ($existing) {
                    jsonResponse(['success' => true, 'track_id' => (int)$existing['id'], 'message' => '已存在']);
                    break;
                }
                $subId = db()->insert('subtitle_tracks', [
                    'file_id' => $fileId,
                    'source' => 'external',
                    'codec' => $ext,
                    'language' => detectSubLang(basename($localPath)),
                    'title' => basename($localPath),
                    'file_path' => $localPath,
                ]);
                jsonResponse(['success' => true, 'track_id' => (int)$subId]);
                break;
            }

            if (!$subUrl || strpos($subUrl, 'local://') === 0) jsonResponse(['error' => '无效的字幕URL'], 400);

            $dir = dirname($file['file_path']);
            $baseName = pathinfo($file['file_path'], PATHINFO_FILENAME);
            $ext = pathinfo(parse_url($subUrl, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'srt';
            $targetFile = $dir . '/' . $baseName . '.' . $lang . '.' . $ext;

            $content = @file_get_contents($subUrl);
            if (!$content) jsonResponse(['error' => '下载字幕失败，请检查网络'], 500);

            file_put_contents($targetFile, $content);

            $subId = db()->insert('subtitle_tracks', [
                'file_id' => $fileId,
                'source' => 'external',
                'codec' => $ext,
                'language' => $lang,
                'title' => $subName,
                'file_path' => $targetFile,
            ]);

            jsonResponse(['success' => true, 'track_id' => (int)$subId, 'file_path' => $targetFile]);
        break;

        default:
            http_response_code(400);
            die('Unknown action');
    }
} catch (Exception $e) {
    http_response_code(500);
    die($e->getMessage());
}

function detectSubLang(string $filename): string
{
    $patterns = [
        '/\b(zh|chi|chinese|chs|cht|中文|简体|繁体)\b/i' => 'zh',
        '/\b(en|eng|english|英文)\b/i' => 'en',
        '/\b(ja|jpn|japanese|日文|日语)\b/i' => 'ja',
        '/\b(ko|kor|korean|韩文|韩语)\b/i' => 'ko',
    ];
    foreach ($patterns as $pattern => $lang) {
        if (preg_match($pattern, $filename)) return $lang;
    }
    return 'und';
}

function srtToVtt(string $srt): string
{
    $vtt = "WEBVTT\n\n";
    $srt = str_replace("\r\n", "\n", $srt);
    $blocks = explode("\n\n", trim($srt));

    foreach ($blocks as $block) {
        $lines = explode("\n", $block);
        if (count($lines) < 3) continue;

        $timeLine = $lines[1];
        $timeLine = str_replace(',', '.', $timeLine);
        $text = implode("\n", array_slice($lines, 2));

        $vtt .= "$timeLine\n$text\n\n";
    }

    return $vtt;
}

function searchSubtitlesOnline(array $file, string $lang = 'zh'): array
{
    $results = [];
    $title = $file['title'] ?? '';
    $year = $file['year'] ?? '';
    $imdbId = $file['imdb_id'] ?? '';
    $tmdbId = $file['tmdb_id'] ?? '';
    $type = $file['type'] ?? 'movie';
    $filePath = $file['file_path'] ?? '';
    $fileName = $file['file_name'] ?? '';

    $hash = '';
    if ($filePath && file_exists($filePath)) {
        $size = filesize($filePath);
        $hash = md5_file($filePath);
    }

    $searchQueries = [];
    if ($title) {
        $searchQueries[] = trim($title);
        if ($year) $searchQueries[] = trim($title) . ' ' . $year;
    }
    if ($fileName) {
        $cleanName = preg_replace('/\.\w+$/', '', $fileName);
        $cleanName = preg_replace('/[\.\-_]/', ' ', $cleanName);
        $cleanName = preg_replace('/\b(1080p|720p|4K|BluRay|WEB-DL|WEBRip|HDRip|BRRip|HDTV|x264|x265|HEVC|AAC|DD5\.1|DD2\.0|DDP5\.1|H\.264|H\.265)\b/i', '', $cleanName);
        $cleanName = trim(preg_replace('/\s+/', ' ', $cleanName));
        if ($cleanName && !in_array($cleanName, $searchQueries)) {
            $searchQueries[] = $cleanName;
        }
    }

    if ($imdbId) {
        $results[] = [
            'source' => 'IMDB链接',
            'name' => "IMDB: $imdbId",
            'url' => "https://www.opensubtitles.org/zh/search/imdbid-$imdbId/sublanguageid-$lang",
            'lang' => $lang,
            'type' => 'link',
        ];
    }

    if ($tmdbId) {
        $tmdbType = $type === 'tv' ? 'tv' : 'movie';
        $results[] = [
            'source' => 'TMDB链接',
            'name' => "TMDB: $tmdbId",
            'url' => "https://www.opensubtitles.org/zh/search/tmdbid-$tmdbId/sublanguageid-$lang",
            'lang' => $lang,
            'type' => 'link',
        ];
    }

    foreach ($searchQueries as $query) {
        $encoded = urlencode($query);
        $results[] = [
            'source' => '搜索: ' . $query,
            'name' => $query,
            'url' => "https://www.opensubtitles.org/zh/search/moviename-$encoded/sublanguageid-$lang",
            'lang' => $lang,
            'type' => 'link',
        ];
    }

    if ($hash && $size) {
        try {
            $ctx = stream_context_create(['http' => ['timeout' => 5, 'user_agent' => 'NASMovieLibrary/1.0']]);
            $subdbUrl = "http://api.thesubdb.com/?action=search&hash=$hash";
            $resp = @file_get_contents($subdbUrl, false, $ctx);
            if ($resp) {
                $langs = explode(',', trim($resp));
                if (in_array($lang, $langs) || in_array('zh', $langs) || in_array('en', $langs)) {
                    $results[] = [
                        'source' => 'TheSubDB',
                        'name' => $fileName . ' (自动匹配)',
                        'url' => "http://api.thesubdb.com/?action=download&hash=$hash&language=" . (in_array($lang, $langs) ? $lang : (in_array('zh', $langs) ? 'zh' : 'en')),
                        'lang' => in_array($lang, $langs) ? $lang : (in_array('zh', $langs) ? 'zh' : 'en'),
                        'type' => 'download',
                    ];
                }
            }
        } catch (\Exception $e) {}
    }

    foreach ($searchQueries as $query) {
        $links = searchLocalSubtitles($filePath, $query, $lang);
        foreach ($links as $link) {
            $results[] = $link;
        }
    }

    return $results;
}

function searchLocalSubtitles(string $filePath, string $query, string $lang): array
{
    $results = [];
    $dir = dirname($filePath);
    $baseName = pathinfo($filePath, PATHINFO_FILENAME);

    $patterns = [
        "$dir/$baseName.$lang.srt",
        "$dir/$baseName.$lang.vtt",
        "$dir/$baseName.$lang.ass",
        "$dir/$baseName.zh.srt",
        "$dir/$baseName.en.srt",
        "$dir/subs/$baseName.*.srt",
        "$dir/Subs/$baseName.*.srt",
        "$dir/subtitles/$baseName.*.srt",
    ];

    foreach ($patterns as $pattern) {
        foreach (glob($pattern) as $subFile) {
            if (!file_exists($subFile)) continue;
            $subName = basename($subFile);
            $subLang = detectSubLang($subName);
            if ($lang && $subLang !== $lang && $subLang !== 'und') continue;

            $results[] = [
                'source' => '本地文件',
                'name' => $subName,
                'url' => 'local://' . $subFile,
                'lang' => $subLang,
                'type' => 'local',
                'local_path' => $subFile,
            ];
        }
    }

    return $results;
}
