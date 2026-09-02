-- Кто последним изменил пункт структуры заявки.
ALTER TABLE `application_structure_items`
  ADD COLUMN `updated_by` int NULL DEFAULT NULL AFTER `updated_at`;
