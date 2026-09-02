<?php
require_once 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? 'client') !== 'manager') {
    echo json_encode(['success' => false, 'error' => 'Доступ запрещен: только для менеджеров']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Неверный метод запроса']);
    exit;
}

$productAppId = intval($_POST['product_app_id'] ?? 0);

if (!$productAppId) {
    echo json_encode(['success' => false, 'error' => 'Неверные параметры']);
    exit;
}

try {
    $pdo = getPDO();
    
    // Получаем информацию о продукте и заявке
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
    
    // Удаляем сообщения чата для этого продукта
    $stmt = $pdo->prepare("DELETE FROM application_product_chats WHERE application_product_id = ?");
    $stmt->execute([$productAppId]);
    
    // Удаляем продукт из заявки
    $stmt = $pdo->prepare("DELETE FROM application_products WHERE id = ?");
    $stmt->execute([$productAppId]);
    
    // Обновляем статус заявки (пересчитываем на основе оставшихся продуктов)
    updateApplicationStatus($product['application_id'], false);
    
    echo json_encode([
        'success' => true, 
        'message' => 'Продукт успешно удален',
        'application_id' => $product['application_id']
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Ошибка сервера: ' . $e->getMessage()]);
}
?>