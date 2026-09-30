-- Сгенерированные DOCX (Решение / Предложение / Договор) при статусе «БГ выдана».
-- Только для ЛК банка (доступ через kind=bank_bg).

CREATE TABLE IF NOT EXISTS `application_product_bank_case_generated_docs` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `bank_case_id` int UNSIGNED NOT NULL,
  `doc_type` varchar(32) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `file_size` int UNSIGNED NOT NULL DEFAULT 0,
  `file_type` varchar(50) NOT NULL DEFAULT 'word',
  `generated_by` int UNSIGNED NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_case_doc_type` (`bank_case_id`, `doc_type`),
  KEY `idx_bg_docs_case` (`bank_case_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
