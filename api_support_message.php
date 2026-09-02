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

$ticketId = (int)($_POST['ticket_id'] ?? 0);
$message = trim($_POST['message'] ?? '');

if ($ticketId <= 0 || $message === '') {
    echo json_encode(['success' => false, 'error' => 'Неверные параметры']);
    exit;
}

try {
    $pdo = getPDO();
    $userId = (int)$_SESSION['user_id'];
    $isSupportUser = defined('SUPPORT_USER_ID') && (int)SUPPORT_USER_ID === $userId;

    $ticketStmt = $pdo->prepare("SELECT * FROM support_tickets WHERE id = ?");
    $ticketStmt->execute([$ticketId]);
    $ticket = $ticketStmt->fetch();

    if (!$ticket) {
        echo json_encode(['success' => false, 'error' => 'Тикет не найден']);
        exit;
    }

    $canWrite = $isSupportUser || (int)$ticket['created_by'] === $userId;
    if (!$canWrite) {
        echo json_encode(['success' => false, 'error' => 'Доступ запрещен']);
        exit;
    }

    if ($ticket['status'] === 'closed') {
        echo json_encode(['success' => false, 'error' => 'Тикет закрыт']);
        exit;
    }

    $msgStmt = $pdo->prepare("
        INSERT INTO support_messages (ticket_id, author_id, message, is_read)
        VALUES (?, ?, ?, 0)
    ");
    $msgStmt->execute([$ticketId, $userId, $message]);

    $status = $ticket['status'] === 'new' ? 'open' : $ticket['status'];
    $statusStmt = $pdo->prepare("UPDATE support_tickets SET status = ?, updated_at = NOW() WHERE id = ?");
    $statusStmt->execute([$status, $ticketId]);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Ошибка сервера: ' . $e->getMessage()]);
}
