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

$documentId = intval($_POST['document_id'] ?? 0);

if (!$documentId) {
    echo json_encode(['success' => false, 'error' => 'Неверные параметры']);
    exit;
}

try {
    $pdo = getPDO();
    
    // Получаем информацию о документе
    $stmt = $pdo->prepare("
        SELECT d.*, a.created_by as application_creator 
        FROM application_documents d 
        JOIN applications a ON d.application_id = a.id 
        WHERE d.id = ?
    ");
    $stmt->execute([$documentId]);
    $document = $stmt->fetch();
    
    if (!$document) {
        echo json_encode(['success' => false, 'error' => 'Документ не найден']);
        exit;
    }
    
    // Проверяем права доступа
    $userRole = $_SESSION['role'] ?? 'client';
    $userId = $_SESSION['user_id'];
    
    // Менеджеры могут удалять любые документы, клиенты - только свои
    $canDelete = ($userRole === 'manager') || ($document['uploaded_by'] == $userId);
    
    if (!$canDelete) {
        echo json_encode(['success' => false, 'error' => 'Недостаточно прав для удаления документа']);
        exit;
    }
    
    // Удаляем файл с диска
    if (file_exists($document['file_path'])) {
        unlink($document['file_path']);
    }
    
    // Удаляем запись из базы данных
    $stmt = $pdo->prepare("DELETE FROM application_documents WHERE id = ?");
    $stmt->execute([$documentId]);
    
    echo json_encode([
        'success' => true, 
        'message' => 'Документ успешно удален'
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Ошибка при удалении документа: ' . $e->getMessage()]);
}
?>