-- Перевод старых заявок со статуса in_progress («В работе») на один из расширенных этапов воронки.
-- Выполняйте вручную и при необходимости поменяйте целевой статус в UPDATE.
--
-- Шаг 1 (только если в applications.status до сих пор старый ENUM без новых значений).
-- Если MySQL ругается на «Duplicate» или колонка уже VARCHAR — этот блок пропустите.

ALTER TABLE `applications` MODIFY COLUMN `status` ENUM(
  'new',
  'in_progress',
  'pending_signing',
  'product_request',
  'terms_negotiation',
  'pending_release',
  'completed',
  'failed'
) NOT NULL DEFAULT 'new';

-- Шаг 2: массово заменить «В работе» на нужный этап.
-- Выберите ОДНО целевое значение под ваш процесс:
--   product_request      — Запрос
--   terms_negotiation    — Согласование условий
--   pending_signing      — На подписании
--   pending_release      — На выпуске
-- По умолчанию ниже — product_request (часто первый «операционный» шаг после общего «в работе»).

UPDATE `applications`
SET `status` = 'product_request'
WHERE `status` = 'in_progress';

-- Если нужно оставить часть заявок в «В работе», сначала сузьте WHERE, например по дате:
-- UPDATE applications SET status = 'product_request'
-- WHERE status = 'in_progress' AND created_at < '2026-01-01';
