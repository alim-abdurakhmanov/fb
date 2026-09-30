<?php
/**
 * Автосоздание документов тестовой заявки SEED-KAMCOM-TEST-APP-001
 * и привязка к пакету кейса Камкомбанка (без ручного запуска CLI).
 */
declare(strict_types=1);

const FINBANK_SEED_KAMCOM_MARKER = 'SEED-KAMCOM-TEST-APP-001';
const FINBANK_SEED_KAMCOM_USER_ID = 1;

function finbank_seed_minimal_pdf(string $title): string
{
    $safe = preg_replace('/[^\x20-\x7E]+/', ' ', $title) ?? 'Document';
    $safe = substr($safe, 0, 80);
    $stream = "BT /F1 12 Tf 50 750 Td (" . str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $safe) . ") Tj ET";
    $len = strlen($stream);

    $objects = [];
    $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
    $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>';
    $objects[] = "<< /Length {$len} >>\nstream\n{$stream}\nendstream";
    $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $i => $body) {
        $offsets[] = strlen($pdf);
        $n = $i + 1;
        $pdf .= "{$n} 0 obj\n{$body}\nendobj\n";
    }
    $xrefPos = strlen($pdf);
    $count = count($objects) + 1;
    $pdf .= "xref\n0 {$count}\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i < $count; $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }
    $pdf .= "trailer\n<< /Size {$count} /Root 1 0 R >>\nstartxref\n{$xrefPos}\n%%EOF\n";
    return $pdf;
}

/**
 * @return list<array{document_type:string,description:string,original_name:string}>
 */
function finbank_seed_kamcom_doc_defs(): array
{
    return [
        [
            'document_type' => 'Квартальная бухгалтерская отчетность (Ф1 + Ф2)',
            'description' => 'Формы 1 и 2 бухгалтерской отчетности за последний отчетный период',
            'original_name' => 'Бухгалтерская_отчетность_Ф1_Ф2_ВолгаСтройИнвест.pdf',
        ],
        [
            'document_type' => 'Проект или скан договора/контракта',
            'description' => 'Проект контракта по закупке (капитальный ремонт МБОУ СОШ № 12)',
            'original_name' => 'Проект_контракта_СОШ_12.pdf',
        ],
        [
            'document_type' => 'Реестр контрактов',
            'description' => 'Реестр исполненных контрактов за последние 3 года',
            'original_name' => 'Реестр_контрактов_3года.pdf',
        ],
        [
            'document_type' => 'Кредитный портфель',
            'description' => 'Информация о текущем кредитном портфеле компании',
            'original_name' => 'Кредитный_портфель.pdf',
        ],
        [
            'document_type' => 'Другие документы',
            'description' => 'Карточка предприятия и решение о назначении директора',
            'original_name' => 'Карточка_предприятия_и_полномочия.pdf',
        ],
    ];
}

function finbank_seed_is_kamcom_test_application(array $application): bool
{
    $purchase = trim((string) ($application['purchase_number'] ?? ''));
    if ($purchase === FINBANK_SEED_KAMCOM_MARKER) {
        return true;
    }
    $comment = (string) ($application['comment'] ?? '');
    return str_contains($comment, FINBANK_SEED_KAMCOM_MARKER);
}

/**
 * Идемпотентно создаёт PDF-заглушки и позиции пакета для тестовой заявки.
 *
 * @return array{added_docs:int,linked:int,app_id:int,case_id:int}|null
 */
function finbank_seed_ensure_kamcom_test_docs(PDO $pdo, int $applicationId, int $caseId, ?int $uploadedBy = null): ?array
{
    if ($applicationId <= 0 || $caseId <= 0) {
        return null;
    }

    $appStmt = $pdo->prepare('SELECT id, purchase_number, comment FROM applications WHERE id = ? LIMIT 1');
    $appStmt->execute([$applicationId]);
    $application = $appStmt->fetch(PDO::FETCH_ASSOC);
    if (!$application || !finbank_seed_is_kamcom_test_application($application)) {
        return null;
    }

    $userId = $uploadedBy ?? FINBANK_SEED_KAMCOM_USER_ID;
    $userOk = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE id = ' . (int) $userId)->fetchColumn();
    if ($userOk <= 0) {
        $userId = FINBANK_SEED_KAMCOM_USER_ID;
        $userOk = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE id = ' . (int) $userId)->fetchColumn();
        if ($userOk <= 0) {
            return null;
        }
    }

    $uploadDirRel = 'uploads/applications/' . $applicationId . '/';
    $uploadDirAbs = rtrim((string) FINBUILD_ROOT, "/\\") . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $uploadDirRel);
    if (!is_dir($uploadDirAbs) && !mkdir($uploadDirAbs, 0755, true) && !is_dir($uploadDirAbs)) {
        return null;
    }

    $existsStmt = $pdo->prepare(
        'SELECT id FROM application_documents
         WHERE application_id = ? AND original_name = ?
         LIMIT 1'
    );
    $insertDoc = $pdo->prepare(
        'INSERT INTO application_documents
         (application_id, document_type, description, file_path, original_name, file_size, file_type, uploaded_by)
         VALUES (?,?,?,?,?,?,?,?)'
    );
    $linkExists = $pdo->prepare(
        "SELECT id FROM application_product_bank_case_items
         WHERE bank_case_id = ? AND item_type = 'application_document' AND ref_id = ?
         LIMIT 1"
    );
    $sortStmt = $pdo->prepare(
        'SELECT COALESCE(MAX(sort_order), -1) FROM application_product_bank_case_items WHERE bank_case_id = ?'
    );
    $sortStmt->execute([$caseId]);
    $sort = (int) $sortStmt->fetchColumn();
    $insertItem = $pdo->prepare(
        'INSERT INTO application_product_bank_case_items
         (bank_case_id, item_type, ref_id, sort_order, excluded)
         VALUES (?,?,?,?,0)'
    );

    $addedDocs = 0;
    $linked = 0;

    foreach (finbank_seed_kamcom_doc_defs() as $doc) {
        $existsStmt->execute([$applicationId, $doc['original_name']]);
        $docId = (int) $existsStmt->fetchColumn();

        if ($docId <= 0) {
            $safeFile = 'seed_' . preg_replace('/[^a-zA-Z0-9_\-]+/', '_', pathinfo($doc['original_name'], PATHINFO_FILENAME)) . '.pdf';
            $relPath = $uploadDirRel . $safeFile;
            $absPath = $uploadDirAbs . $safeFile;
            $binary = finbank_seed_minimal_pdf($doc['document_type'] . ' — ' . FINBANK_SEED_KAMCOM_MARKER);
            if (file_put_contents($absPath, $binary) === false) {
                continue;
            }
            $size = (int) filesize($absPath);
            $insertDoc->execute([
                $applicationId,
                $doc['document_type'],
                $doc['description'],
                $relPath,
                $doc['original_name'],
                $size,
                'pdf',
                $userId,
            ]);
            $docId = (int) $pdo->lastInsertId();
            $addedDocs++;
        }

        $linkExists->execute([$caseId, $docId]);
        if (!(int) $linkExists->fetchColumn()) {
            $sort++;
            $insertItem->execute([$caseId, 'application_document', $docId, $sort]);
            $linked++;
        }
    }

    return [
        'added_docs' => $addedDocs,
        'linked' => $linked,
        'app_id' => $applicationId,
        'case_id' => $caseId,
    ];
}
