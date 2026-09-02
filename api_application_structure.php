<?php
/**
 * API: структура заявки (списки преимуществ / стоп-факторов / примечаний).
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/application_structure.php';

if (!isset($_SESSION['user_id']) || !finbuild_sync_session_user()) {
    finbuild_application_structure_send_json(['success' => false, 'error' => 'Не авторизован']);
}

$pdo = getPDO();
$userId = (int) $_SESSION['user_id'];
$role = (string) ($_SESSION['role'] ?? '');
$isAnalystFlag = !empty($_SESSION['is_analyst']);
$action = (string) ($_POST['action'] ?? $_GET['action'] ?? '');
$applicationId = (int) ($_POST['application_id'] ?? $_GET['application_id'] ?? 0);

finbuild_application_structure_dispatch($pdo, $role, $userId, $applicationId, $action, (string) $_SERVER['REQUEST_METHOD'], $isAnalystFlag);
