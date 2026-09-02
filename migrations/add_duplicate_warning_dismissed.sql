-- Предупреждение о возможном дубле заявки (БГ): скрывается кнопкой «Это не дубль»
ALTER TABLE applications
ADD COLUMN duplicate_warning_dismissed TINYINT(1) NOT NULL DEFAULT 0;
