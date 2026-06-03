-- 迁移 v3: 性能索引优化
-- media_items: 排序和过滤索引
ALTER TABLE `media_items` ADD INDEX `idx_created_at` (`created_at`);
ALTER TABLE `media_items` ADD INDEX `idx_rating` (`rating`);
ALTER TABLE `media_items` ADD INDEX `idx_play_count` (`play_count`);
ALTER TABLE `media_items` ADD INDEX `idx_type_created` (`type`, `created_at`);
ALTER TABLE `media_items` ADD INDEX `idx_type_rating` (`type`, `rating`);
ALTER TABLE `media_items` ADD FULLTEXT INDEX `idx_title_ft` (`title`);
ALTER TABLE `media_items` ADD FULLTEXT INDEX `idx_cast_ft` (`cast_list`);

-- play_history: 排序和筛选索引
ALTER TABLE `play_history` ADD INDEX `idx_played_at` (`played_at`);
ALTER TABLE `play_history` ADD INDEX `idx_file_id` (`file_id`);
ALTER TABLE `play_history` ADD INDEX `idx_user_completed` (`user_id`, `media_id`, `completed`);
ALTER TABLE `play_history` ADD INDEX `idx_user_played` (`user_id`, `media_id`, `played_at`);
