-- Если уже применяли старую схему с bank_case_id — перенос на application_id.
-- На чистой установке достаточно add_bank_methodology_assessments.sql (уже с application_id).
-- Код мигрирует схему автоматически при первом обращении к API (в т.ч. если остались ОБЕ колонки).

-- Добавить application_id, если его ещё нет:
-- ALTER TABLE bank_case_methodology_assessments ADD COLUMN `application_id` int UNSIGNED NULL AFTER `id`;

-- Проставить application_id из кейса:
-- UPDATE bank_case_methodology_assessments a
-- INNER JOIN application_product_bank_cases c ON c.id = a.bank_case_id
-- INNER JOIN application_products ap ON ap.id = c.application_product_id
-- SET a.application_id = ap.application_id
-- WHERE a.application_id IS NULL OR a.application_id = 0;

-- DELETE FROM bank_case_methodology_assessments WHERE application_id IS NULL OR application_id = 0;
-- ALTER TABLE bank_case_methodology_assessments MODIFY application_id int UNSIGNED NOT NULL;
-- ALTER TABLE bank_case_methodology_assessments DROP INDEX uniq_case_version;
-- ALTER TABLE bank_case_methodology_assessments DROP COLUMN bank_case_id;
-- ALTER TABLE bank_case_methodology_assessments ADD UNIQUE KEY uniq_app_version (application_id, version);
