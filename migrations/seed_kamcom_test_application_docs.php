<?php
/**
 * Опциональный CLI-запуск. Обычно не нужен: документы создаются сами
 * при открытии тестовой заявки в ЛК банка (api_bank_case.php → bank_get).
 *
 *   php migrations/seed_kamcom_test_application_docs.php
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/seed_kamcom_test_docs.php';

$pdo = getPDO();

$stmt = $pdo->prepare(
    'SELECT a.id AS application_id, c.id AS case_id
     FROM applications a
     INNER JOIN application_products ap ON ap.application_id = a.id AND ap.product_type = ? AND ap.bank_name = ?
     INNER JOIN application_product_bank_cases c ON c.application_product_id = ap.id
     WHERE a.purchase_number = ? OR a.comment LIKE ?
     ORDER BY a.id DESC
     LIMIT 1'
);
$stmt->execute(['bg', 'Камкомбанк', FINBANK_SEED_KAMCOM_MARKER, '%' . FINBANK_SEED_KAMCOM_MARKER . '%']);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    fwrite(STDERR, "Тестовая заявка " . FINBANK_SEED_KAMCOM_MARKER . " не найдена.\n");
    exit(1);
}

$result = finbank_seed_ensure_kamcom_test_docs(
    $pdo,
    (int) $row['application_id'],
    (int) $row['case_id'],
    FINBANK_SEED_KAMCOM_USER_ID
);

if ($result === null) {
    fwrite(STDERR, "Не удалось создать документы.\n");
    exit(1);
}

echo "Готово. Заявка #{$result['app_id']}, кейс #{$result['case_id']}: "
    . "новых документов {$result['added_docs']}, новых связей {$result['linked']}.\n";
