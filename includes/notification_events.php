<?php
/**
 * События для e-mail уведомлений.
 * Все вызовы безопасны при отключённой почте: внутри проверяются флаги и адреса.
 */

declare(strict_types=1);

require_once __DIR__ . '/mail.php';

function finbuild_site_base_url(): string
{
    if (defined('SITE_BASE_URL') && SITE_BASE_URL !== '') {
        return rtrim((string) SITE_BASE_URL, '/');
    }
    if (!empty($_SERVER['HTTP_HOST'])) {
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $scheme = $https ? 'https' : 'http';
        return $scheme . '://' . $_SERVER['HTTP_HOST'];
    }
    return '';
}

function finbuild_application_status_label(string $code): string
{
    $map = [
        'new' => 'На проверке',
        'in_progress' => 'В работе',
        'pending_signing' => 'На подписании',
        'product_request' => 'Запрос',
        'terms_negotiation' => 'Согласование условий',
        'pending_release' => 'На выпуске',
        'completed' => 'Завершена',
        'failed' => 'Провалена',
    ];
    return $map[$code] ?? $code;
}

/**
 * Адрес для доставки уведомлений: активный пользователь, toggle и поле notification_email.
 */
function finbuild_user_notification_email(PDO $pdo, int $userId): ?string
{
    $stmt = $pdo->prepare(
        'SELECT email, notification_email, email_notifications_enabled FROM users WHERE id = ? AND is_active = 1 LIMIT 1'
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || empty($row['email_notifications_enabled'])) {
        return null;
    }
    $addr = trim((string) ($row['notification_email'] ?? ''));
    if ($addr === '') {
        $addr = (string) $row['email'];
    }
    if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) {
        return null;
    }
    return $addr;
}

/**
 * Ящик пользователя без проверки тогла — чтобы не слать письмо самому себе (сравнение адресов).
 */
function finbuild_user_mailbox_address(PDO $pdo, int $userId): ?string
{
    $stmt = $pdo->prepare('SELECT email, notification_email FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $addr = trim((string) ($row['notification_email'] ?? ''));
    if ($addr === '') {
        $addr = (string) $row['email'];
    }
    return filter_var($addr, FILTER_VALIDATE_EMAIL) ? $addr : null;
}

/**
 * Адреса из MAIL_MANAGER_FALLBACK (через запятую/точку с запятой/ пробел).
 */
function finbuild_manager_fallback_emails(): array
{
    if (!defined('MAIL_MANAGER_FALLBACK') || trim((string) MAIL_MANAGER_FALLBACK) === '') {
        return [];
    }
    $raw = preg_split('/[\s,;]+/', (string) MAIL_MANAGER_FALLBACK, -1, PREG_SPLIT_NO_EMPTY);
    $out = [];
    foreach ($raw as $e) {
        if (filter_var($e, FILTER_VALIDATE_EMAIL)) {
            $out[] = $e;
        }
    }
    return array_unique($out);
}

/**
 * Обёртка для смены статуса заявки из updateApplicationStatus(): уведомляет владельца заявки (created_by).
 */
function finbuild_apply_application_status_change(
    PDO $pdo,
    int $applicationId,
    string $oldStatus,
    string $newStatus,
    bool $notifyOwner = true
): string {
    if ($oldStatus === $newStatus) {
        return $newStatus;
    }
    $stmt = $pdo->prepare('UPDATE applications SET status = ? WHERE id = ?');
    $stmt->execute([$newStatus, $applicationId]);
    if ($notifyOwner) {
        notify_application_owner_status_changed($pdo, $applicationId, $oldStatus, $newStatus);
    }
    return $newStatus;
}

/**
 * Владелец заявки (клиент/партнёр): смена статуса заявки на уровне applications.
 */
function notify_application_owner_status_changed(PDO $pdo, int $applicationId, string $oldStatus, string $newStatus): void
{
    $stmt = $pdo->prepare('SELECT id, company_name, created_by FROM applications WHERE id = ?');
    $stmt->execute([$applicationId]);
    $app = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$app || empty($app['created_by'])) {
        return;
    }
    $email = finbuild_user_notification_email($pdo, (int) $app['created_by']);
    if (!$email) {
        return;
    }
    $base = finbuild_site_base_url();
    $link = $base !== '' ? $base . '/application_details.php?id=' . $applicationId : '';
    $title = 'Заявка №' . $applicationId . ': ' . finbuild_application_status_label($newStatus);
    $html = '<p>Статус заявки по компании <strong>' . htmlspecialchars((string) $app['company_name']) . '</strong> изменён.</p>';
    $html .= '<p>Было: <strong>' . htmlspecialchars(finbuild_application_status_label($oldStatus)) . '</strong><br>';
    $html .= 'Стало: <strong>' . htmlspecialchars(finbuild_application_status_label($newStatus)) . '</strong></p>';
    if ($link !== '') {
        $html .= '<p><a href="' . htmlspecialchars($link) . '">Открыть заявку</a></p>';
    }
    finbuild_send_mail($email, $title, $html);
}

/**
 * Новая заявка: только если заявку создал сам клиент/партнёр (added_by пуст) и нет ответственного (assigned_to пуст).
 * Тогда — запасные адреса MAIL_MANAGER_FALLBACK. Заявки, оформленные менеджером, этим письмом не уведомляем.
 */
function notify_new_application_managers(PDO $pdo, int $applicationId): void
{
    $stmt = $pdo->prepare('SELECT id, company_name, assigned_to, added_by FROM applications WHERE id = ?');
    $stmt->execute([$applicationId]);
    $app = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$app) {
        return;
    }
    if (!empty($app['assigned_to'])) {
        return;
    }
    if (!empty($app['added_by'])) {
        return;
    }
    $recipients = finbuild_manager_fallback_emails();
    if (empty($recipients)) {
        return;
    }
    $base = finbuild_site_base_url();
    $link = $base !== '' ? $base . '/application_details.php?id=' . $applicationId : '';
    $title = 'Новая заявка №' . $applicationId;
    $html = '<p>Создана заявка по компании <strong>' . htmlspecialchars((string) $app['company_name']) . '</strong>.</p>';
    if ($link !== '') {
        $html .= '<p><a href="' . htmlspecialchars($link) . '">Открыть заявку</a></p>';
    }
    foreach ($recipients as $to) {
        finbuild_send_mail($to, $title, $html);
    }
}

/**
 * Менеджеру назначили заявку (assigned_to установлен или сменился).
 * Не шлём, если ящик ответственного совпадает с любым из MAIL_MANAGER_FALLBACK — чтобы не дублировать с другими рассылками.
 */
function notify_manager_assigned(PDO $pdo, int $applicationId, int $managerUserId): void
{
    $email = finbuild_user_notification_email($pdo, $managerUserId);
    if (!$email) {
        return;
    }
    foreach (finbuild_manager_fallback_emails() as $fallbackAddr) {
        if (strcasecmp($email, $fallbackAddr) === 0) {
            return;
        }
    }
    $stmt = $pdo->prepare('SELECT company_name FROM applications WHERE id = ?');
    $stmt->execute([$applicationId]);
    $app = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$app) {
        return;
    }
    $base = finbuild_site_base_url();
    $link = $base !== '' ? $base . '/application_details.php?id=' . $applicationId : '';
    $title = 'Вам назначена заявка №' . $applicationId;
    $html = '<p>Вам назначена заявка по компании <strong>' . htmlspecialchars((string) $app['company_name']) . '</strong>.</p>';
    if ($link !== '') {
        $html .= '<p><a href="' . htmlspecialchars($link) . '">Открыть заявку</a></p>';
    }
    finbuild_send_mail($email, $title, $html);
}

/**
 * Сообщение в чате продукта: менеджеру (клиент написал) или владельцу заявки (менеджер написал).
 */
function notify_intake_review_result(
    PDO $pdo,
    int $applicationId,
    string $action,
    int $principalUserId,
    bool $createdClient,
    string $plainPassword = ''
): void {
    $stmt = $pdo->prepare('SELECT id, created_by, company_name, approved_amount, approved_limit, amount FROM applications WHERE id = ? LIMIT 1');
    $stmt->execute([$applicationId]);
    $app = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$app) {
        return;
    }
    $base = finbuild_site_base_url();
    $link = $base !== '' ? $base . '/application_details.php?id=' . $applicationId : '';
    $company = htmlspecialchars((string) ($app['company_name'] ?? ''));

    if ($action === 'reject') {
        $ownerId = (int) $app['created_by'];
        $email = finbuild_user_notification_email($pdo, $ownerId);
        if ($email) {
            $html = '<p>Запрос на банковскую гарантию по заявке №' . $applicationId . ' отклонён.</p>';
            if ($link !== '') {
                $html .= '<p><a href="' . htmlspecialchars($link) . '">Открыть заявку</a></p>';
            }
            finbuild_send_mail($email, 'Заявка №' . $applicationId . ' отклонена', $html);
        }
        return;
    }

    $sumText = '';
    if (!empty($app['approved_limit'])) {
        $sumText = 'установлен лимит ' . number_format((float) $app['approved_limit'], 0, '.', ' ') . ' ₽';
    } elseif (!empty($app['approved_amount'])) {
        $sumText = 'одобрена сумма ' . number_format((float) $app['approved_amount'], 0, '.', ' ') . ' ₽';
    } elseif (!empty($app['amount'])) {
        $sumText = 'одобрена сумма ' . number_format((float) $app['amount'], 0, '.', ' ') . ' ₽';
    }

    $ownerId = (int) $app['created_by'];
    $ownerEmail = finbuild_user_notification_email($pdo, $ownerId);
    if ($ownerEmail) {
        $html = '<p>Ваш запрос по заявке №' . $applicationId . ' одобрен'
            . ($sumText !== '' ? ': ' . htmlspecialchars($sumText) : '')
            . '.</p>';
        if ($company !== '') {
            $html .= '<p>Принципал: <strong>' . $company . '</strong></p>';
        }
        if ($link !== '') {
            $html .= '<p><a href="' . htmlspecialchars($link) . '">Открыть заявку</a></p>';
        }
        finbuild_send_mail($ownerEmail, 'Заявка №' . $applicationId . ' одобрена', $html);
    }

    if ($principalUserId > 0) {
        $clientEmail = finbuild_user_notification_email($pdo, $principalUserId);
        if ($clientEmail) {
            $html = '<p>Вам открыт доступ к заявке №' . $applicationId
                . ($company !== '' ? ' (' . $company . ')' : '')
                . '.</p>';
            if ($sumText !== '') {
                $html .= '<p>' . htmlspecialchars(ucfirst($sumText)) . '.</p>';
            }
            if ($createdClient && $plainPassword !== '') {
                $html .= '<p>Логин: ваш e-mail<br>Временный пароль: <strong>' . htmlspecialchars($plainPassword) . '</strong></p>';
            }
            if ($link !== '') {
                $html .= '<p><a href="' . htmlspecialchars($link) . '">Открыть заявку</a></p>';
            }
            finbuild_send_mail($clientEmail, 'Доступ к заявке №' . $applicationId, $html);
        }
    }
}

/**
 * Сообщение в чате продукта: менеджеру (клиент написал) или владельцу заявки (менеджер написал).
 */
function notify_product_chat_message(
    PDO $pdo,
    int $applicationProductId,
    int $senderUserId,
    string $messageText,
    bool $hasAttachments
): void {
    $stmt = $pdo->prepare(
        'SELECT ap.application_id, ap.bank_name, a.created_by, a.assigned_to, a.company_name, u.role AS sender_role
         FROM application_products ap
         INNER JOIN applications a ON ap.application_id = a.id
         INNER JOIN users u ON u.id = ?
         WHERE ap.id = ?'
    );
    $stmt->execute([$senderUserId, $applicationProductId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return;
    }
    $senderRole = $row['sender_role'] ?? '';
    $applicationId = (int) $row['application_id'];
    $ownerId = (int) $row['created_by'];
    $assignedTo = isset($row['assigned_to']) ? (int) $row['assigned_to'] : 0;
    $companyName = trim((string) ($row['company_name'] ?? ''));
    $bankName = trim((string) ($row['bank_name'] ?? ''));
    $subject = 'Новое сообщение по заявке №' . $applicationId;
    if ($companyName !== '') {
        $subject .= ' (' . $companyName . ')';
    }

    $preview = trim($messageText);
    if ($preview === '' && $hasAttachments) {
        $preview = '(прикреплён файл)';
    }
    if (mb_strlen($preview) > 300) {
        $preview = mb_substr($preview, 0, 297) . '…';
    }

    $base = finbuild_site_base_url();
    $productLink = $base !== '' ? $base . '/product_details.php?id=' . $applicationProductId : '';
    $productLabel = $bankName !== '' ? htmlspecialchars($bankName) : '';

    if (finbuild_is_manager($senderRole)) {
        if ($ownerId <= 0 || $ownerId === $senderUserId) {
            return;
        }
        $email = finbuild_user_notification_email($pdo, $ownerId);
        if (!$email) {
            return;
        }
        $subj = $subject;
        $html = '<p>По вашей заявке пришло сообщение в чате продукта'
            . ($productLabel !== '' ? ' ' . $productLabel : '')
            . '.</p>';
        $html .= '<blockquote style="border-left:3px solid #ccc;padding-left:10px;">' . htmlspecialchars($preview) . '</blockquote>';
        if ($productLink !== '') {
            $html .= '<p><a href="' . htmlspecialchars($productLink) . '">Открыть чат</a></p>';
        }
        finbuild_send_mail($email, $subj, $html);
        return;
    }

    // Сообщение от клиента/партнёра — менеджеру (ответственному или fallback).
    // Ранее копия дублировалась на адрес поддержки — сейчас отключено (см. заглушку ниже).
    $recipients = [];
    $sentToResponsible = false;
    if ($assignedTo > 0) {
        $e = finbuild_user_notification_email($pdo, $assignedTo);
        if ($e) {
            $recipients[] = $e;
            $sentToResponsible = true;
        }
    }
    if (empty($recipients)) {
        $recipients = finbuild_manager_fallback_emails();
    }
    if (empty($recipients)) {
        return;
    }
    // Заглушка: копия уведомления на адрес поддержки временно отключена.
    // if ($sentToResponsible) {
    //     $recipients[] = 'support@finbuild.ru';
    // }
    $recipients = array_values(array_unique($recipients));
    $subj = $subject;
    $managerProductLabel = $productLabel !== '' ? $productLabel : 'продукта';
    $html = '<p>Сообщение в чате продукта: ' . $managerProductLabel . '</p>';
    $html .= '<blockquote style="border-left:3px solid #ccc;padding-left:10px;">' . htmlspecialchars($preview) . '</blockquote>';
    if ($productLink !== '') {
        $html .= '<p><a href="' . htmlspecialchars($productLink) . '">Открыть чат</a></p>';
    }
    foreach ($recipients as $to) {
        finbuild_send_mail($to, $subj, $html);
    }
}

/**
 * Запрос документа менеджером — уведомляем владельца заявки.
 */
function notify_document_request_created(PDO $pdo, int $applicationProductId, string $title): void
{
    $stmt = $pdo->prepare(
        'SELECT a.id AS application_id, a.created_by
         FROM application_products ap
         INNER JOIN applications a ON ap.application_id = a.id
         WHERE ap.id = ?'
    );
    $stmt->execute([$applicationProductId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || empty($row['created_by'])) {
        return;
    }
    $email = finbuild_user_notification_email($pdo, (int) $row['created_by']);
    if (!$email) {
        return;
    }
    $applicationId = (int) $row['application_id'];
    $base = finbuild_site_base_url();
    $link = $base !== '' ? $base . '/product_details.php?id=' . $applicationProductId : '';
    $subj = 'Запрос документа по заявке №' . $applicationId;
    $html = '<p>Запрошен документ: <strong>' . htmlspecialchars($title) . '</strong></p>';
    if ($link !== '') {
        $html .= '<p><a href="' . htmlspecialchars($link) . '">Перейти к продукту</a></p>';
    }
    finbuild_send_mail($email, $subj, $html);
}

/**
 * Загрузка файла по запросу документа — только менеджерам (ответственный или fallback).
 * Клиент/партнёр по загрузкам письма не получает (только о новом запросе документа — см. notify_document_request_created).
 */
function notify_product_document_uploaded(PDO $pdo, int $documentId, int $uploaderUserId): void
{
    $stmt = $pdo->prepare(
        'SELECT d.title, d.application_product_id, a.id AS application_id, a.created_by, a.assigned_to, u.role AS uploader_role
         FROM application_product_documents d
         INNER JOIN application_products ap ON d.application_product_id = ap.id
         INNER JOIN applications a ON ap.application_id = a.id
         INNER JOIN users u ON u.id = ?
         WHERE d.id = ?'
    );
    $stmt->execute([$uploaderUserId, $documentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return;
    }
    $applicationId = (int) $row['application_id'];
    $productId = (int) $row['application_product_id'];
    $assignedTo = isset($row['assigned_to']) ? (int) $row['assigned_to'] : 0;
    $role = $row['uploader_role'] ?? '';

    $base = finbuild_site_base_url();
    $link = $base !== '' ? $base . '/product_details.php?id=' . $productId : '';

    $uploaderIsManager = finbuild_is_manager($role);
    // Ответственный загрузил сам — менеджерам не пишем (в т.ч. не дублируем fallback про «сотрудник загрузил»)
    $responsibleUploadedSelf = $uploaderIsManager && $assignedTo > 0 && $uploaderUserId === $assignedTo;

    if ($responsibleUploadedSelf) {
        return;
    }

    // Клиент/партнёр или другой менеджер — письмо ответственному и fallback (не самому себе по адресу)
    $uploaderMailbox = finbuild_user_mailbox_address($pdo, $uploaderUserId);

    $recipients = [];
    $sentToResponsible = false;
    if ($assignedTo > 0 && $uploaderUserId !== $assignedTo) {
        $e = finbuild_user_notification_email($pdo, $assignedTo);
        if ($e) {
            $recipients[] = $e;
            $sentToResponsible = true;
        }
    }
    if (empty($recipients)) {
        $recipients = finbuild_manager_fallback_emails();
    }
    if ($uploaderMailbox !== null) {
        $recipients = array_values(array_filter($recipients, static function (string $to) use ($uploaderMailbox) {
            return strcasecmp($to, $uploaderMailbox) !== 0;
        }));
    }
    if (empty($recipients)) {
        return;
    }
    // Заглушка: копия уведомления на адрес поддержки временно отключена.
    // if ($sentToResponsible) {
    //     $recipients[] = 'support@finbuild.ru';
    // }
    $recipients = array_values(array_unique($recipients));

    // Тема: другой менеджер / клиент или партнёр (ответственному не шлём — отсечено выше)
    $subj = $uploaderIsManager
        ? ('Сотрудник загрузил документ по заявке №' . $applicationId)
        : ('Клиент или партнёр загрузил документ по заявке №' . $applicationId);
    $html = '<p>По запросу «<strong>' . htmlspecialchars((string) $row['title']) . '</strong>» загружены файлы.</p>';
    if ($link !== '') {
        $html .= '<p><a href="' . htmlspecialchars($link) . '">Открыть продукт</a></p>';
    }
    foreach ($recipients as $to) {
        finbuild_send_mail($to, $subj, $html);
    }
}

/**
 * Превью текста для письма (чат, комментарий).
 */
function finbuild_notification_message_preview(string $messageText, bool $hasAttachments): string
{
    $preview = trim($messageText);
    if ($preview === '' && $hasAttachments) {
        $preview = '(прикреплён файл)';
    }
    if (mb_strlen($preview) > 300) {
        $preview = mb_substr($preview, 0, 297) . '…';
    }
    return $preview;
}

/**
 * Активные пользователи ЛК банка с включёнными e-mail уведомлениями.
 *
 * @return list<string>
 */
function finbuild_bank_portal_notification_emails(PDO $pdo, string $bankCode, ?int $excludeUserId = null): array
{
    require_once __DIR__ . '/bank_portals.php';
    $bankCode = trim($bankCode);
    if ($bankCode === '' || finbank_portal_by_code($bankCode) === null) {
        return [];
    }
    try {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'bank' AND is_active = 1 AND bank_code = ?");
        $stmt->execute([$bankCode]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException) {
        $stmt = $pdo->query("SELECT id FROM users WHERE role = 'bank' AND is_active = 1");
        $rows = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
        if ($bankCode !== 'noosfera') {
            return [];
        }
    }
    $emails = [];
    foreach ($rows as $uid) {
        $uid = (int) $uid;
        if ($excludeUserId !== null && $uid === $excludeUserId) {
            continue;
        }
        $addr = finbuild_user_notification_email($pdo, $uid);
        if ($addr !== null) {
            $emails[] = $addr;
        }
    }
    return array_values(array_unique($emails));
}

/**
 * Менеджеры для уведомлений по банковскому кейсу: ответственный или MAIL_MANAGER_FALLBACK (+ support при ответственном).
 *
 * @return list<string>
 */
function finbuild_bank_case_manager_recipients(PDO $pdo, int $assignedTo, ?int $excludeUserId = null): array
{
    $recipients = [];
    $sentToResponsible = false;
    if ($assignedTo > 0 && ($excludeUserId === null || $assignedTo !== $excludeUserId)) {
        $e = finbuild_user_notification_email($pdo, $assignedTo);
        if ($e) {
            $recipients[] = $e;
            $sentToResponsible = true;
        }
    }
    if ($recipients === []) {
        $recipients = finbuild_manager_fallback_emails();
    }
    if ($excludeUserId !== null) {
        $senderMailbox = finbuild_user_mailbox_address($pdo, $excludeUserId);
        if ($senderMailbox !== null) {
            $recipients = array_values(array_filter(
                $recipients,
                static fn (string $to): bool => strcasecmp($to, $senderMailbox) !== 0
            ));
        }
    }
    if ($recipients === []) {
        return [];
    }
    // Заглушка: копия уведомления на адрес поддержки временно отключена.
    // if ($sentToResponsible) {
    //     $recipients[] = 'support@finbuild.ru';
    // }
    return array_values(array_unique($recipients));
}

/**
 * @return array<string, mixed>|null
 */
function finbuild_bank_case_notification_row(PDO $pdo, int $caseId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT c.id AS case_id, c.application_product_id, c.bank_code, c.status, c.manager_comment,
                ap.application_id, ap.bank_name, ap.product_name,
                a.company_name, a.assigned_to
         FROM application_product_bank_cases c
         INNER JOIN application_products ap ON ap.id = c.application_product_id
         INNER JOIN applications a ON a.id = ap.application_id
         WHERE c.id = ?'
    );
    $stmt->execute([$caseId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Менеджер отправил пакет в банк — уведомляем пользователей ЛК банка.
 */
function notify_bank_case_submitted(PDO $pdo, int $caseId, int $submitterUserId): void
{
    $row = finbuild_bank_case_notification_row($pdo, $caseId);
    if (!$row) {
        return;
    }
    $recipients = finbuild_bank_portal_notification_emails($pdo, (string) ($row['bank_code'] ?? ''), $submitterUserId);
    if ($recipients === []) {
        return;
    }

    $applicationId = (int) $row['application_id'];
    $companyName = trim((string) ($row['company_name'] ?? ''));
    $bankName = trim((string) ($row['bank_name'] ?? ''));
    $subject = 'Новая заявка в банк №' . $applicationId;
    if ($companyName !== '') {
        $subject .= ' (' . $companyName . ')';
    }

    $base = finbuild_site_base_url();
    $link = $base !== '' ? $base . '/bank_application_detail.php?id=' . $caseId : '';
    $productLabel = $bankName !== '' ? htmlspecialchars($bankName) : 'продукт';
    $html = '<p>Менеджер отправил в банк материалы по заявке №' . $applicationId
        . ($companyName !== '' ? ' (<strong>' . htmlspecialchars($companyName) . '</strong>)' : '')
        . ', ' . $productLabel . '.</p>';
    $comment = trim((string) ($row['manager_comment'] ?? ''));
    if ($comment !== '') {
        $html .= '<blockquote style="border-left:3px solid #ccc;padding-left:10px;">'
            . htmlspecialchars($comment) . '</blockquote>';
    }
    if ($link !== '') {
        $html .= '<p><a href="' . htmlspecialchars($link) . '">Открыть в личном кабинете банка</a></p>';
    }

    foreach ($recipients as $to) {
        finbuild_send_mail($to, $subject, $html);
    }
}

/**
 * Сообщение в чате банковского кейса: менеджер → ЛК банка, банк → ответственный менеджер.
 */
function notify_bank_case_chat_message(
    PDO $pdo,
    int $caseId,
    int $senderUserId,
    string $messageText,
    bool $hasAttachments
): void {
    $row = finbuild_bank_case_notification_row($pdo, $caseId);
    if (!$row) {
        return;
    }

    $stmt = $pdo->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$senderUserId]);
    $senderRole = $stmt->fetchColumn();
    if ($senderRole === false) {
        return;
    }
    $senderRole = (string) $senderRole;

    $applicationId = (int) $row['application_id'];
    $applicationProductId = (int) $row['application_product_id'];
    $assignedTo = isset($row['assigned_to']) ? (int) $row['assigned_to'] : 0;
    $companyName = trim((string) ($row['company_name'] ?? ''));
    $bankName = trim((string) ($row['bank_name'] ?? ''));

    $subject = 'Сообщение по заявке в банк №' . $applicationId;
    if ($companyName !== '') {
        $subject .= ' (' . $companyName . ')';
    }
    $preview = finbuild_notification_message_preview($messageText, $hasAttachments);
    $productLabel = $bankName !== '' ? htmlspecialchars($bankName) : 'продукта';
    $base = finbuild_site_base_url();

    if (finbuild_is_manager($senderRole)) {
        $recipients = finbuild_bank_portal_notification_emails($pdo, (string) ($row['bank_code'] ?? ''));
        if ($recipients === []) {
            return;
        }
        $link = $base !== '' ? $base . '/bank_application_detail.php?id=' . $caseId : '';
        $html = '<p>Новое сообщение от менеджера в чате по заявке в банк (' . $productLabel . ').</p>';
        $html .= '<blockquote style="border-left:3px solid #ccc;padding-left:10px;">' . htmlspecialchars($preview) . '</blockquote>';
        if ($link !== '') {
            $html .= '<p><a href="' . htmlspecialchars($link) . '">Открыть чат</a></p>';
        }
        foreach ($recipients as $to) {
            finbuild_send_mail($to, $subject, $html);
        }
        return;
    }

    if ($senderRole !== 'bank') {
        return;
    }

    $recipients = finbuild_bank_case_manager_recipients($pdo, $assignedTo, $senderUserId);
    if ($recipients === []) {
        return;
    }
    $link = $base !== '' ? $base . '/product_details.php?id=' . $applicationProductId . '&tab=bank' : '';
    $html = '<p>Новое сообщение от банка в чате «Работа с банком» (' . $productLabel . ').</p>';
    $html .= '<blockquote style="border-left:3px solid #ccc;padding-left:10px;">' . htmlspecialchars($preview) . '</blockquote>';
    if ($link !== '') {
        $html .= '<p><a href="' . htmlspecialchars($link) . '">Открыть чат</a></p>';
    }
    foreach ($recipients as $to) {
        finbuild_send_mail($to, $subject, $html);
    }
}

/**
 * Банк сменил статус кейса — уведомляем ответственного менеджера (или fallback).
 */
function notify_bank_case_status_changed(
    PDO $pdo,
    int $caseId,
    int $changerUserId,
    string $oldStatus,
    string $newStatus,
    string $comment
): void {
    $stmt = $pdo->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$changerUserId]);
    if ((string) ($stmt->fetchColumn() ?: '') !== 'bank') {
        return;
    }

    $row = finbuild_bank_case_notification_row($pdo, $caseId);
    if (!$row) {
        return;
    }

    require_once __DIR__ . '/bank_portal.php';

    $assignedTo = isset($row['assigned_to']) ? (int) $row['assigned_to'] : 0;
    $recipients = finbuild_bank_case_manager_recipients($pdo, $assignedTo, $changerUserId);
    if ($recipients === []) {
        return;
    }

    $applicationId = (int) $row['application_id'];
    $applicationProductId = (int) $row['application_product_id'];
    $companyName = trim((string) ($row['company_name'] ?? ''));
    $bankName = trim((string) ($row['bank_name'] ?? ''));

    $subject = 'Статус в банке по заявке №' . $applicationId;
    if ($companyName !== '') {
        $subject .= ' (' . $companyName . ')';
    }

    $oldLabel = finbank_case_status_label_manager($oldStatus);
    $newLabel = finbank_case_status_label_manager($newStatus);
    $productLabel = $bankName !== '' ? htmlspecialchars($bankName) : 'продукта';
    $base = finbuild_site_base_url();
    $link = $base !== '' ? $base . '/product_details.php?id=' . $applicationProductId . '&tab=bank' : '';

    $html = '<p>Банк изменил статус по заявке в банк (' . $productLabel . ').</p>';
    $html .= '<p>Было: <strong>' . htmlspecialchars($oldLabel) . '</strong><br>';
    $html .= 'Стало: <strong>' . htmlspecialchars($newLabel) . '</strong></p>';
    $comment = trim($comment);
    if ($comment !== '') {
        $html .= '<blockquote style="border-left:3px solid #ccc;padding-left:10px;">'
            . htmlspecialchars($comment) . '</blockquote>';
    }
    if ($link !== '') {
        $html .= '<p><a href="' . htmlspecialchars($link) . '">Открыть заявку</a></p>';
    }

    foreach ($recipients as $to) {
        finbuild_send_mail($to, $subject, $html);
    }
}
