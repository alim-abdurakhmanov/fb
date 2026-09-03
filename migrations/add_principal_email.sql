-- Email принципала для intake-заявок от заказчика
ALTER TABLE `applications`
  ADD COLUMN `principal_email` VARCHAR(255) DEFAULT NULL AFTER `principal_company_name`;
