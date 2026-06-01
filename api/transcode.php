<?php
require_once __DIR__ . '/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'start':
            if ($method !== 'POST') jsonResponse(['error' => '方法不允许'], 405);
            auth()->requireAdmin();

            $input = json_decode(file_get_contents('php://input'), true);
            $fileId = (int)($input['file_id'] ?? 0);
            $quality = $input['quality'] ?? '720p';

            if (!$fileId) jsonResponse(['error' => '缺少file_id'], 400);

            $file = db()->fetchOne('SELECT * FROM media_files WHERE id = ?', [$fileId]);
            if (!$file) jsonResponse(['error' => '文件不存在'], 404);

            $existing = db()->fetchOne(
                'SELECT * FROM transcode_jobs WHERE file_id = ? AND quality = ?',
                [$fileId, $quality]
            );
            if ($existing && $existing['status'] === 'completed') {
                jsonResponse(['success' => true, 'job' => $existing, 'message' => '已存在完成的转码']);
            }
            if ($existing && $existing['status'] === 'running') {
                jsonResponse(['success' => true, 'job' => $existing, 'message' => '转码进行中']);
            }

            $hlsDir = getSetting('hls_output_dir', sys_get_temp_dir() . '/nas_hls');
            $outputDir = $hlsDir . "/file_{$fileId}/{$quality}";

            if ($existing) {
                db()->update('transcode_jobs', [
                    'status'   => 'pending',
                    'progress' => 0,
                    'error_message' => null,
                ], 'id = ?', [$existing['id']]);
                $jobId = $existing['id'];
            } else {
                $jobId = db()->insert('transcode_jobs', [
                    'file_id'  => $fileId,
                    'quality'  => $quality,
                    'status'   => 'pending',
                    'progress' => 0,
                ]);
            }

            $ffmpeg = new FFmpeg();
            $ffmpeg->startTranscodeBackground($jobId, $file['file_path'], $quality, $outputDir);

            $job = db()->fetchOne('SELECT * FROM transcode_jobs WHERE id = ?', [$jobId]);
            jsonResponse(['success' => true, 'job' => $job]);
            break;

        case 'status':
            $jobId = (int)($_GET['job_id'] ?? 0);
            if (!$jobId) jsonResponse(['error' => '缺少job_id'], 400);

            $job = db()->fetchOne('SELECT * FROM transcode_jobs WHERE id = ?', [$jobId]);
            if (!$job) jsonResponse(['error' => '任务不存在'], 404);

            if ($job['status'] === 'running' && $job['pid']) {
                $pidExists = (bool) shell_exec("tasklist /FI \"PID eq {$job['pid']}\" 2>nul | find \"{$job['pid']}\"");
                if (!$pidExists) {
                    $completed = file_exists($job['hls_playlist']);
                    db()->update('transcode_jobs', [
                        'status'   => $completed ? 'completed' : 'failed',
                        'progress' => $completed ? 100 : 0,
                        'pid'      => null,
                    ], 'id = ?', [$jobId]);
                    $job = db()->fetchOne('SELECT * FROM transcode_jobs WHERE id = ?', [$jobId]);
                }
            }

            jsonResponse($job);
            break;

        case 'qualities':
            $fileId = (int)($_GET['file_id'] ?? 0);
            if (!$fileId) jsonResponse(['error' => '缺少file_id'], 400);

            $ffmpeg = new FFmpeg();
            $qualities = $ffmpeg->getAvailableQualities($fileId);
            jsonResponse($qualities);
            break;

        case 'hls':
            $fileId = (int)($_GET['file_id'] ?? 0);
            $quality = $_GET['quality'] ?? '';
            if (!$fileId || !$quality) jsonResponse(['error' => '参数不完整'], 400);

            $job = db()->fetchOne(
                'SELECT * FROM transcode_jobs WHERE file_id = ? AND quality = ? AND status = "completed"',
                [$fileId, $quality]
            );
            if (!$job || empty($job['hls_playlist'])) {
                jsonResponse(['error' => '转码未完成'], 404);
            }

            jsonResponse([
                'playlist' => '/api/transcode.php?action=playlist&file_id=' . $fileId . '&quality=' . $quality,
                'status'   => 'completed',
            ]);
            break;

        case 'playlist':
            $fileId = (int)($_GET['file_id'] ?? 0);
            $quality = $_GET['quality'] ?? '';

            $job = db()->fetchOne(
                'SELECT * FROM transcode_jobs WHERE file_id = ? AND quality = ? AND status = "completed"',
                [$fileId, $quality]
            );
            if (!$job || !file_exists($job['hls_playlist'])) {
                http_response_code(404);
                die('Not found');
            }

            header('Content-Type: application/vnd.apple.mpegurl');
            header('Cache-Control: no-cache');
            readfile($job['hls_playlist']);
            break;

        case 'segment':
            $fileId = (int)($_GET['file_id'] ?? 0);
            $quality = $_GET['quality'] ?? '';
            $segment = $_GET['seg'] ?? '';

            if (!$fileId || !$quality || !$segment) {
                http_response_code(400);
                die('Bad request');
            }

            $job = db()->fetchOne(
                'SELECT * FROM transcode_jobs WHERE file_id = ? AND quality = ? AND status = "completed"',
                [$fileId, $quality]
            );
            if (!$job) {
                http_response_code(404);
                die('Not found');
            }

            $segmentPath = dirname($job['hls_playlist']) . '/' . basename($segment);
            if (!file_exists($segmentPath)) {
                http_response_code(404);
                die('Segment not found');
            }

            header('Content-Type: video/mp2t');
            header('Cache-Control: max-age=86400');
            readfile($segmentPath);
            break;

        case 'cancel':
            if ($method !== 'POST') jsonResponse(['error' => '方法不允许'], 405);
            auth()->requireAdmin();

            $input = json_decode(file_get_contents('php://input'), true);
            $jobId = (int)($input['job_id'] ?? 0);

            $job = db()->fetchOne('SELECT * FROM transcode_jobs WHERE id = ?', [$jobId]);
            if (!$job) jsonResponse(['error' => '任务不存在'], 404);

            if ($job['status'] === 'running' && $job['pid']) {
                shell_exec("taskkill /F /PID {$job['pid']} 2>nul");
            }

            db()->update('transcode_jobs', ['status' => 'failed', 'error_message' => '用户取消', 'pid' => null], 'id = ?', [$jobId]);
            jsonResponse(['success' => true]);
            break;

        case 'multi_hls':
            $fileId = (int)($_GET['file_id'] ?? 0);
            if (!$fileId) jsonResponse(['error' => '缺少file_id'], 400);

            $file = db()->fetchOne('SELECT * FROM media_files WHERE id = ?', [$fileId]);
            if (!$file) jsonResponse(['error' => '文件不存在'], 404);

            $audioTracks = db()->fetchAll('SELECT * FROM audio_tracks WHERE file_id = ? ORDER BY stream_index', [$fileId]);
            if (count($audioTracks) <= 1) {
                jsonResponse(['playlist' => '/api/stream.php?id=' . $fileId, 'multi_track' => false]);
                break;
            }

            $hlsDir = getSetting('hls_output_dir', sys_get_temp_dir() . '/nas_hls');
            $outputDir = $hlsDir . "/multi_{$fileId}";
            if (!is_dir($outputDir)) mkdir($outputDir, 0755, true);

            $masterPath = $outputDir . '/master.m3u8';
            $videoPath = $outputDir . '/video.m3u8';
            $cacheKey = $outputDir . '/version.txt';
            $currentMtime = filemtime($file['file_path']);

            $cached = file_exists($cacheKey) ? (int)file_get_contents($cacheKey) : 0;
            $needsRebuild = $cached !== $currentMtime || !file_exists($masterPath) || !file_exists($videoPath);

            if ($needsRebuild) {
                $ffmpeg = new FFmpeg();
                $ffmpegCmd = sprintf(
                    '%s -y -i "%s" -map 0:v:0 -map 0:a? -c copy -hls_time 8 -hls_list_size 0 -hls_segment_filename "%s/video_%%d.ts" -f hls "%s"',
                    $ffmpeg->getFfmpegPath(),
                    addslashes($file['file_path']),
                    addslashes($outputDir),
                    addslashes($videoPath)
                );

                exec($ffmpegCmd . ' 2>&1', $out, $code);

                if ($code !== 0 || !file_exists($videoPath)) {
                    jsonResponse(['error' => 'HLS生成失败: ' . implode("\n", array_slice($out, -5))], 500);
                    break;
                }

                $master = "#EXTM3U\n#EXT-X-VERSION:3\n\n";
                $master .= "#EXT-X-STREAM-INF:BANDWIDTH=2000000,RESOLUTION=1920x1080\n";
                $master .= "video.m3u8\n";

                file_put_contents($masterPath, $master);
                file_put_contents($cacheKey, $currentMtime);

                $infoPath = $outputDir . '/tracks.json';
                file_put_contents($infoPath, json_encode(['audio' => $audioTracks]));
            }

            jsonResponse([
                'playlist' => '/api/transcode.php?action=multi_playlist&file_id=' . $fileId,
                'segment_prefix' => '/api/transcode.php?action=multi_segment&file_id=' . $fileId . '&seg=',
                'audio_tracks' => $audioTracks,
                'multi_track' => true,
            ]);
            break;

        case 'multi_playlist':
            $fileId = (int)($_GET['file_id'] ?? 0);

            $hlsDir = getSetting('hls_output_dir', sys_get_temp_dir() . '/nas_hls');
            $outputDir = $hlsDir . "/multi_{$fileId}";
            $path = $outputDir . '/master.m3u8';

            if (!file_exists($path)) {
                http_response_code(404);
                die('Playlist not found');
            }

            header('Content-Type: application/vnd.apple.mpegurl');
            header('Access-Control-Allow-Origin: *');
            header('Cache-Control: no-cache');
            readfile($path);
            break;

        case 'multi_segment':
            $fileId = (int)($_GET['file_id'] ?? 0);
            $seg = $_GET['seg'] ?? '';

            $hlsDir = getSetting('hls_output_dir', sys_get_temp_dir() . '/nas_hls');
            $outputDir = $hlsDir . "/multi_{$fileId}";
            $segPath = $outputDir . '/' . basename($seg);

            if (!file_exists($segPath)) {
                http_response_code(404);
                die('Segment not found');
            }

            header('Content-Type: video/mp2t');
            header('Access-Control-Allow-Origin: *');
            header('Cache-Control: max-age=86400');
            readfile($segPath);
            break;

        default:
            jsonResponse(['error' => '未知操作'], 400);
    }
} catch (Exception $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}
