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

$subject = trim($_POST['subject'] ?? '');
$message = trim($_POST['message'] ?? '');

if ($subject === '' || $message === '') {
    echo json_encode(['success' => false, 'error' => 'Заполните тему и сообщение']);
    exit;
}

if (!defined('SUPPORT_USER_ID') || (int)SUPPORT_USER_ID <= 0) {
    echo json_encode(['success' => false, 'error' => 'Не настроен получатель поддержки']);
    exit;
}

try {
    $pdo = getPDO();
    $userId = (int)$_SESSION['user_id'];
    $assignedTo = (int)SUPPORT_USER_ID;

    $stmt = $pdo->prepare("
        INSERT INTO support_tickets (subject, status, created_by, assigned_to)
        VALUES (?, 'new', ?, ?)
    ");
    $stmt->execute([$subject, $userId, $assignedTo]);
    $ticketId = (int)$pdo->lastInsertId();

    $msgStmt = $pdo->prepare("
        INSERT INTO support_messages (ticket_id, author_id, message, is_read)
        VALUES (?, ?, ?, 0)
    ");
    $msgStmt->execute([$ticketId, $userId, $message]);

    echo json_encode(['success' => true, 'ticket_id' => $ticketId]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Ошибка сервера: ' . $e->getMessage()]);
}
