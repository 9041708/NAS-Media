<?php
class MediaScanner
{
    private Database $db;
    private TmdbApi $tmdb;
    private array $config;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->tmdb = new TmdbApi();
        $this->config = require __DIR__ . '/../config.php';
    }

    public function scanLibrary(int $libraryId): array
    {
        $library = $this->db->fetchOne('SELECT * FROM libraries WHERE id = ?', [$libraryId]);
        if (!$library) {
            throw new Exception("媒体库不存在: $libraryId");
        }

        $path = $library['path'];
        if (!is_dir($path)) {
            throw new Exception("目录不存在: $path");
        }

        $extensions = $this->config['video']['extensions'];
        $results = ['total' => 0, 'new' => 0, 'updated' => 0, 'errors' => []];

        $hasSeasonCols = false;
        try {
            $cols = $this->db->fetchColumn(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'media_files' AND COLUMN_NAME = 'season_number'"
            );
            $hasSeasonCols = (int)$cols > 0;
        } catch (\Throwable $e) {}

        $progressFile = $this->getProgressFile($libraryId);
        $this->writeProgress($progressFile, $libraryId, 'scanning', 0, 0, '');

        if ($library['type'] === 'tv') {
            $fileEntries = $this->scanTvDirectory($path, $extensions);
        } else {
            $rawFiles = $this->scanDirectory($path, $extensions);
            $fileEntries = [];
            foreach ($rawFiles as $filePath) {
                $fileEntries[] = ['path' => $filePath, 'showName' => '', 'seasonNum' => 0, 'episodeNum' => 0];
            }
        }

        $total = count($fileEntries);
        $results['total'] = $total;

        $this->writeProgress($progressFile, $libraryId, 'scanning', 0, $total, '');

        $this->db->beginTransaction();
        try {
            foreach ($fileEntries as $i => $entry) {
                $filePath = $entry['path'];
                $this->writeProgress($progressFile, $libraryId, 'scanning', $i + 1, $total, basename($filePath));

                try {
                    $existing = $this->db->fetchOne(
                        'SELECT id FROM media_files WHERE file_path = ?',
                        [$filePath]
                    );

                    if ($existing && $this->config['scan']['skip_existing']) {
                        continue;
                    }

                    $fileInfo = $this->getFileInfo($filePath);

                    if ($hasSeasonCols) {
                        $fileInfo['season_number'] = $entry['seasonNum'] > 0 ? $entry['seasonNum'] : 1;
                        $fileInfo['episode_number'] = $entry['episodeNum'];
                    }

                    if ($existing) {
                        $this->db->update('media_files', $fileInfo, 'id = ?', [$existing['id']]);
                        $results['updated']++;
                        try {
                            $this->saveStreamTracks($existing['id'], $filePath);
                        } catch (Exception $e) {
                        }
                    } else {
                        $fileInfo['library_id'] = $libraryId;
                        $fileId = $this->db->insert('media_files', $fileInfo);
                        $results['new']++;

                        if ($this->config['scan']['auto_metadata']) {
                            $this->fetchAndSaveMetadata($fileId, $fileInfo['file_name'], $library['type'], $entry['showName'], $entry['seasonNum']);
                        }

                        try {
                            $this->saveStreamTracks($fileId, $filePath);
                        } catch (Exception $e) {
                        }
                    }
                } catch (Exception $e) {
                    $results['errors'][] = $filePath . ': ' . $e->getMessage();
                }
            }

            $this->db->update('libraries', ['last_scan' => date('Y-m-d H:i:s')], 'id = ?', [$libraryId]);
            $this->db->commit();

            $this->writeProgress($progressFile, $libraryId, 'completed', $total, $total, '');
        } catch (Exception $e) {
            $this->db->rollBack();
            $this->writeProgress($progressFile, $libraryId, 'failed', 0, $total, '');
            throw $e;
        }

        return $results;
    }

    private function scanTvDirectory(string $dir, array $extensions): array
    {
        $entries = [];
        $showDirs = glob($dir . '/*', GLOB_ONLYDIR);
        if (empty($showDirs)) {
            $showDirs = [$dir];
        }

        foreach ($showDirs as $showDir) {
            if (!is_dir($showDir)) continue;
            $showName = basename($showDir);

            $seasonDirs = glob($showDir . '/*', GLOB_ONLYDIR);
            if (empty($seasonDirs)) {
                $files = $this->scanDirectoryFlat($showDir, $extensions);
                foreach ($files as $filePath) {
                    $epNum = $this->extractEpisodeNumber(basename($filePath));
                    $entries[] = ['path' => $filePath, 'showName' => $showName, 'seasonNum' => 1, 'episodeNum' => $epNum];
                }
            } else {
                foreach ($seasonDirs as $seasonDir) {
                    if (!is_dir($seasonDir)) continue;
                    $seasonNum = $this->extractSeasonNumber(basename($seasonDir));
                    $files = $this->scanDirectoryFlat($seasonDir, $extensions);
                    foreach ($files as $filePath) {
                        $epNum = $this->extractEpisodeNumber(basename($filePath));
                        $entries[] = ['path' => $filePath, 'showName' => $showName, 'seasonNum' => $seasonNum, 'episodeNum' => $epNum];
                    }
                }
            }
        }

        return $entries;
    }

    private function scanDirectoryFlat(string $dir, array $extensions): array
    {
        $files = [];
        $iterator = new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS);
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $ext = strtolower($file->getExtension());
                if (in_array($ext, $extensions)) {
                    $files[] = $file->getPathname();
                }
            }
        }
        sort($files);
        return $files;
    }

    private function extractSeasonNumber(string $folderName): int
    {
        $name = trim($folderName);
        if (preg_match('/season\s*(\d+)/i', $name, $m)) return (int) $m[1];
        if (preg_match('/^s(\d+)/i', $name, $m)) return (int) $m[1];
        if (preg_match('/第\s*(\d+)\s*季/', $name, $m)) return (int) $m[1];
        if (preg_match('/^(\d+)$/', $name, $m)) return (int) $m[1];
        if (preg_match('/番外|SP|special|specials|ova|oad|extras?|bonus|幕后|花絮/i', $name)) return 0;
        return 1;
    }

    private function extractEpisodeNumber(string $fileName): int
    {
        $name = pathinfo($fileName, PATHINFO_FILENAME);
        if (preg_match('/[Ee](\d{1,4})/', $name, $m)) return (int) $m[1];
        if (preg_match('/[Ee][Pp]\.?\s*(\d{1,4})/', $name, $m)) return (int) $m[1];
        if (preg_match('/第\s*(\d{1,4})\s*集/', $name, $m)) return (int) $m[1];
        if (preg_match('/第\s*(\d{1,4})\s*[话話]/', $name, $m)) return (int) $m[1];
        if (preg_match('/[\[\(（]\s*(\d{1,4})\s*[\]\)）]/', $name, $m)) return (int) $m[1];
        if (preg_match('/[-_\.]\s*(\d{1,4})\s*$/', $name, $m) && (int)$m[1] <= 200) return (int) $m[1];
        return 0;
    }

    private function getProgressFile(int $libraryId): string
    {
        $dir = __DIR__ . '/../cache';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        return $dir . '/scan_progress_' . $libraryId . '.json';
    }

    private function writeProgress(string $file, int $libraryId, string $status, int $current, int $total, string $fileName): void
    {
        $data = [
            'library_id' => $libraryId,
            'status'     => $status,
            'current'    => $current,
            'total'      => $total,
            'percent'    => $total > 0 ? min(100, round($current / $total * 100)) : 0,
            'file_name'  => $fileName,
            'updated_at' => time(),
        ];
        @file_put_contents($file, json_encode($data));
    }

    public static function getProgress(int $libraryId): ?array
    {
        $file = __DIR__ . '/../cache/scan_progress_' . $libraryId . '.json';
        if (!file_exists($file)) return null;
        $data = json_decode(file_get_contents($file), true);
        if (!$data) return null;
        if ($data['status'] === 'completed' || $data['status'] === 'failed') {
            if (time() - ($data['updated_at'] ?? 0) > 30) {
                @unlink($file);
                return null;
            }
        }
        return $data;
    }

    public function fetchAndSaveMetadata(int $fileId, string $fileName, string $type = 'movie', string $showName = '', int $seasonNum = 0): ?int
    {
        if ($type === 'tv' && !empty($showName)) {
            $title = $showName;
            $year = null;
            if (preg_match('/^(.*?)(?:\s*[\(\[（]\s*(19|20\d{2})\s*[\)\]）]|\s+(19|20\d{2})\s*)/u', $showName, $m)) {
                $title = trim($m[1]);
                $year = (int) ($m[2] ?: $m[3]);
            }
        } else {
            $parsed = $this->parseFileName($fileName);
            $title = $parsed['title'];
            $year = $parsed['year'];
        }

        if (empty($title)) {
            return null;
        }

        $existingMedia = $this->findExistingMedia($title, $year, $type);
        if ($existingMedia) {
            $this->db->update('media_files', ['media_id' => $existingMedia['id']], 'id = ?', [$fileId]);
            return $existingMedia['id'];
        }

        $tmdbResults = [];
        if ($type === 'tv') {
            $tmdbResults = $this->tmdb->searchTv($title);
        } else {
            $tmdbResults = $this->tmdb->searchMovie($title, $year);
        }

        if (empty($tmdbResults)) {
            $mediaId = $this->db->insert('media_items', [
                'title'   => $title,
                'year'    => $year,
                'type'    => $type,
                'overview'=> '',
            ]);
            $this->db->update('media_files', ['media_id' => $mediaId], 'id = ?', [$fileId]);
            return $mediaId;
        }

        $best = $tmdbResults[0];
        $details = null;
        if ($type === 'tv') {
            $details = $this->tmdb->getTvDetails($best['id']);
        } else {
            $details = $this->tmdb->getMovieDetails($best['id']);
        }

        $mediaData = $this->tmdb->formatMovieData($details ?? $best);
        if ($type === 'tv' && !empty($showName)) {
            $mediaData['title'] = $title;
        }
        $mediaId = $this->db->insert('media_items', $mediaData);
        $this->db->update('media_files', ['media_id' => $mediaId], 'id = ?', [$fileId]);

        return $mediaId;
    }

    public function parseFileName(string $fileName): array
    {
        $name = pathinfo($fileName, PATHINFO_FILENAME);
        $name = preg_replace('/[\.\-_]/', ' ', $name);

        $year = null;
        if (preg_match('/\b((?:19|20)\d{2})\b/', $name, $m)) {
            $year = (int) $m[1];
            $name = str_replace($m[0], '', $name);
        }

        $name = preg_replace('/\b(4K|2160p|1080p|720p|480p|BluRay|BRRip|DVDRip|WEBRip|HDRip|HDTV|WEB-DL|x264|x265|HEVC|AAC|FLAC|DTS|REMUX|PROPER|REPACK)\b/i', '', $name);
        $name = preg_replace('/\[[^\]]*\]/', '', $name);
        $name = preg_replace('/\{[^}]*\}/', '', $name);
        $name = preg_replace('/\s+/', ' ', trim($name));

        return [
            'title'        => trim($name),
            'clean_title'  => trim($name),
            'year'         => $year,
        ];
    }

    private function findExistingMedia(string $title, ?int $year, string $type): ?array
    {
        if ($year) {
            $result = $this->db->fetchOne(
                'SELECT * FROM media_items WHERE title = ? AND year = ? AND type = ?',
                [$title, $year, $type]
            );
            if ($result) return $result;
        }

        return $this->db->fetchOne(
            'SELECT * FROM media_items WHERE title = ? AND type = ?',
            [$title, $type]
        );
    }

    private function scanDirectory(string $dir, array $extensions): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $ext = strtolower($file->getExtension());
                if (in_array($ext, $extensions)) {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    private function getFileInfo(string $filePath): array
    {
        $stat = stat($filePath);
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        $info = [
            'file_path' => $filePath,
            'file_name' => basename($filePath),
            'file_size' => $stat['size'],
            'file_type' => $ext,
        ];

        $probe = $this->probeVideoFile($filePath);
        if ($probe) {
            $info = array_merge($info, $probe['basic'] ?? []);
        }

        return $info;
    }

    public function saveStreamTracks(int $fileId, string $filePath): void
    {
        $ffmpeg = new FFmpeg();
        $probe = $ffmpeg->probe($filePath);
        if (!$probe) return;

        $this->db->delete('audio_tracks', 'file_id = ?', [$fileId]);
        $this->db->delete('subtitle_tracks', 'file_id = ? AND source = "embedded"', [$fileId]);

        foreach ($probe['audio'] ?? [] as $i => $audio) {
            $this->db->insert('audio_tracks', [
                'file_id'        => $fileId,
                'stream_index'   => $audio['index'],
                'codec'          => $audio['codec'],
                'language'       => $audio['language'],
                'title'          => $audio['title'],
                'channels'       => $audio['channels'],
                'channel_layout' => $audio['channel_layout'],
                'sample_rate'    => $audio['sample_rate'],
                'bitrate'        => $audio['bitrate'],
                'is_default'     => $audio['is_default'],
            ]);
        }

        foreach ($probe['subtitle'] ?? [] as $sub) {
            $this->db->insert('subtitle_tracks', [
                'file_id'      => $fileId,
                'stream_index' => $sub['index'],
                'source'       => 'embedded',
                'codec'        => $sub['codec'],
                'language'     => $sub['language'],
                'title'        => $sub['title'],
                'is_default'   => $sub['is_default'],
            ]);
        }

        $this->scanExternalSubtitles($fileId, $filePath);
    }

    private function scanExternalSubtitles(int $fileId, string $filePath): void
    {
        $dir = dirname($filePath);
        $baseName = pathinfo($filePath, PATHINFO_FILENAME);
        $subExts = ['srt', 'ass', 'ssa', 'vtt', 'sub'];

        foreach ($subExts as $ext) {
            $patterns = [
                "$dir/$baseName.$ext",
                "$dir/$baseName.*.$ext",
                "$dir/subs/$baseName.$ext",
                "$dir/Subs/$baseName.$ext",
            ];
            foreach ($patterns as $pattern) {
                foreach (glob($pattern) as $subFile) {
                    if (!file_exists($subFile)) continue;
                    $existing = $this->db->fetchOne(
                        'SELECT id FROM subtitle_tracks WHERE file_id = ? AND file_path = ?',
                        [$fileId, $subFile]
                    );
                    if ($existing) continue;

                    $lang = $this->detectSubLanguage(basename($subFile));
                    $this->db->insert('subtitle_tracks', [
                        'file_id'   => $fileId,
                        'source'    => 'external',
                        'codec'     => $ext,
                        'language'  => $lang,
                        'title'     => basename($subFile),
                        'file_path' => $subFile,
                    ]);
                }
            }
        }
    }

    private function detectSubLanguage(string $filename): string
    {
        $patterns = [
            '/\b(zh|chi|chinese|chs|cht)\b/i' => 'zh',
            '/\b(en|eng|english)\b/i' => 'en',
            '/\b(ja|jpn|japanese)\b/i' => 'ja',
            '/\b(ko|kor|korean)\b/i' => 'ko',
        ];
        foreach ($patterns as $pattern => $lang) {
            if (preg_match($pattern, $filename)) return $lang;
        }
        return 'und';
    }

    private function probeVideoFile(string $filePath): ?array
    {
        require_once __DIR__ . '/FFmpegFinder.php';
        $ffprobe = getSetting('ffprobe_path', '') ?: (FFmpegFinder::findProbe() ?? 'ffprobe');
        $cmd = sprintf(
            '%s -v quiet -print_format json -show_format -show_streams "%s"',
            $ffprobe,
            addslashes($filePath)
        );

        $output = shell_exec($cmd);
        if (!$output) return null;

        $data = json_decode($output, true);
        if (!$data) return null;

        $info = [];
        if (!empty($data['format']['duration'])) {
            $info['duration'] = (int) round((float) $data['format']['duration']);
        }
        if (!empty($data['format']['bit_rate'])) {
            $info['bitrate'] = (int) $data['format']['bit_rate'];
        }

        foreach ($data['streams'] ?? [] as $stream) {
            if ($stream['codec_type'] === 'video' && empty($info['resolution'])) {
                $info['resolution'] = $stream['width'] . 'x' . $stream['height'];
                $info['codec'] = $stream['codec_name'] ?? null;
                break;
            }
        }

        return $info ? ['basic' => $info] : null;
    }

    public function refreshMetadata(int $mediaId): bool
    {
        $media = $this->db->fetchOne('SELECT * FROM media_items WHERE id = ?', [$mediaId]);
        if (!$media) throw new Exception('媒体不存在');

        $tmdbId = $media['tmdb_id'];
        if (!$tmdbId && !empty($media['title'])) {
            $results = $this->tmdb->searchMovie($media['title'], $media['year']);
            if (empty($results)) throw new Exception('TMDB搜索无结果: ' . $media['title']);
            $tmdbId = $results[0]['id'];
        }

        if (!$tmdbId) throw new Exception('无TMDB ID，无法刷新元数据');

        $details = $media['type'] === 'tv'
            ? $this->tmdb->getTvDetails($tmdbId)
            : $this->tmdb->getMovieDetails($tmdbId);

        if (!$details) throw new Exception('TMDB获取详情失败 (ID: ' . $tmdbId . ')');

        $data = $this->tmdb->formatMovieData($details);
        $this->db->update('media_items', $data, 'id = ?', [$mediaId]);

        return true;
    }
}
