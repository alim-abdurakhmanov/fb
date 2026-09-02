-- Сохранённые фильтры/пресеты пользователя (JSON в TEXT для совместимости)
ALTER TABLE `users`
  ADD COLUMN `saved_filters` TEXT NULL DEFAULT NULL
    COMMENT 'JSON: сохранённые фильтры/пресеты пользователя (например applications)';

