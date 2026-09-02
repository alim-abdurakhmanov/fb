<?php
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authorized']);
    exit;
}

$applicationId = (int) ($_GET['application_id'] ?? 0);

if ($applicationId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Не указан ID заявки']);
    exit;
}

$user = getCurrentUser();
$userRole = $user['role'] ?? '';

if ($userRole === 'bank') {
    require_once __DIR__ . '/includes/bank_portal.php';
    $pdo = getPDO();
    $bankCode = finbank_user_bank_code($pdo, $user);
    if ($bankCode === null || !finbank_bank_can_view_application($pdo, $applicationId, $bankCode)) {
        echo json_encode(['success' => false, 'error' => 'Доступ запрещён']);
        exit;
    }
}

$fileName = "analytics_cache/analytics_{$applicationId}.json";

if (!file_exists($fileName)) {
    echo json_encode(['success' => false, 'error' => 'Данные не найдены']);
    exit;
}

$fileData = json_decode(file_get_contents($fileName), true);

if (!$fileData) {
    echo json_encode(['success' => false, 'error' => 'Ошибка чтения файла']);
    exit;
}

echo json_encode([
    'success' => true,
    'data' => json_decode($fileData['data'], true),
    'timestamp' => $fileData['timestamp'],
], JSON_UNESCAPED_UNICODE);
