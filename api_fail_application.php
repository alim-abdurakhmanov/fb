<?php
require_once 'config.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || !finbuild_is_manager((string) ($_SESSION['role'] ?? ''))) {
    echo json_encode(['success' => false, 'error' => 'Доступ запрещен']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Неверный метод запроса']);
    exit;
}

$applicationId = (int)($_POST['application_id'] ?? 0);
if ($applicationId < 1) {
    echo json_encode(['success' => false, 'error' => 'Некорректная заявка']);
    exit;
}

try {
    $pdo = getPDO();

    $check = $pdo->prepare('SELECT id FROM applications WHERE id = ?');
    $check->execute([$applicationId]);
    if (!$check->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Заявка не найдена']);
        exit;
    }

    $cnt = $pdo->prepare('SELECT COUNT(*) FROM application_products WHERE application_id = ?');
    $cnt->execute([$applicationId]);
    if ((int)$cnt->fetchColumn() === 0) {
        echo json_encode(['success' => false, 'error' => 'В заявке нет продуктов']);
        exit;
    }

    $pdo->beginTransaction();

    $upd = $pdo->prepare("UPDATE application_products SET status = 'Отказано' WHERE application_id = ?");
    $upd->execute([$applicationId]);

    $newStatus = updateApplicationStatus($applicationId, false);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'application_status' => $newStatus,
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'error' => 'Ошибка сервера: ' . $e->getMessage()]);
}
