-- Вложения к сообщениям чата «менеджер — банк» и к записям истории смены статуса (ЛК банка).

CREATE TABLE IF NOT EXISTS `application_product_bank_case_message_files` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `bank_case_message_id` int UNSIGNED NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `file_size` int UNSIGNED NOT NULL DEFAULT 0,
  `file_type` varchar(50) NOT NULL DEFAULT 'file',
  `uploaded_by` int UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bank_msg_file_msg` (`bank_case_message_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `application_product_bank_case_status_log_files` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `status_log_id` int UNSIGNED NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `file_size` int UNSIGNED NOT NULL DEFAULT 0,
  `file_type` varchar(50) NOT NULL DEFAULT 'file',
  `uploaded_by` int UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bank_status_log_file` (`status_log_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
