-- Запросы документов от банка менеджеру (слоты application_product_documents).
-- request_source: manager (по умолчанию) | bank
-- bank_case_id: кейс ЛК банка, из которого создан запрос

ALTER TABLE `application_product_documents`
  ADD COLUMN `request_source` varchar(16) NOT NULL DEFAULT 'manager' AFTER `created_by`,
  ADD COLUMN `bank_case_id` int UNSIGNED NULL DEFAULT NULL AFTER `request_source`;

ALTER TABLE `application_product_documents`
  ADD KEY `idx_apd_request_source` (`request_source`),
  ADD KEY `idx_apd_bank_case` (`bank_case_id`);
