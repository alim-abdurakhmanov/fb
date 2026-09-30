-- Портал Камкомбанка: пользователь ЛК + сброс methodology.view у менеджеров в сохранённой матрице прав.
-- Логин: bank@kamcom.local
-- Пароль: KamcomBank2026!

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
  'bank@kamcom.local',
  '$2y$10$eGzEc7.kBBialac3CThNxOp7/fszFTe84czEqueEHtFr4l3iD4sGW',
  '',
  'Камкомбанк',
  'ЛК',
  '',
  'Камкомбанк',
  'kamcom',
  'bank',
  CURDATE(),
  1,
  1
WHERE NOT EXISTS (
  SELECT 1 FROM `users` WHERE `email` = 'bank@kamcom.local' LIMIT 1
);

-- На случай, если пользователь уже был без bank_code
UPDATE `users`
SET `bank_code` = 'kamcom',
    `company_name` = IF(TRIM(COALESCE(`company_name`, '')) = '', 'Камкомбанк', `company_name`),
    `role` = 'bank',
    `is_active` = 1
WHERE `email` = 'bank@kamcom.local';
