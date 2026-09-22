-- Нечистая БД: таблица уже создана со старой колонкой bank_case_id.
-- Перенос на application_id (оценка по заявке, без привязки к кейсу банка).
-- Безопасно гонять повторно: шаги с IF / information_schema.

-- 1) application_id, если ещё нет
SET @has_app := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'bank_case_methodology_assessments'
    AND COLUMN_NAME = 'application_id'
);
SET @sql := IF(@has_app = 0,
  'ALTER TABLE bank_case_methodology_assessments ADD COLUMN `application_id` int UNSIGNED NULL AFTER `id`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2) проставить application_id из банковского кейса (если колонка bank_case_id ещё есть)
SET @has_case := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'bank_case_methodology_assessments'
    AND COLUMN_NAME = 'bank_case_id'
);
SET @sql := IF(@has_case > 0,
  'UPDATE bank_case_methodology_assessments a
   INNER JOIN application_product_bank_cases c ON c.id = a.bank_case_id
   INNER JOIN application_products ap ON ap.id = c.application_product_id
   SET a.application_id = ap.application_id
   WHERE a.application_id IS NULL OR a.application_id = 0',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3) строки без заявки
DELETE FROM bank_case_methodology_assessments
WHERE application_id IS NULL OR application_id = 0;

-- 4) application_id обязателен
ALTER TABLE bank_case_methodology_assessments
  MODIFY `application_id` int UNSIGNED NOT NULL;

-- 5) старые индексы по bank_case_id
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'bank_case_methodology_assessments'
     AND INDEX_NAME = 'uniq_case_version') > 0,
  'ALTER TABLE bank_case_methodology_assessments DROP INDEX `uniq_case_version`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'bank_case_methodology_assessments'
     AND INDEX_NAME = 'idx_case_status') > 0,
  'ALTER TABLE bank_case_methodology_assessments DROP INDEX `idx_case_status`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'bank_case_methodology_assessments'
     AND INDEX_NAME = 'idx_case_updated') > 0,
  'ALTER TABLE bank_case_methodology_assessments DROP INDEX `idx_case_updated`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 6) убрать bank_case_id
SET @has_case := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'bank_case_methodology_assessments'
    AND COLUMN_NAME = 'bank_case_id'
);
SET @sql := IF(@has_case > 0,
  'ALTER TABLE bank_case_methodology_assessments DROP COLUMN `bank_case_id`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 7) индексы по application_id
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'bank_case_methodology_assessments'
     AND INDEX_NAME = 'uniq_app_version') = 0,
  'ALTER TABLE bank_case_methodology_assessments ADD UNIQUE KEY `uniq_app_version` (`application_id`, `version`)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'bank_case_methodology_assessments'
     AND INDEX_NAME = 'idx_app_status') = 0,
  'ALTER TABLE bank_case_methodology_assessments ADD KEY `idx_app_status` (`application_id`, `status`)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'bank_case_methodology_assessments'
     AND INDEX_NAME = 'idx_app_updated') = 0,
  'ALTER TABLE bank_case_methodology_assessments ADD KEY `idx_app_updated` (`application_id`, `updated_at`)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
