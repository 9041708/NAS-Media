-- 迁移 v1: 初始化版本追踪
-- 为已有用户添加 db_version 设置项和 remote_access_enabled 设置项

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('db_version', '0');

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('remote_access_enabled', '0');
