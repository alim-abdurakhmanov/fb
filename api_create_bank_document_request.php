<?php
/**
 * ЛК банка: создать запрос документа менеджеру (слот на вкладке «Документы» продукта).
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/bank_portal.php';
require_once __DIR__ . '/includes/bank_document_requests.php';
require_once __DIR__ . '/includes/notification_events.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || !finbuild_sync_session_user()) {
    echo json_encode(['success' => false, 'error' => 'Не авторизован'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Неверный метод запроса'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SESSION['role'] ?? '') !== 'bank') {
    echo json_encode(['success' => false, 'error' => 'Только банк может создавать такие запросы'], JSON_UNESCAPED_UNICODE);
    exit;
}

$caseId = (int) ($_POST['bank_case_id'] ?? 0);
$title = trim((string) ($_POST['title'] ?? ''));
$description = trim((string) ($_POST['description'] ?? ''));

if ($caseId <= 0 || $title === '') {
    echo json_encode(['success' => false, 'error' => 'Заполните название документа'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = getPDO();
    $userId = (int) $_SESSION['user_id'];
    $user = getCurrentUser() ?: ['id' => $userId, 'role' => 'bank'];
    $bankCode = finbank_user_bank_code($pdo, $user);
    if ($bankCode === null) {
        echo json_encode(['success' => false, 'error' => 'Нет доступа'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $case = finbank_bank_submitted_case($pdo, $caseId, $bankCode);
    if (!$case) {
        echo json_encode(['success' => false, 'error' => 'Нет доступа к заявке'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (finbank_product_status_is_failed($case['product_status'] ?? null)) {
        echo json_encode(['success' => false, 'error' => 'Заявка не актуальна'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $productAppId = (int) $case['application_product_id'];
    finbank_product_docs_ensure_bank_columns($pdo);

    $stmt = $pdo->prepare(
        'INSERT INTO application_product_documents
         (application_product_id, title, description, created_by, request_source, bank_case_id)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $productAppId,
        $title,
        $description !== '' ? $description : null,
        $userId,
        'bank',
        $caseId,
    ]);
    $documentId = (int) $pdo->lastInsertId();

    notify_bank_document_request_created($pdo, $caseId, $productAppId, $title);

    echo json_encode([
        'success' => true,
        'document_id' => $documentId,
        'message' => 'Запрос на документ отправлен менеджеру',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => 'Ошибка: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
