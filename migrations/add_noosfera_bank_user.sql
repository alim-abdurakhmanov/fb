-- Тестовый пользователь ЛК банка Ноосфера.
-- Логин: bank@noosfera.local
-- Пароль: NoosferaBank2026!

INSERT INTO `users` (
  `email`,
  `password`,
  `inn`,
  `first_name`,
  `last_name`,
  `phone`,
  `company_name`,
  `bank_code`,
  `role`,
  `registration_date`,
  `is_active`,
  `email_notifications_enabled`
)
SELECT
  'bank@noosfera.local',
  '$2y$10$3JiB/wIIOxeh9cZtImgE.uBh4tqLSO.hGvwEs/Dgzfcx2LNlXh3SC',
  '',
  'Ноосфера',
  'ЛК',
  '',
  'Ноосфера',
  'noosfera',
  'bank',
  CURDATE(),
  1,
  1
WHERE NOT EXISTS (
  SELECT 1 FROM `users` WHERE `email` = 'bank@noosfera.local' LIMIT 1
);

-- На случай, если пользователь уже был без bank_code / с другим порталом
UPDATE `users`
SET `bank_code` = 'noosfera',
    `company_name` = IF(TRIM(COALESCE(`company_name`, '')) = '', 'Ноосфера', `company_name`),
    `role` = 'bank',
    `is_active` = 1
WHERE `email` = 'bank@noosfera.local';
