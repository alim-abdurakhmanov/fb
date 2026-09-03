<?php
require_once 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !finbuild_is_manager((string) ($_SESSION['role'] ?? 'client'))) {
    echo json_encode(['success' => false, 'error' => 'Доступ запрещен']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Неверный метод запроса']);
    exit;
}

$productAppId = intval($_POST['product_app_id'] ?? 0);
$newStatus = $_POST['status'] ?? '';

// Допустимые статусы для разных типов продуктов
$allowedStatuses = [
    'bg' => ['В работе', 'На подписании', 'Запрос', 'Согласование условий', 'На выпуске', 'БГ выпущена', 'Отказано', 'Не актуален для клиента'],
    'credit' => ['В работе', 'Запрос', 'Выдан', 'Отказано', 'Не актуален для клиента']
];

if (!$productAppId || empty($newStatus)) {
    echo json_encode(['success' => false, 'error' => 'Неверные параметры']);
    exit;
}

try {
    $pdo = getPDO();
    
    // Получаем информацию о продукте
    $stmt = $pdo->prepare("
        SELECT ap.*, a.id as application_id 
        FROM application_products ap 
        JOIN applications a ON ap.application_id = a.id 
        WHERE ap.id = ?
    ");
    $stmt->execute([$productAppId]);
    $product = $stmt->fetch();
    
    if (!$product) {
        echo json_encode(['success' => false, 'error' => 'Продукт не найден']);
        exit;
    }
    
    // Проверяем допустимость статуса для типа продукта
    $productType = $product['product_type'];
    if (!in_array($newStatus, $allowedStatuses[$productType])) {
        echo json_encode(['success' => false, 'error' => 'Недопустимый статус для данного типа продукта']);
        exit;
    }
    
    // Обновляем статус продукта
    $stmt = $pdo->prepare("UPDATE application_products SET status = ? WHERE id = ?");
    $stmt->execute([$newStatus, $productAppId]);
    
    // Автоматически обновляем статус заявки
  $applicationStatus = updateApplicationStatus($product['application_id'], false); // false = не новый продукт
    
    echo json_encode([
        'success' => true, 
        'product_status' => $newStatus,
        'application_status' => $applicationStatus
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Ошибка сервера: ' . $e->getMessage()]);
}
?>