<?php
require_once 'config.php';
require_once __DIR__ . '/includes/application_documents_upload.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Доступ запрещен']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Неверный метод запроса']);
    exit;
}

$applicationId = intval($_POST['application_id'] ?? 0);
$documentType = $_POST['document_type'] ?? '';
$description = $_POST['description'] ?? '';

if (!$applicationId || empty($documentType) || empty($_FILES['document_file'])) {
    echo json_encode(['success' => false, 'error' => 'Неверные параметры']);
    exit;
}

try {
    $pdo = getPDO();
    
    // Проверяем существование заявки и доступ
    $stmt = $pdo->prepare("SELECT * FROM applications WHERE id = ?");
    $stmt->execute([$applicationId]);
    $application = $stmt->fetch();
    
    if (!$application) {
        echo json_encode(['success' => false, 'error' => 'Заявка не найдена']);
        exit;
    }
    
    // Проверяем доступ (клиенты могут загружать только в свои заявки, менеджеры - в любые)
    $userRole = $_SESSION['role'] ?? 'client';
    $userId = $_SESSION['user_id'];
    
    if (!finbuild_is_manager($userRole) && $application['created_by'] != $userId) {
        echo json_encode(['success' => false, 'error' => 'Доступ запрещен']);
        exit;
    }
    
    // Обработка файла
    $file = $_FILES['document_file'];
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("Ошибка загрузки файла: " . $file['error']);
    }
    
    $file_tmp = $file['tmp_name'];
    $file_size = $file['size'];
    $original_name = $file['name'];
    $file_ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
    finbuild_application_document_validate_upload($original_name, (int) $file_size);
    
    // Создаем директорию если не существует
    $upload_dir = 'uploads/applications/' . $applicationId . '/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    // Генерируем уникальное имя файла
    $file_name = uniqid() . '.' . $file_ext;
    $file_path = $upload_dir . $file_name;
    
    // Определяем тип файла для иконки
    $file_type = finbuild_application_document_file_type($file_ext);
    
    // Перемещаем файл
    if (!move_uploaded_file($file_tmp, $file_path)) {
        throw new Exception("Ошибка при сохранении файла");
    }
    
    // Сохраняем в базу данных
    $stmt = $pdo->prepare("INSERT INTO application_documents 
        (application_id, document_type, description, file_path, original_name, file_size, file_type, uploaded_by) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    
    $stmt->execute([
        $applicationId,
        $documentType,
        $description,
        $file_path,
        $original_name,
        $file_size,
        $file_type,
        $userId
    ]);
    
    $documentId = $pdo->lastInsertId();
    
    echo json_encode([
        'success' => true, 
        'document_id' => $documentId,
        'message' => 'Документ успешно загружен'
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>