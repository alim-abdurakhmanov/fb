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
$status = $_POST['status'] ?? '';

if ($ticketId <= 0 || !in_array($status, ['open', 'closed'], true)) {
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

    $isOwner = (int)$ticket['created_by'] === $userId;

    if ($status === 'open' && !$isSupportUser) {
        echo json_encode(['success' => false, 'error' => 'Только поддержка может открывать тикеты']);
        exit;
    }

    if (!$isSupportUser && !$isOwner) {
        echo json_encode(['success' => false, 'error' => 'Доступ запрещен']);
        exit;
    }

    $stmt = $pdo->prepare("UPDATE support_tickets SET status = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$status, $ticketId]);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Ошибка сервера: ' . $e->getMessage()]);
}
