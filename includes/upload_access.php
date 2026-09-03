<?php
/**
 * Защищённая выдача файлов из uploads/ — только через api_serve_upload.php.
 */
declare(strict_types=1);

/** @return array<string, string> */
function finbuild_upload_kinds(): array
{
    return [
        'app_doc' => 'application_documents',
        'prod_doc' => 'application_product_document_files',
        'bank_upload' => 'application_product_bank_case_uploads',
        'bank_msg' => 'application_product_bank_case_message_files',
        'bank_log' => 'application_product_bank_case_status_log_files',
        'chat' => 'application_product_chat_files',
        'app_chat' => 'application_chat_files',
        'avatar' => 'users',
    ];
}

function finbuild_upload_file_url(string $kind, int $id, bool $download = false): string
{
    $url = 'api_serve_upload.php?kind=' . rawurlencode($kind) . '&id=' . (int) $id;
    if ($download) {
        $url .= '&download=1';
    }
    return $url;
}

function finbuild_upload_row_with_url(array $row, string $kind): array
{
    $id = (int) ($row['id'] ?? 0);
    if ($id > 0) {
        $row['file_url'] = finbuild_upload_file_url($kind, $id);
        $row['file_url_download'] = finbuild_upload_file_url($kind, $id, true);
    }
    return $row;
}

function finbuild_upload_resolve_path(string $relPath): ?string
{
    $relPath = str_replace('\\', '/', trim($relPath));
    if ($relPath === '' || str_contains($relPath, '..') || str_starts_with($relPath, '/')) {
        return null;
    }
    if (!str_starts_with($relPath, 'uploads/')) {
        return null;
    }

    $root = rtrim((string) FINBUILD_ROOT, "/\\");
    $abs = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
    $real = realpath($abs);
    if ($real === false || !is_file($real)) {
        return null;
    }

    $uploadsRoot = realpath($root . DIRECTORY_SEPARATOR . 'uploads');
    if ($uploadsRoot === false || !str_starts_with($real, $uploadsRoot . DIRECTORY_SEPARATOR)) {
        return null;
    }

    return $real;
}

function finbuild_upload_can_access_application_id(PDO $pdo, int $applicationId, array $user): bool
{
    if ($applicationId <= 0) {
        return false;
    }

    $role = (string) ($user['role'] ?? '');
    $userId = (int) ($user['id'] ?? 0);
    $isAnalystFlag = finbuild_user_is_analyst_flag($user);

    if ($role === 'bank') {
        require_once __DIR__ . '/bank_portal.php';
        $bankCode = finbank_user_bank_code($pdo, $user);
        if ($bankCode === null) {
            return false;
        }
        return finbank_bank_can_view_application($pdo, $applicationId, $bankCode);
    }

    $stmt = $pdo->prepare('SELECT id, created_by, status FROM applications WHERE id = ? LIMIT 1');
    $stmt->execute([$applicationId]);
    $app = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$app) {
        return false;
    }

    if (finbuild_analyst_cannot_view_failed_application($app, $role, $isAnalystFlag, $userId)) {
        return false;
    }

    return finbuild_can_view_application_documents($pdo, $applicationId, $role, $userId, $isAnalystFlag);
}

function finbuild_upload_can_access_bank_case(PDO $pdo, int $caseId, array $user): bool
{
    if ($caseId <= 0) {
        return false;
    }

    $role = (string) ($user['role'] ?? '');
    $userId = (int) ($user['id'] ?? 0);

    if ($role === 'bank') {
        require_once __DIR__ . '/bank_portal.php';
        $bankCode = finbank_user_bank_code($pdo, $user);
        if ($bankCode === null) {
            return false;
        }
        return finbank_bank_submitted_case($pdo, $caseId, $bankCode) !== null;
    }

    if (!finbuild_is_manager($role)) {
        return false;
    }

    $stmt = $pdo->prepare(
        'SELECT ap.application_id
         FROM application_product_bank_cases c
         INNER JOIN application_products ap ON ap.id = c.application_product_id
         WHERE c.id = ?
         LIMIT 1'
    );
    $stmt->execute([$caseId]);
    $applicationId = (int) $stmt->fetchColumn();
    if ($applicationId <= 0) {
        return false;
    }

    return finbuild_can_view_application_documents($pdo, $applicationId, $role, $userId, false);
}

/**
 * @return array{file_path: string, original_name: string, mime?: string}|null
 */
function finbuild_upload_load(PDO $pdo, string $kind, int $id): ?array
{
    if ($id <= 0 || !isset(finbuild_upload_kinds()[$kind])) {
        return null;
    }

    switch ($kind) {
        case 'app_doc':
            $stmt = $pdo->prepare('SELECT file_path, original_name FROM application_documents WHERE id = ? LIMIT 1');
            break;
        case 'prod_doc':
            $stmt = $pdo->prepare('SELECT file_path, original_name FROM application_product_document_files WHERE id = ? LIMIT 1');
            break;
        case 'bank_upload':
            $stmt = $pdo->prepare('SELECT file_path, original_name FROM application_product_bank_case_uploads WHERE id = ? LIMIT 1');
            break;
        case 'bank_msg':
            $stmt = $pdo->prepare('SELECT file_path, original_name FROM application_product_bank_case_message_files WHERE id = ? LIMIT 1');
            break;
        case 'bank_log':
            $stmt = $pdo->prepare('SELECT file_path, original_name FROM application_product_bank_case_status_log_files WHERE id = ? LIMIT 1');
            break;
        case 'chat':
            $stmt = $pdo->prepare('SELECT file_path, original_name FROM application_product_chat_files WHERE id = ? LIMIT 1');
            break;
        case 'app_chat':
            $stmt = $pdo->prepare('SELECT file_path, original_name FROM application_chat_files WHERE id = ? LIMIT 1');
            break;
        case 'avatar':
            $stmt = $pdo->prepare('SELECT avatar_path AS file_path, CONCAT("avatar_", id, ".jpg") AS original_name FROM users WHERE id = ? AND avatar_path IS NOT NULL AND avatar_path <> "" LIMIT 1');
            break;
        default:
            return null;
    }

    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || empty($row['file_path'])) {
        return null;
    }

    return [
        'file_path' => (string) $row['file_path'],
        'original_name' => (string) ($row['original_name'] ?? 'file'),
    ];
}

function finbuild_upload_can_access(PDO $pdo, string $kind, int $id, array $user): bool
{
    if ($id <= 0 || !isset(finbuild_upload_kinds()[$kind])) {
        return false;
    }

    $role = (string) ($user['role'] ?? '');
    $userId = (int) ($user['id'] ?? 0);
    $isAnalystFlag = finbuild_user_is_analyst_flag($user);

    switch ($kind) {
        case 'app_doc':
            $stmt = $pdo->prepare('SELECT application_id FROM application_documents WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            $applicationId = (int) $stmt->fetchColumn();
            return finbuild_upload_can_access_application_id($pdo, $applicationId, $user);

        case 'prod_doc':
            $stmt = $pdo->prepare(
                'SELECT ap.application_id
                 FROM application_product_document_files f
                 INNER JOIN application_product_documents d ON d.id = f.document_id
                 INNER JOIN application_products ap ON ap.id = d.application_product_id
                 WHERE f.id = ?
                 LIMIT 1'
            );
            $stmt->execute([$id]);
            $applicationId = (int) $stmt->fetchColumn();
            return finbuild_upload_can_access_application_id($pdo, $applicationId, $user);

        case 'bank_upload':
            $stmt = $pdo->prepare('SELECT bank_case_id FROM application_product_bank_case_uploads WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            $caseId = (int) $stmt->fetchColumn();
            return finbuild_upload_can_access_bank_case($pdo, $caseId, $user);

        case 'bank_msg':
            $stmt = $pdo->prepare(
                'SELECT m.bank_case_id
                 FROM application_product_bank_case_message_files f
                 INNER JOIN application_product_bank_case_messages m ON m.id = f.bank_case_message_id
                 WHERE f.id = ?
                 LIMIT 1'
            );
            $stmt->execute([$id]);
            $caseId = (int) $stmt->fetchColumn();
            return finbuild_upload_can_access_bank_case($pdo, $caseId, $user);

        case 'bank_log':
            $stmt = $pdo->prepare(
                'SELECT l.bank_case_id
                 FROM application_product_bank_case_status_log_files f
                 INNER JOIN application_product_bank_case_status_log l ON l.id = f.status_log_id
                 WHERE f.id = ?
                 LIMIT 1'
            );
            $stmt->execute([$id]);
            $caseId = (int) $stmt->fetchColumn();
            return finbuild_upload_can_access_bank_case($pdo, $caseId, $user);

        case 'chat':
            if (!finbuild_can_use_product_chat($user)) {
                return false;
            }
            $stmt = $pdo->prepare(
                'SELECT ap.application_id
                 FROM application_product_chat_files f
                 INNER JOIN application_product_chats c ON c.id = f.chat_message_id
                 INNER JOIN application_products ap ON ap.id = c.application_product_id
                 WHERE f.id = ?
                 LIMIT 1'
            );
            $stmt->execute([$id]);
            $applicationId = (int) $stmt->fetchColumn();
            return finbuild_upload_can_access_application_id($pdo, $applicationId, $user);

        case 'app_chat':
            if (!finbuild_can_use_product_chat($user)) {
                return false;
            }
            $stmt = $pdo->prepare(
                'SELECT c.application_id
                 FROM application_chat_files f
                 INNER JOIN application_chats c ON c.id = f.chat_message_id
                 WHERE f.id = ?
                 LIMIT 1'
            );
            $stmt->execute([$id]);
            $applicationId = (int) $stmt->fetchColumn();
            return finbuild_upload_can_access_application_id($pdo, $applicationId, $user);

        case 'avatar':
            return $userId > 0;

        default:
            return false;
    }
}

function finbuild_upload_guess_mime(string $absPath, string $originalName): string
{
    if (function_exists('mime_content_type')) {
        $mime = mime_content_type($absPath);
        if (is_string($mime) && $mime !== '') {
            return $mime;
        }
    }

    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    return match ($ext) {
        'pdf' => 'application/pdf',
        'jpg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'zip' => 'application/zip',
        'txt' => 'text/plain; charset=utf-8',
        default => 'application/octet-stream',
    };
}

function finbuild_upload_serve_file(string $absPath, string $originalName, bool $asAttachment): void
{
    $mime = finbuild_upload_guess_mime($absPath, $originalName);
    $safeName = preg_replace('/[\\\\\\/\\:\\*\\?\\"\\<\\>\\|]+/', '_', $originalName) ?? 'file';
    $safeName = trim($safeName) !== '' ? trim($safeName) : 'file';

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string) filesize($absPath));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store, max-age=0');
    header(
        'Content-Disposition: ' . ($asAttachment ? 'attachment' : 'inline')
        . '; filename="' . rawurlencode($safeName) . '"; filename*=UTF-8\'\'' . rawurlencode($safeName)
    );

    readfile($absPath);
}
