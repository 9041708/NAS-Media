-- 迁移 v5: 一起看功能
;

CREATE TABLE IF NOT EXISTS `watch_rooms` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `code` varchar(8) NOT NULL,
    `host_user_id` int(11) NOT NULL,
    `file_id` int(11) NOT NULL,
    `current_time` decimal(10,2) NOT NULL DEFAULT 0.00,
    `is_playing` tinyint(1) NOT NULL DEFAULT 1,
    `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_code` (`code`),
    KEY `idx_host` (`host_user_id`),
    KEY `idx_file` (`file_id`),
    CONSTRAINT `fk_wr_host` FOREIGN KEY (`host_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_wr_file` FOREIGN KEY (`file_id`) REFERENCES `media_files` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `watch_room_members` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `room_id` int(11) NOT NULL,
    `user_id` int(11) NOT NULL,
    `joined_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_room_user` (`room_id`, `user_id`),
    KEY `idx_user` (`user_id`),
    CONSTRAINT `fk_wrm_room` FOREIGN KEY (`room_id`) REFERENCES `watch_rooms` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_wrm_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
