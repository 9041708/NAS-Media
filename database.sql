-- NAS Media Server Database Schema
-- 兼容 MySQL 5.7+ / MariaDB 10.3+
-- Version: 5.0

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS `nas_media` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `nas_media`;

-- ============================================================
-- 1. 系统配置表
-- ============================================================
DROP TABLE IF EXISTS `settings`;
CREATE TABLE `settings` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `setting_key` varchar(100) NOT NULL,
    `setting_value` text,
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 2. 媒体库（视频文件夹）表
-- ============================================================
DROP TABLE IF EXISTS `libraries`;
CREATE TABLE `libraries` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `name` varchar(255) NOT NULL,
    `path` varchar(1024) NOT NULL,
    `type` enum('movie','tv','other') NOT NULL DEFAULT 'movie',
    `enabled` tinyint(1) NOT NULL DEFAULT 1,
    `sort_order` int(11) NOT NULL DEFAULT 0,
    `last_scan` timestamp NULL DEFAULT NULL,
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 3. 用户权限组
-- ============================================================
DROP TABLE IF EXISTS `user_groups`;
CREATE TABLE `user_groups` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `name` varchar(128) NOT NULL,
    `permissions` text DEFAULT NULL COMMENT 'JSON: {"can_see_all":1,"episode_limit":0,"movie_minutes_limit":0}',
    `is_default` tinyint(1) NOT NULL DEFAULT 0,
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 4. 用户表
-- ============================================================
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `username` varchar(100) NOT NULL,
    `password` varchar(255) NOT NULL,
    `display_name` varchar(100) DEFAULT NULL,
    `email` varchar(255) DEFAULT NULL,
    `avatar` varchar(512) DEFAULT NULL,
    `role` enum('admin','user') NOT NULL DEFAULT 'user',
    `group_id` int(11) NOT NULL DEFAULT 1,
    `language` varchar(10) DEFAULT 'zh-CN',
    `subtitle_pref` varchar(20) DEFAULT 'zh',
    `audio_pref` varchar(20) DEFAULT 'zh',
    `quality_pref` varchar(20) DEFAULT 'auto',
    `speed_pref` decimal(3,2) DEFAULT 1.00,
    `last_login` timestamp NULL DEFAULT NULL,
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_username` (`username`),
    INDEX `idx_group` (`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 5. 媒体信息表（电影/剧集元数据）
-- ============================================================
DROP TABLE IF EXISTS `media_items`;
CREATE TABLE `media_items` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `tmdb_id` int(11) DEFAULT NULL,
    `imdb_id` varchar(20) DEFAULT NULL,
    `douban_id` varchar(20) DEFAULT NULL,
    `title` varchar(512) NOT NULL,
    `original_title` varchar(512) DEFAULT NULL,
    `year` int(4) DEFAULT NULL,
    `type` enum('movie','tv','other') NOT NULL DEFAULT 'movie',
    `vip_only` tinyint(1) NOT NULL DEFAULT 0,
    `overview` text,
    `poster_path` varchar(512) DEFAULT NULL,
    `backdrop_path` varchar(512) DEFAULT NULL,
    `rating` decimal(3,1) DEFAULT NULL,
    `vote_count` int(11) DEFAULT 0,
    `genres` varchar(512) DEFAULT NULL,
    `release_date` date DEFAULT NULL,
    `runtime` int(11) DEFAULT NULL,
    `language` varchar(20) DEFAULT NULL,
    `country` varchar(100) DEFAULT NULL,
    `director` varchar(255) DEFAULT NULL,
    `cast_list` text,
    `tags` varchar(512) DEFAULT NULL,
    `play_count` int(11) NOT NULL DEFAULT 0,
    `last_played` timestamp NULL DEFAULT NULL,
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_tmdb` (`tmdb_id`),
    KEY `idx_imdb` (`imdb_id`),
    KEY `idx_douban` (`douban_id`),
    KEY `idx_type` (`type`),
    KEY `idx_vip` (`vip_only`),
    KEY `idx_title` (`title`(191)),
    KEY `idx_year` (`year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 6. 视频文件表
-- ============================================================
DROP TABLE IF EXISTS `media_files`;
CREATE TABLE `media_files` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `library_id` int(11) NOT NULL,
    `file_path` varchar(1024) NOT NULL,
    `file_name` varchar(512) NOT NULL,
    `file_size` bigint(20) NOT NULL DEFAULT 0,
    `file_type` varchar(50) DEFAULT NULL,
    `duration` int(11) DEFAULT NULL,
    `resolution` varchar(20) DEFAULT NULL,
    `codec` varchar(50) DEFAULT NULL,
    `bitrate` bigint(20) DEFAULT NULL,
    `media_id` int(11) DEFAULT NULL,
    `season_number` int(11) NOT NULL DEFAULT 1,
    `episode_number` int(11) NOT NULL DEFAULT 0,
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_library` (`library_id`),
    KEY `idx_media` (`media_id`),
    KEY `idx_season_ep` (`media_id`, `season_number`, `episode_number`),
    KEY `idx_path` (`file_path`(191)),
    CONSTRAINT `fk_files_library` FOREIGN KEY (`library_id`) REFERENCES `libraries` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_files_media` FOREIGN KEY (`media_id`) REFERENCES `media_items` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 7. 播放记录表
-- ============================================================
DROP TABLE IF EXISTS `play_history`;
CREATE TABLE `play_history` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `user_id` int(11) NOT NULL,
    `media_id` int(11) NOT NULL,
    `file_id` int(11) NOT NULL,
    `position` int(11) NOT NULL DEFAULT 0,
    `duration` int(11) NOT NULL DEFAULT 0,
    `completed` tinyint(1) NOT NULL DEFAULT 0,
    `played_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user` (`user_id`),
    KEY `idx_media` (`media_id`),
    CONSTRAINT `fk_history_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_history_media` FOREIGN KEY (`media_id`) REFERENCES `media_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 8. 收藏表
-- ============================================================
DROP TABLE IF EXISTS `favorites`;
CREATE TABLE `favorites` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `user_id` int(11) NOT NULL,
    `media_id` int(11) NOT NULL,
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_user_media` (`user_id`, `media_id`),
    CONSTRAINT `fk_fav_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_fav_media` FOREIGN KEY (`media_id`) REFERENCES `media_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 9. 音频轨信息表
-- ============================================================
DROP TABLE IF EXISTS `audio_tracks`;
CREATE TABLE `audio_tracks` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `file_id` int(11) NOT NULL,
    `stream_index` int(11) NOT NULL,
    `codec` varchar(50) DEFAULT NULL,
    `language` varchar(20) DEFAULT NULL,
    `title` varchar(255) DEFAULT NULL,
    `channels` int(11) DEFAULT NULL,
    `channel_layout` varchar(50) DEFAULT NULL,
    `sample_rate` int(11) DEFAULT NULL,
    `bitrate` int(11) DEFAULT NULL,
    `is_default` tinyint(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_file` (`file_id`),
    CONSTRAINT `fk_audio_file` FOREIGN KEY (`file_id`) REFERENCES `media_files` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 10. 字幕轨信息表
-- ============================================================
DROP TABLE IF EXISTS `subtitle_tracks`;
CREATE TABLE `subtitle_tracks` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `file_id` int(11) NOT NULL,
    `stream_index` int(11) DEFAULT NULL,
    `source` enum('embedded','external') NOT NULL DEFAULT 'embedded',
    `codec` varchar(50) DEFAULT NULL,
    `language` varchar(20) DEFAULT NULL,
    `title` varchar(255) DEFAULT NULL,
    `file_path` varchar(1024) DEFAULT NULL,
    `is_default` tinyint(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_file` (`file_id`),
    CONSTRAINT `fk_sub_file` FOREIGN KEY (`file_id`) REFERENCES `media_files` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 11. 转码任务表
-- ============================================================
DROP TABLE IF EXISTS `transcode_jobs`;
CREATE TABLE `transcode_jobs` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `file_id` int(11) NOT NULL,
    `quality` varchar(20) NOT NULL,
    `status` enum('pending','running','completed','failed') NOT NULL DEFAULT 'pending',
    `progress` int(3) NOT NULL DEFAULT 0,
    `output_path` varchar(1024) DEFAULT NULL,
    `hls_playlist` varchar(1024) DEFAULT NULL,
    `error_message` text DEFAULT NULL,
    `pid` int(11) DEFAULT NULL,
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_file_quality` (`file_id`, `quality`),
    KEY `idx_status` (`status`),
    CONSTRAINT `fk_transcode_file` FOREIGN KEY (`file_id`) REFERENCES `media_files` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 12. 片头片尾时间表
-- ============================================================
DROP TABLE IF EXISTS `skip_segments`;
CREATE TABLE `skip_segments` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `media_id` int(11) NOT NULL,
    `type` enum('intro','outro','recap','preview') NOT NULL DEFAULT 'intro',
    `start_time` int(11) NOT NULL DEFAULT 0,
    `end_time` int(11) NOT NULL DEFAULT 0,
    `auto_detected` tinyint(1) NOT NULL DEFAULT 0,
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_media` (`media_id`),
    CONSTRAINT `fk_skip_media` FOREIGN KEY (`media_id`) REFERENCES `media_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 13. 合集表
-- ============================================================
DROP TABLE IF EXISTS `collections`;
CREATE TABLE `collections` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `name` varchar(512) NOT NULL,
    `overview` text DEFAULT NULL,
    `poster_path` varchar(512) DEFAULT NULL,
    `user_id` int(11) NOT NULL,
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user` (`user_id`),
    CONSTRAINT `fk_collection_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `collection_items`;
CREATE TABLE `collection_items` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `collection_id` int(11) NOT NULL,
    `media_id` int(11) NOT NULL,
    `sort_order` int(11) NOT NULL DEFAULT 0,
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_coll_media` (`collection_id`, `media_id`),
    CONSTRAINT `fk_ci_collection` FOREIGN KEY (`collection_id`) REFERENCES `collections` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ci_media` FOREIGN KEY (`media_id`) REFERENCES `media_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 14. 活跃播放会话表
-- ============================================================
DROP TABLE IF EXISTS `active_sessions`;
CREATE TABLE `active_sessions` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `user_id` int(11) NOT NULL,
    `media_id` int(11) DEFAULT NULL,
    `file_id` int(11) DEFAULT NULL,
    `media_title` varchar(512) DEFAULT NULL,
    `file_name` varchar(512) DEFAULT NULL,
    `poster_path` varchar(512) DEFAULT NULL,
    `position` int(11) NOT NULL DEFAULT 0,
    `duration` int(11) NOT NULL DEFAULT 0,
    `device` varchar(255) DEFAULT NULL,
    `ip_address` varchar(45) DEFAULT NULL,
    `last_heartbeat` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `started_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_user` (`user_id`),
    KEY `idx_heartbeat` (`last_heartbeat`),
    CONSTRAINT `fk_session_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 15. 通知消息表
-- ============================================================
DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `target_user_id` int(11) DEFAULT NULL COMMENT 'NULL=全局广播',
    `from_user_id` int(11) NOT NULL,
    `message` text NOT NULL,
    `type` enum('info','warning','success') NOT NULL DEFAULT 'info',
    `delivered` tinyint(1) NOT NULL DEFAULT 0,
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_target` (`target_user_id`),
    KEY `idx_delivered` (`delivered`),
    KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 16. 弹幕表
-- ============================================================
DROP TABLE IF EXISTS `danmaku`;
CREATE TABLE `danmaku` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `file_id` int(11) NOT NULL,
    `user_id` int(11) NOT NULL,
    `content` varchar(500) NOT NULL,
    `time_pos` decimal(10,2) NOT NULL COMMENT '弹幕出现时间(秒)',
    `color` varchar(7) DEFAULT '#ffffff',
    `type` enum('scroll','top','bottom') DEFAULT 'scroll',
    `font_size` tinyint(3) DEFAULT 18,
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_file_time` (`file_id`, `time_pos`),
    KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 17. 密码重置令牌表
-- ============================================================
DROP TABLE IF EXISTS `password_resets`;
CREATE TABLE `password_resets` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `user_id` int(11) NOT NULL,
    `token` varchar(64) NOT NULL,
    `expires_at` timestamp NOT NULL,
    `used` tinyint(1) NOT NULL DEFAULT 0,
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_token` (`token`),
    KEY `idx_user` (`user_id`),
    CONSTRAINT `fk_reset_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 默认数据
-- ============================================================

-- 默认权限组
INSERT INTO `user_groups` (`id`, `name`, `permissions`, `is_default`) VALUES
(1, '普通用户', '{"can_see_all":1,"episode_limit":0,"movie_minutes_limit":0}', 1),
(2, 'VIP用户', '{"can_see_all":1,"episode_limit":0,"movie_minutes_limit":0}', 0);

-- 默认系统设置
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
('site_name', 'NAS影库'),
('tmdb_api_key', ''),
('poster_lang', 'zh-CN'),
('theme', 'dark'),
('allow_register', '1'),
('remote_access_enabled', '0'),
('default_user_group', '1'),
('scan_interval', '3600'),
('db_version', '2'),
('ffmpeg_path', 'ffmpeg'),
('ffprobe_path', 'ffprobe'),
('transcode_enabled', '0'),
('hls_segment_duration', '6'),
('hls_output_dir', ''),
('auto_skip_intro', '0'),
('default_intro_duration', '90'),
('default_outro_duration', '60'),
('smtp_host', ''),
('smtp_port', '465'),
('smtp_encryption', 'ssl'),
('smtp_username', ''),
('smtp_password', ''),
('smtp_from_email', ''),
('smtp_from_name', 'NAS影视库'),
('smtp_enabled', '0'),
('allow_password_reset', '1'),
('require_email_register', '1');

SET FOREIGN_KEY_CHECKS = 1;
