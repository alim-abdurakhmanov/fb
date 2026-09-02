-- Доп. право «аналитик» для партнёров-исключений (основная роль остаётся partner).
ALTER TABLE `users`
  ADD COLUMN `is_analyst` TINYINT(1) NOT NULL DEFAULT 0 AFTER `role`;
