<?php
require_once 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Доступ запрещен']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Неверный метод запроса']);
    exit;
}

$userRole = $_SESSION['role'] ?? 'client';
if (!finbuild_is_manager($userRole)) {
    echo json_encode(['success' => false, 'error' => 'Только менеджеры могут создавать запросы документов']);
    exit;
}

$productAppId = intval($_POST['product_app_id'] ?? 0);
$title = trim($_POST['title'] ?? '');
$description = trim($_POST['description'] ?? '');

if (!$productAppId || empty($title)) {
    echo json_encode(['success' => false, 'error' => 'Заполните название документа']);
    exit;
}

try {
    $pdo = getPDO();
    $userId = $_SESSION['user_id'];
    
    // Проверяем существование продукта
    $stmt = $pdo->prepare("SELECT id FROM application_products WHERE id = ?");
    $stmt->execute([$productAppId]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Продукт не найден']);
        exit;
    }
    
    // Создаем запрос документа
    $stmt = $pdo->prepare("
        INSERT INTO application_product_documents (application_product_id, title, description, created_by)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$productAppId, $title, $description, $userId]);
    
    $documentId = $pdo->lastInsertId();

    require_once __DIR__ . '/includes/notification_events.php';
    notify_document_request_created($pdo, $productAppId, $title);
    
    echo json_encode([
        'success' => true,
        'document_id' => $documentId,
        'message' => 'Запрос на документ создан'
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Ошибка: ' . $e->getMessage()]);
}
?>
