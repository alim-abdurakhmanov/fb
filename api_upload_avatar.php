<?php
require_once 'config.php';
require_once __DIR__ . '/includes/upload_access.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Доступ запрещен']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Неверный метод запроса']);
    exit;
}

if (!isset($_FILES['avatar'])) {
    echo json_encode(['success' => false, 'error' => 'Файл не загружен']);
    exit;
}

try {
    $pdo = getPDO();
    $userId = $_SESSION['user_id'];
    
    // Получаем текущего пользователя
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if (!$user) {
        echo json_encode(['success' => false, 'error' => 'Пользователь не найден']);
        exit;
    }
    
    // Обработка файла
    $file = $_FILES['avatar'];
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("Ошибка загрузки файла: " . $file['error']);
    }
    
    $file_tmp = $file['tmp_name'];
    $file_size = $file['size'];
    $original_name = $file['name'];
    $file_ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
    
    // Проверяем тип файла
    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (!in_array($file_ext, $allowed_extensions)) {
        throw new Exception("Недопустимый формат изображения. Разрешены: " . implode(', ', $allowed_extensions));
    }
    
    // Проверяем размер файла (2MB максимум)
    if ($file_size > 2 * 1024 * 1024) {
        throw new Exception("Файл слишком большой. Максимальный размер: 2MB");
    }
    
    // Создаем директорию если не существует
    $upload_dir = 'uploads/avatars/' . $userId . '/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    // Удаляем старый аватар если есть
    if ($user['avatar_path'] && file_exists($user['avatar_path'])) {
        unlink($user['avatar_path']);
    }
    
    // Генерируем уникальное имя файла
    $file_name = 'avatar_' . time() . '.' . $file_ext;
    $file_path = $upload_dir . $file_name;
    
    // Просто перемещаем файл без ресайза
    if (!move_uploaded_file($file_tmp, $file_path)) {
        throw new Exception("Ошибка при сохранении файла");
    }
    
    // Обновляем путь к аватару в базе данных
    $stmt = $pdo->prepare("UPDATE users SET avatar_path = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$file_path, $userId]);
    
    echo json_encode([
        'success' => true,
        'avatar_url' => finbuild_upload_file_url('avatar', $userId),
        'message' => 'Аватар успешно обновлен'
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>