<?php
/**
 * Запросы документов банка → менеджеру (слоты application_product_documents).
 */
declare(strict_types=1);

function finbank_product_docs_ensure_bank_columns(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM application_product_documents')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable) {
        return;
    }
    if (!in_array('request_source', $cols, true)) {
        try {
            $pdo->exec(
                "ALTER TABLE `application_product_documents`
                 ADD COLUMN `request_source` varchar(16) NOT NULL DEFAULT 'manager' AFTER `created_by`"
            );
        } catch (Throwable) {
        }
    }
    if (!in_array('bank_case_id', $cols, true)) {
        try {
            $pdo->exec(
                "ALTER TABLE `application_product_documents`
                 ADD COLUMN `bank_case_id` int UNSIGNED NULL DEFAULT NULL AFTER `request_source`"
            );
        } catch (Throwable) {
        }
    }
    try {
        $pdo->exec('ALTER TABLE `application_product_documents` ADD KEY `idx_apd_request_source` (`request_source`)');
    } catch (Throwable) {
    }
    try {
        $pdo->exec('ALTER TABLE `application_product_documents` ADD KEY `idx_apd_bank_case` (`bank_case_id`)');
    } catch (Throwable) {
    }
    $done = true;
}

function finbank_product_doc_is_bank_request(array $doc): bool
{
    return ($doc['request_source'] ?? 'manager') === 'bank';
}

/**
 * Добавить слот продукта с файлами в пакет кейса (в т.ч. уже отправленный).
 */
function finbank_case_package_ensure_product_document(PDO $pdo, int $caseId, int $documentId): void
{
    if ($caseId <= 0 || $documentId <= 0) {
        return;
    }
    $hasFile = $pdo->prepare(
        'SELECT 1 FROM application_product_document_files WHERE document_id = ? LIMIT 1'
    );
    $hasFile->execute([$documentId]);
    if (!$hasFile->fetchColumn()) {
        return;
    }
    $exists = $pdo->prepare(
        "SELECT id FROM application_product_bank_case_items
         WHERE bank_case_id = ? AND item_type = 'product_document' AND ref_id = ?
         LIMIT 1"
    );
    $exists->execute([$caseId, $documentId]);
    if ($exists->fetchColumn()) {
        return;
    }
    $max = $pdo->prepare(
        'SELECT COALESCE(MAX(sort_order), -1) FROM application_product_bank_case_items WHERE bank_case_id = ?'
    );
    $max->execute([$caseId]);
    $order = (int) $max->fetchColumn() + 1;
    $pdo->prepare(
        'INSERT INTO application_product_bank_case_items
         (bank_case_id, item_type, ref_id, sort_order, excluded)
         VALUES (?,?,?,?,0)'
    )->execute([$caseId, 'product_document', $documentId, $order]);
}

/**
 * @return list<array<string,mixed>>
 */
function finbank_bank_document_requests_for_case(PDO $pdo, int $caseId): array
{
    finbank_product_docs_ensure_bank_columns($pdo);
    require_once __DIR__ . '/upload_access.php';

    $stmt = $pdo->prepare(
        "SELECT d.*
         FROM application_product_documents d
         WHERE d.bank_case_id = ? AND d.request_source = 'bank'
         ORDER BY d.created_at DESC, d.id DESC"
    );
    $stmt->execute([$caseId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $doc) {
        $filesStmt = $pdo->prepare(
            'SELECT * FROM application_product_document_files WHERE document_id = ? ORDER BY id ASC'
        );
        $filesStmt->execute([(int) $doc['id']]);
        $files = array_map(
            static fn (array $f): array => finbuild_upload_row_with_url($f, 'prod_doc'),
            $filesStmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
        $doc['files'] = $files;
        $doc['has_files'] = $files !== [];
        $out[] = $doc;
    }
    return $out;
}
