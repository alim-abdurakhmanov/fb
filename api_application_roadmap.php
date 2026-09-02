<?php
/**
 * API: дорожная карта заявки (только руководители).
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/application_roadmap.php';

if (!isset($_SESSION['user_id']) || !finbuild_sync_session_user()) {
    finbuild_roadmap_send_json(['success' => false, 'error' => 'Не авторизован']);
}

$pdo = getPDO();
$user = getCurrentUser();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$applicationId = (int) ($_POST['application_id'] ?? $_GET['application_id'] ?? 0);
$action = (string) ($_POST['action'] ?? $_GET['action'] ?? 'get');
$method = (string) $_SERVER['REQUEST_METHOD'];

finbuild_roadmap_dispatch($pdo, $user, $userId, $applicationId, $action, $method);
