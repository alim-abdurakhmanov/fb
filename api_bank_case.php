<?php
/**
 * API: кейс «Работа с банком». Роли manager | bank.
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/bank_portal.php';
require_once __DIR__ . '/includes/notification_events.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || !finbuild_sync_session_user()) {
    echo json_encode(['success' => false, 'error' => 'Не авторизован'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = getPDO();
$userId = (int) $_SESSION['user_id'];
$role = (string) ($_SESSION['role'] ?? '');
$currentUser = getCurrentUser() ?: ['role' => $role, 'id' => $userId];
$canBankWork = finbuild_can_work_with_banks($currentUser);

$action = $_POST['action'] ?? $_GET['action'] ?? '';

function json_out(array $data): void
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function assert_manager_product(PDO $pdo, int $applicationProductId): ?array
{
    $user = getCurrentUser() ?: ['role' => (string) ($_SESSION['role'] ?? '')];
    if (!finbuild_can_work_with_banks($user)) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT ap.*, a.id AS application_id
         FROM application_products ap
         INNER JOIN applications a ON a.id = ap.application_id
         WHERE ap.id = ?'
    );
    $stmt->execute([$applicationProductId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !finbank_is_portal_application_product($row)) {
        return null;
    }
    $applicationId = (int) ($row['application_id'] ?? 0);
    $role = (string) ($user['role'] ?? $_SESSION['role'] ?? '');
    $userId = (int) ($user['id'] ?? $_SESSION['user_id'] ?? 0);
    $isAnalystFlag = function_exists('finbuild_user_is_analyst_flag') && finbuild_user_is_analyst_flag($user);
    if ($applicationId > 0 && function_exists('finbuild_can_access_application')
        && !finbuild_can_access_application($pdo, $applicationId, $role, $userId, $isAnalystFlag)) {
        return null;
    }
    return $row;
}

function assert_bank_case(PDO $pdo, int $caseId): ?array
{
    if (($_SESSION['role'] ?? '') !== 'bank') {
        return null;
    }
    $user = getCurrentUser();
    $bankCode = finbank_user_bank_code($pdo, $user);
    if ($bankCode === null) {
        return null;
    }
    return finbank_bank_submitted_case($pdo, $caseId, $bankCode);
}

try {
    if ($action === 'manager_get' && $canBankWork) {
        $applicationProductId = (int) ($_GET['application_product_id'] ?? 0);
        $row = assert_manager_product($pdo, $applicationProductId);
        if (!$row) {
            json_out(['success' => false, 'error' => 'Нет доступа или банк не подключён к ЛК']);
        }
        $applicationId = (int) $row['application_id'];
        $case = finbank_ensure_draft_case($pdo, $applicationProductId, $applicationId);
        $caseId = (int) $case['id'];
        $stmt = $pdo->prepare('SELECT * FROM application_product_bank_cases WHERE id = ?');
        $stmt->execute([$caseId]);
        $caseRow = $stmt->fetch(PDO::FETCH_ASSOC);

        $app = $pdo->prepare('SELECT * FROM applications WHERE id = ?');
        $app->execute([$applicationId]);
        $application = $app->fetch(PDO::FETCH_ASSOC);

        $pkg = finbank_build_package_payload($pdo, $caseId, $applicationId, $applicationProductId, true);

        $msg = $pdo->prepare(
            'SELECT m.*, u.first_name, u.last_name, u.role, u.company_name AS user_company_name
             FROM application_product_bank_case_messages m
             JOIN users u ON u.id = m.user_id WHERE m.bank_case_id = ? ORDER BY m.created_at ASC'
        );
        $msg->execute([$caseId]);
        $messages = finbank_bank_case_messages_append_files($pdo, $msg->fetchAll(PDO::FETCH_ASSOC));

        $log = $pdo->prepare(
            'SELECT l.*, u.first_name, u.last_name, u.role, u.company_name AS changer_company_name
             FROM application_product_bank_case_status_log l
             LEFT JOIN users u ON u.id = l.changed_by WHERE l.bank_case_id = ? ORDER BY l.created_at ASC'
        );
        $log->execute([$caseId]);
        $statusLog = finbank_bank_case_status_log_append_files($pdo, $log->fetchAll(PDO::FETCH_ASSOC));

        json_out([
            'success' => true,
            'case' => $caseRow,
            'application' => $application,
            'package' => $pkg,
            'messages' => $messages,
            'status_log' => $statusLog,
            'status_label_manager' => finbank_case_status_label_manager($caseRow['status']),
            'status_badge_class' => finbank_case_status_badge_class((string) $caseRow['status']),
        ]);
    }

    if ($action === 'bank_get' && $role === 'bank') {
        $caseId = (int) ($_GET['bank_case_id'] ?? $_GET['id'] ?? 0);
        $c = assert_bank_case($pdo, $caseId);
        if (!$c) {
            json_out(['success' => false, 'error' => 'Нет доступа']);
        }
        $applicationProductId = (int) $c['application_product_id'];
        $applicationId = (int) $c['application_id'];
        $stmt = $pdo->prepare('SELECT * FROM application_product_bank_cases WHERE id = ?');
        $stmt->execute([$caseId]);
        $caseRow = $stmt->fetch(PDO::FETCH_ASSOC);
        $app = $pdo->prepare('SELECT * FROM applications WHERE id = ?');
        $app->execute([$applicationId]);
        $application = $app->fetch(PDO::FETCH_ASSOC);
        if ($application) {
            $assignedName = '';
            if (!empty($application['assigned_to'])) {
                $stm = $pdo->prepare('SELECT first_name, last_name FROM users WHERE id = ? AND role IN (' . finbuild_manager_roles_sql_in() . ')');
                $stm->execute([(int) $application['assigned_to']]);
                $mr = $stm->fetch(PDO::FETCH_ASSOC);
                if ($mr) {
                    $assignedName = trim(($mr['first_name'] ?? '') . ' ' . ($mr['last_name'] ?? ''));
                }
            }
            $application['assigned_manager_name'] = $assignedName !== '' ? $assignedName : null;
        }
        $ap = $pdo->prepare('SELECT * FROM application_products WHERE id = ?');
        $ap->execute([$applicationProductId]);
        $applicationProduct = $ap->fetch(PDO::FETCH_ASSOC);
        $pkg = finbank_build_package_payload($pdo, $caseId, $applicationId, $applicationProductId, false);
        $msg = $pdo->prepare(
            'SELECT m.*, u.first_name, u.last_name, u.role, u.is_submanager, u.company_name AS user_company_name
             FROM application_product_bank_case_messages m
             JOIN users u ON u.id = m.user_id WHERE m.bank_case_id = ? ORDER BY m.created_at ASC'
        );
        $msg->execute([$caseId]);
        $messages = finbank_bank_case_messages_append_files($pdo, $msg->fetchAll(PDO::FETCH_ASSOC));
        $messages = finbuild_mask_staff_identity_in_chat_messages($messages);
        $log = $pdo->prepare(
            'SELECT l.*, u.first_name, u.last_name, u.role, u.company_name AS changer_company_name
             FROM application_product_bank_case_status_log l
             LEFT JOIN users u ON u.id = l.changed_by WHERE l.bank_case_id = ? ORDER BY l.created_at ASC'
        );
        $log->execute([$caseId]);
        $statusLog = finbank_bank_case_status_log_append_files($pdo, $log->fetchAll(PDO::FETCH_ASSOC));
        $productStatus = (string) ($applicationProduct['status'] ?? '');
        $caseStatus = (string) ($caseRow['status'] ?? '');
        $productFailed = finbank_product_status_is_failed($productStatus);
        $allowedCodes = $productFailed ? [] : finbank_bank_allowed_targets($caseStatus);
        $allowedStatuses = array_map(static function (string $s): array {
            return ['code' => $s, 'label' => finbank_case_status_label_bank($s)];
        }, $allowedCodes);
        json_out([
            'success' => true,
            'case' => $caseRow,
            'application' => $application,
            'application_product' => $applicationProduct,
            'package' => $pkg,
            'messages' => $messages,
            'status_log' => $statusLog,
            'status_label_bank' => finbank_bank_display_status_label($caseStatus, $productStatus),
            'status_badge_class' => finbank_bank_display_status_badge_class($caseStatus, $productStatus),
            'allowed_statuses' => $allowedStatuses,
            'product_obsolete' => $productFailed,
        ]);
    }

    if ($action === 'manager_toggle_item' && $canBankWork && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $applicationProductId = (int) ($_POST['application_product_id'] ?? 0);
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $excluded = (int) ($_POST['excluded'] ?? 0) ? 1 : 0;
        $row = assert_manager_product($pdo, $applicationProductId);
        if (!$row) {
            json_out(['success' => false, 'error' => 'Нет доступа']);
        }
        $stmt = $pdo->prepare('SELECT c.id, c.status FROM application_product_bank_cases c WHERE c.application_product_id = ?');
        $stmt->execute([$applicationProductId]);
        $c = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$c || finbank_case_is_submitted($c['status'])) {
            json_out(['success' => false, 'error' => 'Пакет уже отправлен']);
        }
        $pdo->prepare('UPDATE application_product_bank_case_items SET excluded = ? WHERE id = ? AND bank_case_id = ?')
            ->execute([$excluded, $itemId, (int) $c['id']]);
        json_out(['success' => true]);
    }

    if ($action === 'manager_upload' && $canBankWork && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $applicationProductId = (int) ($_POST['application_product_id'] ?? 0);
        $row = assert_manager_product($pdo, $applicationProductId);
        if (!$row) {
            json_out(['success' => false, 'error' => 'Нет доступа']);
        }
        $stmt = $pdo->prepare('SELECT id, status FROM application_product_bank_cases WHERE application_product_id = ?');
        $stmt->execute([$applicationProductId]);
        $c = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$c || finbank_case_is_submitted($c['status'])) {
            json_out(['success' => false, 'error' => 'Пакет уже отправлен']);
        }
        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title === '') {
            json_out(['success' => false, 'error' => 'Укажите название']);
        }
        $description = trim((string) ($_POST['description'] ?? ''));
        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            json_out(['success' => false, 'error' => 'Файл не загружен']);
        }
        $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
        $allowed = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'txt', 'zip', 'rar'];
        if (!in_array($ext, $allowed, true)) {
            json_out(['success' => false, 'error' => 'Недопустимый тип файла']);
        }
        $dir = 'uploads/bank_case/' . (int) $c['id'] . '/';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $safe = uniqid('bc_', true) . '.' . $ext;
        $path = $dir . $safe;
        if (!move_uploaded_file($_FILES['file']['tmp_name'], $path)) {
            json_out(['success' => false, 'error' => 'Не удалось сохранить файл']);
        }
        $size = (int) filesize($path);
        $pdo->prepare(
            'INSERT INTO application_product_bank_case_uploads
            (bank_case_id, title, description, file_path, original_name, file_size, file_type, uploaded_by)
            VALUES (?,?,?,?,?,?,?,?)'
        )->execute([
            (int) $c['id'],
            $title,
            $description === '' ? null : $description,
            $path,
            $_FILES['file']['name'],
            $size,
            $ext,
            $userId,
        ]);
        json_out(['success' => true, 'upload_id' => (int) $pdo->lastInsertId()]);
    }

    if ($action === 'manager_delete_upload' && $canBankWork && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $applicationProductId = (int) ($_POST['application_product_id'] ?? 0);
        $uploadId = (int) ($_POST['upload_id'] ?? 0);
        $row = assert_manager_product($pdo, $applicationProductId);
        if (!$row) {
            json_out(['success' => false, 'error' => 'Нет доступа']);
        }
        $stmt = $pdo->prepare(
            'SELECT u.file_path FROM application_product_bank_case_uploads u
             JOIN application_product_bank_cases c ON c.id = u.bank_case_id
             WHERE u.id = ? AND c.application_product_id = ? AND c.status = ?'
        );
        $stmt->execute([$uploadId, $applicationProductId, FINBANK_STATUS_DRAFT]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$u) {
            json_out(['success' => false, 'error' => 'Файл не найден']);
        }
        if (is_file($u['file_path'])) {
            @unlink($u['file_path']);
        }
        $pdo->prepare('DELETE FROM application_product_bank_case_uploads WHERE id = ?')->execute([$uploadId]);
        json_out(['success' => true]);
    }

    if ($action === 'manager_submit' && $canBankWork && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $applicationProductId = (int) ($_POST['application_product_id'] ?? 0);
        $comment = trim((string) ($_POST['manager_comment'] ?? ''));
        $row = assert_manager_product($pdo, $applicationProductId);
        if (!$row) {
            json_out(['success' => false, 'error' => 'Нет доступа']);
        }
        $applicationId = (int) $row['application_id'];
        finbank_ensure_draft_case($pdo, $applicationProductId, $applicationId);
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT * FROM application_product_bank_cases WHERE application_product_id = ?');
        $stmt->execute([$applicationProductId]);
        $caseRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$caseRow || finbank_case_is_submitted($caseRow['status'])) {
            $pdo->rollBack();
            json_out(['success' => false, 'error' => 'Уже отправлено']);
        }
        $caseId = (int) $caseRow['id'];
        $cnt = $pdo->prepare(
            'SELECT COUNT(*) FROM application_product_bank_case_items WHERE bank_case_id = ? AND excluded = 0'
        );
        $cnt->execute([$caseId]);
        $cntU = $pdo->prepare('SELECT COUNT(*) FROM application_product_bank_case_uploads WHERE bank_case_id = ?');
        $cntU->execute([$caseId]);
        $hasDocs = (int) $cnt->fetchColumn() > 0;
        $hasUploads = (int) $cntU->fetchColumn() > 0;
        if (!$hasDocs && !$hasUploads) {
            $pdo->rollBack();
            json_out(['success' => false, 'error' => 'Добавьте в пакет хотя бы один документ или файл']);
        }
        $pdo->prepare(
            'UPDATE application_product_bank_cases
             SET status = ?, manager_comment = ?, submitted_by = ?, submitted_at = NOW(), updated_at = NOW()
             WHERE id = ?'
        )->execute([FINBANK_STATUS_SENT, $comment === '' ? null : $comment, $userId, $caseId]);
        $pdo->prepare(
            'INSERT INTO application_product_bank_case_status_log (bank_case_id, old_status, new_status, comment, changed_by)
             VALUES (?,?,?,?,?)'
        )->execute([$caseId, FINBANK_STATUS_DRAFT, FINBANK_STATUS_SENT, $comment === '' ? null : $comment, $userId]);
        $pdo->commit();
        notify_bank_case_submitted($pdo, $caseId, $userId);
        json_out(['success' => true]);
    }

    if ($action === 'post_message' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $caseId = (int) ($_POST['bank_case_id'] ?? 0);
        $text = trim((string) ($_POST['message'] ?? ''));
        $hasFiles = !empty($_FILES['chat_files']['name']) && is_array($_FILES['chat_files']['name'])
            && (string) ($_FILES['chat_files']['name'][0] ?? '') !== '';
        if ($text === '' && !$hasFiles) {
            json_out(['success' => false, 'error' => 'Введите сообщение или прикрепите файл']);
        }
        if ($canBankWork) {
            $stmt = $pdo->prepare(
                'SELECT c.id, c.status, ap.id AS application_product_id FROM application_product_bank_cases c
                 JOIN application_products ap ON ap.id = c.application_product_id
                 WHERE c.id = ?'
            );
            $stmt->execute([$caseId]);
            $c = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$c || !assert_manager_product($pdo, (int) $c['application_product_id'])) {
                json_out(['success' => false, 'error' => 'Нет доступа']);
            }
            if ($c['status'] === FINBANK_STATUS_DRAFT) {
                json_out(['success' => false, 'error' => 'Сначала отправьте пакет в банк']);
            }
        } elseif ($role === 'bank') {
            if (!assert_bank_case($pdo, $caseId)) {
                json_out(['success' => false, 'error' => 'Нет доступа']);
            }
        } else {
            json_out(['success' => false, 'error' => 'Нет доступа']);
        }
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO application_product_bank_case_messages (bank_case_id, user_id, message) VALUES (?,?,?)'
            )->execute([$caseId, $userId, $text]);
            $messageId = (int) $pdo->lastInsertId();
            try {
                finbank_bank_case_message_files_save_from_request($pdo, $messageId, $caseId, $userId);
            } catch (Throwable) {
                // вложения не критичны для текста сообщения
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        notify_bank_case_chat_message($pdo, $caseId, $userId, $text, $hasFiles);
        json_out(['success' => true, 'message_id' => $messageId]);
    }

    if ($action === 'bank_set_status' && $role === 'bank' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $caseId = (int) ($_POST['bank_case_id'] ?? 0);
        $newStatus = trim((string) ($_POST['new_status'] ?? ''));
        $comment = trim((string) ($_POST['comment'] ?? ''));
        $c = assert_bank_case($pdo, $caseId);
        if (!$c) {
            json_out(['success' => false, 'error' => 'Нет доступа']);
        }
        if (finbank_product_status_is_failed($c['product_status'] ?? null)) {
            json_out(['success' => false, 'error' => 'Заявка не актуальна']);
        }
        $old = $c['status'];
        $allowed = finbank_bank_allowed_targets($old);
        if (!in_array($newStatus, $allowed, true)) {
            json_out(['success' => false, 'error' => 'Недопустимый переход статуса']);
        }
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE application_product_bank_cases SET status = ?, updated_at = NOW() WHERE id = ?')
                ->execute([$newStatus, $caseId]);
            $pdo->prepare(
                'INSERT INTO application_product_bank_case_status_log (bank_case_id, old_status, new_status, comment, changed_by)
                 VALUES (?,?,?,?,?)'
            )->execute([$caseId, $old, $newStatus, $comment === '' ? null : $comment, $userId]);
            $logId = (int) $pdo->lastInsertId();
            try {
                finbank_bank_case_status_log_files_save_from_request($pdo, $logId, $caseId, $userId);
            } catch (Throwable) {
                // файлы к записи лога — опционально
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        notify_bank_case_status_changed($pdo, $caseId, $userId, $old, $newStatus, $comment);
        json_out(['success' => true]);
    }

    json_out(['success' => false, 'error' => 'Неизвестное действие']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_out(['success' => false, 'error' => 'Ошибка: ' . $e->getMessage()]);
}
