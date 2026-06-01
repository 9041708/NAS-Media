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
                                $lang = $this->detectSubLang(basename($subFile));
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
                    $content = $this->srtToVtt($content);
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
