-- Роль «Заказчик» (бенефициар) + поля intake-заявки + thread в чате продукта

ALTER TABLE `users`
  MODIFY COLUMN `role` ENUM(
    'client',
    'partner',
    'manager',
    'case_manager',
    'director',
    'bank',
    'analyst',
    'beneficiary'
  ) NOT NULL DEFAULT 'client';

ALTER TABLE `applications`
  ADD COLUMN `principal_inn` VARCHAR(12) DEFAULT NULL AFTER `customer_name`,
  ADD COLUMN `principal_company_name` VARCHAR(255) DEFAULT NULL AFTER `principal_inn`,
  ADD COLUMN `principal_user_id` INT UNSIGNED DEFAULT NULL AFTER `principal_company_name`,
  ADD COLUMN `amount_mode` VARCHAR(16) NOT NULL DEFAULT 'fixed' AFTER `amount`,
  ADD COLUMN `requested_amount` DECIMAL(15,2) DEFAULT NULL AFTER `amount_mode`,
  ADD COLUMN `approved_amount` DECIMAL(15,2) DEFAULT NULL AFTER `requested_amount`,
  ADD COLUMN `approved_limit` DECIMAL(15,2) DEFAULT NULL AFTER `approved_amount`,
  ADD COLUMN `intake_status` VARCHAR(32) DEFAULT NULL AFTER `approved_limit`,
  ADD COLUMN `intake_reviewed_at` DATETIME DEFAULT NULL AFTER `intake_status`,
  ADD COLUMN `intake_reviewed_by` INT UNSIGNED DEFAULT NULL AFTER `intake_reviewed_at`;

ALTER TABLE `applications`
  ADD KEY `idx_applications_principal_user` (`principal_user_id`),
  ADD KEY `idx_applications_intake_status` (`intake_status`);

ALTER TABLE `application_product_chats`
  ADD COLUMN `thread` VARCHAR(16) NOT NULL DEFAULT 'principal' AFTER `application_product_id`;

ALTER TABLE `application_product_chats`
  ADD KEY `idx_apc_product_thread` (`application_product_id`, `thread`);
