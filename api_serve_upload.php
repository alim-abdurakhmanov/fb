<?php
/**
 * Выдача файлов из uploads/ только авторизованным пользователям с проверкой прав.
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/user_roles.php';
require_once __DIR__ . '/includes/upload_access.php';

if (!isset($_SESSION['user_id']) || !finbuild_sync_session_user()) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Unauthorized';
    exit;
}

$pdo = getPDO();
$user = getCurrentUser();
if (!$user) {
    http_response_code(401);
    echo 'Unauthorized';
    exit;
}

$kind = (string) ($_GET['kind'] ?? '');
$id = (int) ($_GET['id'] ?? 0);
$asAttachment = !empty($_GET['download']);

if (!isset(finbuild_upload_kinds()[$kind]) || $id <= 0) {
    http_response_code(400);
    echo 'Bad request';
    exit;
}

if (!finbuild_upload_can_access($pdo, $kind, $id, $user)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$file = finbuild_upload_load($pdo, $kind, $id);
if ($file === null) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

$absPath = finbuild_upload_resolve_path($file['file_path']);
if ($absPath === null) {
    http_response_code(404);
    echo 'File missing';
    exit;
}

finbuild_upload_serve_file($absPath, $file['original_name'], $asAttachment);
exit;
