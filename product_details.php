<?php
$current_page = 'applications';
require_once 'config.php';
require_once __DIR__ . '/includes/chat_helpers.php';
require_once __DIR__ . '/includes/upload_access.php';
checkAuth();

$pdo = getPDO();
$currentUser = getCurrentUser();
$userRole = $_SESSION['role'] ?? 'client';
$userId = $_SESSION['user_id'];
$userIsAnalystFlag = finbuild_user_is_analyst_flag($currentUser);

// Проверяем ID продукта в заявке
$productAppId = $_GET['id'] ?? 0;
if (!$productAppId) {
    header('Location: applications.php');
    exit();
}

// Получаем данные продукта в заявке
$stmt = $pdo->prepare("
    SELECT ap.*, a.id as application_id, a.company_name, a.inn, a.product_type as app_product_type,
           a.amount as app_amount, a.term as app_term, a.term_bg as app_term_bg,
           a.created_at as app_created_at, a.status as app_status, 
           a.created_by as application_creator,
           a.assigned_to as application_assigned_to,
           u.first_name, u.last_name, u.company_name as user_company, 
           u.role as user_role
    FROM application_products ap
    JOIN applications a ON ap.application_id = a.id
    LEFT JOIN users u ON a.created_by = u.id
    WHERE ap.id = ?
");
$stmt->execute([$productAppId]);
$productApp = $stmt->fetch();

// Проверяем доступ
if (!$productApp) {
    header('Location: applications.php');
    exit();
}

if (finbuild_is_pure_analyst($currentUser)) {
    header('Location: application_details.php?id=' . (int) $productApp['application_id']);
    exit();
}

if ($userIsAnalystFlag && (int) $productApp['application_creator'] !== (int) $userId) {
    header('Location: application_details.php?id=' . (int) $productApp['application_id']);
    exit();
}

// Клиенты и партнеры могут видеть только свои заявки; case_manager — только назначенные
if (!finbuild_can_access_application(
    $pdo,
    (int) $productApp['application_id'],
    $userRole,
    (int) $userId,
    $userIsAnalystFlag
)) {
    header('Location: applications.php');
    exit();
}

require_once __DIR__ . '/includes/bank_portal.php';
$showBankWorkTab = (finbuild_is_manager($userRole) && finbank_is_portal_application_product($productApp));
$productTab = $_GET['tab'] ?? 'details';
$allowedProductTabs = $showBankWorkTab ? ['details', 'documents', 'bank'] : ['details', 'documents'];
if (!in_array($productTab, $allowedProductTabs, true)) {
    $productTab = 'details';
}

// Получаем детали продукта
if ($productApp['product_type'] === 'bg') {
    $stmt = $pdo->prepare("SELECT * FROM bank_products WHERE id = ?");
} else {
    $stmt = $pdo->prepare("SELECT * FROM credit_products WHERE id = ?");
}
$stmt->execute([$productApp['product_id']]);
$product = $stmt->fetch();

// Допустимые статусы
$allowedStatuses = [
    'bg' => ['В работе', 'На подписании', 'Запрос', 'Согласование условий', 'На выпуске', 'БГ выпущена', 'Отказано', 'Не актуален для клиента'],
    'credit' => ['В работе', 'Запрос', 'Выдан', 'Отказано', 'Не актуален для клиента']
];

// Обработка изменения статуса и данных продукта
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_product'])) {
    $newStatus = $_POST['status'] ?? '';
    $notes = $_POST['notes'] ?? '';
    
    if (in_array($newStatus, $allowedStatuses[$productApp['product_type']])) {
        // Обновляем только status и notes (без conditions)
        $stmt = $pdo->prepare("UPDATE application_products SET status = ?, notes = ? WHERE id = ?");
        $stmt->execute([$newStatus, $notes, $productAppId]);
        
        // Обновляем статус заявки
        updateApplicationStatus($productApp['application_id']);
        
        // Перенаправляем чтобы избежать повторной отправки
        header('Location: product_details.php?id=' . $productAppId . '&tab=details');
        exit();
    }
}

// Обработка отправки сообщения и файлов
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['message']) || isset($_FILES['chat_files']))) {
    $message = trim($_POST['message'] ?? '');
    $hasFiles = !empty($_FILES['chat_files']['name'][0]);
    
    // Проверяем, что есть либо сообщение, либо файлы
    if (!empty($message) || $hasFiles) {
        // Создаем сообщение
        $stmt = $pdo->prepare("
            INSERT INTO application_product_chats (application_product_id, user_id, message) 
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$productAppId, $userId, $message]);
        $messageId = $pdo->lastInsertId();
        
        // Обрабатываем файлы
        if ($hasFiles) {
            $uploadDir = 'uploads/chat/' . $productAppId . '/';
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            foreach ($_FILES['chat_files']['name'] as $key => $name) {
                if ($_FILES['chat_files']['error'][$key] === UPLOAD_ERR_OK) {
                    $fileTmp = $_FILES['chat_files']['tmp_name'][$key];
                    $fileSize = $_FILES['chat_files']['size'][$key];
                    $originalName = $name;
                    $fileExt = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                    
                    // Проверяем тип файла
                    $allowedExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'txt', 'zip', 'rar'];
                    if (!in_array($fileExt, $allowedExtensions)) {
                        continue; // Пропускаем недопустимые файлы
                    }
                    
                    // Проверяем размер файла (20MB максимум)
                    if ($fileSize > 20 * 1024 * 1024) {
                        continue; // Пропускаем слишком большие файлы
                    }
                    
                    // Генерируем уникальное имя файла
                    $fileName = uniqid() . '.' . $fileExt;
                    $filePath = $uploadDir . $fileName;
                    
                    // Определяем тип файла для иконки
                    $fileType = getFileType($fileExt);
                    
                    // Перемещаем файл
                    if (move_uploaded_file($fileTmp, $filePath)) {
                        // Сохраняем информацию о файле в базу
                        $stmt = $pdo->prepare("INSERT INTO application_product_chat_files 
                            (chat_message_id, file_path, original_name, file_size, file_type, uploaded_by) 
                            VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$messageId, $filePath, $originalName, $fileSize, $fileType, $userId]);
                    }
                }
            }
        }

        require_once __DIR__ . '/includes/notification_events.php';
        notify_product_chat_message($pdo, (int) $productAppId, (int) $userId, $message, $hasFiles);
        
        // Перенаправляем чтобы избежать повторной отправки
    header('Location: product_details.php?id=' . $productAppId . '&tab=details');
        exit();
    }
}
// Получаем сообщения чата с файлами
$stmt = $pdo->prepare("
    SELECT c.*, u.first_name, u.last_name, u.role, u.is_submanager
    FROM application_product_chats c
    JOIN users u ON c.user_id = u.id
    WHERE c.application_product_id = ?
    ORDER BY c.created_at ASC
");
$stmt->execute([$productAppId]);
$messages = $stmt->fetchAll();

// Получаем файлы для каждого сообщения
$messageFiles = [];
foreach ($messages as $message) {
    $stmt = $pdo->prepare("
        SELECT * FROM application_product_chat_files 
        WHERE chat_message_id = ? 
        ORDER BY created_at ASC
    ");
    $stmt->execute([$message['id']]);
    $messageFiles[$message['id']] = array_map(
        static fn (array $f): array => finbuild_upload_row_with_url($f, 'chat'),
        $stmt->fetchAll()
    );
}

// Помечаем сообщения как прочитанные (для менеджера — только от владельца заявки)
if (finbuild_is_manager($userRole)) {
    $stmt = $pdo->prepare("
        UPDATE application_product_chats 
        SET is_read = 1 
        WHERE application_product_id = ? AND user_id = ? AND is_read = 0
    ");
    $stmt->execute([$productAppId, $productApp['application_creator']]);
} else {
    $stmt = $pdo->prepare("
        UPDATE application_product_chats 
        SET is_read = 1 
        WHERE application_product_id = ? AND user_id != ? AND is_read = 0
    ");
    $stmt->execute([$productAppId, $userId]);
}

// Получаем общее количество непрочитанных сообщений по всем продуктам заявки
// Для менеджеров — только сообщения от владельца заявки (a.created_by)
if (finbuild_is_manager($userRole)) {
    $stmtUnreadTotal = $pdo->prepare("
        SELECT COUNT(DISTINCT apc.id) as total_unread
        FROM application_products ap
        INNER JOIN applications a ON ap.application_id = a.id
        LEFT JOIN application_product_chats apc ON apc.application_product_id = ap.id 
            AND apc.is_read = 0 
            AND apc.user_id = a.created_by
        WHERE ap.application_id = ?
    ");
    $stmtUnreadTotal->execute([$productApp['application_id']]);
} else {
    $stmtUnreadTotal = $pdo->prepare("
        SELECT COUNT(DISTINCT apc.id) as total_unread
        FROM application_products ap
        LEFT JOIN application_product_chats apc ON apc.application_product_id = ap.id 
            AND apc.is_read = 0 
            AND apc.user_id != ?
        WHERE ap.application_id = ?
    ");
    $stmtUnreadTotal->execute([$userId, $productApp['application_id']]);
}
$totalUnreadMessages = $stmtUnreadTotal->fetch()['total_unread'] ?? 0;
// --- НАЧАЛО ВСТАВКИ: Логика документов ---
// Получаем документы продукта
$stmt = $pdo->prepare("
    SELECT d.*, u.first_name, u.last_name, u.role as creator_role
    FROM application_product_documents d
    LEFT JOIN users u ON d.created_by = u.id
    WHERE d.application_product_id = ?
    ORDER BY d.created_at DESC
");
$stmt->execute([$productAppId]);
$documents = $stmt->fetchAll();

// Получаем файлы для документов
$documentFiles = [];
foreach ($documents as $doc) {
    $stmt = $pdo->prepare("SELECT * FROM application_product_document_files WHERE document_id = ?");
    $stmt->execute([$doc['id']]);
    $documentFiles[$doc['id']] = array_map(
        static fn (array $f): array => finbuild_upload_row_with_url($f, 'prod_doc'),
        $stmt->fetchAll()
    );
}

// Считаем бейдж для вкладки
$documentsCount = 0;
if (finbuild_is_manager($userRole)) {
    // Менеджер: считаем заполненные
    foreach ($documents as $d) { if (!empty($documentFiles[$d['id']])) $documentsCount++; }
} else {
    // Клиент: считаем пустые
    foreach ($documents as $d) { if (empty($documentFiles[$d['id']])) $documentsCount++; }
}

// Считаем статусы документов
$docsCompleted = 0; // Загруженные
$docsPending = 0;   // Ожидающие

foreach ($documents as $doc) {
    if (!empty($documentFiles[$doc['id']])) {
        $docsCompleted++;
    } else {
        $docsPending++;
    }
}
// --- КОНЕЦ ВСТАВКИ ---

require_once 'header.php';

// Функции для форматирования
function getProductStatusBadge($status, $productType) {
    $statusColors = [
        'В работе' => 'bg-primary',
        'На подписании' => 'bg-info',
        'Запрос' => 'bg-warning',
        'Согласование условий' => 'bg-purple',
        'На выпуске' => 'bg-orange',
        'БГ выпущена' => 'bg-success',
        'Выдан' => 'bg-success',
        'Отказано' => 'bg-danger',
        'Не актуален для клиента' => 'bg-secondary'
    ];
    
    $color = $statusColors[$status] ?? 'bg-secondary';
    return '<span class="badge ' . $color . '">' . $status . '</span>';
}

function getApplicationStatusBadge($status) {
    switch ($status) {
        case 'new': 
            return '<span class="badge status-badge-new">На проверке</span>';
        case 'in_progress': 
            return '<span class="badge status-badge-in-progress">В работе</span>';
        case 'pending_signing':
            return '<span class="badge status-badge-pending-signing">На подписании</span>';
        case 'product_request':
            return '<span class="badge status-badge-product-request">Запрос</span>';
        case 'terms_negotiation':
            return '<span class="badge status-badge-terms-negotiation">Согласование условий</span>';
        case 'pending_release':
            return '<span class="badge status-badge-pending-release">На выпуске</span>';
        case 'completed': 
            return '<span class="badge status-badge-completed">Завершена</span>';
        case 'failed': 
            return '<span class="badge status-badge-failed">Провалена</span>';
        default: 
            return '<span class="badge bg-secondary">' . htmlspecialchars((string)$status) . '</span>';
    }
}

function formatMessageDate($date) {
    $today = date('Y-m-d');
    $messageDate = date('Y-m-d', strtotime($date));
    
    if ($today === $messageDate) {
        return 'Сегодня в ' . date('H:i', strtotime($date));
    } else {
        return date('d.m.Y в H:i', strtotime($date));
    }
}

function formatFileSize($bytes) {
    if ($bytes == 0) return '0 Bytes';
    $k = 1024;
    $sizes = ['Bytes', 'KB', 'MB', 'GB'];
    $i = floor(log($bytes) / log($k));
    return round($bytes / pow($k, $i), 2) . ' ' . $sizes[$i];
}

function getFileIcon($fileType) {
    $icons = [
        'pdf' => 'bi-file-earmark-pdf text-danger',
        'word' => 'bi-file-earmark-word text-primary',
        'excel' => 'bi-file-earmark-spreadsheet text-success',
        'image' => 'bi-file-earmark-image text-warning',
        'archive' => 'bi-file-earmark-zip text-secondary',
        'text' => 'bi-file-earmark-text text-info',
        'file' => 'bi-file-earmark text-secondary'
    ];
    
    return $icons[$fileType] ?? 'bi-file-earmark text-secondary';
}

function getFileType($extension) {
    $types = [
        'pdf' => 'pdf',
        'doc' => 'word', 
        'docx' => 'word',
        'xls' => 'excel',
        'xlsx' => 'excel',
        'jpg' => 'image',
        'jpeg' => 'image',
        'png' => 'image',
        'txt' => 'text',
        'zip' => 'archive',
        'rar' => 'archive'
    ];
    
    return $types[$extension] ?? 'file';
}

function formatAmount($amount) {
    if (!$amount) return '-';
    return number_format($amount, 2, ',', ' ') . ' ₽';
}

function getProductTypeText($productType) {
    return $productType === 'bg' ? 'Банковская гарантия' : 'Кредит для бизнеса';
}
?>

<div class="page-header">
    <!-- Верхняя градиентная полоса статуса -->
    <?php
    $appStripMap = [
        'new' => 'status-strip-new',
        'in_progress' => 'status-strip-in-progress',
        'pending_signing' => 'status-strip-pending-signing',
        'product_request' => 'status-strip-product-request',
        'terms_negotiation' => 'status-strip-terms-negotiation',
        'pending_release' => 'status-strip-pending-release',
        'completed' => 'status-strip-completed',
        'failed' => 'status-strip-failed',
    ];
    $appStripClass = $appStripMap[$productApp['app_status']] ?? 'status-strip-in-progress';
    ?>
    <div class="status-strip <?= htmlspecialchars($appStripClass) ?>"></div>
    
    <div class="row align-items-center">
        <div class="col">
            <div class="d-flex align-items-center gap-3 mb-2">
                <h1 class="h3 mb-0">
                    <a href="application_details.php?id=<?= $productApp['application_id'] ?>" 
                       class="text-dark text-decoration-none">
                        Заявка #<?= $productApp['application_id'] ?>
                    </a>
                </h1>
                <?= getApplicationStatusBadge($productApp['app_status']) ?>
                <span class="badge bg-light text-dark">
                    <i class="bi bi-arrow-right me-1"></i>
                    Продукт <?= htmlspecialchars($productApp['bank_name']) ?> в заявке
                </span>
            </div>
            
            <div class="d-flex flex-wrap align-items-center gap-3">
                <div class="application-meta-item">
                    <i class="bi bi-building me-1 text-muted"></i>
                    <span class="text-dark fw-medium"><?= htmlspecialchars($productApp['company_name']) ?></span>
                </div>
                <div class="text-muted dot-separator">•</div>
                <div class="application-meta-item">
                    <i class="bi bi-tag me-1 text-muted"></i>
                    <span><?= getProductTypeText($productApp['app_product_type']) ?></span>
                </div>
                <?php if ($productApp['app_amount']): ?>
                <div class="text-muted dot-separator">•</div>
                <div class="application-meta-item">
                    <i class="bi bi-currency-ruble me-1 text-muted"></i>
                    <strong class="text-success"><?= formatAmount($productApp['app_amount']) ?></strong>
                </div>
                <?php endif; ?>
            <?php if ($productApp['app_product_type'] === 'bg' && $productApp['app_term_bg']): ?>
<div class="text-muted dot-separator">•</div>
<div class="application-meta-item">
    <i class="bi bi-calendar me-1 text-muted"></i>
    <span>до <?= date('d.m.Y', strtotime($productApp['app_term_bg'])) ?> (<?= $productApp['app_term'] ?> мес.)</span>
</div>
<?php elseif ($productApp['app_product_type'] === 'credit' && $productApp['app_term']): ?>
<div class="text-muted dot-separator">•</div>
<div class="application-meta-item">
    <i class="bi bi-calendar me-1 text-muted"></i>
    <span><?= $productApp['app_term'] ?> мес.</span>
</div>
<?php endif; ?>
            </div>
            
            <div class="mt-2">
                <small class="text-muted">
                    <i class="bi bi-clock me-1"></i>
                    Создана: <?= date('d.m.Y H:i', strtotime($productApp['app_created_at'])) ?>
                    <?php if ($totalUnreadMessages > 0): ?>
                        <span class="ms-3 text-danger">
                            <i class="bi bi-chat-dots me-1"></i>
                            <strong><?= $totalUnreadMessages ?> непрочитанных сообщений</strong>
                        </span>
                    <?php endif; ?>
                </small>
            </div>
        </div>
        <div class="col-auto">
            <a href="application_details.php?id=<?= $productApp['application_id'] ?>" class="btn btn-primary">
                <i class="bi bi-arrow-left me-2"></i>Назад к заявке
            </a>
        </div>
    </div>
</div>




<!-- Информация о заявке -->
<!-- <div class="card mb-4">
    <div class="card-header">
        <h5 class="card-title mb-0 small">
            <i class="bi bi-file-text me-2"></i>Информация о заявке
        </h5>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-4">
                <div class="mb-3">
                    <strong>Компания:</strong><br>
                    <?= htmlspecialchars($productApp['company_name']) ?>
                </div>
                <div class="mb-3">
                    <strong>ИНН:</strong><br>
                    <?= htmlspecialchars($productApp['inn']) ?>
                </div>
            </div>
            <div class="col-md-4">
                <div class="mb-3">
                    <strong>Статус заявки:</strong><br>
                    <?= getApplicationStatusBadge($productApp['app_status']) ?>
                </div>
                <div class="mb-3">
                    <strong>Сумма:</strong><br>
                    <?= formatAmount($productApp['app_amount']) ?>
                </div>
            </div>
            <div class="col-md-4">
                <div class="mb-3">
                    <strong>Срок:</strong><br>
                    <?= $productApp['app_term'] ? $productApp['app_term'] . ' мес.' : '-' ?>
                </div>
                <div class="mb-3">
                    <strong>Создана:</strong><br>
                    <?= date('d.m.Y H:i', strtotime($productApp['app_created_at'])) ?>
                </div>
            </div>
        </div>
    </div>
</div> -->
<!-- НАВИГАЦИЯ ТАБОВ -->
<!-- Современные табы (Underline Style) -->
<ul class="nav nav-pills nav-fill bg-light rounded-pill p-1 mb-4 shadow-sm" id="productTabs" role="tablist" style="max-width: <?= $showBankWorkTab ? '820px' : '600px' ?>;">
    <li class="nav-item" role="presentation">
        <button class="nav-link rounded-pill d-flex align-items-center justify-content-center <?= $productTab === 'details' ? 'active' : '' ?>" id="details-tab" data-bs-toggle="pill" data-bs-target="#details" type="button" role="tab">
            <i class="bi bi-info-circle me-2"></i>Детали и чат
        </button>
    </li>
    <li class="nav-item" role="presentation">
    <button class="nav-link rounded-pill d-flex align-items-center justify-content-center position-relative <?= $productTab === 'documents' ? 'active' : '' ?>" id="documents-tab" data-bs-toggle="pill" data-bs-target="#documents" type="button" role="tab">
    <i class="bi bi-folder me-2"></i>Документы
    
    <?php if (finbuild_is_manager($userRole)): ?>
        <!-- Для менеджера: Два счетчика -->
        <?php if ($docsCompleted > 0): ?>
            <span class="badge rounded-pill bg-success ms-2 shadow-sm" title="Загружено" style="font-size: 0.7em;">
                <?php echo $docsCompleted; ?>
            </span>
        <?php endif; ?>
        
        <?php if ($docsPending > 0): ?>
            <span class="badge rounded-pill bg-danger ms-1 shadow-sm" title="Ожидает загрузки" style="font-size: 0.7em;">
                <?php echo $docsPending; ?>
            </span>
        <?php endif; ?>
        
    <?php else: ?>
        <!-- Для клиента: Один счетчик (сколько осталось загрузить) -->
        <?php if ($docsPending > 0): ?>
            <span class="badge rounded-pill bg-danger ms-2 shadow-sm" style="font-size: 0.7em;">
                <?php echo $docsPending; ?>
            </span>
        <?php endif; ?>
    <?php endif; ?>
</button>
    </li>
    <?php if ($showBankWorkTab): ?>
    <li class="nav-item" role="presentation">
        <button class="nav-link rounded-pill d-flex align-items-center justify-content-center <?= $productTab === 'bank' ? 'active' : '' ?>" id="bank-work-tab" data-bs-toggle="pill" data-bs-target="#bank-work" type="button" role="tab">
            <i class="bi bi-bank me-2"></i>Работа с банком
        </button>
    </li>
    <?php endif; ?>
</ul>

<style>
/* Кастомные стили для табов-переключателей */
.nav-pills .nav-link {
    color: #6c757d;
    font-weight: 500;
    transition: all 0.3s ease;
}
.nav-pills .nav-link:hover {
    color: #0d6efd;
    background-color: rgba(13, 110, 253, 0.05);
}
.nav-pills .nav-link.active {
    background-color: #fff !important;
    color: #0d6efd !important;
    box-shadow: 0 2px 4px rgba(0,0,0,0.08);
}

</style>
<!-- КОНТЕНТ ТАБОВ -->
<div class="tab-content" id="productTabsContent">
    
    <!-- ТАБ 1: СУЩЕСТВУЮЩИЙ КОНТЕНТ -->
    <div class="tab-pane fade <?= $productTab === 'details' ? 'show active' : '' ?>" id="details" role="tabpanel">
<div class="row">
    <!-- Информация о продукте -->
<div class="col-lg-8">
    <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
        <h5 class="card-title mb-0">
            <i class="bi bi-box me-2"></i>О продукте
        </h5>
        <div>
            <?= getProductStatusBadge($productApp['status'], $productApp['product_type']) ?>
        </div>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-6">
                <div class="mb-3">
                    <strong>Банк:</strong><br>
                    <?= htmlspecialchars($productApp['bank_name']) ?>
                </div>
                <div class="mb-3">
                    <strong>Продукт:</strong><br>
                    <?= htmlspecialchars($productApp['product_name']) ?>
                </div>
                <div class="mb-3">
                    <strong>Тип:</strong><br>
                    <?= $productApp['product_type'] === 'bg' ? 'Банковская гарантия' : 'Кредит для бизнеса' ?>
                </div>
            </div>
            
            <div class="col-md-6">
                <?php if ($product): ?>
                    <?php if ($productApp['product_type'] === 'bg'): ?>
                        <div class="mb-3">
                            <strong>Макс. сумма:</strong><br>
                            <?= $product['max_amount'] ? number_format($product['max_amount'], 0, '', ' ') . ' ₽' : 'не ограничена' ?>
                        </div>
                        <div class="mb-3">
                            <strong>Макс. срок:</strong><br>
                            <?= $product['max_term'] ? $product['max_term'] . ' мес.' : 'не ограничен' ?>
                        </div>
                    <?php else: ?>
                        <div class="mb-3">
                            <strong>Сумма:</strong><br>
                            <?= $product['amount'] ? number_format($product['amount'], 0, '', ' ') . ' ₽' : 'не указана' ?>
                        </div>
                        <div class="mb-3">
                            <strong>Срок:</strong><br>
                            <?= htmlspecialchars($product['term'] ?? 'не указан') ?>
                        </div>
                        <div class="mb-3">
                            <strong>Ставка:</strong><br>
                            <?= htmlspecialchars($product['interest_rate'] ?? 'не указана') ?>
                        </div>
                    <?php endif; ?>
                    
                    
                <?php else: ?>
                    <div class="text-muted">Информация о продукте не найдена</div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Дополнительная информация (аккордеон) -->
        <?php if ($product): ?>
        <div class="accordion mt-4" id="additionalInfoAccordion">
            <div class="accordion-item">
                <h2 class="accordion-header" id="additionalInfoHeading">
                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" 
                            data-bs-target="#additionalInfoCollapse" aria-expanded="false" 
                            aria-controls="additionalInfoCollapse">
                        <i class="bi bi-info-circle me-2"></i>Дополнительная информация
                    </button>
                </h2>
                <div id="additionalInfoCollapse" class="accordion-collapse collapse" 
                     aria-labelledby="additionalInfoHeading" data-bs-parent="#additionalInfoAccordion">
                    <div class="accordion-body">
                        <div class="row">
                            <?php if ($productApp['product_type'] === 'bg'): ?>
                                <!-- Банковские гарантии -->
                                <div class="col-md-6">
                                    <?php 
                                    // Виды БГ (JSON)
                                    $bg_types = $product['bg_types'] ? json_decode($product['bg_types'], true) : [];
                                    if ($bg_types && is_array($bg_types)): 
                                    ?>
                                    <div class="mb-3">
                                        <strong>Виды БГ:</strong><br>
                                        <?= implode(', ', $bg_types) ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php 
                                    // ФЗ (JSON)
                                    $fz_types = $product['fz_types'] ? json_decode($product['fz_types'], true) : [];
                                    if ($fz_types && is_array($fz_types)): 
                                    ?>
                                    <div class="mb-3">
                                        <strong>ФЗ:</strong><br>
                                        <?= implode(', ', $fz_types) ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($product['limit_amount']): ?>
                                    <div class="mb-3">
                                        <strong>Лимитная сумма:</strong><br>
                                        <?= htmlspecialchars($product['limit_amount']) ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($product['company_age']): ?>
                                    <div class="mb-3">
                                        <strong>Возраст компании:</strong><br>
                                        <?= htmlspecialchars($product['company_age']) ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($product['spfs']): ?>
                                    <div class="mb-3">
                                        <strong>СПФС:</strong><br>
                                        <?= htmlspecialchars($product['spfs']) ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($product['third_party_payment']): ?>
                                    <div class="mb-3">
                                        <strong>Оплата третьих лиц:</strong><br>
                                        <?= htmlspecialchars($product['third_party_payment']) ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="col-md-6">
                                    <?php if ($product['customers']): ?>
                                    <div class="mb-3">
                                        <strong>Заказчики:</strong><br>
                                        <?= nl2br(htmlspecialchars($product['customers'])) ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($product['works_with_individual_entrepreneurs']): ?>
                                    <div class="mb-3">
                                        <strong>Работа с ИП:</strong><br>
                                        <?= htmlspecialchars($product['works_with_individual_entrepreneurs']) ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($product['works_with_state_enterprises']): ?>
                                    <div class="mb-3">
                                        <strong>Работа с госпредприятиями:</strong><br>
                                        <?= htmlspecialchars($product['works_with_state_enterprises']) ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php 
                                    // Стоп-регионы
                                    $stop_regions_principal = trim($product['stop_regions_principal'] ?? '');
                                    $stop_regions_beneficiary = trim($product['stop_regions_beneficiary'] ?? '');
                                    
                                    if ($stop_regions_principal || $stop_regions_beneficiary): 
                                    ?>
                                    <div class="mb-3">
                                        <?php if ($stop_regions_principal === $stop_regions_beneficiary): ?>
                                            <strong>Стоп-регионы:</strong><br>
                                            <?= nl2br(htmlspecialchars($stop_regions_principal)) ?>
                                        <?php else: ?>
                                            <?php if ($stop_regions_principal): ?>
                                            <div class="mb-2">
                                                <strong>Стоп-регионы принципала:</strong><br>
                                                <?= nl2br(htmlspecialchars($stop_regions_principal)) ?>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <?php if ($stop_regions_beneficiary): ?>
                                            <div class="mb-2">
                                                <strong>Стоп-регионы бенефициара:</strong><br>
                                                <?= nl2br(htmlspecialchars($stop_regions_beneficiary)) ?>
                                            </div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($product['stop_factors']): ?>
                                    <div class="mb-3">
                                        <strong>Стоп-факторы:</strong><br>
                                        <?= nl2br(htmlspecialchars($product['stop_factors'])) ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                
                            <?php else: ?>
                                <!-- Кредиты -->
                                <div class="col-md-6">
                                    <?php if ($product['type']): ?>
                                    <div class="mb-3">
                                        <strong>Вид:</strong><br>
                                        <?= htmlspecialchars($product['type']) ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($product['credit_line_type']): ?>
                                    <div class="mb-3">
                                        <strong>Вид кредитной линии:</strong><br>
                                        <?= htmlspecialchars($product['credit_line_type']) ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($product['tranch_term']): ?>
                                    <div class="mb-3">
                                        <strong>Срок транша:</strong><br>
                                        <?= htmlspecialchars($product['tranch_term']) ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($product['collateral']): ?>
                                    <div class="mb-3">
                                        <strong>Залог:</strong><br>
                                        <?= htmlspecialchars($product['collateral']) ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if (isset($product['individual_entrepreneur'])): ?>
                                    <div class="mb-3">
                                        <strong>ИП:</strong><br>
                                        <?= $product['individual_entrepreneur'] ? 'Да' : 'Нет' ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($product['documents']): ?>
                                    <div class="mb-3">
                                        <strong>Документы:</strong><br>
                                        <?= nl2br(htmlspecialchars($product['documents'])) ?>
                                    </div>
                                    <?php endif; ?>
                                       
        <?php if ($product && $product['features'] && $productApp['product_type'] === 'credit'): ?>
        <div class="mb-3">
            <strong>Особенности продукта:</strong><br>
            <?= nl2br(htmlspecialchars($product['features'])) ?>
        </div>
        <?php endif; ?>
                                </div>
                                
                                <div class="col-md-6">
                                    <?php if ($product['stops']): ?>
                                    <div class="mb-3">
                                        <strong>Стопы:</strong><br>
                                        <?= nl2br(htmlspecialchars($product['stops'])) ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($product['consideration_term']): ?>
                                    <div class="mb-3">
                                        <strong>Срок рассмотрения:</strong><br>
                                        <?= htmlspecialchars($product['consideration_term']) ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($product['comments']): ?>
                                    <div class="mb-3">
                                        <strong>Комментарий:</strong><br>
                                        <?= nl2br(htmlspecialchars($product['comments'])) ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Дополнительные данные только для руководителя (не для ограниченного менеджера) -->
        <?php if (finbuild_has_full_manager_access($currentUser) && $product): ?>
        <div class="accordion mt-3" id="managerInfoAccordion">
            <div class="accordion-item">
                <h2 class="accordion-header" id="managerInfoHeading">
                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" 
                            data-bs-target="#managerInfoCollapse" aria-expanded="false" 
                            aria-controls="managerInfoCollapse">
                        <i class="bi bi-shield-lock me-2"></i>Дополнительные данные (только для руководителей)
                    </button>
                </h2>
                <div id="managerInfoCollapse" class="accordion-collapse collapse" 
                     aria-labelledby="managerInfoHeading" data-bs-parent="#managerInfoAccordion">
                    <div class="accordion-body">
                        <div class="row">
                            <?php if ($productApp['product_type'] === 'bg'): ?>
                                <!-- Банковские гарантии -->
                                <div class="col-md-6">
                                    <?php if ($product['product_passport_link']): ?>
                                    <div class="mb-3">
                                        <strong>Паспорт продукта:</strong><br>
                                        <a href="<?= htmlspecialchars($product['product_passport_link']) ?>" 
                                           target="_blank" class="text-decoration-underline">
                                            <?= htmlspecialchars($product['product_passport_link']) ?>
                                        </a>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($product['curator']): ?>
                                    <div class="mb-3">
                                        <strong>Куратор:</strong><br>
                                        <?= nl2br(htmlspecialchars($product['curator'])) ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="col-md-6">
                                    <?php if ($product['platform_access']): ?>
                                    <div class="mb-3">
                                        <strong>Данные для входа в площадку:</strong><br>
                                        <?= nl2br(htmlspecialchars($product['platform_access'])) ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                
                            <?php else: ?>
                                <!-- Кредиты -->
                                <div class="col-md-6">
                                    <?php if ($product['passport_link']): ?>
                                    <div class="mb-3">
                                        <strong>Паспорт продукта:</strong><br>
                                        <a href="<?= htmlspecialchars($product['passport_link']) ?>" 
                                           target="_blank" class="text-decoration-underline">
                                            <?= htmlspecialchars($product['passport_link']) ?>
                                        </a>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($product['curator']): ?>
                                    <div class="mb-3">
                                        <strong>Куратор:</strong><br>
                                        <?= nl2br(htmlspecialchars($product['curator'])) ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="col-md-6">
                                    <?php if ($product['work_stages']): ?>
                                    <div class="mb-3">
                                        <strong>Этапы работы:</strong><br>
                                        <?= nl2br(htmlspecialchars($product['work_stages'])) ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($product['platform_access']): ?>
                                    <div class="mb-3">
                                        <strong>Данные для входа в площадку:</strong><br>
                                        <?= nl2br(htmlspecialchars($product['platform_access'])) ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
     
        
        <?php if (!empty($productApp['notes'])): ?>
        <div class="mt-3 p-3 bg-warning bg-opacity-10 rounded">
            <strong>Примечания:</strong><br>
            <?= nl2br(htmlspecialchars($productApp['notes'])) ?>
        </div>
        <?php endif; ?>
        
        <!-- Управление для менеджеров -->
        <?php if (finbuild_is_manager($userRole)): ?>
        <div class="mt-4 pt-3 border-top">
            <h6 class="mb-3">Управление продуктом</h6>
            <form method="POST">
                <input type="hidden" name="update_product" value="1">
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Статус продукта</label>
                            <select class="form-select" name="status" required>
                                <?php foreach ($allowedStatuses[$productApp['product_type']] as $status): ?>
                                    <option value="<?= $status ?>" <?= $productApp['status'] === $status ? 'selected' : '' ?>>
                                        <?= $status ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Примечания</label>
                    <textarea class="form-control" name="notes" rows="2" placeholder="Дополнительные примечания..."><?= htmlspecialchars($productApp['notes'] ?? '') ?></textarea>
                </div>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-circle me-2"></i>Сохранить изменения
                </button>
            </form>
            
            <div class="mt-3 pt-3 border-top">
                <div class="d-flex justify-content-end">
                    <a href="#" class="text-danger text-decoration-underline small delete-product-link" 
                       onclick="deleteProduct(<?= $productAppId ?>, '<?= htmlspecialchars($productApp['product_name']) ?>')">
                        <i class="bi bi-trash me-1"></i>Удалить продукт из заявки
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
    
          <div class="col-lg-4 product-chat-aside">
        <!-- Чат -->
        <div class="card chat-panel" id="chatPanel">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h5 class="card-title mb-0">
                    <i class="bi bi-chat-dots me-2"></i>Чат по продукту
                </h5>
                <button type="button" class="btn btn-sm btn-light d-md-none chat-close" id="chatClose">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
            <div class="card-body p-0">
                <div class="chat-container" style="min-height: 550px; max-height: 700px; height: 70vh; display: flex; flex-direction: column;">
                    <div class="chat-messages" id="chatMessages" style="flex: 1; padding: 1.5rem; overflow-y: auto; background: white;">
                        <?php if (empty($messages)): ?>
                            <div class="message-system">
                                <div class="message-content">
                                    <i class="bi bi-chat-dots me-2"></i>Чат начат. Напишите первое сообщение.
                                </div>
                            </div>
                        <?php else: ?>
                            <?php foreach ($messages as $message): ?>
                                <div class="message <?= $message['user_id'] == $userId ? 'own' : ((finbuild_is_manager((string)($message['role'] ?? ''))) ? 'other-manager' : '') ?> <?= (finbuild_is_manager((string)($message['role'] ?? ''))) ? 'from-manager' : 'from-client' ?>">
                                    <div class="message-header">
                                        <span class="message-sender">
                                            <?= finbuild_chat_sender_html($message, $currentUser) ?>
                                        </span>
                                        <span class="message-time">
                                            <?= formatMessageDate($message['created_at']) ?>
                                        </span>
                                    </div>
                                    
                                    <?php if (!empty($message['message'])): ?>
                                    <div class="message-content">
                                        <?= finbuild_chat_format_message_text((string)$message['message']) ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($messageFiles[$message['id']])): ?>
                                    <div class="message-files">
                                        <?php foreach ($messageFiles[$message['id']] as $file): ?>
                                            <a href="<?= htmlspecialchars(finbuild_upload_file_url('chat', (int) $file['id'])) ?>" class="file-item" target="_blank" download="<?= htmlspecialchars($file['original_name']) ?>">
                                                <div class="file-icon">
                                                    <i class="bi <?= getFileIcon($file['file_type']) ?>"></i>
                                                </div>
                                                <div class="file-info">
                                                    <div class="file-name"><?= htmlspecialchars($file['original_name']) ?></div>
                                                    <div class="file-size"><?= formatFileSize($file['file_size']) ?></div>
                                                </div>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <div class="chat-input" style="padding: 1rem 1.5rem; background: #f8f9fa; border-top: 1px solid #e9ecef; flex-shrink: 0;">
                        <form method="POST" id="chatForm" enctype="multipart/form-data" >
                            <div class="mb-2">
                                <textarea name="message" class="form-control chat-textarea" placeholder="Введите сообщение..."></textarea>
                            </div>
                            
                            <div class="d-flex align-items-center gap-2">
                                <div class="flex-grow-1">
                                    <div class="file-input-wrapper">
                                        <button type="button" class="btn btn-outline-secondary btn-sm">
                                            <i class="bi bi-paperclip me-1"></i> Файлы
                                        </button>
                                        <input type="file" name="chat_files[]" id="chatFiles" multiple 
                                               accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.txt,.zip,.rar">
                                    </div>
                                    <div class="selected-files" id="selectedFiles"></div>
                                </div>
                                <button type="submit" class="btn btn-primary px-3">
                                    <i class="bi bi-send"></i>
                                </button>
                            </div>
                            <div class="form-text product-chat-file-hint">
                                Макс. размер файла: 20MB. Разрешены: PDF, DOC, XLS, JPG, PNG, TXT, ZIP, RAR
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
    <!-- Mobile chat FAB + overlay: только на вкладке «Детали» (чат в этой вкладке) -->
    <div class="chat-overlay d-md-none" id="chatOverlay"></div>
    <button class="chat-fab d-md-none" id="chatFab" type="button">
        <i class="bi bi-chat-dots"></i>
        <span class="chat-fab-label">Чат</span>
        <?php if ($totalUnreadMessages > 0): ?>
            <span class="chat-fab-badge"><?= (int)$totalUnreadMessages ?></span>
        <?php endif; ?>
    </button>
    </div> <!-- Закрываем tab-pane #details -->

   <div class="tab-pane fade <?= $productTab === 'documents' ? 'show active' : '' ?>" id="documents" role="tabpanel">
    
    <!-- Шапка с кнопкой добавления -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h5 class="mb-1">Пакет документов</h5>
            <p class="text-muted small mb-0">Загрузите требуемые файлы в соответствующие ячейки</p>
        </div>
        <?php if (finbuild_is_manager($userRole)): ?>
            <button class="btn btn-primary shadow-sm mobile-nowrap-btn" data-bs-toggle="modal" data-bs-target="#createDocumentModal">
                <i class="bi bi-plus-lg me-2"></i>Добавить запрос
            </button>
        <?php endif; ?>
    </div>

    <?php if (empty($documents)): ?>
        <div class="text-center py-5 rounded-3 border border-dashed bg-light mt-4">
            <div class="mb-3">
                <i class="bi bi-folder-plus text-muted opacity-50 display-1"></i>
            </div>
            <h5 class="text-muted fw-normal">Список документов пока пуст</h5>
            <p class="text-muted small">Менеджер еще не сформировал список необходимых документов.</p>
        </div>
    <?php else: ?>
        <div class="row g-4">
            <?php foreach ($documents as $doc): 
                $hasFiles = !empty($documentFiles[$doc['id']]);
                // Стили для разных состояний
                $cardClass = $hasFiles ? 'border-success border-opacity-25 bg-white' : 'border-dashed bg-light';
                $iconClass = $hasFiles ? 'bi-check-circle-fill text-success' : 'bi-circle text-muted';
                $statusText = $hasFiles ? 'Загружено' : 'Ожидает загрузки';
                $statusBadge = $hasFiles ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary';
            ?>
                <div class="col-xl-6 col-12">
                    <div class="card h-100 shadow-sm document-slot <?php echo $cardClass; ?>">
                        <div class="card-body d-flex flex-column">
                            
                            <!-- Заголовок слота -->
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div class="d-flex align-items-center">
                                    <i class="bi <?php echo $iconClass; ?> fs-4 me-3"></i>
                                    <div>
                                        <h6 class="fw-bold mb-0 text-dark"><?php echo htmlspecialchars($doc['title']); ?></h6>
                                        <div class="d-flex align-items-center mt-1">
                                            <span class="badge rounded-pill <?php echo $statusBadge; ?> border border-opacity-10" style="font-weight: 500;">
                                                <?php echo $statusText; ?>
                                            </span>
                                            <small class="text-muted ms-2" style="font-size: 0.75rem;">
                                                <?php echo date('d.m.Y', strtotime($doc['created_at'])); ?>
                                            </small>
                                        </div>
                                    </div>
                                </div>
                                <?php if (finbuild_is_manager($userRole)): ?>
                                    <div class="dropdown">
                                        <button class="btn btn-link text-muted p-0" data-bs-toggle="dropdown">
                                            <i class="bi bi-three-dots-vertical"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <li><a class="dropdown-item text-danger delete-document-btn" href="#" data-document-id="<?php echo $doc['id']; ?>">Удалить запрос</a></li>
                                        </ul>
                                    </div>
                                <?php endif; ?>
                            </div>

                        <!-- Описание от менеджера -->
<?php if ($doc['description']): ?>
    <div class="mb-2">
        <div class="p-2 rounded bg-light border border-opacity-10">
            <small class="text-secondary d-block fw-bold mb-1">
                <i class="bi bi-info-circle me-1"></i>Описание:
            </small>
            <p class="small mb-0 text-dark"><?php echo nl2br(htmlspecialchars($doc['description'])); ?></p>
        </div>
    </div>
<?php endif; ?>
<!-- Комментарий клиента/партнера -->
<?php if (!empty($doc['client_comment'])): ?>
    <div class="mb-3">
        <div class="p-2 rounded bg-opacity-10 bg-warning border border-warning border-opacity-10">
            <small class="text-warning-emphasis d-block fw-bold mb-1">
                <i class="bi bi-chat-left-text me-1"></i>
                <?php 
                    if (finbuild_is_manager($userRole)) {
                        
                        echo 'Комментарий ' . (isset($productApp['user_role']) && $productApp['user_role'] === 'partner' ? 'партнера' : 'клиента') . ':';
                        
                    } else {
                        // Если смотрит сам клиент или партнер
                        echo 'Ваш комментарий:';
                    }
                ?>
            </small>
            <p class="small mb-0 text-dark fst-italic">
                "<?php echo nl2br(htmlspecialchars($doc['client_comment'])); ?>"
            </p>
        </div>
    </div>
<?php endif; ?>


                            <!-- Область файлов -->
                            <div class="flex-grow-1 mt-2">
                                <?php if ($hasFiles): ?>
                                    <div class="d-flex flex-column gap-2">
                                        <?php foreach ($documentFiles[$doc['id']] as $file): ?>
                                            <div class="d-flex align-items-center p-2 border rounded bg-white file-row hover-shadow position-relative">
                                                <div class="me-3 text-secondary">
                                                    <i class="bi <?php echo getFileIcon($file['file_type']); ?> fs-4"></i>
                                                </div>
                                                <div class="flex-grow-1 text-truncate" style="min-width: 0;">
                                                    <a href="<?php echo htmlspecialchars(finbuild_upload_file_url('prod_doc', (int) $file['id'])); ?>" target="_blank" class="fw-medium text-dark text-decoration-none stretched-link">
                                                        <?php echo htmlspecialchars($file['original_name']); ?>
                                                    </a>
                                                    <div class="small text-muted">
                                                        <?php echo formatFileSize($file['file_size']); ?>
                                                    </div>
                                                </div>
                                                <?php if (finbuild_is_manager($userRole)): ?>
                                                    <!-- z-index нужен, чтобы кнопка была поверх stretched-link -->
                                                    <div class="ms-2 position-relative" style="z-index: 10;">
                                                        <button class="btn btn-icon btn-sm text-danger bg-light rounded-circle delete-file-btn" data-file-id="<?php echo $file['id']; ?>" title="Удалить">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-4 border rounded-3 bg-white border-light h-100 d-flex flex-column justify-content-center">
                                        <span class="text-muted small">Нет загруженных файлов</span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Кнопка действия -->
                            <div class="mt-4">
                                <button class="btn <?php echo $hasFiles ? 'btn-outline-primary' : 'btn-primary'; ?> w-100 upload-files-btn" data-document-id="<?php echo $doc['id']; ?>">
                                    <i class="bi bi-upload me-2"></i>
                                    <?php echo $hasFiles ? 'Загрузить еще файлы' : 'Загрузить документы'; ?>
                                </button>
                            </div>

                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    </div><!-- #documents -->
    <?php if ($showBankWorkTab): ?>
    <div class="tab-pane fade <?= $productTab === 'bank' ? 'show active' : '' ?>" id="bank-work" role="tabpanel">
        <div id="bankWorkRoot" data-application-product-id="<?= (int) $productAppId ?>">
            <div class="d-flex align-items-center py-5 text-muted" id="bankWorkLoading">
                <div class="spinner-border spinner-border-sm me-2" role="status"></div>
                Загрузка…
            </div>
            <div id="bankWorkBody" class="d-none">
                <div class="row g-4 align-items-start">
                    <div class="col-lg-8">
                        <div class="mb-4">
                            <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                                <span class="badge bg-secondary fs-6" id="bankWorkStatusBadge"></span>
                            </div>
                            <div id="bankWorkBankLastAction" class="d-none border rounded-3 bg-light p-3 small text-body"></div>
                        </div>
                        <div class="card shadow-sm border-0 mb-4">
                            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <h5 class="mb-0"><i class="bi bi-folder2-open me-2"></i>Пакет документов для банка</h5>
                            </div>
                            <div class="card-body">
                                <p class="text-muted small mb-3" id="bankWorkPackageHelp">Документы из заявки и из вкладки «Документы» этого продукта. Снимите позицию с отправки крестиком; при необходимости добавьте файлы с названием и описанием.</p>
                                <div id="bankWorkPackageList" class="list-group list-group-flush mb-3"></div>
                                <div id="bankWorkDraftUpload" class="border rounded p-3 bg-light mb-3 d-none">
                                    <h6 class="small text-uppercase text-muted mb-3">Добавить документ в пакет</h6>
                                    <div class="row g-2 mb-2">
                                        <div class="col-md-4">
                                            <input type="text" class="form-control form-control-sm" id="bankUploadTitle" placeholder="Название *">
                                        </div>
                                        <div class="col-md-5">
                                            <input type="text" class="form-control form-control-sm" id="bankUploadDesc" placeholder="Описание">
                                        </div>
                                        <div class="col-md-3">
                                            <input type="file" class="form-control form-control-sm" id="bankUploadFile">
                                        </div>
                                    </div>
                                    <button type="button" class="btn btn-sm btn-outline-primary" id="bankUploadBtn"><i class="bi bi-upload me-1"></i>Загрузить</button>
                                </div>
                                <div class="mb-3" id="bankManagerCommentSection">
                                    <div class="mb-0" id="bankManagerCommentBlock">
                                        <label class="form-label small fw-semibold">Комментарий для банка</label>
                                        <div id="bankManagerCommentEdit">
                                            <textarea class="form-control" id="bankManagerComment" rows="3" placeholder="Виден банку вместе с пакетом"></textarea>
                                        </div>
                                        <div id="bankManagerCommentRead" class="d-none bank-manager-comment-display small text-body"></div>
                                    </div>
                                </div>
                                <button type="button" class="btn btn-primary" id="bankSubmitBtn"><i class="bi bi-send me-2"></i>Отправить в банк</button>
                            </div>
                        </div>
                        <div class="accordion shadow-sm rounded overflow-hidden mb-4" id="bankWorkStatusLogAccordion">
                            <div class="accordion-item border-0">
                                <h2 class="accordion-header" id="bankWorkStatusLogHeading">
                                    <button class="accordion-button collapsed bg-white py-3 shadow-none" type="button" data-bs-toggle="collapse" data-bs-target="#bankWorkStatusLogCollapse" aria-expanded="false" aria-controls="bankWorkStatusLogCollapse">
                                        <span class="mb-0 fw-semibold"><i class="bi bi-clock-history me-2"></i>История статусов</span>
                                    </button>
                                </h2>
                                <div id="bankWorkStatusLogCollapse" class="accordion-collapse collapse" aria-labelledby="bankWorkStatusLogHeading" data-bs-parent="#bankWorkStatusLogAccordion">
                                    <div class="accordion-body small pt-0 border-top bg-white" id="bankWorkStatusLog"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4 product-chat-aside">
                        <div class="card shadow-sm border-0 mb-4 bg-light bank-work-chat-placeholder" id="bankWorkChatPlaceholder">
                            <div class="card-body text-center text-muted py-5 px-3">
                                <i class="bi bi-chat-dots display-6 d-block mb-3 opacity-50"></i>
                                <p class="mb-0 small">Чат с банком откроется здесь после отправки пакета.</p>
                            </div>
                        </div>
                        <div class="card shadow-sm border-0 mb-4 d-none bank-work-panel-chat" id="bankWorkChatCard">
                            <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between">
                                <h5 class="card-title mb-0"><i class="bi bi-chat-dots me-2"></i>Чат с банком</h5>
                            </div>
                            <div class="card-body p-0">
                                <div class="chat-container bank-work-chat-container">
                                    <div class="chat-messages" id="bankWorkMessages" style="flex: 1; min-height: 0; overflow-y: auto; background: #fff;"></div>
                                    <div class="chat-input">
                                        <div class="mb-2">
                                            <textarea id="bankWorkMessageInput" class="form-control chat-textarea" placeholder="Введите сообщение…" rows="2"></textarea>
                                        </div>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="flex-grow-1">
                                                <div class="file-input-wrapper">
                                                    <button type="button" class="btn btn-outline-secondary btn-sm">
                                                        <i class="bi bi-paperclip me-1"></i> Файлы
                                                    </button>
                                                    <input type="file" id="bankWorkChatFiles" name="chat_files[]" multiple>
                                                </div>
                                                <div class="selected-files" id="bankWorkChatSelectedFiles"></div>
                                            </div>
                                            <button type="button" class="btn btn-primary px-3" id="bankWorkMessageSend" title="Отправить">
                                                <i class="bi bi-send"></i>
                                            </button>
                                        </div>
                                        <div class="form-text product-chat-file-hint mb-0">Макс. 20 МБ на файл.</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="alert alert-danger d-none" id="bankWorkError"></div>
        </div>
    </div>
    <?php endif; ?>
</div> <!-- Закрываем tab-content -->

<style>





/* Кастомные стили для этого блока */
.border-dashed {
    border-style: dashed !important;
    border-width: 2px !important;
}
.document-slot {
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.document-slot:hover {
    transform: translateY(-3px);
    box-shadow: 0 .5rem 1rem rgba(0,0,0,.08)!important;
}
.file-row {
    transition: background-color 0.2s;
}
.file-row:hover {
    background-color: #f8f9fa !important;
}
.btn-icon {
    width: 32px;
    height: 32px;
    padding: 0;
    display: flex;
    align-items: center;
    justify-content: center;
}
/* Растягиваем ссылку на весь блок файла, но оставляем кнопку удаления рабочей */
.stretched-link::after {
    z-index: 1;
}
/* Стили для иконок внутри алертов */
.text-warning-emphasis {
    color: #664d03;
}
</style>






<style>
    /* Ограничение ширины текста в аккордеонах */
.accordion-body {
    word-wrap: break-word;
    word-break: break-word;
    overflow-wrap: break-word;
}

/* Дополнительно можно ограничить длинные слова в других элементах */
.accordion-body strong + br + *,
.accordion-body div {
    word-wrap: break-word;
    word-break: break-word;
}
    /* Бейджи статусов с небольшим улучшением */
.status-badge-new {
    background: linear-gradient(135deg, #ffc107, #ff9800);
    border: none;
    padding: 0.4rem 0.8rem;
    font-weight: 500;
    box-shadow: 0 2px 4px rgba(255, 152, 0, 0.2);
}

.status-badge-in-progress {
    background: linear-gradient(135deg, #3498db, #2980b9);
    border: none;
    padding: 0.4rem 0.8rem;
    font-weight: 500;
    box-shadow: 0 2px 4px rgba(52, 152, 219, 0.2);
}

.status-badge-completed {
    background: linear-gradient(135deg, #28a745, #20c997);
    border: none;
    padding: 0.4rem 0.8rem;
    font-weight: 500;
    box-shadow: 0 2px 4px rgba(39, 174, 96, 0.2);
}

.status-badge-failed {
    background: linear-gradient(135deg, #dc3545, #c82333);
    border: none;
    padding: 0.4rem 0.8rem;
    font-weight: 500;
    box-shadow: 0 2px 4px rgba(220, 53, 69, 0.2);
}

.status-badge-pending-signing {
    background: linear-gradient(135deg, #17a2b8, #138496);
    border: none;
    padding: 0.4rem 0.8rem;
    font-weight: 500;
    color: #fff;
}

.status-badge-product-request {
    background: linear-gradient(135deg, #ffc107, #e0a800);
    border: none;
    padding: 0.4rem 0.8rem;
    font-weight: 500;
    color: #fff;
}

.status-badge-terms-negotiation {
    background: linear-gradient(135deg, #6f42c1, #5a32a3);
    border: none;
    padding: 0.4rem 0.8rem;
    font-weight: 500;
    color: #fff;
}

.status-badge-pending-release {
    background: linear-gradient(135deg, #fd7e14, #e8590c);
    border: none;
    padding: 0.4rem 0.8rem;
    font-weight: 500;
    color: #fff;
}
    /* Стили для новой шапки продукта */
.product-name-badge {
    background: linear-gradient(135deg, #f8f9fa, #e9ecef);
    padding: 0.75rem 1.25rem;
    border-radius: 10px;
    border: 1px solid #dee2e6;
    font-size: 1rem;
}

.product-name-badge i {
    font-size: 1.1rem;
}

/* Верхняя градиентная полоса статуса */
.status-strip {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    border-radius: 12px 12px 0 0;
}

.status-strip-new {
    background: linear-gradient(90deg, #ffc107, #ff9800);
}

.status-strip-in-progress {
    background: linear-gradient(90deg, #3498db, #2980b9);
}

.status-strip-completed {
    background: linear-gradient(90deg, #28a745, #20c997);
}

.status-strip-failed {
    background: linear-gradient(90deg, #dc3545, #c82333);
}

.status-strip-pending-signing {
    background: linear-gradient(90deg, #17a2b8, #138496);
}

.status-strip-product-request {
    background: linear-gradient(90deg, #ffc107, #e0a800);
}

.status-strip-terms-negotiation {
    background: linear-gradient(90deg, #6f42c1, #5a32a3);
}

.status-strip-pending-release {
    background: linear-gradient(90deg, #fd7e14, #e8590c);
}

/* Обновляем page-header для позиционирования полосы */
.page-header {
    background: white;
    border-radius: 12px;
    padding: 2rem;
    margin-bottom: 2rem;
    box-shadow: 0 2px 20px rgba(0,0,0,0.08);
    border: 1px solid #e9ecef;
    position: relative;
    padding-top: 2.5rem;
}

.application-meta-item {
    display: flex;
    align-items: center;
}

/* Кликабельный заголовок заявки */
.page-header h1 a:hover {
    color: #3498db !important;
    text-decoration: underline !important;
}

/* Адаптивность */
@media (max-width: 768px) {
    .page-header {
        padding: 1rem;
        border-radius: 12px;
    }

    .page-header h1 {
        font-size: 1.05rem;
    }

    .page-header .row {
        row-gap: 0.6rem;
    }

    .page-header .d-flex.align-items-center {
        gap: 0.35rem !important;
        flex-wrap: wrap;
    }

    .page-header .d-flex.flex-wrap {
        align-items: center;
    }
    
    .page-header .text-muted.dot-separator {
        display: none;
    }
    
    .application-meta-item {
        margin-bottom: 0.25rem;
    }
    
    .product-name-badge {
        padding: 0.5rem 1rem;
        font-size: 0.9rem;
    }

    .page-header .application-meta-item,
    .page-header small {
        font-size: 0.85rem;
    }

    .page-header .btn {
        width: 100%;
    }

    .page-header .col-auto {
        width: 100%;
    }

    .mobile-nowrap-btn {
        white-space: nowrap;
        font-size: 0.85rem;
        padding: 0.45rem 0.7rem;
    }

    .card-title {
        font-size: 1.1rem !important;
    }
}
.chat-container {
    height: 500px;
    display: flex;
    flex-direction: column;
}

.chat-messages {
    flex: 1;
    padding: 1.5rem;
    overflow-y: auto;
    background: white;
}

.chat-input {
    padding: 1rem 1.5rem;
    background: #f8f9fa;
    border-top: 1px solid #e9ecef;
    flex-shrink: 0;
}

/* Подсказка под вложениями в чатах */
.product-chat-file-hint {
    font-size: 0.68rem;
    line-height: 1.35;
    color: #6c757d;
    margin-top: 0.35rem;
}

.message {
    margin-bottom: 1.5rem;
    max-width: 85%;
}

.message.own {
    margin-left: auto;
}

.message-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.5rem;
}

.message.own .message-header {
    justify-content: flex-end;
}

.message-sender {
    font-weight: 600;
    color: #2c3e50;
    font-size: 0.875rem;
    line-height: 1.3;
}

.message-sender .badge {
    font-size: 0.65rem;
    padding: 0.12rem 0.32rem;
    line-height: 1.12;
    vertical-align: middle;
}

.message-time {
    font-size: 0.72rem;
    color: #6c757d;
    margin-left: 1rem;
}

@media (max-width: 768px) {
    .message-header {
        flex-wrap: nowrap;
        gap: 8px;
        align-items: flex-start;
    }
    .message-sender {
        min-width: 0;
        flex: 1 1 auto;
        font-size: 0.82rem;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        gap: 2px;
    }
    .message-time {
        white-space: nowrap;
        font-size: 0.66rem;
        margin-left: 0.5rem;
        flex: 0 0 auto;
        padding-top: 1px;
    }
    .message-sender .badge {
        font-size: 0.58rem;
        padding: 0.14rem 0.3rem;
        line-height: 1.1;
        margin-left: 0 !important;
    }
}

.message-content {
    background: #f8f9fa;
    padding: 1rem;
    border-radius: 12px;
    border: 1px solid #e9ecef;
    max-width: 100%;
    overflow-wrap: anywhere;
    word-break: break-word;
}

.message-content a {
    color: #0d6efd;
    text-decoration: underline;
    word-break: break-all;
}

.message.own .message-content a {
    color: #fff;
}

.message.own .message-content {
    background: #3498db;
    color: white;
    border-color: #3498db;
}

.message.other-manager .message-content {
    background: #e2e8f0;
}

.message-files {
    margin-top: 0.75rem;
    padding-top: 0.75rem;
    border-top: 1px solid rgba(0,0,0,0.1);
}

.message.own .message-files {
    border-top-color: rgba(255,255,255,0.3);
}

.file-item {
    display: flex;
    align-items: center;
    padding: 0.75rem;
    background: white;
    border-radius: 8px;
    margin-bottom: 0.5rem;
    text-decoration: none;
    color: #2c3e50;
    border: 1px solid #e9ecef;
    transition: all 0.2s;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.message.own .file-item {
    background: rgba(255,255,255,0.9);
    color: #2c3e50;
    border-color: rgba(255,255,255,0.5);
}

.file-item:hover {
    background: #f8f9fa;
    text-decoration: none;
    color: #2c3e50;
    border-color: #3498db;
    transform: translateY(-1px);
    box-shadow: 0 2px 5px rgba(0,0,0,0.15);
}

.message.own .file-item:hover {
    background: white;
    color: #2c3e50;
    border-color: #3498db;
}

.file-item:last-child {
    margin-bottom: 0;
}

.file-icon {
    font-size: 1.8rem;
    margin-right: 1rem;
    flex-shrink: 0;
    width: 40px;
    text-align: center;
}

.file-info {
    flex: 1;
    min-width: 0;
}

.file-name {
    font-weight: 500;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    margin-bottom: 0.25rem;
    font-size: 0.9rem;
}

.file-size {
    font-size: 0.75rem;
    opacity: 0.7;
    color: #6c757d;
}

.message-system {
    text-align: center;
    margin: 2rem 0;
}

.message-system .message-content {
    background: #fff3cd;
    border-color: #ffeaa7;
    color: #856404;
    display: inline-block;
    padding: 0.75rem 1.5rem;
    border-radius: 20px;
}

.chat-textarea {
    resize: none;
    min-height: 60px;
    max-height: 120px;
}

.file-input-wrapper {
    position: relative;
    overflow: hidden;
    display: inline-block;
}

.file-input-wrapper input[type=file] {
    position: absolute;
    left: 0;
    top: 0;
    opacity: 0;
    width: 100%;
    height: 100%;
    cursor: pointer;
}

.selected-files {
    margin-top: 0.5rem;
}

.selected-file {
    display: inline-flex;
    align-items: center;
    background: #e9ecef;
    padding: 0.4rem 0.75rem;
    border-radius: 6px;
    margin-right: 0.5rem;
    margin-bottom: 0.5rem;
    font-size: 0.85rem;
    border: 1px solid #dee2e6;
}

.selected-file .bi {
    margin-right: 0.5rem;
}

.remove-file {
    margin-left: 0.75rem;
    cursor: pointer;
    opacity: 0.7;
    color: #dc3545;
}

.remove-file:hover {
    opacity: 1;
}

/*
 * Компактный чат по ширине колонки: только если колонка узкая (< 480px), и без сильного уменьшения шрифтов.
 */
.product-chat-aside {
    container-type: inline-size;
    container-name: product-chat;
}

@container product-chat (max-width: 480px) {
    .chat-panel #chatMessages,
    .bank-work-panel-chat #bankWorkMessages {
        padding: 0.95rem 1rem !important;
    }

    .chat-panel .chat-input,
    .bank-work-panel-chat .chat-input {
        padding: 0.65rem 1rem !important;
    }

    .chat-panel .message,
    .bank-work-panel-chat .message {
        margin-bottom: 1rem;
        max-width: 92%;
    }

    .chat-panel .message-header,
    .bank-work-panel-chat .message-header {
        margin-bottom: 0.38rem;
        gap: 6px;
        align-items: flex-start;
    }

    .chat-panel .message-sender,
    .bank-work-panel-chat .message-sender {
        font-size: 0.82rem !important;
        line-height: 1.28;
    }

    .chat-panel .message-time,
    .bank-work-panel-chat .message-time {
        font-size: 0.68rem !important;
        margin-left: 0.5rem;
        line-height: 1.15;
    }

    .chat-panel .message-sender .badge,
    .bank-work-panel-chat .message-sender .badge {
        font-size: 0.6rem !important;
        padding: 0.1rem 0.28rem !important;
        line-height: 1.1;
    }

    .chat-panel .message-content,
    .bank-work-panel-chat .message-content {
        padding: 0.65rem 0.9rem !important;
        font-size: 0.95rem !important;
        line-height: 1.48;
        border-radius: 11px;
    }

    .chat-panel .message-files,
    .bank-work-panel-chat .message-files {
        margin-top: 0.5rem;
        padding-top: 0.5rem;
    }

    .chat-panel .file-item,
    .bank-work-panel-chat .file-item {
        padding: 0.55rem 0.65rem;
        margin-bottom: 0.35rem;
        border-radius: 8px;
    }

    .chat-panel .file-icon,
    .bank-work-panel-chat .file-icon {
        font-size: 1.45rem;
        margin-right: 0.55rem;
        width: 34px;
    }

    .chat-panel .file-name,
    .bank-work-panel-chat .file-name {
        font-size: 0.84rem;
        margin-bottom: 0.12rem;
    }

    .chat-panel .file-size,
    .bank-work-panel-chat .file-size {
        font-size: 0.74rem;
    }

    .chat-panel .message-system,
    .bank-work-panel-chat .message-system {
        margin: 1rem 0;
    }

    .chat-panel .message-system .message-content,
    .bank-work-panel-chat .message-system .message-content {
        padding: 0.55rem 1.05rem !important;
        font-size: 0.9rem !important;
        border-radius: 14px;
    }

    .chat-panel .chat-textarea,
    .bank-work-panel-chat .chat-textarea {
        min-height: 54px;
        max-height: 108px;
        font-size: 0.95rem !important;
    }

    .chat-panel .selected-file,
    .bank-work-panel-chat .selected-file {
        font-size: 0.82rem;
        padding: 0.32rem 0.6rem;
    }
}

/* Старые браузеры без container queries — мягче и только на относительно узком окне */
@supports not (container-type: inline-size) {
    @media (max-width: 1199.98px) {
        #chatMessages,
        #bankWorkMessages {
            padding: 0.95rem 1rem !important;
        }
        .chat-panel .chat-input,
        .bank-work-panel-chat .chat-input {
            padding: 0.65rem 1rem !important;
        }
        .message-header {
            margin-bottom: 0.38rem;
            gap: 6px;
        }
        .message-sender {
            font-size: 0.82rem !important;
        }
        .message-time {
            font-size: 0.68rem !important;
        }
        .message-sender .badge {
            font-size: 0.6rem !important;
            padding: 0.1rem 0.28rem !important;
        }
        .message-content {
            padding: 0.65rem 0.9rem !important;
            font-size: 0.95rem !important;
        }
        .chat-textarea {
            font-size: 0.95rem !important;
            min-height: 54px;
        }
        .file-icon {
            font-size: 1.45rem;
            width: 34px;
        }
        .file-name {
            font-size: 0.84rem;
        }
    }
}

/* Вкладка «Работа с банком»: фиксированная высота чата как у «Чат по продукту» (не тянется при раскрытии истории) */
.bank-work-panel-chat .bank-work-chat-container {
    min-height: 550px;
    max-height: 700px;
    height: 70vh;
    display: flex;
    flex-direction: column;
}

.bank-manager-comment-display {
    white-space: pre-wrap;
    word-break: break-word;
    line-height: 1.55;
    padding: 0.35rem 0 0;
}

.badge.bg-purple { background-color: #6f42c1; }
.badge.bg-orange { background-color: #fd7e14; }
.status-badge-failed {
    background: linear-gradient(45deg, #dc3545, #c82333);
    color: white;
    border: none;
}

.badge.bg-danger {
    background: linear-gradient(45deg, #dc3545, #c82333) !important;
}

/* Стили для ссылки удаления продукта */
.delete-product-link {
    font-size: 0.85rem;
    color: #dc3545 !important;
    text-decoration: underline !important;
    cursor: pointer;
    transition: all 0.2s;
}

.delete-product-link:hover {
    color: #c82333 !important;
    text-decoration: none !important;
}

.delete-product-link:active {
    color: #bd2130 !important;
}

.chat-fab {
    position: fixed;
    right: 16px;
    bottom: 16px;
    z-index: 1055;
    width: auto;
    height: 48px;
    padding: 0 14px;
    border-radius: 999px;
    border: none;
    background: #0d6efd;
    color: #fff;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    box-shadow: 0 10px 20px rgba(13, 110, 253, 0.25);
    font-size: 0.85rem;
    font-weight: 600;
}

.chat-fab-badge {
    position: absolute;
    top: -4px;
    right: -4px;
    background: #dc3545;
    color: #fff;
    border-radius: 999px;
    padding: 0 6px;
    font-size: 0.7rem;
    line-height: 1.4;
}

.chat-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.45);
    z-index: 1050;
}

@media (max-width: 768px) {
    .chat-panel {
        display: none;
    }

    body.chat-open {
        overflow: hidden;
    }

    body.chat-open .chat-overlay {
        display: block;
    }

    body.chat-open .chat-panel {
        display: flex;
        flex-direction: column;
        position: fixed;
        top: 32px;
        right: 0;
        bottom: 0;
        left: 0;
        height: calc(100dvh - 32px);
        width: 100%;
        z-index: 1060;
        border-radius: 0;
        box-shadow: 0 -10px 30px rgba(15, 23, 42, 0.2);
        margin: 0;
        overflow: hidden;
        background: #fff;
    }

    .chat-panel {
        margin-bottom: 0;
    }

    .chat-panel .card-header {
        border-radius: 0;
    }

    .chat-panel .card-body {
        padding: 0;
        display: flex;
        flex-direction: column;
        height: 100%;
    }

    .chat-panel .chat-container {
        height: 100% !important;
        min-height: 0 !important;
        max-height: none !important;
    }

    .chat-panel .chat-messages {
        padding: 1rem !important;
        flex: 1 1 auto;
    }

    .chat-panel .chat-input {
        padding: 0.75rem 1rem !important;
        margin: 0;
        flex-shrink: 0;
    }

}
/* Mobile typography parity with application_details.php */
@media (max-width: 768px) {
    body {
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
        font-size: 14px;
        line-height: 1.45;
        color: #0f172a;
    }

    .page-header {
        padding: 0.85rem 1rem;
        border-radius: 12px;
    }

    .page-header h1 {
        font-size: 1.15rem;
        font-weight: 700;
        line-height: 1.3;
    }

    .page-header .row {
        row-gap: 0.5rem;
    }

    .page-header .d-flex.align-items-center {
        gap: 0.35rem !important;
        flex-wrap: wrap;
    }

    .page-header .application-meta-item,
    .page-header small {
        font-size: 0.82rem;
    }

    .page-header .btn {
        width: 100%;
    }

    .page-header .col-auto {
        width: 100%;
    }

    .product-name-badge {
        padding: 0.5rem 1rem;
        font-size: 0.9rem;
    }

    .card-title {
        font-size: 1.05rem !important;
    }

    .nav-pills .nav-link {
        font-size: 0.82rem;
        padding: 0.5rem 0.8rem;
        line-height: 1.2;
    }

    .nav-pills .nav-link .bi {
        display: none;
    }

    .tab-pane {
        padding: 1rem;
    }

    /* Шире блок "О продукте" на телефонах */
    #details {
        padding-left: 0.5rem;
        padding-right: 0.5rem;
    }

    #details .row {
        margin-left: 0;
        margin-right: 0;
    }

    #details .col-lg-8,
    #details .col-lg-4 {
        padding-left: 0;
        padding-right: 0;
    }
}
</style>

<script>
// Прокрутка чата вниз при загрузке
document.addEventListener('DOMContentLoaded', function() {
    const chatMessages = document.getElementById('chatMessages');
    if (chatMessages) {
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }
    initFileUpload();
    const textarea = document.querySelector('#chatForm .chat-textarea');
    if (textarea) {
        textarea.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 120) + 'px';
        });
    }
});

document.addEventListener('DOMContentLoaded', function() {
    const fab = document.getElementById('chatFab');
    const overlay = document.getElementById('chatOverlay');
    const closeBtn = document.getElementById('chatClose');
    const chatMessages = document.getElementById('chatMessages');

    function openChat() {
        document.body.classList.add('chat-open');
        if (chatMessages) {
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }
    }

    function closeChat() {
        document.body.classList.remove('chat-open');
    }

    if (fab) fab.addEventListener('click', openChat);
    if (overlay) overlay.addEventListener('click', closeChat);
    if (closeBtn) closeBtn.addEventListener('click', closeChat);
});



// Функция для работы с загрузкой файлов
function initFileUpload() {
    const fileInput = document.getElementById('chatFiles');
    const selectedFilesContainer = document.getElementById('selectedFiles');
    if (!fileInput || !selectedFilesContainer) {
        return;
    }
    fileInput.addEventListener('change', function(e) {
        selectedFilesContainer.innerHTML = '';
        
        if (this.files.length > 0) {
            Array.from(this.files).forEach((file, index) => {
                const fileElement = document.createElement('div');
                fileElement.className = 'selected-file';
                fileElement.innerHTML = `
                    <i class="bi bi-file-earmark"></i>
                    ${file.name}
                    <span class="remove-file" onclick="removeFile(${index})">
                        <i class="bi bi-x"></i>
                    </span>
                `;
                selectedFilesContainer.appendChild(fileElement);
            });
        }
    });
}

// Функция удаления файла из выбранных
function removeFile(index) {
    const fileInput = document.getElementById('chatFiles');
    if (!fileInput) {
        return;
    }
    const dt = new DataTransfer();
    const files = Array.from(fileInput.files);
    
    files.forEach((file, i) => {
        if (i !== index) {
            dt.items.add(file);
        }
    });
    
    fileInput.files = dt.files;
    
    // Обновляем отображение
    const event = new Event('change');
    fileInput.dispatchEvent(event);
}

// Прокрутка чата (дублирующий блок убран — см. первый DOMContentLoaded выше)

function showNotification(message, type = 'success') {
    const alertClass = type === 'success' ? 'alert-success' : 'alert-danger';
    const notification = document.createElement('div');
    notification.className = `alert ${alertClass} alert-dismissible fade show position-fixed`;
    notification.style.cssText = 'bottom: 20px; right: 20px; z-index: 9999; min-width: 300px;';
    
    notification.innerHTML = `
        <div class="d-flex align-items-center">
            <i class="bi ${type === 'success' ? 'bi-check-circle' : 'bi-exclamation-circle'} me-2"></i>
            ${message}
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
        </div>
    `;
    
    document.body.appendChild(notification);
    
    setTimeout(() => {
        if (notification.parentNode) {
            notification.remove();
        }
    }, 3000);
}

// Добавляем глобальную переменную для блокировки двойной отправки
let isSubmitting = false;

// Обновленный обработчик
const chatFormEl = document.getElementById('chatForm');
if (chatFormEl) {
chatFormEl.addEventListener('submit', function(e) {
    e.preventDefault();
    
    // Проверяем, не отправляется ли уже форма
    if (isSubmitting) {
        return;
    }
    
    const messageInput = this.querySelector('textarea[name="message"]');
    const fileInput = this.querySelector('input[name="chat_files[]"]');
    if (!messageInput || !fileInput) {
        return;
    }
    if (messageInput.value.trim() === '' && fileInput.files.length === 0) {
        showNotification('Введите сообщение или прикрепите файл', 'danger');
        return;
    }
    
    // Блокируем повторную отправку
    isSubmitting = true;
    
    const formData = new FormData(this);
    const submitBtn = this.querySelector('button[type="submit"]');
    if (!submitBtn) {
        isSubmitting = false;
        return;
    }
    const originalText = submitBtn.innerHTML;
    
    submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i> Отправка...';
    submitBtn.disabled = true;
    
    // Отправляем запрос
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (response.ok) {
            showNotification('Сообщение отправлено!', 'success');
            this.reset();
            if (document.getElementById('selectedFiles')) {
                document.getElementById('selectedFiles').innerHTML = '';
            }
            setTimeout(() => {
                // Перезагружаем страницу, явно указывая вкладку документов
const url = new URL(window.location);
// ВАЖНО: Убедитесь, что 'documents' — это точный ID вашей вкладки с документами
url.searchParams.set('tab', 'details'); 
window.location.href = url.toString();

            }, 500);
        } else {
            throw new Error('Ошибка сервера: ' + response.status);
        }
    })
    .catch(error => {
        showNotification('Ошибка отправки: ' + error.message, 'danger');
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
        isSubmitting = false; // Разблокируем форму
    })
    .finally(() => {
        // На всякий случай, через 5 секунд разблокируем если что-то пошло не так
        setTimeout(() => {
            isSubmitting = false;
        }, 5000);
    });
});
}

// Функция для удаления продукта из заявки
function deleteProduct(productAppId, productName) {
    if (confirm('Вы точно хотите удалить продукт "' + productName + '" из заявки?\n\nЭто действие нельзя отменить!')) {
        // Показываем индикатор загрузки
        const deleteLink = event.target.closest('.delete-product-link');
        const originalHtml = deleteLink.innerHTML;
        deleteLink.innerHTML = '<i class="bi bi-hourglass-split me-1"></i> Удаление...';
        deleteLink.style.pointerEvents = 'none';
        deleteLink.style.opacity = '0.7';
        
        // Отправляем запрос на удаление
        const formData = new URLSearchParams();
        formData.append('product_app_id', productAppId);
        
        fetch('api_delete_product.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification('Продукт успешно удален!', 'success');
                setTimeout(() => {
                    // Перенаправляем на страницу заявки
                    window.location.href = 'application_details.php?id=' + data.application_id;
                }, 1000);
            } else {
                showNotification('Ошибка: ' + data.error, 'danger');
                deleteLink.innerHTML = originalHtml;
                deleteLink.style.pointerEvents = 'auto';
                deleteLink.style.opacity = '1';
            }
        })
        .catch(error => {
            showNotification('Ошибка: ' + error.message, 'danger');
            deleteLink.innerHTML = originalHtml;
            deleteLink.style.pointerEvents = 'auto';
            deleteLink.style.opacity = '1';
        });
    }
    
    // Предотвращаем переход по ссылке
    event.preventDefault();
    return false;
}
</script>
<!-- Модальное окно создания (только менеджер) -->
<div class="modal fade" id="createDocumentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Новый запрос</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="createDocumentForm">
                    <input type="hidden" name="product_app_id" value="<?php echo $productAppId; ?>">
                    <div class="mb-3">
                        <label>Название</label>
                        <input type="text" class="form-control" name="title" required>
                    </div>
                    <div class="mb-3">
                        <label>Описание</label>
                        <textarea class="form-control" name="description"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" id="createDocumentBtn">Создать</button>
            </div>
        </div>
    </div>
</div>

<!-- Модальное окно загрузки -->
<div class="modal fade" id="uploadFilesModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Загрузка файлов</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="uploadFilesForm">
                    <input type="hidden" name="document_id" id="upload_document_id">
                    <div class="mb-3">
                        <label>Файлы</label>
                        <input type="file" class="form-control" name="document_files[]" multiple required>
                    </div>
                    <?php if (!finbuild_is_manager($userRole)): ?>
                        <div class="mb-3">
                            <label>Комментарий</label>
                            <textarea class="form-control" name="client_comment"></textarea>
                        </div>
                    <?php endif; ?>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" id="uploadFilesBtn">Загрузить</button>
            </div>
        </div>
    </div>
</div>
<script>
// --- 1. ГЛОБАЛЬНАЯ ФУНКЦИЯ УВЕДОМЛЕНИЙ (КАК В ЗАЯВКАХ) ---
function showNotification(message, type = 'success') {
    const alertClass = type === 'success' ? 'alert-success' : 'alert-danger';
    const iconClass = type === 'success' ? 'bi-check-circle' : 'bi-exclamation-circle';
    
    const notification = document.createElement('div');
    notification.className = `alert ${alertClass} alert-dismissible fade show position-fixed`;
    notification.style.cssText = `
        bottom: 20px;
        right: 20px;
        z-index: 9999;
        min-width: 300px;
        max-width: 400px;
        border-radius: 8px;
        border: none;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        animation: slideInRight 0.3s ease-out;
    `;
    
    notification.innerHTML = `
        <div class="d-flex align-items-center">
            <i class="bi ${iconClass} me-2 fs-5"></i>
            <div class="flex-grow-1">
                ${message}
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Закрыть"></button>
        </div>
    `;
    
    document.body.appendChild(notification);
    
    // Автоматически скрыть через 3 секунды
    setTimeout(() => {
        if (notification.parentNode) {
            notification.style.animation = 'slideOutRight 0.3s ease-in';
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.remove();
                }
            }, 300);
        }
    }, 3000);
}

// Добавить CSS анимации
if (!document.querySelector('#notification-styles')) {
    const style = document.createElement('style');
    style.id = 'notification-styles';
    style.textContent = `
        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        @keyframes slideOutRight {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(100%); opacity: 0; }
        }
        .alert-success {
            background: linear-gradient(135deg, #d4edda, #c3e6cb);
            border: 1px solid #b1dfbb;
            color: #155724;
        }
        .alert-danger {
            background: linear-gradient(135deg, #f8d7da, #f5c6cb);
            border: 1px solid #f1b0b7;
            color: #721c24;
        }
    `;
    document.head.appendChild(style);
}

// --- ОСНОВНОЙ ОБРАБОТЧИК ---
document.addEventListener('DOMContentLoaded', function() {
    
    // ==========================================
    // 1. ЛОГИКА ТАБОВ (URL)
    // ==========================================
    
    // А. При загрузке страницы: открываем вкладку из URL
 var params = new URLSearchParams(window.location.search);
    var activeTab = params.get('tab');
    if (activeTab) {
        var tabTrigger = document.querySelector('[data-bs-target="#' + activeTab + '"], [href="#' + activeTab + '"]');
        if (tabTrigger && typeof bootstrap !== 'undefined' && bootstrap.Tab) {
            try {
                var tab = new bootstrap.Tab(tabTrigger);
                tab.show();
            } catch (e) { console.warn('Tab init', e); }
        }
    }

    // Б. При клике на вкладку меняем URL без перезагрузки
    var selector = 'button[data-bs-toggle="tab"], a[data-bs-toggle="tab"], button[data-bs-toggle="pill"], a[data-bs-toggle="pill"]';
    var triggerTabList = [].slice.call(document.querySelectorAll(selector));
    
    triggerTabList.forEach(function (triggerEl) {
        triggerEl.addEventListener('shown.bs.tab', function (event) {
            var el = event.currentTarget || event.target;
            var target = el.getAttribute('data-bs-target') || el.getAttribute('href');
            if (target) {
                var tabName = target.replace('#', '');
                var url = new URL(window.location);
                url.searchParams.set('tab', tabName);
                window.history.replaceState({}, '', url);
                if (tabName !== 'details' && document.body.classList.contains('chat-open')) {
                    document.body.classList.remove('chat-open');
                }
            }
        });
    });
    // ==========================================
    // 2. ПРОВЕРКА ОТЛОЖЕННЫХ УВЕДОМЛЕНИЙ
    // ==========================================
    const msg = localStorage.getItem('toastMessage');
    const type = localStorage.getItem('toastType');
    if (msg) {
        showNotification(msg, type);
        localStorage.removeItem('toastMessage');
        localStorage.removeItem('toastType');
    }

    // ==========================================
    // 3. ОБРАБОТЧИКИ ФОРМ И ФАЙЛОВ
    // ==========================================

    // Создание запроса
    const createDocBtn = document.getElementById('createDocumentBtn');
    if (createDocBtn) {
        createDocBtn.addEventListener('click', function() {
            const form = document.getElementById('createDocumentForm');
            const btn = this; btn.disabled = true;

            fetch('api_create_document_request.php', { method: 'POST', body: new FormData(form) })
            .then(r => r.json())
            .then(data => {
                if(data.success) {
                    localStorage.setItem('toastMessage', 'Запрос успешно создан');
                    localStorage.setItem('toastType', 'success');
                    // Перезагрузка с сохранением вкладки
                    const url = new URL(window.location);
                    url.searchParams.set('tab', 'documents');
                    window.location.href = url.toString();
                } else {
                    showNotification(data.error || 'Ошибка', 'error');
                    btn.disabled = false;
                }
            })
            .catch(() => { showNotification('Ошибка сети', 'error'); btn.disabled = false; });
        });
    }

    // Загрузка файлов (открытие модалки)
    document.querySelectorAll('.upload-files-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('upload_document_id').value = this.dataset.documentId;
            new bootstrap.Modal(document.getElementById('uploadFilesModal')).show();
        });
    });

    // Отправка файлов на сервер
    const uploadBtn = document.getElementById('uploadFilesBtn');
    if (uploadBtn) {
        uploadBtn.addEventListener('click', function() {
            const btn = this; 
            btn.disabled = true; 
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Загрузка...';

            fetch('api_upload_product_document.php', { method: 'POST', body: new FormData(document.getElementById('uploadFilesForm')) })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    localStorage.setItem('toastMessage', 'Файлы успешно загружены');
                    localStorage.setItem('toastType', 'success');
                    // Перезагрузка с сохранением вкладки
                    const url = new URL(window.location);
                    url.searchParams.set('tab', 'documents');
                    window.location.href = url.toString();
                } else {
                    showNotification(data.error || 'Ошибка', 'error');
                    btn.disabled = false;
                    btn.textContent = 'Загрузить';
                }
            })
            .catch(() => { showNotification('Ошибка сети', 'error'); btn.disabled = false; btn.textContent = 'Загрузить'; });
        });
    }

    // Удаление запроса
    document.querySelectorAll('.delete-document-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            if(!confirm('Удалить запрос?')) return;

            const fd = new FormData(); fd.append('document_id', this.dataset.documentId);
            fetch('api_delete_product_document.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if(data.success) {
                    localStorage.setItem('toastMessage', 'Запрос удален');
                    localStorage.setItem('toastType', 'success');
                    // Перезагрузка с сохранением вкладки
                    const url = new URL(window.location);
                    url.searchParams.set('tab', 'documents');
                    window.location.href = url.toString();
                } else {
                    showNotification(data.error, 'error');
                }
            });
        });
    });

    // Удаление файла
    document.querySelectorAll('.delete-file-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault(); e.stopPropagation();
            if(!confirm('Удалить файл?')) return;

            const fd = new FormData(); fd.append('file_id', this.dataset.fileId);
            fetch('api_delete_product_document.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if(data.success) {
                    localStorage.setItem('toastMessage', 'Файл удален');
                    localStorage.setItem('toastType', 'success');
                    // Перезагрузка с сохранением вкладки
                    const url = new URL(window.location);
                    url.searchParams.set('tab', 'documents');
                    window.location.href = url.toString();
                } else {
                    showNotification(data.error, 'error');
                }
            });
        });
    });
});
</script>

<?php if ($showBankWorkTab): ?>
<script>
(function () {
    const root = document.getElementById('bankWorkRoot');
    if (!root) return;

    const applicationProductId = root.getAttribute('data-application-product-id');
    const FINBANK_DRAFT = <?= json_encode(FINBANK_STATUS_DRAFT, JSON_UNESCAPED_UNICODE) ?>;
    const bankChatUserId = <?= (int) $userId ?>;
    const finbuildManagerRoleLabels = <?= json_encode([
        'director' => 'Руководитель',
        'manager' => 'Менеджер',
        'case_manager' => 'Менеджер по заявкам',
    ], JSON_UNESCAPED_UNICODE) ?>;
    const bankWorkHistoryLabels = <?= json_encode([
        FINBANK_STATUS_DRAFT => finbank_case_status_label_history(FINBANK_STATUS_DRAFT),
        FINBANK_STATUS_SENT => finbank_case_status_label_history(FINBANK_STATUS_SENT),
        FINBANK_STATUS_IN_PROGRESS => finbank_case_status_label_history(FINBANK_STATUS_IN_PROGRESS),
        FINBANK_STATUS_REQUEST => finbank_case_status_label_history(FINBANK_STATUS_REQUEST),
        FINBANK_STATUS_APPROVED => finbank_case_status_label_history(FINBANK_STATUS_APPROVED),
        FINBANK_STATUS_BG_ISSUED => finbank_case_status_label_history(FINBANK_STATUS_BG_ISSUED),
        FINBANK_STATUS_REJECTED => finbank_case_status_label_history(FINBANK_STATUS_REJECTED),
    ], JSON_UNESCAPED_UNICODE) ?>;

    function esc(s) {
        const d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function bankWorkFormatFileSize(bytes) {
        const n = Number(bytes);
        if (!n || n <= 0) return '';
        const k = 1024;
        const sizes = ['Б', 'КБ', 'МБ', 'ГБ'];
        const i = Math.floor(Math.log(n) / Math.log(k));
        return (Math.round((n / Math.pow(k, i)) * 100) / 100) + ' ' + sizes[i];
    }

    function bankWorkFileIconClass(fileType) {
        const m = {
            pdf: 'bi-file-earmark-pdf text-danger',
            word: 'bi-file-earmark-word text-primary',
            excel: 'bi-file-earmark-spreadsheet text-success',
            image: 'bi-file-earmark-image text-warning',
            archive: 'bi-file-earmark-zip text-secondary',
            text: 'bi-file-earmark-text text-info',
            file: 'bi-file-earmark text-secondary'
        };
        return m[fileType] || m.file;
    }

    function bankWorkRefreshChatFiles() {
        const inp = document.getElementById('bankWorkChatFiles');
        const wrap = document.getElementById('bankWorkChatSelectedFiles');
        if (!inp || !wrap) return;
        wrap.innerHTML = '';
        if (!inp.files || !inp.files.length) return;
        Array.from(inp.files).forEach(function (file, index) {
            const row = document.createElement('div');
            row.className = 'selected-file';
            row.innerHTML = '<i class="bi bi-file-earmark"></i> ' + esc(file.name)
                + ' <span class="remove-file" role="button" tabindex="0" title="Убрать"><i class="bi bi-x"></i></span>';
            const remove = row.querySelector('.remove-file');
            const doRemove = function () {
                const dt = new DataTransfer();
                Array.from(inp.files).forEach(function (f, i) {
                    if (i !== index) dt.items.add(f);
                });
                inp.files = dt.files;
                bankWorkRefreshChatFiles();
            };
            remove.addEventListener('click', doRemove);
            remove.addEventListener('keydown', function (ev) {
                if (ev.key === 'Enter' || ev.key === ' ') {
                    ev.preventDefault();
                    doRemove();
                }
            });
            wrap.appendChild(row);
        });
    }

    function bankWorkLogLabel(code) {
        if (code == null || code === '') {
            return '—';
        }
        return bankWorkHistoryLabels[code] || code;
    }

    function bankWorkFormatMsgTime(iso) {
        if (!iso) {
            return '';
        }
        const d = new Date(iso);
        if (isNaN(d.getTime())) {
            return String(iso);
        }
        return d.toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    }

    function bankWorkRenderStatusLog(rows, draft) {
        const el = document.getElementById('bankWorkStatusLog');
        if (!el) {
            return;
        }
        rows = rows || [];
        if (!rows.length) {
            el.innerHTML = '<span class="text-muted">' + (draft
                ? 'История появится после отправки пакета в банк.'
                : 'Пока нет записей') + '</span>';
            return;
        }
        let html = '<ul class="list-unstyled mb-0">';
        rows.slice().reverse().forEach(function (r) {
            const who = ((r.first_name || '') + ' ' + (r.last_name || '')).trim();
            html += '<li class="mb-3 pb-3 border-bottom border-light">';
            html += '<div class="text-muted">' + esc(r.created_at || '') + (who ? ' · ' + esc(who) : '') + '</div>';
            html += '<div>' + esc(bankWorkLogLabel(r.old_status)) + ' → <strong>' + esc(bankWorkLogLabel(r.new_status)) + '</strong></div>';
            if (r.comment) {
                html += '<div class="mt-1">' + esc(r.comment).replace(/\n/g, '<br>') + '</div>';
            }
            const lf = r.files && r.files.length ? r.files : [];
            if (lf.length) {
                html += '<div class="mt-1">';
                lf.forEach(function (f) {
                    html += '<a class="btn btn-sm btn-outline-secondary me-1 mb-1" href="' + esc(f.file_url || f.file_path || '#') + '" target="_blank" rel="noopener"><i class="bi bi-paperclip me-1"></i>' + esc(f.original_name || 'Файл') + '</a>';
                });
                html += '</div>';
            }
            html += '</li>';
        });
        html += '</ul>';
        el.innerHTML = html;
    }

    function isDraft(st) {
        return !st || st === FINBANK_DRAFT;
    }

    async function bankReload() {
        const loading = document.getElementById('bankWorkLoading');
        const body = document.getElementById('bankWorkBody');
        const err = document.getElementById('bankWorkError');
        if (err) {
            err.classList.add('d-none');
            err.textContent = '';
        }
        if (loading) loading.classList.remove('d-none');
        if (body) body.classList.add('d-none');
        try {
            const r = await fetch('api_bank_case.php?action=manager_get&application_product_id=' + encodeURIComponent(applicationProductId));
            const data = await r.json();
            if (!data.success) {
                throw new Error(data.error || 'Ошибка загрузки');
            }
            if (loading) loading.classList.add('d-none');
            if (body) body.classList.remove('d-none');
            bankRender(data);
        } catch (e) {
            if (loading) loading.classList.add('d-none');
            if (err) {
                err.textContent = e.message || String(e);
                err.classList.remove('d-none');
            }
        }
    }

    function bankRender(data) {
        const c = data.case || {};
        const st = c.status || '';
        const draft = isDraft(st);

        const pkgHelp = document.getElementById('bankWorkPackageHelp');
        if (pkgHelp) {
            pkgHelp.classList.toggle('d-none', !draft);
        }

        const badge = document.getElementById('bankWorkStatusBadge');
        if (badge) {
            badge.textContent = data.status_label_manager || '';
            badge.className = 'badge fs-6 ' + (data.status_badge_class || 'bg-secondary');
        }

        const bankNote = document.getElementById('bankWorkBankLastAction');
        if (bankNote) {
            const logRows = data.status_log || [];
            let picked = null;
            for (let i = logRows.length - 1; i >= 0; i--) {
                const r = logRows[i];
                if (String(r.role || '') !== 'bank') {
                    continue;
                }
                const hasC = (r.comment || '').trim() !== '';
                const hasF = r.files && r.files.length > 0;
                if (hasC || hasF) {
                    picked = r;
                    break;
                }
            }
            if (!picked) {
                bankNote.classList.add('d-none');
                bankNote.innerHTML = '';
            } else {
                bankNote.classList.remove('d-none');
                const org = ((picked.changer_company_name || '').trim()) || 'Банк';
                const who = ((picked.first_name || '') + ' ' + (picked.last_name || '')).trim();
                let h = '<div class="fw-semibold mb-1"><i class="bi bi-bank me-1 text-primary"></i>' + esc(org);
                if (who) {
                    h += ' <span class="text-muted fw-normal">(' + esc(who) + ')</span>';
                }
                h += '</div>';
                h += '<div class="text-muted mb-1">' + esc(bankWorkFormatMsgTime(picked.created_at) || String(picked.created_at || '')) + '</div>';
                h += '<div class="mb-1">' + esc(bankWorkLogLabel(picked.old_status)) + ' → <strong>' + esc(bankWorkLogLabel(picked.new_status)) + '</strong></div>';
                if ((picked.comment || '').trim() !== '') {
                    h += '<div class="mt-2">' + esc(picked.comment || '').replace(/\n/g, '<br>') + '</div>';
                }
                if (picked.files && picked.files.length) {
                    h += '<div class="mt-2 d-flex flex-wrap gap-1">';
                    picked.files.forEach(function (f) {
                        h += '<a class="btn btn-sm btn-outline-secondary" href="' + esc(f.file_url || f.file_path || '#') + '" target="_blank" rel="noopener"><i class="bi bi-paperclip me-1"></i>' + esc(f.original_name || 'Файл') + '</a>';
                    });
                    h += '</div>';
                }
                bankNote.innerHTML = h;
            }
        }

        bankWorkRenderStatusLog(data.status_log || [], draft);

        const pkg = data.package || { items: [], uploads: [] };
        const listEl = document.getElementById('bankWorkPackageList');
        if (listEl) {
            let html = '';
            (pkg.items || []).forEach(function (it) {
                const excluded = Number(it.excluded) === 1;
                html += '<div class="list-group-item d-flex flex-wrap align-items-start gap-2 px-0' + (excluded ? ' opacity-50' : '') + '">';
                html += '<div class="flex-grow-1 min-w-0">';
                html += '<div class="fw-semibold">' + esc(it.title) + ' <span class="badge bg-light text-secondary border">' + (it.type === 'application_document' ? 'Заявка' : 'Продукт') + '</span></div>';
                if (it.description) {
                    html += '<div class="text-muted small">' + esc(it.description).replace(/\n/g, '<br>') + '</div>';
                }
                if (it.type === 'application_document' && (it.file_url || it.file_path)) {
                    html += '<a class="btn btn-sm btn-outline-secondary mt-1 me-1" href="' + esc(it.file_url || it.file_path) + '" target="_blank" rel="noopener">' + esc(it.original_name || 'Файл') + '</a>';
                }
                if (it.type === 'product_document' && it.files && it.files.length) {
                    it.files.forEach(function (f) {
                        html += '<a class="btn btn-sm btn-outline-secondary mt-1 me-1" href="' + esc(f.file_url || f.file_path) + '" target="_blank" rel="noopener">' + esc(f.original_name || 'Файл') + '</a>';
                    });
                }
                html += '</div>';
                if (draft) {
                    html += '<button type="button" class="btn btn-sm btn-outline-danger border-0 bank-pack-remove" data-item-id="' + esc(String(it.item_id)) + '" data-excluded="' + (excluded ? '0' : '1') + '" title="' + (excluded ? 'Вернуть в пакет' : 'Убрать из пакета') + '">';
                    html += excluded ? '<i class="bi bi-arrow-counterclockwise"></i>' : '<i class="bi bi-x-lg"></i>';
                    html += '</button>';
                }
                html += '</div>';
            });
            (pkg.uploads || []).forEach(function (u) {
                html += '<div class="list-group-item d-flex flex-wrap align-items-start gap-2 px-0">';
                html += '<div class="flex-grow-1 min-w-0"><span class="badge bg-secondary me-1">Доп.</span> <span class="fw-semibold">' + esc(u.title) + '</span>';
                if (u.description) {
                    html += '<div class="text-muted small">' + esc(u.description).replace(/\n/g, '<br>') + '</div>';
                }
                html += '<div class="mt-1"><a class="btn btn-sm btn-outline-secondary" href="' + esc(u.file_url || u.file_path) + '" target="_blank" rel="noopener">' + esc(u.original_name || '') + '</a></div></div>';
                if (draft) {
                    html += '<button type="button" class="btn btn-sm btn-outline-danger border-0 bank-upload-remove" data-upload-id="' + esc(String(u.id)) + '"><i class="bi bi-x-lg"></i></button>';
                }
                html += '</div>';
            });
            listEl.innerHTML = html || '<p class="text-muted small mb-0">Нет позиций. Добавьте документы на вкладке «Документы» или загрузите файл ниже.</p>';

            listEl.querySelectorAll('.bank-pack-remove').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const itemId = this.getAttribute('data-item-id');
                    const excluded = this.getAttribute('data-excluded');
                    const fd = new FormData();
                    fd.append('action', 'manager_toggle_item');
                    fd.append('application_product_id', applicationProductId);
                    fd.append('item_id', itemId);
                    fd.append('excluded', excluded);
                    fetch('api_bank_case.php', { method: 'POST', body: fd })
                        .then(function (r) { return r.json(); })
                        .then(function (d) {
                            if (d.success) {
                                bankReload();
                            } else {
                                showNotification(d.error || 'Ошибка', 'error');
                            }
                        })
                        .catch(function () { showNotification('Ошибка сети', 'error'); });
                });
            });
            listEl.querySelectorAll('.bank-upload-remove').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    if (!confirm('Удалить файл из пакета?')) return;
                    const fd = new FormData();
                    fd.append('action', 'manager_delete_upload');
                    fd.append('application_product_id', applicationProductId);
                    fd.append('upload_id', this.getAttribute('data-upload-id'));
                    fetch('api_bank_case.php', { method: 'POST', body: fd })
                        .then(function (r) { return r.json(); })
                        .then(function (d) {
                            if (d.success) {
                                bankReload();
                            } else {
                                showNotification(d.error || 'Ошибка', 'error');
                            }
                        })
                        .catch(function () { showNotification('Ошибка сети', 'error'); });
                });
            });
        }

        const draftBox = document.getElementById('bankWorkDraftUpload');
        if (draftBox) {
            draftBox.classList.toggle('d-none', !draft);
        }
        const commentEl = document.getElementById('bankManagerComment');
        const commentEdit = document.getElementById('bankManagerCommentEdit');
        const commentRead = document.getElementById('bankManagerCommentRead');
        const commentBlock = document.getElementById('bankManagerCommentBlock');
        const commentRaw = (c.manager_comment != null) ? String(c.manager_comment) : '';
        const t = commentRaw.trim();
        if (commentEl && commentEdit && commentRead && commentBlock) {
            if (draft) {
                commentBlock.classList.remove('d-none');
                commentEdit.classList.remove('d-none');
                commentRead.classList.add('d-none');
                commentRead.innerHTML = '';
                commentEl.value = commentRaw !== '' ? commentRaw : '';
            } else {
                if (!t) {
                    commentBlock.classList.add('d-none');
                } else {
                    commentBlock.classList.remove('d-none');
                    commentEdit.classList.add('d-none');
                    commentRead.classList.remove('d-none');
                    commentEl.value = commentRaw;
                    commentRead.innerHTML = esc(t).replace(/\n/g, '<br>');
                }
            }
        }
        const submitBtn = document.getElementById('bankSubmitBtn');
        if (submitBtn) {
            submitBtn.classList.toggle('d-none', !draft);
        }

        const chatCard = document.getElementById('bankWorkChatCard');
        const chatPh = document.getElementById('bankWorkChatPlaceholder');
        const msgBox = document.getElementById('bankWorkMessages');
        if (draft) {
            if (chatCard) chatCard.classList.add('d-none');
            if (chatPh) chatPh.classList.remove('d-none');
        } else {
            if (chatPh) chatPh.classList.add('d-none');
            if (chatCard) chatCard.classList.remove('d-none');
            if (msgBox) {
                const messages = data.messages || [];
                let mh = '';
                if (!messages.length) {
                    mh = '<div class="message-system"><div class="message-content"><i class="bi bi-chat-dots me-2"></i>Чат с банком начат. Напишите первое сообщение.</div></div>';
                } else {
                    messages.forEach(function (m) {
                        const uid = parseInt(String(m.user_id || '0'), 10);
                        const own = uid === bankChatUserId;
                        const who = ((m.first_name || '') + ' ' + (m.last_name || '')).trim();
                        const bankBadgeText = ((m.user_company_name || '').trim()) || 'Банк';
                        const managerLabel = finbuildManagerRoleLabels[m.role] || 'Менеджер';
                        const roleBadge = m.role === 'bank'
                            ? '<span class="badge bg-secondary ms-1">' + esc(bankBadgeText) + '</span>'
                            : '<span class="badge bg-primary text-white ms-1">' + esc(managerLabel) + '</span>';
                        const files = m.files && m.files.length ? m.files : [];
                        const text = (m.message || '').trim();
                        mh += '<div class="message' + (own ? ' own' : '') + '">';
                        mh += '<div class="message-header">';
                        mh += '<span class="message-sender">' + esc(who || 'Пользователь') + roleBadge + '</span>';
                        mh += '<span class="message-time">' + esc(bankWorkFormatMsgTime(m.created_at)) + '</span></div>';
                        if (text !== '') {
                            mh += '<div class="message-content">' + esc(m.message || '').replace(/\n/g, '<br>') + '</div>';
                        }
                        if (files.length) {
                            mh += '<div class="message-files">';
                            files.forEach(function (f) {
                                const ic = bankWorkFileIconClass(f.file_type || 'file');
                                mh += '<a href="' + esc(f.file_url || f.file_path || '#') + '" class="file-item" target="_blank" rel="noopener" download="' + esc(f.original_name || '') + '">';
                                mh += '<div class="file-icon"><i class="bi ' + ic + '"></i></div>';
                                mh += '<div class="file-info"><div class="file-name">' + esc(f.original_name || 'Файл') + '</div>';
                                mh += '<div class="file-size">' + esc(bankWorkFormatFileSize(f.file_size)) + '</div></div></a>';
                            });
                            mh += '</div>';
                        }
                        mh += '</div>';
                    });
                }
                msgBox.innerHTML = mh;
                msgBox.scrollTop = msgBox.scrollHeight;
            }
        }
    }

    const bankUploadBtn = document.getElementById('bankUploadBtn');
    if (bankUploadBtn) {
    bankUploadBtn.addEventListener('click', function () {
        const title = (document.getElementById('bankUploadTitle').value || '').trim();
        const description = (document.getElementById('bankUploadDesc').value || '').trim();
        const fileInput = document.getElementById('bankUploadFile');
        if (!title) {
            showNotification('Укажите название файла', 'error');
            return;
        }
        if (!fileInput || !fileInput.files || !fileInput.files[0]) {
            showNotification('Выберите файл', 'error');
            return;
        }
        const fd = new FormData();
        fd.append('action', 'manager_upload');
        fd.append('application_product_id', applicationProductId);
        fd.append('title', title);
        fd.append('description', description);
        fd.append('file', fileInput.files[0]);
        const btn = this;
        btn.disabled = true;
        fetch('api_bank_case.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.success) {
                    document.getElementById('bankUploadTitle').value = '';
                    document.getElementById('bankUploadDesc').value = '';
                    fileInput.value = '';
                    bankReload();
                    showNotification('Файл добавлен', 'success');
                } else {
                    showNotification(d.error || 'Ошибка', 'error');
                }
            })
            .catch(function () { showNotification('Ошибка сети', 'error'); })
            .finally(function () { btn.disabled = false; });
    });
    }

    const bankSubmitBtnEl = document.getElementById('bankSubmitBtn');
    if (bankSubmitBtnEl) {
    bankSubmitBtnEl.addEventListener('click', function () {
        const comment = (document.getElementById('bankManagerComment').value || '').trim();
        const fd = new FormData();
        fd.append('action', 'manager_submit');
        fd.append('application_product_id', applicationProductId);
        fd.append('manager_comment', comment);
        const btn = this;
        btn.disabled = true;
        fetch('api_bank_case.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.success) {
                    const url = new URL(window.location.href);
                    url.searchParams.set('tab', 'bank');
                    window.location.href = url.toString();
                } else {
                    showNotification(d.error || 'Ошибка', 'error');
                }
            })
            .catch(function () { showNotification('Ошибка сети', 'error'); })
            .finally(function () { btn.disabled = false; });
    });
    }

    const bankWorkMsgSend = document.getElementById('bankWorkMessageSend');
    if (bankWorkMsgSend) {
    bankWorkMsgSend.addEventListener('click', function () {
        const ta = document.getElementById('bankWorkMessageInput');
        const fileInput = document.getElementById('bankWorkChatFiles');
        if (!ta) {
            showNotification('Ошибка интерфейса чата', 'error');
            return;
        }
        const text = (ta.value || '').trim();
        const hasFiles = fileInput && fileInput.files && fileInput.files.length > 0;
        if (!text && !hasFiles) {
            showNotification('Введите сообщение или прикрепите файл', 'error');
            return;
        }
        const btn = this;
        btn.disabled = true;
        fetch('api_bank_case.php?action=manager_get&application_product_id=' + encodeURIComponent(applicationProductId))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success || !data.case) {
                    throw new Error(data.error || 'Нет кейса');
                }
                const fd = new FormData();
                fd.append('action', 'post_message');
                fd.append('message', text);
                fd.append('bank_case_id', String(data.case.id));
                if (hasFiles) {
                    Array.from(fileInput.files).forEach(function (f) {
                        fd.append('chat_files[]', f);
                    });
                }
                return fetch('api_bank_case.php', { method: 'POST', body: fd });
            })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.success) {
                    ta.value = '';
                    if (fileInput) {
                        fileInput.value = '';
                        bankWorkRefreshChatFiles();
                    }
                    bankReload();
                } else {
                    showNotification(d.error || 'Ошибка', 'error');
                }
            })
            .catch(function (e) {
                showNotification(e.message || 'Ошибка сети', 'error');
            })
            .finally(function () { btn.disabled = false; });
    });
    }

    document.addEventListener('DOMContentLoaded', function () {
        const bf = document.getElementById('bankWorkChatFiles');
        if (bf) {
            bf.addEventListener('change', bankWorkRefreshChatFiles);
        }
        const bankTa = document.getElementById('bankWorkMessageInput');
        if (bankTa) {
            bankTa.addEventListener('input', function () {
                this.style.height = 'auto';
                this.style.height = Math.min(this.scrollHeight, 120) + 'px';
            });
        }
    });

    function bankMaybeLoad() {
        const pane = document.getElementById('bank-work');
        if (pane && pane.classList.contains('active')) {
            bankReload();
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        bankMaybeLoad();
        const tabBtn = document.getElementById('bank-work-tab');
        if (tabBtn) {
            tabBtn.addEventListener('shown.bs.tab', bankReload);
        }
    });
})();
</script>
<?php endif; ?>

<?php require_once 'footer.php'; ?>