<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/finscore.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

$application_id = (int) ($_GET['application_id'] ?? 0);

if ($application_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid application ID'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = getPDO();

$user = getCurrentUser();
if (($user['role'] ?? '') === 'bank') {
    require_once __DIR__ . '/includes/bank_portal.php';
    $bankCode = finbank_user_bank_code($pdo, $user);
    if ($bankCode === null || !finbank_bank_can_view_application($pdo, $application_id, $bankCode)) {
        echo json_encode(['success' => false, 'error' => 'Доступ запрещён'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$stmt = $pdo->prepare('SELECT inn FROM applications WHERE id = ?');
$stmt->execute([$application_id]);
$application = $stmt->fetch();

if (!$application || empty($application['inn'])) {
    echo json_encode(['success' => false, 'error' => 'Application or INN not found'], JSON_UNESCAPED_UNICODE);
    exit;
}

$inn = preg_replace('/\D+/', '', (string) $application['inn']);

try {
    $built = finscore_build_for_inn($inn, ['product_type' => 'bg']);

    if (empty($built['ok'])) {
        echo json_encode([
            'success' => false,
            'error' => $built['error'] ?? 'Не удалось получить данные Checko',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $result = [
        'success' => true,
        'inn' => $inn,
        'finscore' => $built['result'],
        'data' => $built['raw'] ?? [
            'company' => ['data' => []],
            'finance' => ['data' => []],
            'enforcements' => ['data' => []],
            'lawsuits' => ['data' => []],
        ],
    ];

    if (!empty($built['warnings'])) {
        $result['warnings'] = $built['warnings'];
    }

    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('api_get_company_analytics: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
