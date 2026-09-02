<?php
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authorized']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (empty($data['application_id']) || empty($data['analytics_data'])) {
    echo json_encode(['success' => false, 'error' => 'Неверные данные']);
    exit;
}

$applicationId = (int) $data['application_id'];

$user = getCurrentUser();
if (($user['role'] ?? '') === 'bank') {
    require_once __DIR__ . '/includes/bank_portal.php';
    $pdo = getPDO();
    $bankCode = finbank_user_bank_code($pdo, $user);
    if ($bankCode === null || !finbank_bank_can_view_application($pdo, $applicationId, $bankCode)) {
        echo json_encode(['success' => false, 'error' => 'Доступ запрещён']);
        exit;
    }
}

$analyticsData = json_encode($data['analytics_data'], JSON_UNESCAPED_UNICODE);
$timestamp = date('Y-m-d H:i:s');

$fileName = "analytics_cache/analytics_{$applicationId}.json";
$saveData = [
    'data' => $analyticsData,
    'timestamp' => $timestamp,
    'updated_at' => $timestamp,
];

if (!file_exists('analytics_cache')) {
    mkdir('analytics_cache', 0755, true);
}

file_put_contents($fileName, json_encode($saveData, JSON_UNESCAPED_UNICODE));

echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
