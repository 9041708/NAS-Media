-- 迁移 v6: 添加管理员权限组
;

CREATE TABLE IF NOT EXISTS `user_groups` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `name` varchar(128) NOT NULL,
    `permissions` text DEFAULT NULL,
    `is_default` tinyint(1) NOT NULL DEFAULT 0,
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `user_groups` (`id`, `name`, `permissions`, `is_default`) VALUES
(3, '管理员', '{"can_see_all":1,"episode_limit":0,"movie_minutes_limit":0,"watch_can_host":true,"watch_can_join":true,"watch_max_guests":0}', 0);

UPDATE `users` SET `group_id` = 3 WHERE `role` = 'admin' AND `group_id` != 3;
