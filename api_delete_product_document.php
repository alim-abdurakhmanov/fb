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
if ($userRole !== 'manager') {
    echo json_encode(['success' => false, 'error' => 'Недостаточно прав']);
    exit;
}

$documentId = intval($_POST['document_id'] ?? 0);
$fileId = intval($_POST['file_id'] ?? 0);

if (!$documentId && !$fileId) {
    echo json_encode(['success' => false, 'error' => 'Неверные параметры']);
    exit;
}

try {
    $pdo = getPDO();
    
    if ($fileId) {
        // Удаление конкретного файла
        $stmt = $pdo->prepare("SELECT * FROM application_product_document_files WHERE id = ?");
        $stmt->execute([$fileId]);
        $file = $stmt->fetch();
        
        if (!$file) {
            echo json_encode(['success' => false, 'error' => 'Файл не найден']);
            exit;
        }
        
        // Удаляем файл с диска
        if (file_exists($file['file_path'])) {
            unlink($file['file_path']);
        }
        
        // Удаляем запись из базы
        $stmt = $pdo->prepare("DELETE FROM application_product_document_files WHERE id = ?");
        $stmt->execute([$fileId]);
        
        echo json_encode(['success' => true, 'message' => 'Файл удален']);
        
    } elseif ($documentId) {
        // Удаление всего документа (ячейки) со всеми файлами
        $stmt = $pdo->prepare("SELECT * FROM application_product_documents WHERE id = ?");
        $stmt->execute([$documentId]);
        $document = $stmt->fetch();
        
        if (!$document) {
            echo json_encode(['success' => false, 'error' => 'Документ не найден']);
            exit;
        }
        
        // Получаем все файлы документа
        $stmt = $pdo->prepare("SELECT * FROM application_product_document_files WHERE document_id = ?");
        $stmt->execute([$documentId]);
        $files = $stmt->fetchAll();
        
        // Удаляем файлы с диска
        foreach ($files as $file) {
            if (file_exists($file['file_path'])) {
                unlink($file['file_path']);
            }
        }
        
        // Удаляем директорию если пустая
        $uploadDir = 'uploads/product_documents/' . $document['application_product_id'] . '/' . $documentId . '/';
        if (is_dir($uploadDir)) {
            @rmdir($uploadDir);
        }
        
        // Удаляем документ (файлы удалятся автоматически через CASCADE)
        $stmt = $pdo->prepare("DELETE FROM application_product_documents WHERE id = ?");
        $stmt->execute([$documentId]);
        
        echo json_encode(['success' => true, 'message' => 'Документ удален']);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Ошибка: ' . $e->getMessage()]);
}
?>
