<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/upload_access.php';
require_once __DIR__ . '/includes/beneficiary_intake.php';
checkAuth();

header('Content-Type: application/json; charset=utf-8');

$pdo = getPDO();
$userId = (int)($_SESSION['user_id'] ?? 0);
$userRole = (string)($_SESSION['role'] ?? 'client');

require_once __DIR__ . '/includes/chat_helpers.php';

function finbuild_chat_fail(int $code, string $message): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function finbuild_chat_ok(array $payload): void
{
    echo json_encode(array_merge(['success' => true], $payload), JSON_UNESCAPED_UNICODE);
    exit;
}

$action = (string)($_POST['action'] ?? $_GET['action'] ?? 'get');
$productId = (int)($_POST['application_product_id'] ?? $_GET['application_product_id'] ?? 0);
if ($productId <= 0) {
    finbuild_chat_fail(400, 'Не указан product id');
}

$stmt = $pdo->prepare(
    "SELECT ap.id AS application_product_id,
            ap.application_id,
            a.created_by AS application_owner_id,
            a.principal_user_id,
            a.intake_status
     FROM application_products ap
     INNER JOIN applications a ON a.id = ap.application_id
     WHERE ap.id = ?
     LIMIT 1"
);
$stmt->execute([$productId]);
$meta = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$meta) {
    finbuild_chat_fail(404, 'Продукт не найден');
}

$applicationId = (int)$meta['application_id'];
$applicationOwnerId = (int)$meta['application_owner_id'];
$principalUserId = (int)($meta['principal_user_id'] ?? 0);
$currentUser = getCurrentUser() ?: ['role' => $userRole, 'id' => $userId];
$userIsAnalystFlag = finbuild_user_is_analyst_flag($currentUser);

if (!finbuild_can_access_application($pdo, $applicationId, $userRole, $userId, $userIsAnalystFlag)) {
    finbuild_chat_fail(403, 'Нет доступа');
}

if (!finbuild_can_use_product_chat($currentUser)) {
    finbuild_chat_fail(403, 'Нет доступа к чату');
}

if ($userRole === 'beneficiary') {
    finbuild_chat_fail(403, 'Чат по продукту недоступен для заказчика');
}

$allowedThreads = finbuild_chat_allowed_threads_for_viewer($currentUser, $meta);
$requestedThread = finbuild_chat_normalize_thread((string)($_POST['thread'] ?? $_GET['thread'] ?? ''));
if (finbuild_is_manager($userRole)) {
    $activeThread = in_array($requestedThread, $allowedThreads, true) ? $requestedThread : $allowedThreads[0];
} else {
    $forced = finbuild_chat_thread_for_viewer($currentUser, $meta);
    $activeThread = $forced && in_array($forced, $allowedThreads, true) ? $forced : $allowedThreads[0];
}

if (!finbuild_chat_thread_has_product_chats($activeThread)) {
    finbuild_chat_fail(403, 'Чат по продукту доступен только с клиентом');
}

/**
 * @return array{0: array<int,int>, 1: int}
 */
function finbuild_chat_compute_unread_counts(
    PDO $pdo,
    int $applicationId,
    string $userRole,
    int $userId,
    int $applicationOwnerId,
    int $principalUserId,
    string $activeThread
): array {
    $stmtProducts = $pdo->prepare("SELECT id FROM application_products WHERE application_id = ?");
    $stmtProducts->execute([$applicationId]);
    $productIds = array_map(static fn($r) => (int)$r['id'], $stmtProducts->fetchAll(PDO::FETCH_ASSOC));

    $unreadCounts = [];
    $totalUnread = 0;
    foreach ($productIds as $pid) {
        if (finbuild_is_manager($userRole)) {
            $stmtUnread = $pdo->prepare(
                "SELECT COUNT(*) FROM application_product_chats c
                 INNER JOIN users u ON u.id = c.user_id
                 WHERE c.application_product_id = ? AND c.thread = 'principal'
                   AND c.is_read = 0 AND u.role IN ('client', 'partner')"
            );
            $stmtUnread->execute([$pid]);
        } else {
            $stmtUnread = $pdo->prepare(
                "SELECT COUNT(*) FROM application_product_chats
                 WHERE application_product_id = ? AND thread = 'principal' AND is_read = 0 AND user_id != ?"
            );
            $stmtUnread->execute([$pid, $userId]);
        }
        $cnt = (int)($stmtUnread->fetchColumn() ?? 0);
        $unreadCounts[$pid] = $cnt;
        $totalUnread += $cnt;
    }
    return [$unreadCounts, $totalUnread];
}

function finbuild_chat_mark_thread_read(
    PDO $pdo,
    int $productId,
    string $userRole,
    int $userId,
    int $applicationOwnerId,
    int $principalUserId,
    string $activeThread
): void {
    if (finbuild_is_manager($userRole)) {
        $stmtRead = $pdo->prepare(
            "UPDATE application_product_chats c
             INNER JOIN users u ON u.id = c.user_id
             SET c.is_read = 1
             WHERE c.application_product_id = ? AND c.thread = 'principal'
               AND c.is_read = 0 AND u.role IN ('client', 'partner')"
        );
        $stmtRead->execute([$productId]);
    } else {
        $stmtRead = $pdo->prepare(
            "UPDATE application_product_chats
             SET is_read = 1
             WHERE application_product_id = ? AND thread = 'principal' AND user_id != ? AND is_read = 0"
        );
        $stmtRead->execute([$productId, $userId]);
    }
}

if ($action === 'counts') {
    [$unreadCounts, $totalUnread] = finbuild_chat_compute_unread_counts(
        $pdo, $applicationId, $userRole, $userId, $applicationOwnerId, $principalUserId, $activeThread
    );
    finbuild_chat_ok([
        'application_id' => $applicationId,
        'thread' => $activeThread,
        'allowed_threads' => $allowedThreads,
        'unread_counts' => $unreadCounts,
        'total_unread' => $totalUnread,
    ]);
}

if ($action === 'mark_read') {
    finbuild_chat_mark_thread_read(
        $pdo, $productId, $userRole, $userId, $applicationOwnerId, $principalUserId, $activeThread
    );
    [$unreadCounts, $totalUnread] = finbuild_chat_compute_unread_counts(
        $pdo, $applicationId, $userRole, $userId, $applicationOwnerId, $principalUserId, $activeThread
    );
    finbuild_chat_ok([
        'application_id' => $applicationId,
        'thread' => $activeThread,
        'allowed_threads' => $allowedThreads,
        'unread_counts' => $unreadCounts,
        'total_unread' => $totalUnread,
    ]);
}

if ($action === 'send') {
    $message = trim((string)($_POST['message'] ?? ''));
    $hasFiles = !empty($_FILES['chat_files']['name'][0]);

    if ($message === '' && !$hasFiles) {
        finbuild_chat_fail(400, 'Пустое сообщение');
    }

    $pdo->beginTransaction();
    try {
        $stmtIns = $pdo->prepare(
            "INSERT INTO application_product_chats (application_product_id, thread, user_id, message)
             VALUES (?, ?, ?, ?)"
        );
        $stmtIns->execute([$productId, $activeThread, $userId, $message]);
        $messageId = (int)$pdo->lastInsertId();

        if ($hasFiles) {
            $uploadDir = __DIR__ . '/uploads/chat/' . $productId . '/';
            $publicDir = 'uploads/chat/' . $productId . '/';
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
                $tmp = (string)($_FILES['chat_files']['tmp_name'][$key] ?? '');
                $size = (int)($_FILES['chat_files']['size'][$key] ?? 0);
                if ($tmp === '' || $size <= 0 || $size > $maxBytes) {
                    continue;
                }

                $ext = strtolower(pathinfo((string)$originalName, PATHINFO_EXTENSION));
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
                    "INSERT INTO application_product_chat_files
                        (chat_message_id, file_path, original_name, file_size, file_type, uploaded_by)
                     VALUES (?, ?, ?, ?, ?, ?)"
                );
                $stmtFile->execute([$messageId, $destPublic, (string)$originalName, $size, $fileType, $userId]);
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        finbuild_chat_fail(500, 'Не удалось отправить сообщение');
    }

    require_once __DIR__ . '/includes/notification_events.php';
    notify_product_chat_message($pdo, $productId, $userId, $message, $hasFiles);
}

$stmtMsg = $pdo->prepare(
    "SELECT c.*, u.first_name, u.last_name, u.role, u.is_submanager
     FROM application_product_chats c
     INNER JOIN users u ON u.id = c.user_id
     WHERE c.application_product_id = ? AND c.thread = ?
     ORDER BY c.created_at ASC"
);
$stmtMsg->execute([$productId, $activeThread]);
$messages = $stmtMsg->fetchAll(PDO::FETCH_ASSOC);

$messageFiles = [];
if (!empty($messages)) {
    $stmtFiles = $pdo->prepare(
        "SELECT * FROM application_product_chat_files
         WHERE chat_message_id = ?
         ORDER BY created_at ASC"
    );
    foreach ($messages as $m) {
        $mid = (int)$m['id'];
        $stmtFiles->execute([$mid]);
        $messageFiles[$mid] = array_map(
            static fn (array $f): array => finbuild_upload_row_with_url($f, 'chat'),
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
    finbuild_chat_mark_thread_read(
        $pdo, $productId, $userRole, $userId, $applicationOwnerId, $principalUserId, $activeThread
    );
}

[$unreadCounts, $totalUnread] = finbuild_chat_compute_unread_counts(
    $pdo, $applicationId, $userRole, $userId, $applicationOwnerId, $principalUserId, $activeThread
);
$messagesHtml = finbuild_chat_render_messages_html($messages, $messageFiles, $userId, getCurrentUser());

finbuild_chat_ok([
    'application_id' => $applicationId,
    'application_product_id' => $productId,
    'thread' => $activeThread,
    'allowed_threads' => $allowedThreads,
    'messages_html' => $messagesHtml,
    'unread_counts' => $unreadCounts,
    'total_unread' => $totalUnread,
]);
