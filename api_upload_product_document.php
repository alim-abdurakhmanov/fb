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
$clientComment = trim($_POST['client_comment'] ?? '');

if (!$documentId || empty($_FILES['document_files'])) {
    echo json_encode(['success' => false, 'error' => 'Неверные параметры']);
    exit;
}

try {
    $pdo = getPDO();
    $userId = $_SESSION['user_id'];
    $userRole = $_SESSION['role'] ?? 'client';
    require_once __DIR__ . '/includes/bank_document_requests.php';
    finbank_product_docs_ensure_bank_columns($pdo);
    
    // Получаем информацию о документе и проверяем доступ
    $stmt = $pdo->prepare("
        SELECT d.*, ap.application_id, ap.id AS application_product_id, a.created_by, a.assigned_to
        FROM application_product_documents d
        JOIN application_products ap ON d.application_product_id = ap.id
        JOIN applications a ON ap.application_id = a.id
        WHERE d.id = ?
    ");
    $stmt->execute([$documentId]);
    $document = $stmt->fetch();
    
    if (!$document) {
        echo json_encode(['success' => false, 'error' => 'Документ не найден']);
        exit;
    }

    $isBankRequest = (($document['request_source'] ?? 'manager') === 'bank');
    
    // Клиенты могут загружать только в свои заявки (не запросы банка), менеджеры - в любые
    if (!finbuild_is_manager($userRole)) {
        if ($isBankRequest || (int) $document['created_by'] !== (int) $userId) {
            echo json_encode(['success' => false, 'error' => 'Доступ запрещен']);
            exit;
        }
    }
    
    // Обновляем комментарий клиента (если есть)
    if (!empty($clientComment)) {
        $stmt = $pdo->prepare("UPDATE application_product_documents SET client_comment = ? WHERE id = ?");
        $stmt->execute([$clientComment, $documentId]);
    }
    
    // Создаем директорию для загрузки
    $uploadDir = 'uploads/product_documents/' . $document['application_product_id'] . '/' . $documentId . '/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    
    $uploadedCount = 0;
    $errors = [];
    
    // Обрабатываем каждый файл
    foreach ($_FILES['document_files']['name'] as $key => $name) {
        if ($_FILES['document_files']['error'][$key] === UPLOAD_ERR_OK) {
            $fileTmp = $_FILES['document_files']['tmp_name'][$key];
            $fileSize = $_FILES['document_files']['size'][$key];
            $originalName = $name;
            $fileExt = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            
            // Проверяем тип файла
            $allowedExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'txt', 'zip', 'rar'];
            if (!in_array($fileExt, $allowedExtensions)) {
                $errors[] = "Файл $originalName имеет недопустимый формат";
                continue;
            }
            
            // Проверяем размер файла (20MB максимум)
            if ($fileSize > 20 * 1024 * 1024) {
                $errors[] = "Файл $originalName слишком большой (макс. 20MB)";
                continue;
            }
            
            // Генерируем уникальное имя файла
            $fileName = uniqid() . '.' . $fileExt;
            $filePath = $uploadDir . $fileName;
            
            // Определяем тип файла для иконки
            $fileType = getFileType($fileExt);
            
            // Перемещаем файл
            if (move_uploaded_file($fileTmp, $filePath)) {
                // Сохраняем информацию о файле в базу
                $stmt = $pdo->prepare("
                    INSERT INTO application_product_document_files 
                    (document_id, file_path, original_name, file_size, file_type, uploaded_by)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$documentId, $filePath, $originalName, $fileSize, $fileType, $userId]);
                $uploadedCount++;
            } else {
                $errors[] = "Не удалось загрузить файл $originalName";
            }
        }
    }
    
    $response = [
        'success' => true,
        'uploaded_count' => $uploadedCount,
        'message' => "Загружено файлов: $uploadedCount"
    ];
    
    if (!empty($errors)) {
        $response['errors'] = $errors;
    }

    if ($uploadedCount > 0) {
        require_once __DIR__ . '/includes/notification_events.php';
        require_once __DIR__ . '/includes/bank_document_requests.php';
        finbank_product_docs_ensure_bank_columns($pdo);

        $src = (string) ($document['request_source'] ?? 'manager');
        $bankCaseId = (int) ($document['bank_case_id'] ?? 0);
        if ($src === 'bank' && $bankCaseId > 0) {
            finbank_case_package_ensure_product_document($pdo, $bankCaseId, $documentId);
            notify_bank_document_request_fulfilled($pdo, $documentId, $userId);
        } else {
            // Для обычных запросов менеджера — по возможности дополняем черновик пакета банка
            try {
                require_once __DIR__ . '/includes/bank_portal.php';
                $caseStmt = $pdo->prepare(
                    'SELECT id, status FROM application_product_bank_cases WHERE application_product_id = ? LIMIT 1'
                );
                $caseStmt->execute([(int) $document['application_product_id']]);
                $caseRow = $caseStmt->fetch(PDO::FETCH_ASSOC);
                if ($caseRow && (string) ($caseRow['status'] ?? '') === FINBANK_STATUS_DRAFT) {
                    finbank_case_package_ensure_product_document($pdo, (int) $caseRow['id'], $documentId);
                }
            } catch (Throwable) {
            }
            notify_product_document_uploaded($pdo, $documentId, $userId);
        }
    }
    
    echo json_encode($response);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Ошибка: ' . $e->getMessage()]);
}

function getFileType($extension) {
    $types = [
        'pdf' => 'pdf',
        'doc' => 'word',
        'docx' => 'word',
        'xls' => 'excel',
        'xlsx' => 'excel',
        'jpg' => 'image',
        'jpeg' => 'image',
        'png' => 'image',
        'txt' => 'text',
        'zip' => 'archive',
        'rar' => 'archive'
    ];
    return $types[$extension] ?? 'file';
}
?>
