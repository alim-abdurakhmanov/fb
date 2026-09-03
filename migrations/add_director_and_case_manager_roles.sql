-- Три роли вместо manager + is_submanager + hardcoded director ids:
--   director      — руководитель (статистика + плашка «Руководитель»)
--   manager       — менеджер (полный доступ к заявкам/админке, без статистики)
--   case_manager  — менеджер по заявкам (только назначенные; бывший is_submanager=1)

ALTER TABLE `users`
  MODIFY COLUMN `role` ENUM(
    'client',
    'partner',
    'manager',
    'case_manager',
    'director',
    'bank',
    'analyst'
  ) NOT NULL DEFAULT 'client';

-- Руководители (раньше: id в FINBUILD_DIRECTOR_USER_IDS)
UPDATE `users`
SET `role` = 'director', `is_submanager` = 0
WHERE `id` IN (1, 23);

-- Менеджеры по заявкам (раньше: manager + is_submanager=1)
UPDATE `users`
SET `role` = 'case_manager'
WHERE `role` = 'manager' AND COALESCE(`is_submanager`, 0) = 1;

-- Остальные manager с is_submanager=0 остаются role=manager (полный доступ без статистики).
-- Колонку is_submanager оставляем для совместимости; логика опирается на role.
UPDATE `users`
SET `is_submanager` = CASE
  WHEN `role` = 'case_manager' THEN 1
  ELSE 0
END;
