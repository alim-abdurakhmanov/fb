<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/upload_access.php';
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

// Проверка доступа + получение связанных данных.
$stmt = $pdo->prepare(
    "SELECT ap.id AS application_product_id,
            ap.application_id,
            a.created_by AS application_owner_id
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
$currentUser = getCurrentUser() ?: ['role' => $userRole, 'id' => $userId];
$userIsAnalystFlag = finbuild_user_is_analyst_flag($currentUser);

if (!finbuild_can_access_application($pdo, $applicationId, $userRole, $userId, $userIsAnalystFlag)) {
    finbuild_chat_fail(403, 'Нет доступа');
}

// Подсчет непрочитанных по всем продуктам заявки (как на странице заявки).
function finbuild_chat_compute_unread_counts(PDO $pdo, int $applicationId, string $userRole, int $userId, int $applicationOwnerId): array
{
    $stmtProducts = $pdo->prepare("SELECT id FROM application_products WHERE application_id = ?");
    $stmtProducts->execute([$applicationId]);
    $productIds = array_map(static fn($r) => (int)$r['id'], $stmtProducts->fetchAll(PDO::FETCH_ASSOC));

    $unreadCounts = [];
    $totalUnread = 0;
    foreach ($productIds as $pid) {
        if (finbuild_is_manager($userRole)) {
            $stmtUnread = $pdo->prepare(
                "SELECT COUNT(*) as unread_count
                 FROM application_product_chats
                 WHERE application_product_id = ? AND is_read = 0 AND user_id = ?"
            );
            $stmtUnread->execute([$pid, $applicationOwnerId]);
        } else {
            $stmtUnread = $pdo->prepare(
                "SELECT COUNT(*) as unread_count
                 FROM application_product_chats
                 WHERE application_product_id = ? AND is_read = 0 AND user_id != ?"
            );
            $stmtUnread->execute([$pid, $userId]);
        }
        $cnt = (int)($stmtUnread->fetchColumn() ?? 0);
        $unreadCounts[$pid] = $cnt;
        $totalUnread += $cnt;
    }
    return [$unreadCounts, $totalUnread];
}

// Быстрый endpoint только для бейджей (для пуллинга, когда drawer закрыт).
if ($action === 'counts') {
    [$unreadCounts, $totalUnread] = finbuild_chat_compute_unread_counts($pdo, $applicationId, $userRole, $userId, $applicationOwnerId);
    finbuild_chat_ok([
        'application_id' => $applicationId,
        'unread_counts' => $unreadCounts,
        'total_unread' => $totalUnread,
    ]);
}

// Пометка прочитанными без перезагрузки ленты (когда пользователь доскроллил до низа).
if ($action === 'mark_read') {
    if (finbuild_is_manager($userRole)) {
        $stmtRead = $pdo->prepare(
            "UPDATE application_product_chats
             SET is_read = 1
             WHERE application_product_id = ? AND user_id = ? AND is_read = 0"
        );
        $stmtRead->execute([$productId, $applicationOwnerId]);
    } else {
        $stmtRead = $pdo->prepare(
            "UPDATE application_product_chats
             SET is_read = 1
             WHERE application_product_id = ? AND user_id != ? AND is_read = 0"
        );
        $stmtRead->execute([$productId, $userId]);
    }
    [$unreadCounts, $totalUnread] = finbuild_chat_compute_unread_counts($pdo, $applicationId, $userRole, $userId, $applicationOwnerId);
    finbuild_chat_ok([
        'application_id' => $applicationId,
        'unread_counts' => $unreadCounts,
        'total_unread' => $totalUnread,
    ]);
}

// Отправка сообщения/файлов.
if ($action === 'send') {
    $message = trim((string)($_POST['message'] ?? ''));
    $hasFiles = !empty($_FILES['chat_files']['name'][0]);

    if ($message === '' && !$hasFiles) {
        finbuild_chat_fail(400, 'Пустое сообщение');
    }

    $pdo->beginTransaction();
    try {
        $stmtIns = $pdo->prepare(
            "INSERT INTO application_product_chats (application_product_id, user_id, message)
             VALUES (?, ?, ?)"
        );
        $stmtIns->execute([$productId, $userId, $message]);
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

// Получение сообщений + файлов.
$stmtMsg = $pdo->prepare(
    "SELECT c.*, u.first_name, u.last_name, u.role, u.is_submanager
     FROM application_product_chats c
     INNER JOIN users u ON u.id = c.user_id
     WHERE c.application_product_id = ?
     ORDER BY c.created_at ASC"
);
$stmtMsg->execute([$productId]);
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

// Пометка прочитанными: после send — всегда; иначе (в т.ч. action=get) — по mark_read (по умолчанию 1).
// При опросе с mark_read=0 не обнуляем бейджи, пока пользователь не увидел конец ленты.
$shouldMarkRead = false;
if ($action === 'send') {
    $shouldMarkRead = true;
} else {
    $mr = strtolower((string) ($_GET['mark_read'] ?? $_POST['mark_read'] ?? '1'));
    $shouldMarkRead = !in_array($mr, ['0', 'false', 'no', 'off'], true);
}

if ($shouldMarkRead) {
    if (finbuild_is_manager($userRole)) {
        $stmtRead = $pdo->prepare(
            "UPDATE application_product_chats
             SET is_read = 1
             WHERE application_product_id = ? AND user_id = ? AND is_read = 0"
        );
        $stmtRead->execute([$productId, $applicationOwnerId]);
    } else {
        $stmtRead = $pdo->prepare(
            "UPDATE application_product_chats
             SET is_read = 1
             WHERE application_product_id = ? AND user_id != ? AND is_read = 0"
        );
        $stmtRead->execute([$productId, $userId]);
    }
}

[$unreadCounts, $totalUnread] = finbuild_chat_compute_unread_counts($pdo, $applicationId, $userRole, $userId, $applicationOwnerId);
$messagesHtml = finbuild_chat_render_messages_html($messages, $messageFiles, $userId, getCurrentUser());

finbuild_chat_ok([
    'application_id' => $applicationId,
    'application_product_id' => $productId,
    'messages_html' => $messagesHtml,
    'unread_counts' => $unreadCounts,
    'total_unread' => $totalUnread,
]);

