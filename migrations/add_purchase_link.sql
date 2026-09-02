-- Ссылка на карточку закупки/контракта в ЕИС (отдельно от номера, для кликабельной ссылки)
ALTER TABLE `applications`
  ADD COLUMN `purchase_link` VARCHAR(1024) NULL DEFAULT NULL AFTER `purchase_number`;
