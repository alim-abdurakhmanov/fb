-- Уведомления по почте: согласие пользователя и опциональный адрес (если логин ≠ ящик)
ALTER TABLE `users`
  ADD COLUMN `email_notifications_enabled` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '1 — пользователь согласен получать письма с портала',
  ADD COLUMN `notification_email` VARCHAR(255) NULL DEFAULT NULL
    COMMENT 'Ящик для писем; если NULL — используется email учётной записи';
