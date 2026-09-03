-- Настройки системы (матрица прав и описания ролей).
CREATE TABLE IF NOT EXISTS `system_settings` (
  `setting_key` varchar(64) NOT NULL,
  `setting_value` longtext NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
