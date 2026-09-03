<?php
/**
 * Единый API FinScore / лимиты / факторы риска по ИНН.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/finscore.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

$inn = preg_replace('/\D+/', '', (string) ($_GET['inn'] ?? $_POST['inn'] ?? ''));
if ($inn === '' || (strlen($inn) !== 10 && strlen($inn) !== 12)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Укажите корректный ИНН (10 или 12 цифр)'], JSON_UNESCAPED_UNICODE);
    exit;
}

$productType = (string) ($_GET['product_type'] ?? $_POST['product_type'] ?? 'bg');
$existingCredits = (float) ($_GET['existing_credits'] ?? $_POST['existing_credits'] ?? 0);

try {
    $built = finscore_build_for_inn($inn, [
        'product_type' => $productType === 'credit' ? 'credit' : 'bg',
        'existing_credits' => $existingCredits,
    ]);

    if (empty($built['ok'])) {
        http_response_code(502);
        echo json_encode([
            'success' => false,
            'error' => $built['error'] ?? 'Не удалось рассчитать FinScore',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'success' => true,
        'inn' => $inn,
        'finscore' => $built['result'],
        'warnings' => $built['warnings'] ?? [],
        'data' => $built['raw'] ?? null,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('api_company_intelligence: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Ошибка расчёта FinScore'], JSON_UNESCAPED_UNICODE);
}
