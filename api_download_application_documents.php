<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
checkAuth();

$pdo = getPDO();
$userId = (int)($_SESSION['user_id'] ?? 0);
$userRole = (string)($_SESSION['role'] ?? 'client');
$isAnalystFlag = !empty($_SESSION['is_analyst']);

function finbuild_zip_safe_name(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return 'file';
    }
    $name = preg_replace('/[\\\\\\/\\:\\*\\?\\"\\<\\>\\|]+/', '_', $name) ?? $name;
    $name = preg_replace('/\\s+/', ' ', $name) ?? $name;
    $name = trim($name);
    return $name !== '' ? $name : 'file';
}

function finbuild_zip_resolve_path(string $relPath): string
{
    $relPathNorm = ltrim($relPath, "/\\");
    $relPathNorm = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relPathNorm);
    return rtrim((string) FINBUILD_ROOT, "/\\") . DIRECTORY_SEPARATOR . $relPathNorm;
}

function finbuild_zip_add_file(ZipArchive $zip, string $absPath, string $zipName, array &$usedNames, int &$added): void
{
    $real = realpath($absPath);
    if ($real === false || !is_file($real)) {
        return;
    }

    $finalName = $zipName;
    if (isset($usedNames[$finalName])) {
        $usedNames[$finalName]++;
        $pi = pathinfo($zipName);
        $dir = isset($pi['dirname']) && $pi['dirname'] !== '.' ? ($pi['dirname'] . '/') : '';
        $base = $pi['filename'] ?? 'file';
        $ext = isset($pi['extension']) && $pi['extension'] !== '' ? ('.' . $pi['extension']) : '';
        $finalName = $dir . $base . ' (' . $usedNames[$finalName] . ')' . $ext;
    } else {
        $usedNames[$finalName] = 1;
    }

    if ($zip->addFile($real, $finalName)) {
        $added++;
    }
}

function finbuild_zip_send_file(string $zipPath, string $zipFileName): void
{
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . finbuild_zip_safe_name($zipFileName) . '"');
    header('Content-Length: ' . (string) filesize($zipPath));
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    readfile($zipPath);
    @unlink($zipPath);
}

if (!class_exists('ZipArchive')) {
    http_response_code(500);
    echo 'ZipArchive not available';
    exit;
}

$bankCaseId = (int) ($_GET['bank_case_id'] ?? 0);
if ($userRole === 'bank' && $bankCaseId > 0) {
    require_once __DIR__ . '/includes/bank_portal.php';

    $bankUser = getCurrentUser();
    $bankCode = finbank_user_bank_code($pdo, $bankUser);
    if ($bankCode === null) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }

    $caseRow = finbank_bank_submitted_case($pdo, $bankCaseId, $bankCode);
    if (!$caseRow) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }

    $applicationId = (int) $caseRow['application_id'];
    $applicationProductId = (int) $caseRow['application_product_id'];
    $pkg = finbank_build_package_payload($pdo, $bankCaseId, $applicationId, $applicationProductId, false);

    $tmpDir = sys_get_temp_dir();
    $zipPath = $tmpDir . DIRECTORY_SEPARATOR . 'bank_case_' . $bankCaseId . '_documents_' . uniqid('', true) . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
        http_response_code(500);
        echo 'Cannot create zip';
        exit;
    }

    $added = 0;
    $usedNames = [];

    foreach ($pkg['items'] as $it) {
        if (($it['type'] ?? '') === 'application_document') {
            $relPath = (string) ($it['file_path'] ?? '');
            if ($relPath === '') {
                continue;
            }
            $orig = finbuild_zip_safe_name((string) ($it['original_name'] ?? 'file'));
            finbuild_zip_add_file($zip, finbuild_zip_resolve_path($relPath), $orig, $usedNames, $added);
            continue;
        }
        if (($it['type'] ?? '') === 'product_document' && !empty($it['files']) && is_array($it['files'])) {
            foreach ($it['files'] as $f) {
                $relPath = (string) ($f['file_path'] ?? '');
                if ($relPath === '') {
                    continue;
                }
                $orig = finbuild_zip_safe_name((string) ($f['original_name'] ?? 'file'));
                finbuild_zip_add_file($zip, finbuild_zip_resolve_path($relPath), $orig, $usedNames, $added);
            }
        }
    }

    foreach ($pkg['uploads'] as $u) {
        $relPath = (string) ($u['file_path'] ?? '');
        if ($relPath === '') {
            continue;
        }
        $orig = finbuild_zip_safe_name((string) ($u['original_name'] ?? 'file'));
        finbuild_zip_add_file($zip, finbuild_zip_resolve_path($relPath), $orig, $usedNames, $added);
    }

    $zip->close();

    if ($added === 0) {
        @unlink($zipPath);
        http_response_code(404);
        echo 'No documents';
        exit;
    }

    finbuild_zip_send_file($zipPath, 'Заявка_' . $applicationId . '_документы.zip');
    exit;
}

$applicationId = (int) ($_GET['application_id'] ?? 0);
if ($applicationId <= 0) {
    http_response_code(400);
    echo 'Bad request';
    exit;
}

$stmt = $pdo->prepare('SELECT id, company_name, created_by, status FROM applications WHERE id = ? LIMIT 1');
$stmt->execute([$applicationId]);
$app = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$app) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

if (finbuild_analyst_cannot_view_failed_application($app, $userRole, $isAnalystFlag, $userId)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

if (!finbuild_can_view_application_documents($pdo, $applicationId, $userRole, $userId, $isAnalystFlag)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$stmtDocs = $pdo->prepare(
    'SELECT id, document_type, file_path, original_name
     FROM application_documents
     WHERE application_id = ?
     ORDER BY created_at DESC'
);
$stmtDocs->execute([$applicationId]);
$docs = $stmtDocs->fetchAll(PDO::FETCH_ASSOC);

if (empty($docs)) {
    http_response_code(404);
    echo 'No documents';
    exit;
}

$tmpDir = sys_get_temp_dir();
$zipPath = $tmpDir . DIRECTORY_SEPARATOR . 'application_' . $applicationId . '_documents_' . uniqid('', true) . '.zip';
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
    http_response_code(500);
    echo 'Cannot create zip';
    exit;
}

$added = 0;
$usedNames = [];
foreach ($docs as $d) {
    $relPath = (string) ($d['file_path'] ?? '');
    if ($relPath === '') {
        continue;
    }

    $docType = finbuild_zip_safe_name((string) ($d['document_type'] ?? 'Документы'));
    $orig = finbuild_zip_safe_name((string) ($d['original_name'] ?? ('document_' . (int) $d['id'])));
    finbuild_zip_add_file($zip, finbuild_zip_resolve_path($relPath), $docType . '/' . $orig, $usedNames, $added);
}

$zip->close();

if ($added === 0) {
    @unlink($zipPath);
    http_response_code(404);
    echo 'No files found on disk';
    exit;
}

finbuild_zip_send_file($zipPath, 'Заявка_' . $applicationId . '_документы.zip');
