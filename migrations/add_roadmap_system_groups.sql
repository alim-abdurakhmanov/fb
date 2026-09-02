-- Системные разделы дорожной карты и автор последнего изменения пункта.
ALTER TABLE `application_roadmap_groups`
  ADD COLUMN `group_key` varchar(32) DEFAULT NULL AFTER `title`,
  ADD KEY `idx_roadmap_groups_key` (`application_id`, `group_key`);

ALTER TABLE `application_roadmap_items`
  ADD COLUMN `updated_by` int UNSIGNED DEFAULT NULL AFTER `updated_at`;

UPDATE `application_roadmap_groups`
SET `group_key` = 'channels_banks'
WHERE `group_key` IS NULL AND `title` IN ('Отправка в банки', 'Отправка в каналы и банки');

UPDATE `application_roadmap_groups`
SET `group_key` = 'tasks'
WHERE `group_key` IS NULL AND `title` = 'Задачи';

UPDATE `application_roadmap_groups`
SET `title` = 'Отправка в каналы и банки'
WHERE `group_key` = 'channels_banks';
