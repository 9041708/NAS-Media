<?php
class FFmpegFinder
{
    private static array $commonPaths = [
        // SynoCommunity
        '/usr/local/bin/ffmpeg',
        '/usr/local/bin/ffprobe',
        // Entware / opkg
        '/opt/bin/ffmpeg',
        '/opt/bin/ffprobe',
        // DSM 6 旧版自带
        '/usr/bin/ffmpeg',
        '/usr/bin/ffprobe',
        // Video Station 附带
        '/var/packages/VideoStation/target/bin/ffmpeg',
        // 群晖 MediaServer 套件
        '/var/packages/MediaServer/target/bin/ffmpeg',
        // Docker 内
        '/usr/lib/jellyfin-ffmpeg/ffmpeg',
        '/usr/lib/emby-server/bin/ffmpeg',
        // 常见 Linux 发行版
        '/snap/bin/ffmpeg',
        '/usr/local/ffmpeg/bin/ffmpeg',
    ];

    public static function find(): ?string
    {
        $cached = self::getCached('ffmpeg');
        if ($cached !== null) return file_exists($cached) ? $cached : null;

        foreach (self::$commonPaths as $path) {
            if (strpos($path, 'ffprobe') !== false) continue;
            if (file_exists($path) && is_executable($path)) {
                self::setCached('ffmpeg', $path);
                return $path;
            }
        }

        $which = trim(shell_exec('which ffmpeg 2>/dev/null') ?? '');
        if ($which && file_exists($which)) {
            self::setCached('ffmpeg', $which);
            return $which;
        }

        $where = trim(shell_exec('where ffmpeg 2>nul') ?? '');
        if ($where) {
            $first = explode("\n", $where)[0] ?? '';
            $first = trim($first);
            if ($first && file_exists($first)) {
                self::setCached('ffmpeg', $first);
                return $first;
            }
        }

        return null;
    }

    public static function findProbe(): ?string
    {
        $cached = self::getCached('ffprobe');
        if ($cached !== null) return file_exists($cached) ? $cached : null;

        foreach (self::$commonPaths as $path) {
            if (strpos($path, 'ffprobe') === false) continue;
            if (file_exists($path) && is_executable($path)) {
                self::setCached('ffprobe', $path);
                return $path;
            }
        }

        $which = trim(shell_exec('which ffprobe 2>/dev/null') ?? '');
        if ($which && file_exists($which)) {
            self::setCached('ffprobe', $which);
            return $which;
        }

        $where = trim(shell_exec('where ffprobe 2>nul') ?? '');
        if ($where) {
            $first = explode("\n", $where)[0] ?? '';
            $first = trim($first);
            if ($first && file_exists($first)) {
                self::setCached('ffprobe', $first);
                return $first;
            }
        }

        $ffmpeg = self::find();
        if ($ffmpeg) {
            $dir = dirname($ffmpeg);
            $probePath = $dir . '/ffprobe';
            if (file_exists($probePath)) {
                self::setCached('ffprobe', $probePath);
                return $probePath;
            }
        }

        return null;
    }

    public static function isAvailable(): bool
    {
        return self::find() !== null && self::findProbe() !== null;
    }

    public static function getVersion(): ?string
    {
        $ffmpeg = self::find();
        if (!$ffmpeg) return null;
        $output = shell_exec("$ffmpeg -version 2>&1");
        if (preg_match('/ffmpeg version (\S+)/', $output, $m)) {
            return $m[1];
        }
        return null;
    }

    public static function autoSave(): void
    {
        $ffmpeg = self::find();
        $ffprobe = self::findProbe();

        if ($ffmpeg && empty(getSetting('ffmpeg_path', ''))) {
            setSetting('ffmpeg_path', $ffmpeg);
        }
        if ($ffprobe && empty(getSetting('ffprobe_path', ''))) {
            setSetting('ffprobe_path', $ffprobe);
        }
    }

    private static function getCached(string $key): ?string
    {
        $cacheFile = __DIR__ . '/../cache/ffpath_' . $key . '.txt';
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 86400) {
            $val = trim(file_get_contents($cacheFile));
            return $val ?: null;
        }
        return null;
    }

    private static function setCached(string $key, string $path): void
    {
        $cacheFile = __DIR__ . '/../cache/ffpath_' . $key . '.txt';
        if (!is_dir(__DIR__ . '/../cache')) {
            mkdir(__DIR__ . '/../cache', 0755, true);
        }
        file_put_contents($cacheFile, $path);
    }
}
