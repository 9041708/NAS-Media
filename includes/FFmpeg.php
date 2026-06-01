<?php
class FFmpeg
{
    private string $ffmpegPath;
    private string $ffprobePath;
    private string $hlsOutputDir;
    private int $segmentDuration;

    public function __construct()
    {
        require_once __DIR__ . '/FFmpegFinder.php';

        $this->ffmpegPath  = getSetting('ffmpeg_path', '') ?: (FFmpegFinder::find() ?? 'ffmpeg');
        $this->ffprobePath = getSetting('ffprobe_path', '') ?: (FFmpegFinder::findProbe() ?? 'ffprobe');
        $this->hlsOutputDir = getSetting('hls_output_dir', '');
        if (empty($this->hlsOutputDir)) {
            $this->hlsOutputDir = sys_get_temp_dir() . '/nas_hls';
        }
        $this->segmentDuration = (int) getSetting('hls_segment_duration', '6');

        if (!is_dir($this->hlsOutputDir)) {
            mkdir($this->hlsOutputDir, 0755, true);
        }

        if (!getSetting('ffmpeg_path', '')) {
            FFmpegFinder::autoSave();
        }
    }

    public function getFfmpegPath(): string
    {
        return $this->ffmpegPath;
    }

    public function getFfprobePath(): string
    {
        return $this->ffprobePath;
    }

    public function probe(string $filePath): ?array
    {
        $cmd = sprintf(
            '%s -v quiet -print_format json -show_format -show_streams -show_chapters "%s"',
            $this->ffprobePath,
            addslashes($filePath)
        );

        $output = shell_exec($cmd);
        if (!$output) return null;

        $data = json_decode($output, true);
        if (!$data) return null;

        $result = [
            'format'   => [],
            'video'    => [],
            'audio'    => [],
            'subtitle' => [],
            'chapters' => [],
        ];

        if (!empty($data['format'])) {
            $f = $data['format'];
            $result['format'] = [
                'duration' => (float) ($f['duration'] ?? 0),
                'size'     => (int) ($f['size'] ?? 0),
                'bitrate'  => (int) ($f['bit_rate'] ?? 0),
                'format'   => $f['format_name'] ?? '',
            ];
        }

        foreach ($data['streams'] ?? [] as $stream) {
            $type = $stream['codec_type'] ?? '';
            $base = [
                'index'     => $stream['index'],
                'codec'     => $stream['codec_name'] ?? '',
                'codec_long'=> $stream['codec_long_name'] ?? '',
            ];

            $tags = $stream['tags'] ?? [];
            $lang = $tags['language'] ?? $tags['LANGUAGE'] ?? '';
            $title = $tags['title'] ?? $tags['TITLE'] ?? '';

            if ($type === 'video') {
                $result['video'][] = array_merge($base, [
                    'width'       => $stream['width'] ?? 0,
                    'height'      => $stream['height'] ?? 0,
                    'fps'         => $this->parseFps($stream['r_frame_rate'] ?? ''),
                    'bitrate'     => (int) ($stream['bit_rate'] ?? 0),
                    'profile'     => $stream['profile'] ?? '',
                    'pix_fmt'     => $stream['pix_fmt'] ?? '',
                    'is_default'  => ($stream['disposition']['default'] ?? 0) ? 1 : 0,
                ]);
            } elseif ($type === 'audio') {
                $result['audio'][] = array_merge($base, [
                    'language'      => $lang,
                    'title'         => $title,
                    'channels'      => $stream['channels'] ?? 0,
                    'channel_layout'=> $stream['channel_layout'] ?? '',
                    'sample_rate'   => (int) ($stream['sample_rate'] ?? 0),
                    'bitrate'       => (int) ($stream['bit_rate'] ?? 0),
                    'is_default'    => ($stream['disposition']['default'] ?? 0) ? 1 : 0,
                ]);
            } elseif ($type === 'subtitle') {
                $result['subtitle'][] = array_merge($base, [
                    'language'   => $lang,
                    'title'      => $title,
                    'is_default' => ($stream['disposition']['default'] ?? 0) ? 1 : 0,
                ]);
            }
        }

        foreach ($data['chapters'] ?? [] as $ch) {
            $result['chapters'][] = [
                'start'     => (float) ($ch['start_time'] ?? 0),
                'end'       => (float) ($ch['end_time'] ?? 0),
                'title'     => $ch['tags']['title'] ?? '',
            ];
        }

        return $result;
    }

    public function extractSubtitle(string $filePath, int $streamIndex, string $outputPath): bool
    {
        $cmd = sprintf(
            '%s -y -i "%s" -map 0:%d -c:s srt "%s" 2>&1',
            $this->ffmpegPath,
            addslashes($filePath),
            $streamIndex,
            addslashes($outputPath)
        );

        exec($cmd, $output, $returnCode);
        return $returnCode === 0 && file_exists($outputPath);
    }

    public function startTranscode(int $jobId, string $filePath, string $quality, string $outputDir): bool
    {
        $dimensions = $this->getQualityDimensions($quality);
        if (!$dimensions) return false;

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $playlistPath = $outputDir . '/playlist.m3u8';

        $cmd = sprintf(
            '%s -y -i "%s" ' .
            '-map 0:v:0 -map 0:a:0? ' .
            '-c:v libx264 -preset medium -crf 23 ' .
            '-vf "scale=%d:%d:force_original_aspect_ratio=decrease,pad=%d:%d:(ow-iw)/2:(oh-ih)/2" ' .
            '-c:a aac -ac 2 -ab 128k ' .
            '-hls_time %d -hls_list_size 0 -hls_segment_filename "%s/seg_%%04d.ts" ' .
            '-f hls "%s" ' .
            '-progress pipe:1 2>/dev/null',
            $this->ffmpegPath,
            addslashes($filePath),
            $dimensions['w'], $dimensions['h'],
            $dimensions['w'], $dimensions['h'],
            $this->segmentDuration,
            addslashes($outputDir),
            addslashes($playlistPath)
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) return false;

        $pid = (int) proc_get_status($process)['pid'];

        db()->update('transcode_jobs', [
            'status'   => 'running',
            'pid'      => $pid,
            'output_path'   => $outputDir,
            'hls_playlist'  => $playlistPath,
        ], 'id = ?', [$jobId]);

        $duration = $this->getDuration($filePath);
        $this->readProgress($pipes[1], $jobId, $duration);

        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $status = proc_get_status($process);
        proc_close($process);

        $success = !$status['running'] && $status['exitcode'] === 0;

        db()->update('transcode_jobs', [
            'status'    => $success ? 'completed' : 'failed',
            'progress'  => $success ? 100 : 0,
            'pid'       => null,
        ], 'id = ?', [$jobId]);

        return $success;
    }

    public function startTranscodeBackground(int $jobId, string $filePath, string $quality, string $outputDir): void
    {
        $dimensions = $this->getQualityDimensions($quality);
        if (!$dimensions) return;

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $playlistPath = $outputDir . '/playlist.m3u8';
        $logFile = $outputDir . '/transcode.log';

        $cmd = sprintf(
            'nohup %s -y -i "%s" ' .
            '-map 0:v:0 -map 0:a:0? ' .
            '-c:v libx264 -preset medium -crf 23 ' .
            '-vf "scale=%d:%d:force_original_aspect_ratio=decrease,pad=%d:%d:(ow-iw)/2:(oh-ih)/2" ' .
            '-c:a aac -ac 2 -ab 128k ' .
            '-hls_time %d -hls_list_size 0 -hls_segment_filename "%s/seg_%%04d.ts" ' .
            '-f hls "%s" > "%s" 2>&1 & echo $!',
            $this->ffmpegPath,
            addslashes($filePath),
            $dimensions['w'], $dimensions['h'],
            $dimensions['w'], $dimensions['h'],
            $this->segmentDuration,
            addslashes($outputDir),
            addslashes($playlistPath),
            addslashes($logFile)
        );

        $pid = (int) trim(shell_exec($cmd) ?? '0');

        if ($pid > 0) {
            db()->update('transcode_jobs', [
                'status'       => 'running',
                'pid'          => $pid,
                'output_path'  => $outputDir,
                'hls_playlist' => $playlistPath,
            ], 'id = ?', [$jobId]);
        }
    }

    public function getTranscodeStatus(int $jobId): ?array
    {
        return db()->fetchOne('SELECT * FROM transcode_jobs WHERE id = ?', [$jobId]);
    }

    public function getAvailableQualities(int $fileId): array
    {
        $file = db()->fetchOne('SELECT * FROM media_files WHERE id = ?', [$fileId]);
        if (!$file) return [];

        $sourceHeight = 0;
        if ($file['resolution'] && preg_match('/x(\d+)/', $file['resolution'], $m)) {
            $sourceHeight = (int) $m[1];
        }

        $allQualities = [
            '360p'  => 360,
            '480p'  => 480,
            '720p'  => 720,
            '1080p' => 1080,
        ];

        $available = [];
        foreach ($allQualities as $label => $height) {
            if ($height <= $sourceHeight || $sourceHeight === 0) {
                $job = db()->fetchOne(
                    'SELECT * FROM transcode_jobs WHERE file_id = ? AND quality = ?',
                    [$fileId, $label]
                );
                $available[] = [
                    'label'    => $label,
                    'height'   => $height,
                    'status'   => $job['status'] ?? 'none',
                    'progress' => $job['progress'] ?? 0,
                    'job_id'   => $job['id'] ?? null,
                    'hls'      => ($job && $job['status'] === 'completed') ? $job['hls_playlist'] : null,
                ];
            }
        }

        $available[] = [
            'label'  => '原画',
            'height' => $sourceHeight,
            'status' => 'completed',
            'hls'    => null,
        ];

        return $available;
    }

    public function detectSilenceSegments(string $filePath, float $minSilenceDuration = 2.0): array
    {
        $cmd = sprintf(
            '%s -i "%s" -af silencedetect=noise=-30dB:d=%f -f null - 2>&1',
            $this->ffmpegPath,
            addslashes($filePath),
            $minSilenceDuration
        );

        $output = shell_exec($cmd);
        if (!$output) return [];

        $segments = [];
        $silenceStart = null;

        foreach (explode("\n", $output) as $line) {
            if (preg_match('/silence_start:\s*([\d.]+)/', $line, $m)) {
                $silenceStart = (float) $m[1];
            }
            if (preg_match('/silence_end:\s*([\d.]+)/', $line, $m) && $silenceStart !== null) {
                $silenceEnd = (float) $m[1];
                $segments[] = ['start' => $silenceStart, 'end' => $silenceEnd];
                $silenceStart = null;
            }
        }

        return $segments;
    }

    public function generateVttThumbnail(string $filePath, string $outputPath, int $interval = 10): bool
    {
        $cmd = sprintf(
            '%s -y -i "%s" -vf "fps=1/%d,scale=160:-1" -c:v mjpeg -q:v 5 "%s" 2>/dev/null',
            $this->ffmpegPath,
            addslashes($filePath),
            $interval,
            addslashes($outputPath)
        );

        exec($cmd, $output, $returnCode);
        return $returnCode === 0;
    }

    private function readProgress($pipe, int $jobId, float $totalDuration): void
    {
        $buffer = '';
        while (!feof($pipe)) {
            $char = fgetc($pipe);
            if ($char === "\n") {
                if (preg_match('/out_time_ms=(\d+)/', $buffer, $m) && $totalDuration > 0) {
                    $currentSec = (int) $m[1] / 1000000;
                    $progress = min(100, (int) ($currentSec / $totalDuration * 100));
                    db()->update('transcode_jobs', ['progress' => $progress], 'id = ?', [$jobId]);
                }
                $buffer = '';
            } else {
                $buffer .= $char;
            }
        }
    }

    private function getDuration(string $filePath): float
    {
        $cmd = sprintf(
            '%s -v quiet -show_entries format=duration -of csv=p=0 "%s"',
            $this->ffprobePath,
            addslashes($filePath)
        );
        $output = trim(shell_exec($cmd) ?? '0');
        return (float) $output;
    }

    private function parseFps(string $fpsStr): float
    {
        if (preg_match('/(\d+)\/(\d+)/', $fpsStr, $m)) {
            return $m[2] > 0 ? round($m[1] / $m[2], 2) : 0;
        }
        return (float) $fpsStr;
    }

    private function getQualityDimensions(string $quality): ?array
    {
        $map = [
            '360p'  => ['w' => 640,  'h' => 360],
            '480p'  => ['w' => 854,  'h' => 480],
            '720p'  => ['w' => 1280, 'h' => 720],
            '1080p' => ['w' => 1920, 'h' => 1080],
        ];
        return $map[$quality] ?? null;
    }
}
