<?php
/**
 * ЛК банка (multi-tenant: noosfera, alfa, …). См. includes/bank_portals.php.
 * Статусы кейса в БД — латиница; подписи для менеджера и банка — в label-функциях.
 */
declare(strict_types=1);

require_once __DIR__ . '/bank_portals.php';

const FINBANK_STATUS_DRAFT = 'draft';
const FINBANK_STATUS_SENT = 'sent_to_bank';
const FINBANK_STATUS_IN_PROGRESS = 'in_progress_at_bank';
const FINBANK_STATUS_REQUEST = 'request';
const FINBANK_STATUS_APPROVED = 'approved';
const FINBANK_STATUS_BG_ISSUED = 'bg_issued';
const FINBANK_STATUS_REJECTED = 'rejected';

function finbank_is_portal_application_product(array $row): bool
{
    return finbank_portal_code_from_product_row($row) !== null;
}

/** Подпись статуса для менеджера */
function finbank_case_status_label_manager(string $status): string
{
    return match ($status) {
        FINBANK_STATUS_DRAFT => 'Черновик (не отправлено)',
        FINBANK_STATUS_SENT => 'Отправлено в банк',
        FINBANK_STATUS_IN_PROGRESS => 'В работе у банка',
        FINBANK_STATUS_REQUEST => 'Запрос',
        FINBANK_STATUS_APPROVED => 'Одобрено',
        FINBANK_STATUS_BG_ISSUED => 'БГ выдана',
        FINBANK_STATUS_REJECTED => 'Отказ',
        default => $status,
    };
}

/** Подпись статуса для банка */
function finbank_case_status_label_bank(string $status): string
{
    return match ($status) {
        FINBANK_STATUS_SENT => 'Новая заявка',
        FINBANK_STATUS_IN_PROGRESS => 'В работе',
        FINBANK_STATUS_REQUEST => 'Запрос',
        FINBANK_STATUS_APPROVED => 'Одобрено',
        FINBANK_STATUS_BG_ISSUED => 'БГ выдана',
        FINBANK_STATUS_REJECTED => 'Отказ',
        FINBANK_STATUS_DRAFT => '—',
        default => $status,
    };
}

/** Продукт в заявке провален — для банка кейс не актуален. */
function finbank_product_status_is_failed(?string $productStatus): bool
{
    return (string) $productStatus === 'failed';
}

/** Статус для отображения банку с учётом статуса продукта в заявке. */
function finbank_bank_display_status_label(string $caseStatus, ?string $productStatus): string
{
    if (finbank_product_status_is_failed($productStatus)) {
        return 'Не актуально';
    }

    return finbank_case_status_label_bank($caseStatus);
}

/** Класс бейджа статуса для банка с учётом статуса продукта. */
function finbank_bank_display_status_badge_class(string $caseStatus, ?string $productStatus): string
{
    if (finbank_product_status_is_failed($productStatus)) {
        return 'bg-secondary';
    }

    return finbank_case_status_badge_class($caseStatus);
}

/** Подпись статуса в таймлайне истории (единый текст для банка и менеджера) */
function finbank_case_status_label_history(string $status): string
{
    return match ($status) {
        FINBANK_STATUS_DRAFT => 'Черновик',
        FINBANK_STATUS_SENT => 'Отправлено в банк',
        FINBANK_STATUS_IN_PROGRESS => 'В работе у банка',
        FINBANK_STATUS_REQUEST => 'Запрос',
        FINBANK_STATUS_APPROVED => 'Одобрено',
        FINBANK_STATUS_BG_ISSUED => 'БГ выдана',
        FINBANK_STATUS_REJECTED => 'Отказ',
        default => $status,
    };
}

/** Классы Bootstrap 5 для бейджа статуса банковского кейса */
function finbank_case_status_badge_class(string $status): string
{
    return match ($status) {
        FINBANK_STATUS_REQUEST => 'bg-warning text-dark',
        FINBANK_STATUS_IN_PROGRESS => 'bg-primary',
        FINBANK_STATUS_BG_ISSUED => 'bg-success',
        FINBANK_STATUS_REJECTED => 'bg-danger',
        FINBANK_STATUS_DRAFT => 'bg-secondary',
        FINBANK_STATUS_SENT => 'bg-secondary',
        FINBANK_STATUS_APPROVED => 'bg-info text-dark',
        default => 'bg-secondary',
    };
}

function finbank_chat_file_type_category(string $ext): string
{
    return match ($ext) {
        'pdf' => 'pdf',
        'doc', 'docx' => 'word',
        'xls', 'xlsx' => 'excel',
        'jpg', 'jpeg', 'png' => 'image',
        'txt' => 'text',
        'zip', 'rar' => 'archive',
        default => 'file',
    };
}

/**
 * Сохраняет файлы из multipart-поля (как chat_files[] у продукта) для сообщения банковского чата.
 * @return int число успешно сохранённых файлов
 */
function finbank_bank_case_message_files_save_from_request(PDO $pdo, int $messageId, int $caseId, int $userId): int
{
    if (empty($_FILES['chat_files']) || !is_array($_FILES['chat_files']['name'] ?? null)) {
        return 0;
    }
    try {
        $uploadDir = 'uploads/bank_case_chat/' . $caseId . '/' . $messageId . '/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        $saved = 0;
        foreach ($_FILES['chat_files']['name'] as $key => $name) {
            if (($_FILES['chat_files']['error'][$key] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }
            $fileTmp = $_FILES['chat_files']['tmp_name'][$key];
            $fileSize = (int) ($_FILES['chat_files']['size'][$key] ?? 0);
            $originalName = (string) $name;
            if ($fileSize <= 0) {
                continue;
            }
            $fileExt = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            $safe = uniqid('bcm_', true);
            if ($fileExt !== '') {
                $safe .= '.' . $fileExt;
            }
            $filePath = $uploadDir . $safe;
            if (!move_uploaded_file($fileTmp, $filePath)) {
                continue;
            }
            $fileType = finbank_chat_file_type_category($fileExt);
            $pdo->prepare(
                'INSERT INTO application_product_bank_case_message_files
                (bank_case_message_id, file_path, original_name, file_size, file_type, uploaded_by)
                VALUES (?,?,?,?,?,?)'
            )->execute([$messageId, $filePath, $originalName, $fileSize, $fileType, $userId]);
            $saved++;
        }
        return $saved;
    } catch (Throwable) {
        return 0;
    }
}

/**
 * Сохраняет файлы из multipart-поля status_files[] для записи истории статуса.
 * @return int число успешно сохранённых файлов
 */
function finbank_bank_case_status_log_files_save_from_request(PDO $pdo, int $logId, int $caseId, int $userId): int
{
    if (empty($_FILES['status_files']) || !is_array($_FILES['status_files']['name'] ?? null)) {
        return 0;
    }
    try {
        $allowed = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'txt', 'zip', 'rar'];
        $maxBytes = 20 * 1024 * 1024;
        $uploadDir = 'uploads/bank_case_status/' . $caseId . '/' . $logId . '/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        $saved = 0;
        foreach ($_FILES['status_files']['name'] as $key => $name) {
            if (($_FILES['status_files']['error'][$key] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }
            $fileTmp = $_FILES['status_files']['tmp_name'][$key];
            $fileSize = (int) ($_FILES['status_files']['size'][$key] ?? 0);
            $originalName = (string) $name;
            $fileExt = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            if (!in_array($fileExt, $allowed, true) || $fileSize <= 0 || $fileSize > $maxBytes) {
                continue;
            }
            $safe = uniqid('bcs_', true) . '.' . $fileExt;
            $filePath = $uploadDir . $safe;
            if (!move_uploaded_file($fileTmp, $filePath)) {
                continue;
            }
            $fileType = finbank_chat_file_type_category($fileExt);
            $pdo->prepare(
                'INSERT INTO application_product_bank_case_status_log_files
                (status_log_id, file_path, original_name, file_size, file_type, uploaded_by)
                VALUES (?,?,?,?,?,?)'
            )->execute([$logId, $filePath, $originalName, $fileSize, $fileType, $userId]);
            $saved++;
        }
        return $saved;
    } catch (Throwable) {
        return 0;
    }
}

/** Добавляет к каждому сообщению ключ files (массив строк из БД). */
function finbank_bank_case_messages_append_files(PDO $pdo, array $messages): array
{
    if ($messages === []) {
        return $messages;
    }
    try {
        $ids = array_map(static fn (array $m): int => (int) ($m['id'] ?? 0), $messages);
        $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            foreach ($messages as &$m) {
                $m['files'] = [];
            }
            unset($m);
            return $messages;
        }
        require_once __DIR__ . '/upload_access.php';
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare(
            "SELECT * FROM application_product_bank_case_message_files WHERE bank_case_message_id IN ($placeholders) ORDER BY id ASC"
        );
        $stmt->execute($ids);
        $byMsg = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $mid = (int) $f['bank_case_message_id'];
            $byMsg[$mid][] = finbuild_upload_row_with_url($f, 'bank_msg');
        }
        foreach ($messages as &$m) {
            $mid = (int) ($m['id'] ?? 0);
            $m['files'] = $byMsg[$mid] ?? [];
        }
        unset($m);
        return $messages;
    } catch (Throwable) {
        foreach ($messages as &$m) {
            $m['files'] = [];
        }
        unset($m);
        return $messages;
    }
}

/** Добавляет к каждой записи лога ключ files. */
function finbank_bank_case_status_log_append_files(PDO $pdo, array $rows): array
{
    if ($rows === []) {
        return $rows;
    }
    try {
        $ids = array_map(static fn (array $r): int => (int) ($r['id'] ?? 0), $rows);
        $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            foreach ($rows as &$r) {
                $r['files'] = [];
            }
            unset($r);
            return $rows;
        }
        require_once __DIR__ . '/upload_access.php';
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare(
            "SELECT * FROM application_product_bank_case_status_log_files WHERE status_log_id IN ($placeholders) ORDER BY id ASC"
        );
        $stmt->execute($ids);
        $byLog = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $lid = (int) $f['status_log_id'];
            $byLog[$lid][] = finbuild_upload_row_with_url($f, 'bank_log');
        }
        foreach ($rows as &$r) {
            $lid = (int) ($r['id'] ?? 0);
            $r['files'] = $byLog[$lid] ?? [];
        }
        unset($r);
        return $rows;
    } catch (Throwable) {
        foreach ($rows as &$r) {
            $r['files'] = [];
        }
        unset($r);
        return $rows;
    }
}

/** Допустимые новые статусы для банка (без draft/sent и терминалов). */
function finbank_bank_allowed_targets(string $from): array
{
    if (in_array($from, [FINBANK_STATUS_BG_ISSUED, FINBANK_STATUS_REJECTED, FINBANK_STATUS_DRAFT], true)) {
        return [];
    }
    $workflow = [
        FINBANK_STATUS_IN_PROGRESS,
        FINBANK_STATUS_REQUEST,
        FINBANK_STATUS_APPROVED,
        FINBANK_STATUS_BG_ISSUED,
        FINBANK_STATUS_REJECTED,
    ];
    if ($from === FINBANK_STATUS_SENT) {
        return $workflow;
    }
    return array_values(array_diff($workflow, [$from]));
}

/**
 * Дополняет пакет черновика: новые документы заявки и слоты продукта с файлами, появившиеся после создания кейса.
 */
function finbank_sync_draft_case_package_items(
    PDO $pdo,
    int $caseId,
    int $applicationId,
    int $applicationProductId,
    string $status
): void {
    if ($status !== FINBANK_STATUS_DRAFT) {
        return;
    }

    $stmtMax = $pdo->prepare('SELECT COALESCE(MAX(sort_order), -1) FROM application_product_bank_case_items WHERE bank_case_id = ?');
    $stmtMax->execute([$caseId]);
    $order = (int) $stmtMax->fetchColumn() + 1;

    $stmtExisting = $pdo->prepare(
        'SELECT ref_id FROM application_product_bank_case_items WHERE bank_case_id = ? AND item_type = ?'
    );
    $stmtExisting->execute([$caseId, 'application_document']);
    $existingApp = array_map('intval', $stmtExisting->fetchAll(PDO::FETCH_COLUMN));

    $ins = $pdo->prepare(
        'INSERT INTO application_product_bank_case_items (bank_case_id, item_type, ref_id, sort_order, excluded) VALUES (?,?,?,?,0)'
    );

    $stmtAppDocs = $pdo->prepare('SELECT id FROM application_documents WHERE application_id = ? ORDER BY id ASC');
    $stmtAppDocs->execute([$applicationId]);
    foreach ($stmtAppDocs->fetchAll(PDO::FETCH_COLUMN) as $docId) {
        $docId = (int) $docId;
        if (!in_array($docId, $existingApp, true)) {
            $ins->execute([$caseId, 'application_document', $docId, $order++]);
        }
    }

    $stmtExisting->execute([$caseId, 'product_document']);
    $existingProd = array_map('intval', $stmtExisting->fetchAll(PDO::FETCH_COLUMN));

    $stmtProdDocs = $pdo->prepare(
        'SELECT d.id FROM application_product_documents d
         INNER JOIN application_product_document_files f ON f.document_id = d.id
         WHERE d.application_product_id = ?
         GROUP BY d.id
         ORDER BY d.id ASC'
    );
    $stmtProdDocs->execute([$applicationProductId]);
    foreach ($stmtProdDocs->fetchAll(PDO::FETCH_COLUMN) as $docId) {
        $docId = (int) $docId;
        if (!in_array($docId, $existingProd, true)) {
            $ins->execute([$caseId, 'product_document', $docId, $order++]);
        }
    }
}

/**
 * Создаёт черновик кейса и заполняет пакет: все документы заявки + все запросы по продукту с файлами.
 */
function finbank_ensure_draft_case(PDO $pdo, int $applicationProductId, int $applicationId): array
{
    $stmt = $pdo->prepare('SELECT id, status FROM application_product_bank_cases WHERE application_product_id = ?');
    $stmt->execute([$applicationProductId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        finbank_sync_draft_case_package_items(
            $pdo,
            (int) $existing['id'],
            $applicationId,
            $applicationProductId,
            (string) $existing['status']
        );

        return $existing;
    }

    $productStmt = $pdo->prepare('SELECT bank_name, product_type FROM application_products WHERE id = ? LIMIT 1');
    $productStmt->execute([$applicationProductId]);
    $productRow = $productStmt->fetch(PDO::FETCH_ASSOC);
    $bankCode = $productRow ? finbank_portal_code_from_product_row($productRow) : null;
    if ($bankCode === null) {
        throw new RuntimeException('Продукт не привязан к банку с ЛК');
    }

    $pdo->prepare(
        'INSERT INTO application_product_bank_cases (application_product_id, bank_code, status) VALUES (?, ?, ?)'
    )->execute([$applicationProductId, $bankCode, FINBANK_STATUS_DRAFT]);
    $caseId = (int) $pdo->lastInsertId();

    $order = 0;
    $ins = $pdo->prepare(
        'INSERT INTO application_product_bank_case_items (bank_case_id, item_type, ref_id, sort_order, excluded) VALUES (?,?,?,?,0)'
    );

    $stmt = $pdo->prepare('SELECT id FROM application_documents WHERE application_id = ? ORDER BY id ASC');
    $stmt->execute([$applicationId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $docId) {
        $ins->execute([$caseId, 'application_document', (int) $docId, $order++]);
    }

    $stmt = $pdo->prepare(
        'SELECT d.id FROM application_product_documents d
         INNER JOIN application_product_document_files f ON f.document_id = d.id
         WHERE d.application_product_id = ?
         GROUP BY d.id
         ORDER BY d.id ASC'
    );
    $stmt->execute([$applicationProductId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $docId) {
        $ins->execute([$caseId, 'product_document', (int) $docId, $order++]);
    }

    finbank_sync_draft_case_package_items($pdo, $caseId, $applicationId, $applicationProductId, FINBANK_STATUS_DRAFT);

    return ['id' => $caseId, 'status' => FINBANK_STATUS_DRAFT];
}

function finbank_case_is_submitted(string $status): bool
{
    return $status !== FINBANK_STATUS_DRAFT && $status !== '';
}

/** Кейс, видимый банку (не черновик). $bankCode — код банка из профиля пользователя. */
function finbank_bank_submitted_case(PDO $pdo, int $caseId, string $bankCode): ?array
{
    $bankCode = trim($bankCode);
    if ($caseId <= 0 || $bankCode === '' || finbank_portal_by_code($bankCode) === null) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT c.*, ap.application_id, ap.id AS application_product_id, ap.bank_name, ap.product_name, ap.product_type, ap.status AS product_status
         FROM application_product_bank_cases c
         INNER JOIN application_products ap ON ap.id = c.application_product_id
         WHERE c.id = ? AND c.bank_code = ? AND c.status <> ?'
    );
    $stmt->execute([$caseId, $bankCode, FINBANK_STATUS_DRAFT]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** Банк имеет доступ к заявке через отправленный кейс своего портала. */
function finbank_bank_can_view_application(PDO $pdo, int $applicationId, string $bankCode): bool
{
    if ($applicationId <= 0) {
        return false;
    }
    $bankCode = trim($bankCode);
    if ($bankCode === '' || finbank_portal_by_code($bankCode) === null) {
        return false;
    }
    $stmt = $pdo->prepare(
        'SELECT 1 FROM application_product_bank_cases c
         INNER JOIN application_products ap ON ap.id = c.application_product_id
         WHERE ap.application_id = ? AND c.bank_code = ? AND c.status <> ?
         LIMIT 1'
    );
    $stmt->execute([$applicationId, $bankCode, FINBANK_STATUS_DRAFT]);
    return (bool) $stmt->fetchColumn();
}

/**
 * Сборка пакета документов для UI и API.
 * @param bool $includeExcluded если true — все позиции с полем excluded (для менеджера до отправки)
 */
function finbank_build_package_payload(
    PDO $pdo,
    int $caseId,
    int $applicationId,
    int $applicationProductId,
    bool $includeExcluded = false
): array {
    $items = [];
    $stmt = $pdo->prepare(
        'SELECT i.* FROM application_product_bank_case_items i WHERE i.bank_case_id = ? ORDER BY i.sort_order ASC, i.id ASC'
    );
    $stmt->execute([$caseId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $it) {
        if (!$includeExcluded && (int) $it['excluded'] === 1) {
            continue;
        }
        if ($it['item_type'] === 'application_document') {
            $d = $pdo->prepare('SELECT * FROM application_documents WHERE id = ? AND application_id = ?');
            $d->execute([(int) $it['ref_id'], $applicationId]);
            $doc = $d->fetch(PDO::FETCH_ASSOC);
            if ($doc) {
                $row = [
                    'item_id' => (int) $it['id'],
                    'type' => 'application_document',
                    'ref_id' => (int) $doc['id'],
                    'title' => $doc['document_type'],
                    'description' => $doc['description'] ?? '',
                    'file_path' => $doc['file_path'],
                    'original_name' => $doc['original_name'],
                    'file_size' => (int) ($doc['file_size'] ?? 0),
                    'file_type' => (string) ($doc['file_type'] ?? ''),
                ];
                if ($includeExcluded) {
                    $row['excluded'] = (int) $it['excluded'];
                }
                $items[] = $row;
            }
        } else {
            $d = $pdo->prepare('SELECT * FROM application_product_documents WHERE id = ? AND application_product_id = ?');
            $d->execute([(int) $it['ref_id'], $applicationProductId]);
            $doc = $d->fetch(PDO::FETCH_ASSOC);
            if ($doc) {
                $f = $pdo->prepare('SELECT * FROM application_product_document_files WHERE document_id = ? ORDER BY id ASC');
                $f->execute([(int) $doc['id']]);
                $files = $f->fetchAll(PDO::FETCH_ASSOC);
                $row = [
                    'item_id' => (int) $it['id'],
                    'type' => 'product_document',
                    'ref_id' => (int) $doc['id'],
                    'title' => $doc['title'],
                    'description' => $doc['description'] ?? '',
                    'files' => $files,
                ];
                if ($includeExcluded) {
                    $row['excluded'] = (int) $it['excluded'];
                }
                $items[] = $row;
            }
        }
    }

    $up = $pdo->prepare('SELECT * FROM application_product_bank_case_uploads WHERE bank_case_id = ? ORDER BY id ASC');
    $up->execute([$caseId]);
    $uploads = $up->fetchAll(PDO::FETCH_ASSOC);

    require_once __DIR__ . '/upload_access.php';
    foreach ($items as &$item) {
        if (($item['type'] ?? '') === 'application_document' && !empty($item['ref_id'])) {
            $item['file_url'] = finbuild_upload_file_url('app_doc', (int) $item['ref_id']);
            $item['file_url_download'] = finbuild_upload_file_url('app_doc', (int) $item['ref_id'], true);
        }
        if (($item['type'] ?? '') === 'product_document' && !empty($item['files']) && is_array($item['files'])) {
            $item['files'] = array_map(
                static fn (array $f): array => finbuild_upload_row_with_url($f, 'prod_doc'),
                $item['files']
            );
        }
    }
    unset($item);
    $uploads = array_map(
        static fn (array $u): array => finbuild_upload_row_with_url($u, 'bank_upload'),
        $uploads
    );

    return ['items' => $items, 'uploads' => $uploads];
}
