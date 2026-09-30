<?php
/**
 * Добавляет к тестовой заявке SEED-KAMCOM-TEST-APP-001 документы заявки
 * и включает их в пакет кейса Камкомбанка (вкладка «Документы» в ЛК банка).
 *
 * Идемпотентно. Запуск из корня проекта:
 *   php migrations/seed_kamcom_test_application_docs.php
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

const SEED_MARKER = 'SEED-KAMCOM-TEST-APP-001';
const SEED_USER_ID = 1;

/**
 * Минимальный одностраничный PDF с текстовой меткой.
 */
function seed_minimal_pdf(string $title): string
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

$docs = [
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

$pdo = getPDO();

$userOk = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE id = ' . SEED_USER_ID)->fetchColumn();
if ($userOk <= 0) {
    fwrite(STDERR, "users.id=" . SEED_USER_ID . " не найден — прерывание.\n");
    exit(1);
}

$stmt = $pdo->prepare(
    'SELECT id FROM applications
     WHERE purchase_number = ? OR comment LIKE ?
     ORDER BY id DESC LIMIT 1'
);
$stmt->execute([SEED_MARKER, '%' . SEED_MARKER . '%']);
$appId = (int) $stmt->fetchColumn();
if ($appId <= 0) {
    fwrite(STDERR, "Тестовая заявка " . SEED_MARKER . " не найдена. Сначала выполните seed_kamcom_test_application.sql\n");
    exit(1);
}

$apStmt = $pdo->prepare(
    "SELECT id FROM application_products
     WHERE application_id = ? AND product_type = 'bg' AND bank_name = 'Камкомбанк'
     ORDER BY id DESC LIMIT 1"
);
$apStmt->execute([$appId]);
$apId = (int) $apStmt->fetchColumn();
if ($apId <= 0) {
    fwrite(STDERR, "Продукт Камкомбанк для заявки #{$appId} не найден.\n");
    exit(1);
}

$caseStmt = $pdo->prepare(
    'SELECT id FROM application_product_bank_cases WHERE application_product_id = ? LIMIT 1'
);
$caseStmt->execute([$apId]);
$caseId = (int) $caseStmt->fetchColumn();
if ($caseId <= 0) {
    fwrite(STDERR, "Кейс банка для application_product #{$apId} не найден.\n");
    exit(1);
}

$uploadDirRel = 'uploads/applications/' . $appId . '/';
$uploadDirAbs = rtrim((string) FINBUILD_ROOT, "/\\") . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $uploadDirRel);
if (!is_dir($uploadDirAbs) && !mkdir($uploadDirAbs, 0755, true) && !is_dir($uploadDirAbs)) {
    fwrite(STDERR, "Не удалось создать каталог {$uploadDirAbs}\n");
    exit(1);
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

foreach ($docs as $doc) {
    $existsStmt->execute([$appId, $doc['original_name']]);
    $docId = (int) $existsStmt->fetchColumn();

    if ($docId <= 0) {
        $safeFile = 'seed_' . preg_replace('/[^a-zA-Z0-9_\-]+/', '_', pathinfo($doc['original_name'], PATHINFO_FILENAME)) . '.pdf';
        $relPath = $uploadDirRel . $safeFile;
        $absPath = $uploadDirAbs . $safeFile;
        $binary = seed_minimal_pdf($doc['document_type'] . ' — ' . SEED_MARKER);
        if (file_put_contents($absPath, $binary) === false) {
            fwrite(STDERR, "Не удалось записать файл {$absPath}\n");
            exit(1);
        }
        $size = (int) filesize($absPath);
        $insertDoc->execute([
            $appId,
            $doc['document_type'],
            $doc['description'],
            $relPath,
            $doc['original_name'],
            $size,
            'pdf',
            SEED_USER_ID,
        ]);
        $docId = (int) $pdo->lastInsertId();
        $addedDocs++;
        echo "Документ создан: {$doc['original_name']} (id={$docId})\n";
    } else {
        echo "Документ уже есть: {$doc['original_name']} (id={$docId})\n";
    }

    $linkExists->execute([$caseId, $docId]);
    if (!(int) $linkExists->fetchColumn()) {
        $sort++;
        $insertItem->execute([$caseId, 'application_document', $docId, $sort]);
        $linked++;
        echo "  → добавлен в пакет кейса #{$caseId}\n";
    } else {
        echo "  → уже в пакете кейса #{$caseId}\n";
    }
}

echo "Готово. Заявка #{$appId}, кейс #{$caseId}: новых документов {$addedDocs}, новых связей в пакете {$linked}.\n";
