<?php
/**
 * Одобрение / отклонение / снятие одобрения intake-заявки заказчика.
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/beneficiary_intake.php';
require_once __DIR__ . '/includes/notification_events.php';

header('Content-Type: application/json; charset=utf-8');

checkAuth();
$pdo = getPDO();
$user = getCurrentUser();
if (!$user || !finbuild_is_manager((string) ($user['role'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Нет доступа'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Метод не поддерживается'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$payload = [];
if (is_string($raw) && $raw !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $payload = $decoded;
    }
}
if (empty($payload)) {
    $payload = $_POST;
}

$applicationId = (int) ($payload['application_id'] ?? 0);
if ($applicationId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Не указана заявка'], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = (int) ($user['id'] ?? 0);
$userRole = (string) ($user['role'] ?? '');
if (!finbuild_can_access_application($pdo, $applicationId, $userRole, $userId, finbuild_user_is_analyst_flag($user))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Нет доступа к заявке'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = (string) ($payload['action'] ?? '');
$result = finbuild_intake_review_application($pdo, $applicationId, $user, [
    'action' => $action,
    'amount_mode' => (string) ($payload['amount_mode'] ?? ''),
    'principal_inn' => (string) ($payload['principal_inn'] ?? ''),
    'principal_company_name' => (string) ($payload['principal_company_name'] ?? ''),
    'principal_email' => (string) ($payload['principal_email'] ?? $payload['client_email'] ?? ''),
    'approved_amount' => isset($payload['approved_amount']) ? (float) $payload['approved_amount'] : null,
    'approved_limit' => isset($payload['approved_limit']) ? (float) $payload['approved_limit'] : null,
    'client_email' => (string) ($payload['client_email'] ?? ''),
    'client_phone' => (string) ($payload['client_phone'] ?? ''),
    'client_first_name' => (string) ($payload['client_first_name'] ?? ''),
    'client_last_name' => (string) ($payload['client_last_name'] ?? ''),
]);

if (empty($result['ok'])) {
    echo json_encode(['success' => false, 'error' => (string) ($result['error'] ?? 'Ошибка')], JSON_UNESCAPED_UNICODE);
    exit;
}

if (
    function_exists('notify_intake_review_result')
    && in_array($action, ['approve', 'approve_amount', 'approve_limit', 'reject'], true)
) {
    $notifyAction = $action === 'approve'
        ? (((string) ($payload['amount_mode'] ?? '')) === 'open' ? 'approve_limit' : 'approve_amount')
        : $action;
    notify_intake_review_result(
        $pdo,
        $applicationId,
        $notifyAction,
        (int) ($result['principal_user_id'] ?? 0),
        !empty($result['created_client']),
        (string) ($result['plain_password'] ?? '')
    );
}

echo json_encode([
    'success' => true,
    'principal_user_id' => (int) ($result['principal_user_id'] ?? 0),
    'created_client' => !empty($result['created_client']),
    'plain_password' => (string) ($result['plain_password'] ?? ''),
    'client_email' => (string) ($result['client_email'] ?? ''),
], JSON_UNESCAPED_UNICODE);
