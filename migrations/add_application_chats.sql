-- Чат на уровне заявки (не зависит от продуктов).
-- thread: beneficiary = с заказчиком, principal = с клиентом/агентом.

CREATE TABLE IF NOT EXISTS `application_chats` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `application_id` int NOT NULL,
  `thread` varchar(16) NOT NULL DEFAULT 'principal',
  `user_id` int NOT NULL,
  `message` text,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ac_app_thread` (`application_id`, `thread`, `created_at`),
  KEY `idx_ac_unread` (`application_id`, `thread`, `is_read`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `application_chat_files` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `chat_message_id` int UNSIGNED NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `file_size` int NOT NULL DEFAULT 0,
  `file_type` varchar(32) NOT NULL DEFAULT 'file',
  `uploaded_by` int NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_acf_message` (`chat_message_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
