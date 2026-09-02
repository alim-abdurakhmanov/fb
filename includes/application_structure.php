<?php
/**
 * Структура заявки: преимущества, стоп-факторы, примечания.
 */
declare(strict_types=1);

require_once __DIR__ . '/user_roles.php';

const FINBUILD_STRUCTURE_SECTION_ADVANTAGES = 'advantages';
const FINBUILD_STRUCTURE_SECTION_STOP_FACTORS = 'stop_factors';
const FINBUILD_STRUCTURE_SECTION_NOTES = 'notes';

/**
 * @return array<string, array{code: string, title: string, icon: string, accent: string}>
 */
function finbuild_structure_sections(): array
{
    return [
        FINBUILD_STRUCTURE_SECTION_ADVANTAGES => [
            'code' => FINBUILD_STRUCTURE_SECTION_ADVANTAGES,
            'title' => 'Преимущества компании',
            'icon' => 'bi-patch-check',
            'accent' => 'success',
        ],
        FINBUILD_STRUCTURE_SECTION_STOP_FACTORS => [
            'code' => FINBUILD_STRUCTURE_SECTION_STOP_FACTORS,
            'title' => 'Стоп-факторы',
            'icon' => 'bi-slash-circle',
            'accent' => 'danger',
        ],
        FINBUILD_STRUCTURE_SECTION_NOTES => [
            'code' => FINBUILD_STRUCTURE_SECTION_NOTES,
            'title' => 'Примечания/Вопросы от аналитика',
            'icon' => 'bi-journal-text',
            'accent' => 'primary',
        ],
    ];
}

function finbuild_structure_is_valid_section(string $section): bool
{
    return isset(finbuild_structure_sections()[$section]);
}

function finbuild_structure_truncate(string $text, int $maxLen): string
{
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($text) > $maxLen) {
            return mb_substr($text, 0, $maxLen);
        }
        return $text;
    }
    if (strlen($text) > $maxLen) {
        return substr($text, 0, $maxLen);
    }
    return $text;
}

function finbuild_structure_format_person_name(?string $first, ?string $last): ?string
{
    $name = trim((string) $first . ' ' . (string) $last);
    return $name !== '' ? $name : null;
}

function finbuild_structure_format_datetime(?string $dt): ?string
{
    if ($dt === null || trim($dt) === '') {
        return null;
    }
    $ts = strtotime($dt);
    if ($ts === false) {
        return null;
    }
    return date('d.m.Y H:i', $ts);
}

/** Таблица application_structure_items создана миграцией. */
function finbuild_application_structure_table_ready(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $pdo->query('SELECT 1 FROM application_structure_items LIMIT 1');
        $ready = true;
    } catch (PDOException) {
        $ready = false;
    }
    return $ready;
}

function finbuild_application_structure_soft_delete_ready(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    if (!finbuild_application_structure_table_ready($pdo)) {
        $ready = false;
        return false;
    }
    try {
        $pdo->query('SELECT deleted_at FROM application_structure_items LIMIT 0');
        $ready = true;
    } catch (PDOException) {
        $ready = false;
    }
    return $ready;
}

function finbuild_application_structure_updated_by_ready(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    if (!finbuild_application_structure_table_ready($pdo)) {
        $ready = false;
        return false;
    }
    try {
        $pdo->query('SELECT updated_by FROM application_structure_items LIMIT 0');
        $ready = true;
    } catch (PDOException) {
        $ready = false;
    }
    return $ready;
}

/** Пункт редактировали после создания (есть автор изменения или заметно позже created_at). */
function finbuild_structure_item_was_edited(array $item): bool
{
    if (!empty($item['updated_by']) || !empty($item['updated_by_name'])) {
        return true;
    }
    $created = isset($item['created_at']) ? strtotime((string) $item['created_at']) : false;
    $updated = isset($item['updated_at']) ? strtotime((string) $item['updated_at']) : false;
    if ($created !== false && $updated !== false && $updated > $created + 60) {
        return true;
    }
    return false;
}

/**
 * @return list<string> строки «Добавлено…» / «Изменено…» для UI
 */
function finbuild_structure_item_meta_lines(array $item, bool $deleted = false): array
{
    if ($deleted) {
        $lines = [];
        $created = [];
        if (!empty($item['author_name'])) {
            $created[] = (string) $item['author_name'];
        }
        if (!empty($item['created_at_label'])) {
            $created[] = (string) $item['created_at_label'];
        }
        if ($created !== []) {
            $lines[] = 'Добавлено: ' . implode(' · ', $created);
        }

        $delMeta = 'Удалено';
        $deletedBy = trim((string) ($item['deleted_by_name'] ?? ''));
        $deletedLabel = (string) ($item['deleted_at_label'] ?? '');
        if ($deletedBy !== '') {
            $delMeta .= ': ' . $deletedBy;
        }
        if ($deletedLabel !== '') {
            $delMeta .= ($deletedBy !== '' ? ', ' : ': ') . $deletedLabel;
        }
        $lines[] = $delMeta;

        return $lines;
    }

    $lines = [];
    $created = [];
    if (!empty($item['author_name'])) {
        $created[] = (string) $item['author_name'];
    }
    if (!empty($item['created_at_label'])) {
        $created[] = (string) $item['created_at_label'];
    }
    if ($created !== []) {
        $lines[] = 'Добавлено: ' . implode(' · ', $created);
    }

    if (finbuild_structure_item_was_edited($item)) {
        $updated = [];
        if (!empty($item['updated_by_name'])) {
            $updated[] = (string) $item['updated_by_name'];
        }
        if (!empty($item['updated_at_label'])) {
            $updated[] = (string) $item['updated_at_label'];
        }
        if ($updated !== []) {
            $lines[] = 'Изменено: ' . implode(' · ', $updated);
        }
    }

    return $lines;
}

/** Мета для ЛК банка: свои пункты — полная, чужие — «Добавлено аналитиком платформы». */
function finbuild_structure_item_meta_lines_bank_lk(array $item, bool $deleted = false): array
{
    if ($deleted) {
        return finbuild_structure_item_meta_lines($item, true);
    }
    if (($item['author_role'] ?? '') === 'bank') {
        return finbuild_structure_item_meta_lines($item, false);
    }
    return ['Добавлено аналитиком платформы'];
}

/** Пункт добавлен пользователем банка. */
function finbuild_structure_item_is_bank_authored(array $item): bool
{
    return ($item['author_role'] ?? '') === 'bank';
}

/** URL отдельного API-файла (тот же каталог, что и страницы заявки). */
function finbuild_application_structure_api_url(): string
{
    return 'api_application_structure.php';
}

/**
 * URL для AJAX с карточки заявки (тот же скрипт — не зависит от выкладки отдельного API).
 */
function finbuild_application_structure_page_ajax_url(int $applicationId, string $returnQuery = ''): string
{
    $url = 'application_details.php?id=' . $applicationId;
    if ($returnQuery !== '') {
        $url .= '&return=' . rawurlencode($returnQuery);
    }
    return $url;
}

function finbuild_application_structure_send_json(array $payload): void
{
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * @return array<string, mixed>
 */
function finbuild_application_structure_map_row(array $row, bool $deleted): array
{
    $author = finbuild_structure_format_person_name($row['first_name'] ?? null, $row['last_name'] ?? null);
    $updatedByName = finbuild_structure_format_person_name(
        $row['updated_first_name'] ?? null,
        $row['updated_last_name'] ?? null
    );
    $item = [
        'id' => (int) $row['id'],
        'content' => (string) $row['content'],
        'sort_order' => (int) $row['sort_order'],
        'created_at' => (string) ($row['created_at'] ?? ''),
        'created_at_label' => finbuild_structure_format_datetime($row['created_at'] ?? null),
        'updated_at' => (string) ($row['updated_at'] ?? ''),
        'updated_at_label' => finbuild_structure_format_datetime($row['updated_at'] ?? null),
        'author_name' => $author,
        'created_by' => (int) ($row['created_by'] ?? 0),
        'author_role' => (string) ($row['author_role'] ?? ''),
        'updated_by' => isset($row['updated_by']) ? (int) $row['updated_by'] : null,
        'updated_by_name' => $updatedByName,
        'is_deleted' => $deleted,
    ];
    if ($deleted) {
        $item['deleted_at'] = (string) ($row['deleted_at'] ?? '');
        $item['deleted_at_label'] = finbuild_structure_format_datetime($row['deleted_at'] ?? null);
        $item['deleted_by_name'] = finbuild_structure_format_person_name(
            $row['deleted_first_name'] ?? null,
            $row['deleted_last_name'] ?? null
        );
    }
    return $item;
}

/**
 * Обработка action=list|add|update|delete. Завершает запрос через send_json().
 */
function finbuild_application_structure_dispatch(
    PDO $pdo,
    string $role,
    int $userId,
    int $applicationId,
    string $action,
    string $httpMethod,
    bool $isAnalystFlag = false
): void {
    if ($applicationId <= 0) {
        finbuild_application_structure_send_json(['success' => false, 'error' => 'Не указана заявка']);
    }

    if (!finbuild_application_structure_can_read($pdo, $applicationId, $role, $userId, $isAnalystFlag)) {
        finbuild_application_structure_send_json(['success' => false, 'error' => 'Нет доступа']);
    }

    if (!finbuild_application_structure_table_ready($pdo)) {
        finbuild_application_structure_send_json([
            'success' => false,
            'error' => 'Таблица структуры не создана. Выполните SQL из migrations/add_application_structure.sql',
        ]);
    }

    try {
        if ($action === 'list' || ($action === '' && $httpMethod === 'GET')) {
            $canEditFull = finbuild_can_edit_application_structure($role, $isAnalystFlag);
            $canEditBank = finbuild_application_structure_can_edit_bank($role);
            finbuild_application_structure_send_json([
                'success' => true,
                'sections' => finbuild_structure_sections(),
                'items' => finbuild_application_structure_fetch_grouped($pdo, $applicationId, false),
                'deleted_items' => finbuild_application_structure_fetch_grouped($pdo, $applicationId, true),
                'can_edit' => $canEditFull || $canEditBank,
                'edit_own_only' => $canEditBank && !$canEditFull,
            ]);
        }

        $canEditFull = finbuild_can_edit_application_structure($role, $isAnalystFlag);
        $canEditBank = finbuild_application_structure_can_edit_bank($role);
        if (!$canEditFull && !$canEditBank) {
            finbuild_application_structure_send_json(['success' => false, 'error' => 'Нет прав на изменение структуры']);
        }

        if ($action === 'add' && $httpMethod === 'POST') {
            $section = trim((string) ($_POST['section'] ?? ''));
            $content = trim((string) ($_POST['content'] ?? ''));
            if ($content === '') {
                finbuild_application_structure_send_json(['success' => false, 'error' => 'Введите текст пункта']);
            }
            if ($canEditBank && !$canEditFull && !finbuild_application_structure_is_bank_editable_section($section)) {
                finbuild_application_structure_send_json(['success' => false, 'error' => 'Недопустимый раздел']);
            }
            $row = finbuild_application_structure_add_item($pdo, $applicationId, $section, $content, $userId);
            if ($row === null) {
                finbuild_application_structure_send_json(['success' => false, 'error' => 'Не удалось добавить пункт']);
            }
            $stmt = $pdo->prepare('SELECT first_name, last_name, role FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);
            $author = finbuild_structure_format_person_name($u['first_name'] ?? null, $u['last_name'] ?? null);
            finbuild_application_structure_send_json([
                'success' => true,
                'item' => [
                    'id' => $row['id'],
                    'content' => $row['content'],
                    'sort_order' => $row['sort_order'],
                    'author_name' => $author,
                    'created_by' => $userId,
                    'author_role' => (string) ($u['role'] ?? $role),
                    'created_at' => date('Y-m-d H:i:s'),
                    'created_at_label' => finbuild_structure_format_datetime(date('Y-m-d H:i:s')),
                    'is_deleted' => false,
                ],
            ]);
        }

        if ($action === 'delete' && $httpMethod === 'POST') {
            $itemId = (int) ($_POST['item_id'] ?? 0);
            if ($itemId <= 0) {
                finbuild_application_structure_send_json(['success' => false, 'error' => 'Не указан пункт']);
            }
            if ($canEditBank && !$canEditFull && !finbuild_application_structure_assert_item_owner($pdo, $itemId, $applicationId, $userId)) {
                finbuild_application_structure_send_json(['success' => false, 'error' => 'Можно удалять только свои пункты']);
            }
            if ($canEditFull && finbuild_structure_item_is_bank_authored(
                ['author_role' => finbuild_application_structure_item_author_role($pdo, $itemId, $applicationId) ?? '']
            )) {
                finbuild_application_structure_send_json(['success' => false, 'error' => 'Нельзя удалять пункты, добавленные банком']);
            }
            $deletedItem = finbuild_application_structure_delete_item($pdo, $itemId, $applicationId, $userId);
            if ($deletedItem === null) {
                finbuild_application_structure_send_json(['success' => false, 'error' => 'Пункт не найден']);
            }
            finbuild_application_structure_send_json(['success' => true, 'item' => $deletedItem]);
        }

        if ($action === 'update' && $httpMethod === 'POST') {
            $itemId = (int) ($_POST['item_id'] ?? 0);
            $content = trim((string) ($_POST['content'] ?? ''));
            if ($itemId <= 0) {
                finbuild_application_structure_send_json(['success' => false, 'error' => 'Не указан пункт']);
            }
            if ($content === '') {
                finbuild_application_structure_send_json(['success' => false, 'error' => 'Введите текст пункта']);
            }
            if ($canEditBank && !$canEditFull && !finbuild_application_structure_assert_item_owner($pdo, $itemId, $applicationId, $userId)) {
                finbuild_application_structure_send_json(['success' => false, 'error' => 'Можно изменять только свои пункты']);
            }
            if ($canEditFull && finbuild_structure_item_is_bank_authored(
                ['author_role' => finbuild_application_structure_item_author_role($pdo, $itemId, $applicationId) ?? '']
            )) {
                finbuild_application_structure_send_json(['success' => false, 'error' => 'Нельзя изменять пункты, добавленные банком']);
            }
            $updatedItem = finbuild_application_structure_update_item($pdo, $itemId, $applicationId, $content, $userId);
            if ($updatedItem === null) {
                finbuild_application_structure_send_json(['success' => false, 'error' => 'Пункт не найден']);
            }
            finbuild_application_structure_send_json(['success' => true, 'item' => $updatedItem]);
        }

        finbuild_application_structure_send_json(['success' => false, 'error' => 'Неизвестное действие']);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('application_structure dispatch: ' . $e->getMessage());
        }
        finbuild_application_structure_send_json(['success' => false, 'error' => 'Ошибка сервера: ' . $e->getMessage()]);
    }
}

/**
 * @return array<string, list<array<string, mixed>>>
 */
function finbuild_application_structure_fetch_grouped(PDO $pdo, int $applicationId, bool $deletedOnly): array
{
    $empty = [];
    foreach (finbuild_structure_sections() as $code => $_meta) {
        $empty[$code] = [];
    }

    if (!finbuild_application_structure_table_ready($pdo)) {
        return $empty;
    }

    $softDelete = finbuild_application_structure_soft_delete_ready($pdo);
    $deletedClause = $softDelete
        ? ($deletedOnly ? 's.deleted_at IS NOT NULL' : 's.deleted_at IS NULL')
        : ($deletedOnly ? '0=1' : '1=1');

    try {
        $trackUpdatedBy = finbuild_application_structure_updated_by_ready($pdo);
        $updatedSelect = $trackUpdatedBy
            ? 'uu.first_name AS updated_first_name, uu.last_name AS updated_last_name,'
            : '';
        $updatedJoin = $trackUpdatedBy
            ? 'LEFT JOIN users uu ON uu.id = s.updated_by'
            : '';
        $sql = 'SELECT s.*,
                       u.first_name, u.last_name, u.role AS author_role,
                       ' . $updatedSelect . '
                       du.first_name AS deleted_first_name, du.last_name AS deleted_last_name
                FROM application_structure_items s
                LEFT JOIN users u ON u.id = s.created_by
                ' . $updatedJoin . '
                LEFT JOIN users du ON du.id = s.deleted_by
                WHERE s.application_id = ? AND ' . $deletedClause . '
                ORDER BY s.section ASC, s.sort_order ASC, s.id ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$applicationId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException) {
        return $empty;
    }

    foreach ($rows as $row) {
        $sec = (string) ($row['section'] ?? '');
        if (!isset($empty[$sec])) {
            continue;
        }
        $empty[$sec][] = finbuild_application_structure_map_row($row, $deletedOnly);
    }

    return $empty;
}

/**
 * Чтение структуры: менеджер, аналитик, владелец заявки, банк по кейсу.
 */
function finbuild_application_structure_can_read(
    PDO $pdo,
    int $applicationId,
    string $role,
    int $userId,
    bool $isAnalystFlag = false
): bool {
    if (finbuild_is_manager($role) || finbuild_is_analyst_role($role) || $isAnalystFlag) {
        $stmt = $pdo->prepare('SELECT id, status, created_by FROM applications WHERE id = ? LIMIT 1');
        $stmt->execute([$applicationId]);
        $app = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$app) {
            return false;
        }
        if (finbuild_analyst_cannot_view_failed_application($app, $role, $isAnalystFlag, $userId)) {
            return false;
        }
        return true;
    }
    $stmt = $pdo->prepare('SELECT id, created_by FROM applications WHERE id = ? LIMIT 1');
    $stmt->execute([$applicationId]);
    $app = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$app) {
        return false;
    }
    if ($role === 'client' || $role === 'partner') {
        return (int) $app['created_by'] === $userId;
    }
    if ($role === 'bank') {
        require_once __DIR__ . '/bank_portal.php';
        $userStmt = $pdo->prepare('SELECT id, role, bank_code, company_name FROM users WHERE id = ? LIMIT 1');
        $userStmt->execute([$userId]);
        $bankUser = $userStmt->fetch(PDO::FETCH_ASSOC);
        $bankCode = $bankUser ? finbank_user_bank_code($pdo, $bankUser) : null;
        if ($bankCode === null) {
            return false;
        }
        try {
            $q = $pdo->prepare(
                'SELECT 1 FROM application_product_bank_cases c
                 INNER JOIN application_products ap ON ap.id = c.application_product_id
                 WHERE ap.application_id = ? AND c.bank_code = ? AND c.status <> ?
                 LIMIT 1'
            );
            $q->execute([$applicationId, $bankCode, FINBANK_STATUS_DRAFT]);
            return (bool) $q->fetchColumn();
        } catch (PDOException) {
            return false;
        }
    }
    return false;
}

function finbuild_application_structure_can_edit(string $role, bool $isAnalystFlag = false): bool
{
    return finbuild_can_edit_application_structure($role, $isAnalystFlag);
}

function finbuild_application_structure_can_edit_bank(string $role): bool
{
    return $role === 'bank';
}

/** Разделы, в которые банк может добавлять пункты. */
function finbuild_application_structure_is_bank_editable_section(string $section): bool
{
    return in_array($section, [
        FINBUILD_STRUCTURE_SECTION_ADVANTAGES,
        FINBUILD_STRUCTURE_SECTION_STOP_FACTORS,
    ], true);
}

function finbuild_application_structure_assert_item_owner(
    PDO $pdo,
    int $itemId,
    int $applicationId,
    int $userId
): bool {
    if ($itemId <= 0 || $applicationId <= 0 || $userId <= 0) {
        return false;
    }
    $softDelete = finbuild_application_structure_soft_delete_ready($pdo);
    $sql = 'SELECT created_by FROM application_structure_items WHERE id = ? AND application_id = ?';
    if ($softDelete) {
        $sql .= ' AND deleted_at IS NULL';
    }
    $sql .= ' LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$itemId, $applicationId]);
    $createdBy = $stmt->fetchColumn();
    if ($createdBy === false) {
        return false;
    }
    return (int) $createdBy === $userId;
}

function finbuild_application_structure_item_author_role(PDO $pdo, int $itemId, int $applicationId): ?string
{
    if ($itemId <= 0 || $applicationId <= 0) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT u.role
         FROM application_structure_items s
         LEFT JOIN users u ON u.id = s.created_by
         WHERE s.id = ? AND s.application_id = ?
         LIMIT 1'
    );
    $stmt->execute([$itemId, $applicationId]);
    $role = $stmt->fetchColumn();
    if ($role === false || $role === null) {
        return null;
    }
    return (string) $role;
}

/** Кнопки редактирования/удаления для пункта в UI. */
function finbuild_structure_ui_item_editable(
    bool $sectionCanEdit,
    bool $editOwnOnly,
    int $currentUserId,
    array $item,
    bool $deleted,
    bool $allowItemMutate = true,
    bool $protectBankItems = false
): bool {
    if (!$sectionCanEdit || $deleted || !$allowItemMutate) {
        return false;
    }
    if ($protectBankItems && finbuild_structure_item_is_bank_authored($item)) {
        return false;
    }
    if (!$editOwnOnly) {
        return true;
    }
    return (int) ($item['created_by'] ?? 0) === $currentUserId;
}

/** Подписи «Добавлено» / «Изменено» для пункта в UI. */
function finbuild_structure_ui_item_show_meta(
    bool $showItemMeta,
    bool $showMetaBankOnly,
    array $item,
    bool $deleted
): bool {
    if (!$showItemMeta) {
        return false;
    }
    if ($deleted && $showMetaBankOnly) {
        return false;
    }
    return true;
}

/**
 * @return array{id: int, section: string, content: string, sort_order: int}|null
 */
function finbuild_application_structure_add_item(
    PDO $pdo,
    int $applicationId,
    string $section,
    string $content,
    int $createdBy
): ?array {
    $content = trim($content);
    if ($content === '' || !finbuild_structure_is_valid_section($section)) {
        return null;
    }
    $content = finbuild_structure_truncate($content, 5000);

    if (!finbuild_application_structure_table_ready($pdo)) {
        return null;
    }

    try {
        if (finbuild_application_structure_soft_delete_ready($pdo)) {
            $stmt = $pdo->prepare(
                'SELECT COALESCE(MAX(sort_order), -1) FROM application_structure_items
                 WHERE application_id = ? AND section = ? AND deleted_at IS NULL'
            );
        } else {
            $stmt = $pdo->prepare(
                'SELECT COALESCE(MAX(sort_order), -1) FROM application_structure_items
                 WHERE application_id = ? AND section = ?'
            );
        }
        $stmt->execute([$applicationId, $section]);
        $order = (int) $stmt->fetchColumn() + 1;

        $ins = $pdo->prepare(
            'INSERT INTO application_structure_items (application_id, section, content, sort_order, created_by)
             VALUES (?, ?, ?, ?, ?)'
        );
        $ins->execute([$applicationId, $section, $content, $order, $createdBy]);
        $id = (int) $pdo->lastInsertId();

        return [
            'id' => $id,
            'section' => $section,
            'content' => $content,
            'sort_order' => $order,
        ];
    } catch (PDOException $e) {
        if (function_exists('error_log')) {
            error_log('finbuild structure add: ' . $e->getMessage());
        }
        return null;
    }
}

/**
 * @return array<string, mixed>|null удалённый пункт для UI
 */
function finbuild_application_structure_delete_item(
    PDO $pdo,
    int $itemId,
    int $applicationId,
    int $deletedBy
): ?array {
    if (!finbuild_application_structure_table_ready($pdo)) {
        return null;
    }
    try {
        if (finbuild_application_structure_soft_delete_ready($pdo)) {
            $stmt = $pdo->prepare(
                'UPDATE application_structure_items
                 SET deleted_at = NOW(), deleted_by = ?
                 WHERE id = ? AND application_id = ? AND deleted_at IS NULL'
            );
            $stmt->execute([$deletedBy, $itemId, $applicationId]);
            if ($stmt->rowCount() <= 0) {
                return null;
            }
            $sel = $pdo->prepare(
                'SELECT s.*, u.first_name, u.last_name, du.first_name AS deleted_first_name, du.last_name AS deleted_last_name
                 FROM application_structure_items s
                 LEFT JOIN users u ON u.id = s.created_by
                 LEFT JOIN users du ON du.id = s.deleted_by
                 WHERE s.id = ? AND s.application_id = ? LIMIT 1'
            );
            $sel->execute([$itemId, $applicationId]);
            $row = $sel->fetch(PDO::FETCH_ASSOC);
            return $row ? finbuild_application_structure_map_row($row, true) : null;
        }

        $stmt = $pdo->prepare('DELETE FROM application_structure_items WHERE id = ? AND application_id = ?');
        $stmt->execute([$itemId, $applicationId]);
        return $stmt->rowCount() > 0 ? ['id' => $itemId] : null;
    } catch (PDOException $e) {
        if (function_exists('error_log')) {
            error_log('finbuild structure delete: ' . $e->getMessage());
        }
        return null;
    }
}

/**
 * @return array<string, mixed>|null
 */
function finbuild_application_structure_update_item(
    PDO $pdo,
    int $itemId,
    int $applicationId,
    string $content,
    int $updatedBy
): ?array {
    $content = trim($content);
    if ($content === '' || $itemId <= 0 || $applicationId <= 0) {
        return null;
    }
    $content = finbuild_structure_truncate($content, 5000);

    if (!finbuild_application_structure_table_ready($pdo)) {
        return null;
    }

    $trackUpdatedBy = finbuild_application_structure_updated_by_ready($pdo);
    $softDelete = finbuild_application_structure_soft_delete_ready($pdo);

    try {
        if ($trackUpdatedBy) {
            if ($softDelete) {
                $stmt = $pdo->prepare(
                    'UPDATE application_structure_items
                     SET content = ?, updated_by = ?, updated_at = NOW()
                     WHERE id = ? AND application_id = ? AND deleted_at IS NULL'
                );
                $stmt->execute([$content, $updatedBy, $itemId, $applicationId]);
            } else {
                $stmt = $pdo->prepare(
                    'UPDATE application_structure_items
                     SET content = ?, updated_by = ?, updated_at = NOW()
                     WHERE id = ? AND application_id = ?'
                );
                $stmt->execute([$content, $updatedBy, $itemId, $applicationId]);
            }
        } elseif ($softDelete) {
            $stmt = $pdo->prepare(
                'UPDATE application_structure_items
                 SET content = ?
                 WHERE id = ? AND application_id = ? AND deleted_at IS NULL'
            );
            $stmt->execute([$content, $itemId, $applicationId]);
        } else {
            $stmt = $pdo->prepare(
                'UPDATE application_structure_items
                 SET content = ?
                 WHERE id = ? AND application_id = ?'
            );
            $stmt->execute([$content, $itemId, $applicationId]);
        }

        if ($stmt->rowCount() <= 0) {
            return null;
        }

        $sel = $pdo->prepare(
            'SELECT s.*,
                    u.first_name, u.last_name, u.role AS author_role' .
            ($trackUpdatedBy ? ', uu.first_name AS updated_first_name, uu.last_name AS updated_last_name' : '') . '
             FROM application_structure_items s
             LEFT JOIN users u ON u.id = s.created_by' .
            ($trackUpdatedBy ? ' LEFT JOIN users uu ON uu.id = s.updated_by' : '') . '
             WHERE s.id = ? AND s.application_id = ? LIMIT 1'
        );
        $sel->execute([$itemId, $applicationId]);
        $row = $sel->fetch(PDO::FETCH_ASSOC);

        return $row ? finbuild_application_structure_map_row($row, false) : null;
    } catch (PDOException $e) {
        if (function_exists('error_log')) {
            error_log('finbuild structure update: ' . $e->getMessage());
        }
        return null;
    }
}
