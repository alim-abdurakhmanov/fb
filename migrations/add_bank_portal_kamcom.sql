-- ЛК банка (пока Держава): кейсы по позиции продукта в заявке, состав пакета документов, чат, история статусов.
-- 1) Расширить роль пользователя
ALTER TABLE `users`
  MODIFY COLUMN `role` ENUM('client','partner','manager','bank') NOT NULL DEFAULT 'client';

-- 2) Кейс «работа с банком» (один на application_product)
CREATE TABLE IF NOT EXISTS `application_product_bank_cases` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `application_product_id` int UNSIGNED NOT NULL,
  `bank_code` varchar(32) NOT NULL DEFAULT 'derzhava',
  `status` varchar(40) NOT NULL DEFAULT 'draft',
  `manager_comment` text NULL,
  `submitted_by` int UNSIGNED NULL,
  `submitted_at` datetime NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_ap_bank_case` (`application_product_id`),
  KEY `idx_bank_cases_status` (`status`, `bank_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) Позиции в пакете (документы заявки или запросы по продукту)
CREATE TABLE IF NOT EXISTS `application_product_bank_case_items` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `bank_case_id` int UNSIGNED NOT NULL,
  `item_type` enum('application_document','product_document') NOT NULL,
  `ref_id` int UNSIGNED NOT NULL,
  `sort_order` int NOT NULL DEFAULT 0,
  `excluded` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_case_items_case` (`bank_case_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4) Файлы, добавленные менеджером вручную в пакет
CREATE TABLE IF NOT EXISTS `application_product_bank_case_uploads` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `bank_case_id` int UNSIGNED NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text NULL,
  `file_path` varchar(500) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `file_size` int UNSIGNED NOT NULL DEFAULT 0,
  `file_type` varchar(50) NOT NULL DEFAULT 'file',
  `uploaded_by` int UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bank_uploads_case` (`bank_case_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5) Чат менеджер — банк
CREATE TABLE IF NOT EXISTS `application_product_bank_case_messages` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `bank_case_id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `message` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bank_msg_case` (`bank_case_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6) История смены статуса (с комментарием банка)
CREATE TABLE IF NOT EXISTS `application_product_bank_case_status_log` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `bank_case_id` int UNSIGNED NOT NULL,
  `old_status` varchar(40) NOT NULL,
  `new_status` varchar(40) NOT NULL,
  `comment` text NULL,
  `changed_by` int UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bank_status_case` (`bank_case_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
