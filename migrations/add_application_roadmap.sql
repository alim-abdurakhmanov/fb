-- Дорожная карта заявки (чек-листы для руководителей).
CREATE TABLE IF NOT EXISTS `application_roadmap_groups` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `application_id` int NOT NULL,
  `title` varchar(255) NOT NULL,
  `group_key` varchar(32) DEFAULT NULL,
  `sort_order` int NOT NULL DEFAULT 0,
  `created_by` int UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_roadmap_groups_app` (`application_id`, `sort_order`),
  KEY `idx_roadmap_groups_key` (`application_id`, `group_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `application_roadmap_items` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `group_id` int UNSIGNED NOT NULL,
  `application_id` int NOT NULL,
  `title` varchar(500) NOT NULL,
  `is_done` tinyint(1) NOT NULL DEFAULT 0,
  `done_at` datetime DEFAULT NULL,
  `done_by` int UNSIGNED DEFAULT NULL,
  `sort_order` int NOT NULL DEFAULT 0,
  `created_by` int UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` int UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_roadmap_items_group` (`group_id`, `sort_order`),
  KEY `idx_roadmap_items_app` (`application_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
