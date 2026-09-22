-- Если уже применяли старую схему с bank_case_id — перенос на application_id.
-- На чистой установке достаточно add_bank_methodology_assessments.sql (уже с application_id).
-- Код также мигрирует схему автоматически при первом обращении к API.

SET @has_case := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'bank_case_methodology_assessments'
    AND COLUMN_NAME = 'bank_case_id'
);
SET @has_app := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'bank_case_methodology_assessments'
    AND COLUMN_NAME = 'application_id'
);

SET @sql := IF(@has_case > 0 AND @has_app = 0,
  'ALTER TABLE bank_case_methodology_assessments ADD COLUMN `application_id` int UNSIGNED NULL AFTER `id`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE bank_case_methodology_assessments a
INNER JOIN application_product_bank_cases c ON c.id = a.bank_case_id
INNER JOIN application_products ap ON ap.id = c.application_product_id
SET a.application_id = ap.application_id
WHERE a.application_id IS NULL
  AND EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'bank_case_methodology_assessments'
      AND COLUMN_NAME = 'bank_case_id'
  );

-- Дальнейшие DROP/MODIFY безопаснее через PHP ensure_table при первом запросе,
-- либо вручную после проверки данных:
-- DELETE FROM bank_case_methodology_assessments WHERE application_id IS NULL;
-- ALTER TABLE bank_case_methodology_assessments MODIFY application_id int UNSIGNED NOT NULL;
-- ALTER TABLE bank_case_methodology_assessments DROP INDEX uniq_case_version;
-- ALTER TABLE bank_case_methodology_assessments DROP COLUMN bank_case_id;
-- ALTER TABLE bank_case_methodology_assessments ADD UNIQUE KEY uniq_app_version (application_id, version);
