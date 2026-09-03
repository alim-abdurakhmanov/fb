<?php
require_once 'config.php';

// Убедимся, что нет лишних пробелов/выводов до заголовков
if (ob_get_level()) ob_clean();
header('Content-Type: application/json');

// Проверяем авторизацию
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Доступ запрещен']);
    exit;
}

// Проверяем роль
$userRole = $_SESSION['role'] ?? 'client';
if (!finbuild_is_manager($userRole)) {
    echo json_encode(['success' => false, 'error' => 'Доступ запрещен: только для менеджеров']);
    exit;
}

// Проверяем метод
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Неверный метод запроса']);
    exit;
}

// Получаем и валидируем параметры
$applicationId = isset($_POST['application_id']) ? intval($_POST['application_id']) : 0;
$productId = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
$productType = $_POST['product_type'] ?? '';

if (!$applicationId || !$productId || !in_array($productType, ['bg', 'credit'])) {
    echo json_encode(['success' => false, 'error' => 'Неверные параметры']);
    exit;
}

try {
    $pdo = getPDO();
    
    // Проверяем существование заявки
    $stmt = $pdo->prepare("SELECT * FROM applications WHERE id = ?");
    $stmt->execute([$applicationId]);
    $application = $stmt->fetch();
    
    if (!$application) {
        echo json_encode(['success' => false, 'error' => 'Заявка не найдена']);
        exit;
    }
    
    // Проверяем существование продукта
    if ($productType === 'bg') {
        $stmt = $pdo->prepare("SELECT * FROM bank_products WHERE id = ?");
    } else {
        $stmt = $pdo->prepare("SELECT * FROM credit_products WHERE id = ?");
    }
    $stmt->execute([$productId]);
    $product = $stmt->fetch();
    
    if (!$product) {
        echo json_encode(['success' => false, 'error' => 'Продукт не найден']);
        exit;
    }
    
    // Проверяем, не добавлен ли уже этот продукт
    $stmt = $pdo->prepare("SELECT id FROM application_products WHERE application_id = ? AND product_id = ? AND product_type = ?");
    $stmt->execute([$applicationId, $productId, $productType]);
    
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Этот продукт уже добавлен к заявке']);
        exit;
    }
    
    // Добавляем продукт к заявке
    $stmt = $pdo->prepare("INSERT INTO application_products (application_id, product_id, product_type, bank_name, product_name) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([
        $applicationId,
        $productId,
        $productType,
        $product['bank_name'],
        $product['name']
    ]);
    
    $productAppId = $pdo->lastInsertId();
    
    // Если это первый добавленный продукт, меняем статус заявки на "В работе"
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM application_products WHERE application_id = ?");
    $stmt->execute([$applicationId]);
   updateApplicationStatus($applicationId, true); // true = новый продукт добавлен
    
    echo json_encode(['success' => true, 'product_app_id' => $productAppId]);
    
} catch (Exception $e) {
    // Ловим любые исключения и возвращаем JSON ошибку
    echo json_encode(['success' => false, 'error' => 'Ошибка сервера: ' . $e->getMessage()]);
}
?>