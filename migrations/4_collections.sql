-- 迁移 v4: 添加合集表
;

CREATE TABLE IF NOT EXISTS `collections` (
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

CREATE TABLE IF NOT EXISTS `collection_items` (
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
