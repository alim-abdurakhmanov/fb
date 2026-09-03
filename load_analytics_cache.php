<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/finscore.php';

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

$analytics = is_string($fileData['data'] ?? null)
    ? json_decode($fileData['data'], true)
    : ($fileData['data'] ?? null);

$finscore = $fileData['finscore'] ?? null;
if (!is_array($finscore) && is_array($analytics)) {
    $pdo = $pdo ?? getPDO();
    $stmt = $pdo->prepare('SELECT inn FROM applications WHERE id = ?');
    $stmt->execute([$applicationId]);
    $inn = (string) ($stmt->fetchColumn() ?: '');
    $finscore = finscore_evaluate([
        'inn' => $inn,
        'company' => $analytics['company']['data'] ?? null,
        'finance' => $analytics['finance']['data'] ?? null,
        'enforcements' => $analytics['enforcements']['data'] ?? null,
        'lawsuits' => $analytics['lawsuits']['data'] ?? null,
    ], ['product_type' => 'bg']);
}

echo json_encode([
    'success' => true,
    'data' => $analytics,
    'finscore' => $finscore,
    'timestamp' => $fileData['timestamp'],
], JSON_UNESCAPED_UNICODE);
