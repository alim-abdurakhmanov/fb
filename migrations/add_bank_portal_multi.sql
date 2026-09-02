-- Мультибанковый ЛК: привязка пользователя банка к bank_code (noosfera, alfa, …).
ALTER TABLE `users`
  ADD COLUMN `bank_code` varchar(32) NULL DEFAULT NULL AFTER `company_name`;

UPDATE `users`
SET `bank_code` = 'noosfera'
WHERE `role` = 'bank'
  AND (`bank_code` IS NULL OR TRIM(`bank_code`) = '');

-- Пример: пользователь Альфа-Банка
-- INSERT INTO users (..., role, bank_code, company_name) VALUES (..., 'bank', 'alfa', 'Альфа-Банк');
