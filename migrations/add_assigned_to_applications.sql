-- Поле "Ответственный" в заявке: ID менеджера (ссылка на users).
-- NULL = не назначен. Заполняется только менеджерами при создании/редактировании заявки.
ALTER TABLE applications
    ADD COLUMN assigned_to INT NULL DEFAULT NULL,
    ADD KEY idx_applications_assigned_to (assigned_to);

-- Внешний ключ: ответственный должен быть пользователем (обычно role = 'manager')
ALTER TABLE applications
    ADD CONSTRAINT fk_applications_assigned_to
    FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL;
