<?php
/**
 * API: сохранение / сброс матрицы прав (только access_rights.manage).
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || !finbuild_sync_session_user()) {
    echo json_encode(['success' => false, 'error' => 'Не авторизован'], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = getCurrentUser();
if (!finbuild_can('access_rights.manage', $user)) {
    echo json_encode(['success' => false, 'error' => 'Доступ запрещён'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Неверный метод'], JSON_UNESCAPED_UNICODE);
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
if ($payload === [] && !empty($_POST)) {
    $payload = $_POST;
}

$action = (string) ($payload['action'] ?? 'save');
$pdo = getPDO();
$userId = (int) ($user['id'] ?? $_SESSION['user_id'] ?? 0);

try {
    if ($action === 'reset') {
        $stmt = $pdo->prepare('DELETE FROM system_settings WHERE setting_key = ?');
        $stmt->execute([FINBUILD_ACCESS_SETTING_KEY]);
        finbuild_access_config_reset();
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action !== 'save') {
        echo json_encode(['success' => false, 'error' => 'Неизвестное действие'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $result = finbuild_access_save($pdo, [
        'roles' => is_array($payload['roles'] ?? null) ? $payload['roles'] : [],
        'matrix' => is_array($payload['matrix'] ?? null) ? $payload['matrix'] : [],
    ], $userId);

    if (empty($result['ok'])) {
        echo json_encode(['success' => false, 'error' => $result['error'] ?? 'Ошибка сохранения'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    $msg = $e->getMessage();
    if (stripos($msg, 'system_settings') !== false) {
        $msg = 'Таблица system_settings не найдена. Выполните migrations/add_system_settings_access_rights.sql';
    }
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
}
