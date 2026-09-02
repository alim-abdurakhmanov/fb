-- Роль «Аналитик» + мягкое удаление пунктов структуры принципала.
ALTER TABLE `users`
  MODIFY COLUMN `role` ENUM('client','partner','manager','bank','analyst') NOT NULL DEFAULT 'client';

ALTER TABLE `application_structure_items`
  ADD COLUMN `deleted_at` datetime NULL DEFAULT NULL AFTER `updated_at`,
  ADD COLUMN `deleted_by` int NULL DEFAULT NULL AFTER `deleted_at`,
  ADD KEY `idx_structure_app_deleted` (`application_id`, `deleted_at`);
