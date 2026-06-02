-- 迁移 v2: 添加弹幕表

CREATE TABLE IF NOT EXISTS `danmaku` (
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
