<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/upload_access.php';
require_once __DIR__ . '/includes/beneficiary_intake.php';
require_once __DIR__ . '/includes/chat_helpers.php';
checkAuth();

header('Content-Type: application/json; charset=utf-8');

$pdo = getPDO();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$userRole = (string) ($_SESSION['role'] ?? 'client');

function finbuild_app_chat_fail(int $code, string $message): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function finbuild_app_chat_ok(array $payload): void
{
    echo json_encode(array_merge(['success' => true], $payload), JSON_UNESCAPED_UNICODE);
    exit;
}

$action = (string) ($_POST['action'] ?? $_GET['action'] ?? 'get');
$applicationId = (int) ($_POST['application_id'] ?? $_GET['application_id'] ?? 0);
if ($applicationId <= 0) {
    finbuild_app_chat_fail(400, 'Не указана заявка');
}

$stmt = $pdo->prepare(
    "SELECT id, created_by, principal_user_id, intake_status, assigned_to, company_name
     FROM applications
     WHERE id = ?
     LIMIT 1"
);
$stmt->execute([$applicationId]);
$application = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$application) {
    finbuild_app_chat_fail(404, 'Заявка не найдена');
}

$currentUser = getCurrentUser() ?: ['role' => $userRole, 'id' => $userId];
$userIsAnalystFlag = finbuild_user_is_analyst_flag($currentUser);

if (!finbuild_can_access_application($pdo, $applicationId, $userRole, $userId, $userIsAnalystFlag)) {
    finbuild_app_chat_fail(403, 'Нет доступа');
}

if (!finbuild_can_use_product_chat($currentUser)) {
    finbuild_app_chat_fail(403, 'Нет доступа к чату');
}

$allowedThreads = finbuild_chat_allowed_threads_for_viewer($currentUser, $application);
$requestedThread = finbuild_chat_normalize_thread((string) ($_POST['thread'] ?? $_GET['thread'] ?? ''));
if (finbuild_is_manager($userRole)) {
    $activeThread = in_array($requestedThread, $allowedThreads, true) ? $requestedThread : ($allowedThreads[0] ?? 'principal');
} else {
    $forced = finbuild_chat_thread_for_viewer($currentUser, $application);
    $activeThread = $forced && in_array($forced, $allowedThreads, true) ? $forced : ($allowedThreads[0] ?? 'principal');
}

/**
 * @return array{unread:int,total_unread:int}
 */
function finbuild_app_chat_unread_payload(
    PDO $pdo,
    int $applicationId,
    string $userRole,
    int $userId,
    array $application,
    string $activeThread,
    array $allowedThreads
): array {
    return [
        'unread' => finbuild_application_chat_unread_count(
            $pdo, $applicationId, $userRole, $userId, $application, $activeThread
        ),
        'total_unread' => finbuild_application_chat_unread_total(
            $pdo, $applicationId, $userRole, $userId, $application, $allowedThreads
        ),
    ];
}

if ($action === 'counts') {
    $unread = finbuild_app_chat_unread_payload(
        $pdo, $applicationId, $userRole, $userId, $application, $activeThread, $allowedThreads
    );
    finbuild_app_chat_ok([
        'application_id' => $applicationId,
        'thread' => $activeThread,
        'allowed_threads' => $allowedThreads,
        'unread' => $unread['unread'],
        'total_unread' => $unread['total_unread'],
    ]);
}

if ($action === 'mark_read') {
    finbuild_application_chat_mark_read(
        $pdo, $applicationId, $userRole, $userId, $application, $activeThread
    );
    $unread = finbuild_app_chat_unread_payload(
        $pdo, $applicationId, $userRole, $userId, $application, $activeThread, $allowedThreads
    );
    finbuild_app_chat_ok([
        'application_id' => $applicationId,
        'thread' => $activeThread,
        'allowed_threads' => $allowedThreads,
        'unread' => $unread['unread'],
        'total_unread' => $unread['total_unread'],
    ]);
}

if ($action === 'send') {
    $message = trim((string) ($_POST['message'] ?? ''));
    $hasFiles = !empty($_FILES['chat_files']['name'][0]);

    if ($message === '' && !$hasFiles) {
        finbuild_app_chat_fail(400, 'Пустое сообщение');
    }

    $pdo->beginTransaction();
    try {
        $stmtIns = $pdo->prepare(
            "INSERT INTO application_chats (application_id, thread, user_id, message)
             VALUES (?, ?, ?, ?)"
        );
        $stmtIns->execute([$applicationId, $activeThread, $userId, $message]);
        $messageId = (int) $pdo->lastInsertId();

        if ($hasFiles) {
            $uploadDir = __DIR__ . '/uploads/app_chat/' . $applicationId . '/';
            $publicDir = 'uploads/app_chat/' . $applicationId . '/';
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            $allowedExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'txt', 'zip', 'rar'];
            $maxBytes = 20 * 1024 * 1024;

            foreach (($_FILES['chat_files']['name'] ?? []) as $key => $originalName) {
                $err = $_FILES['chat_files']['error'][$key] ?? UPLOAD_ERR_NO_FILE;
                if ($err !== UPLOAD_ERR_OK) {
                    continue;
                }
                $tmp = (string) ($_FILES['chat_files']['tmp_name'][$key] ?? '');
                $size = (int) ($_FILES['chat_files']['size'][$key] ?? 0);
                if ($tmp === '' || $size <= 0 || $size > $maxBytes) {
                    continue;
                }

                $ext = strtolower(pathinfo((string) $originalName, PATHINFO_EXTENSION));
                if (!in_array($ext, $allowedExtensions, true)) {
                    continue;
                }

                $fileName = uniqid('', true) . '.' . $ext;
                $destAbs = $uploadDir . $fileName;
                $destPublic = $publicDir . $fileName;

                if (!move_uploaded_file($tmp, $destAbs)) {
                    continue;
                }

                $fileType = finbuild_chat_get_file_type($ext);
                $stmtFile = $pdo->prepare(
                    "INSERT INTO application_chat_files
                        (chat_message_id, file_path, original_name, file_size, file_type, uploaded_by)
                     VALUES (?, ?, ?, ?, ?, ?)"
                );
                $stmtFile->execute([$messageId, $destPublic, (string) $originalName, $size, $fileType, $userId]);
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        finbuild_app_chat_fail(500, 'Не удалось отправить сообщение');
    }

    require_once __DIR__ . '/includes/notification_events.php';
    notify_application_chat_message($pdo, $applicationId, $userId, $activeThread, $message, $hasFiles);
}

$stmtMsg = $pdo->prepare(
    "SELECT c.*, u.first_name, u.last_name, u.role, u.is_submanager
     FROM application_chats c
     INNER JOIN users u ON u.id = c.user_id
     WHERE c.application_id = ? AND c.thread = ?
     ORDER BY c.created_at ASC"
);
$stmtMsg->execute([$applicationId, $activeThread]);
$messages = $stmtMsg->fetchAll(PDO::FETCH_ASSOC);

$messageFiles = [];
if (!empty($messages)) {
    $stmtFiles = $pdo->prepare(
        "SELECT * FROM application_chat_files
         WHERE chat_message_id = ?
         ORDER BY created_at ASC"
    );
    foreach ($messages as $m) {
        $mid = (int) $m['id'];
        $stmtFiles->execute([$mid]);
        $messageFiles[$mid] = array_map(
            static fn (array $f): array => finbuild_upload_row_with_url($f, 'app_chat'),
            $stmtFiles->fetchAll(PDO::FETCH_ASSOC)
        );
    }
}

$shouldMarkRead = false;
if ($action === 'send') {
    $shouldMarkRead = true;
} else {
    $mr = strtolower((string) ($_GET['mark_read'] ?? $_POST['mark_read'] ?? '1'));
    $shouldMarkRead = !in_array($mr, ['0', 'false', 'no', 'off'], true);
}

if ($shouldMarkRead) {
    finbuild_application_chat_mark_read(
        $pdo, $applicationId, $userRole, $userId, $application, $activeThread
    );
}

$unread = finbuild_app_chat_unread_payload(
    $pdo, $applicationId, $userRole, $userId, $application, $activeThread, $allowedThreads
);
$messagesHtml = finbuild_chat_render_messages_html($messages, $messageFiles, $userId, getCurrentUser(), 'app_chat');

finbuild_app_chat_ok([
    'application_id' => $applicationId,
    'thread' => $activeThread,
    'allowed_threads' => $allowedThreads,
    'messages_html' => $messagesHtml,
    'unread' => $unread['unread'],
    'total_unread' => $unread['total_unread'],
]);
