-- Оценка по внутрибанковской методике экспресс-БГ (отдельно от FinScore).
-- Одна версия на кейс; draft обновляется in-place, final фиксируется.

CREATE TABLE IF NOT EXISTS `bank_case_methodology_assessments` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `bank_case_id` int UNSIGNED NOT NULL,
  `version` int UNSIGNED NOT NULL DEFAULT 1,
  `status` varchar(20) NOT NULL DEFAULT 'draft',
  `state_json` longtext NOT NULL,
  `result_json` longtext NULL,
  `total_score` decimal(8,2) NULL,
  `rating` varchar(16) NULL,
  `position_code` varchar(16) NULL,
  `hard_stop` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` int UNSIGNED NOT NULL,
  `updated_by` int UNSIGNED NULL,
  `finalized_by` int UNSIGNED NULL,
  `finalized_at` datetime NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_case_version` (`bank_case_id`, `version`),
  KEY `idx_case_status` (`bank_case_id`, `status`),
  KEY `idx_case_updated` (`bank_case_id`, `updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
