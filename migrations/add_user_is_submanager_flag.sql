-- Ограниченная роль «Менеджер»: основная роль остаётся manager (полный «Руководитель»),
-- а is_submanager=1 помечает ограниченного менеджера.
ALTER TABLE `users`
  ADD COLUMN `is_submanager` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_analyst`;

-- Сделать существующего пользователя ограниченным менеджером:
-- UPDATE users SET role = 'manager', is_submanager = 1 WHERE id = <id>;
