-- Структура заявки: преимущества, стоп-факторы, примечания (списки пунктов).
CREATE TABLE IF NOT EXISTS `application_structure_items` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `application_id` int NOT NULL,
  `section` enum('advantages','stop_factors','notes') NOT NULL,
  `content` text NOT NULL,
  `sort_order` int NOT NULL DEFAULT 0,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_structure_app_section` (`application_id`, `section`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
