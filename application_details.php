<?php
$current_page = 'applications';
require_once 'config.php';
require_once __DIR__ . '/includes/application_documents_upload.php';
require_once __DIR__ . '/includes/upload_access.php';
require_once __DIR__ . '/includes/beneficiary_intake.php';
require_once __DIR__ . '/includes/chat_helpers.php';
checkAuth();

$pdo = getPDO();
$currentUser = getCurrentUser();
$userRole = $_SESSION['role'] ?? 'client';
$userId = $_SESSION['user_id'];
$userIsAnalystFlag = finbuild_user_is_analyst_flag($currentUser);

// Проверяем ID заявки
$applicationId = $_GET['id'] ?? 0;
if (!$applicationId) {
    header('Location: applications.php');
    exit();
}

// Получаем данные заявки (включая ответственного — ФИ менеджера)
$stmt = $pdo->prepare("
    SELECT a.*, 
           u.first_name, u.last_name, u.phone, u.company_name as user_company, u.role as user_role,
           m.first_name as manager_name, m.last_name as manager_surname,
           assigned_user.first_name as assigned_first_name, assigned_user.last_name as assigned_last_name
    FROM applications a 
    LEFT JOIN users u ON a.created_by = u.id 
    LEFT JOIN users m ON a.added_by = m.id
    LEFT JOIN users assigned_user ON assigned_user.id = a.assigned_to
    WHERE a.id = ?
");
$stmt->execute([$applicationId]);
$application = $stmt->fetch();

if (!$application) {
    header('Location: applications.php');
    exit();
}

// Проверяем доступ
if (!finbuild_can_access_application($pdo, (int) $applicationId, $userRole, (int) $userId, $userIsAnalystFlag)) {
    header('Location: applications.php');
    exit();
}

$isAnalystView = finbuild_application_details_analyst_mode(
    $userRole,
    $userIsAnalystFlag,
    (int) $application['created_by'],
    (int) $userId
);

if (finbuild_analyst_cannot_view_failed_application($application, $userRole, $userIsAnalystFlag, (int) $userId)) {
    $redirectScope = finbuild_user_is_analyst_flag($currentUser) && (string) ($_GET['scope'] ?? '') === 'all' ? '?scope=all' : '';
    header('Location: applications.php' . $redirectScope);
    exit();
}

if (finbuild_is_manager($userRole) && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['dismiss_duplicate_warning'])) {
    $postAid = (int)($_POST['application_id'] ?? 0);
    if ($postAid === (int)$applicationId) {
        try {
            if (($application['product_type'] ?? '') === 'bg') {
                $stmtDismiss = $pdo->prepare("
                    UPDATE applications SET duplicate_warning_dismissed = 1
                    WHERE product_type = 'bg'
                      AND inn <=> ?
                      AND amount <=> ?
                      AND fz_type <=> ?
                      AND guarantee_type <=> ?
                ");
                $stmtDismiss->execute([
                    $application['inn'],
                    $application['amount'],
                    $application['fz_type'],
                    $application['guarantee_type'],
                ]);
            } else {
                $pdo->prepare('UPDATE applications SET duplicate_warning_dismissed = 1 WHERE id = ?')->execute([$applicationId]);
            }
        } catch (PDOException $e) {
            // колонка duplicate_warning_dismissed появится после миграции
        }
    }
    header('Location: application_details.php?id=' . (int)$applicationId);
    exit;
}

require_once __DIR__ . '/includes/application_structure.php';

// AJAX структуры — через эту же страницу (сессия и авторизация уже проверены выше).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['structure_ajax'] ?? '') === '1') {
    $ajaxAppId = (int) ($_POST['application_id'] ?? $applicationId);
    if ($ajaxAppId !== (int) $applicationId) {
        finbuild_application_structure_send_json(['success' => false, 'error' => 'Неверная заявка']);
    }
    $action = (string) ($_POST['action'] ?? '');
    finbuild_application_structure_dispatch($pdo, $userRole, (int) $userId, $applicationId, $action, 'POST', $userIsAnalystFlag);
    exit;
}

$structureCanEdit = finbuild_can_edit_application_structure(
    $userRole,
    $userIsAnalystFlag || finbuild_is_analyst_role($userRole),
    $currentUser
);
$showStructureTab = finbuild_can('structure.view', $currentUser)
    || in_array($userRole, ['client', 'partner', 'bank'], true);
$structureGrouped = finbuild_application_structure_fetch_grouped($pdo, (int) $applicationId, false);

require_once 'header.php';
?>
<link rel="stylesheet" href="assets/css/company_analytics.css">
<?php
require_once __DIR__ . '/includes/public/landing_data.php';

// Безопасный return назад в список заявок (только внутренний applications.php?... без протокола/домена)
$returnParam = (string)($_GET['return'] ?? '');
$backToApplications = 'applications.php';
if ($returnParam !== '' && strpos($returnParam, '://') === false && strpos($returnParam, 'applications.php') === 0) {
    $backToApplications = $returnParam;
}

$structureAjaxUrl = finbuild_application_structure_page_ajax_url((int) $applicationId, $returnParam);

// Без права applications.assign не может менять ответственного
$isSubmanager = finbuild_should_mask_owner_identity($currentUser)
    || !finbuild_can('applications.assign', $currentUser);
$showRoadmapTab = finbuild_can('roadmap.view', $currentUser);
$roadmapCanEdit = finbuild_can('roadmap.edit', $currentUser);
$canUseProductChat = finbuild_can_use_product_chat($currentUser);
$chatAllowedThreads = finbuild_chat_allowed_threads_for_viewer($currentUser, $application);
$chatDefaultThread = finbuild_is_manager($userRole)
    ? ($chatAllowedThreads[0] ?? 'principal')
    : (finbuild_chat_thread_for_viewer($currentUser, $application) ?? 'principal');
if (!in_array($chatDefaultThread, $chatAllowedThreads, true)) {
    $chatDefaultThread = $chatAllowedThreads[0] ?? 'principal';
}
$viewerHasProductChats = $userRole !== 'beneficiary';

// Список менеджеров для поля "Ответственный"
$managersList = [];
if (finbuild_can('applications.assign', $currentUser)) {
    $stmtManagers = $pdo->prepare('SELECT id, first_name, last_name FROM users WHERE role IN (' . finbuild_manager_roles_sql_in() . ') AND is_active = 1 ORDER BY last_name ASC, first_name ASC');
    $stmtManagers->execute();
    $managersList = $stmtManagers->fetchAll(PDO::FETCH_ASSOC);
}

// Получаем продукты заявки
$products = [];
$unreadCounts = [];
$appChatUnread = 0;
$appChatUnreadCurrent = 0;
$productUnreadTotal = 0;
$totalUnreadMessages = 0;
$showProductChatTabs = false;
$productNavEnabled = !$isAnalystView;

$stmtProducts = $pdo->prepare("
    SELECT ap.*, 
           CASE 
               WHEN ap.product_type = 'bg' THEN bp.name
               WHEN ap.product_type = 'credit' THEN cp.name
           END as product_name,
           CASE 
               WHEN ap.product_type = 'bg' THEN bp.bank_name
               WHEN ap.product_type = 'credit' THEN cp.bank_name
           END as bank_name
    FROM application_products ap
    LEFT JOIN bank_products bp ON ap.product_id = bp.id AND ap.product_type = 'bg'
    LEFT JOIN credit_products cp ON ap.product_id = cp.id AND ap.product_type = 'credit'
    WHERE ap.application_id = ?
    ORDER BY ap.created_at DESC
");
$stmtProducts->execute([$applicationId]);
$products = $stmtProducts->fetchAll();

if (!$isAnalystView && $canUseProductChat) {
$applicationOwnerId = (int) $application['created_by'];
$principalUid = (int) ($application['principal_user_id'] ?? 0);
$showProductChatTabs = $userRole !== 'beneficiary' && count($products) > 0;
if ($showProductChatTabs) {
    foreach ($products as $product) {
        if (finbuild_is_manager($userRole)) {
            $stmtUnread = $pdo->prepare("
                SELECT COUNT(*) as unread_count 
                FROM application_product_chats c
                INNER JOIN users u ON u.id = c.user_id
                WHERE c.application_product_id = ? AND c.thread = 'principal'
                  AND c.is_read = 0 AND u.role IN ('client', 'partner')
            ");
            $stmtUnread->execute([$product['id']]);
        } else {
            $stmtUnread = $pdo->prepare("
                SELECT COUNT(*) as unread_count 
                FROM application_product_chats 
                WHERE application_product_id = ? AND thread = 'principal' AND is_read = 0 AND user_id != ?
            ");
            $stmtUnread->execute([$product['id'], $userId]);
        }
        $unreadCounts[$product['id']] = $stmtUnread->fetch()['unread_count'];
    }
}

$appChatUnreadCurrent = finbuild_application_chat_unread_count(
    $pdo,
    (int) $applicationId,
    (string) $userRole,
    (int) $userId,
    $application,
    $chatDefaultThread
);
$appChatUnread = finbuild_application_chat_unread_total(
    $pdo,
    (int) $applicationId,
    (string) $userRole,
    (int) $userId,
    $application,
    $chatAllowedThreads
);
$productUnreadTotal = 0;
foreach ($unreadCounts as $unreadCount) {
    $productUnreadTotal += (int) $unreadCount;
}
$totalUnreadMessages = $appChatUnread + $productUnreadTotal;
}

// Функции для форматирования

function getStatusBadge($status) {
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

function getProductTypeText($productType) {
    return $productType === 'bg' ? 'Банковская гарантия' : 'Кредит для бизнеса';
}

function renderApplicationProductBankVisual(array $product): string
{
    $bankName = (string)($product['bank_name'] ?? '');
    $productName = (string)($product['product_name'] ?? '');
    $productType = (string)($product['product_type'] ?? 'bg');
    $logoUrl = finbuild_bank_logo_url_for_bank_name($bankName, $productName);

    if ($logoUrl !== null && $logoUrl !== '') {
        return '<div class="app-product-bank-logo me-3" aria-hidden="true">'
            . '<img src="' . htmlspecialchars($logoUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '" alt="">'
            . '</div>';
    }

    $icon = $productType === 'bg' ? 'bi-shield-check' : 'bi-cash-coin';

    return '<div class="app-product-bank-icon avatar-circle-sm bg-light text-primary me-3 rounded-circle d-flex align-items-center justify-content-center">'
        . '<i class="bi ' . $icon . ' fs-5"></i>'
        . '</div>';
}
// Функция для форматирования размера файла
function formatFileSize($bytes) {
    if ($bytes == 0) return '0 Bytes';
    $k = 1024;
    $sizes = ['Bytes', 'KB', 'MB', 'GB'];
    $i = floor(log($bytes) / log($k));
    return round($bytes / pow($k, $i), 2) . ' ' . $sizes[$i];
}
function formatAmount($amount) {
    if (!$amount) return '-';
    return number_format($amount, 2, ',', ' ') . ' ₽';
}

// Обработка изменения статуса заявки (вручную менеджером — письмо владельцу заявки при смене)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $newStatus = $_POST['status'] ?? '';
    $allowedAppStatuses = [
        'new', 'in_progress', 'pending_signing', 'product_request', 'terms_negotiation', 'pending_release',
        'completed', 'failed',
    ];
    if (in_array($newStatus, $allowedAppStatuses, true)) {
        $oldStatus = $application['status'];
        if ($oldStatus !== $newStatus) {
            $stmt = $pdo->prepare("UPDATE applications SET status = ? WHERE id = ?");
            $stmt->execute([$newStatus, $applicationId]);
            $application['status'] = $newStatus;
            require_once __DIR__ . '/includes/notification_events.php';
            notify_application_owner_status_changed($pdo, (int) $applicationId, $oldStatus, $newStatus);
        }
    }
}

$possibleDuplicateApplicationId = null;
if (finbuild_is_manager($userRole) && $application['product_type'] === 'bg' && empty($application['duplicate_warning_dismissed'] ?? 0)) {
    try {
        $stmtDup = $pdo->prepare("
            SELECT id FROM applications
            WHERE product_type = 'bg'
              AND id != ?
              AND inn <=> ?
              AND amount <=> ?
              AND fz_type <=> ?
              AND guarantee_type <=> ?
            ORDER BY id ASC
            LIMIT 1
        ");
        $stmtDup->execute([
            $applicationId,
            $application['inn'],
            $application['amount'],
            $application['fz_type'],
            $application['guarantee_type'],
        ]);
        $dupRow = $stmtDup->fetch(PDO::FETCH_ASSOC);
        if ($dupRow) {
            $possibleDuplicateApplicationId = (int)$dupRow['id'];
        }
    } catch (PDOException $e) {
        $possibleDuplicateApplicationId = null;
    }
}

?>

<!-- <div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1 class="h3 mb-0">Заявка #<?= $application['id'] ?></h1>
            <p class="text-muted mb-0">
                <?= getProductTypeText($application['product_type']) ?> • 
                <?= htmlspecialchars($application['company_name']) ?>
            </p>
        </div>
        <div class="col-auto">
            <a href="applications.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-2"></i>Назад к заявкам
            </a>
        </div>
    </div>
</div> -->
<div class="page-header">
    <!-- Верхняя градиентная полоса статуса -->
    <?php
    $stripMap = [
        'new' => 'status-strip-new',
        'in_progress' => 'status-strip-in-progress',
        'pending_signing' => 'status-strip-pending-signing',
        'product_request' => 'status-strip-product-request',
        'terms_negotiation' => 'status-strip-terms-negotiation',
        'pending_release' => 'status-strip-pending-release',
        'completed' => 'status-strip-completed',
        'failed' => 'status-strip-failed',
    ];
    $stripClass = $stripMap[$application['status']] ?? 'status-strip-in-progress';
    ?>
    <div class="status-strip <?= htmlspecialchars($stripClass) ?>"></div>
    
    <div class="row align-items-center">
        <div class="col">
            <div class="d-flex align-items-center gap-3 mb-2">
                <h1 class="h3 mb-0">Заявка #<?= $application['id'] ?></h1>
                <?= getStatusBadge($application['status']) ?>
            </div>
            <div class="d-flex flex-wrap align-items-center gap-3">
                <div class="application-meta-item">
                    <i class="bi bi-building me-1 text-muted"></i>
                    <span class="text-dark fw-medium"><?= htmlspecialchars($application['company_name']) ?></span>
                </div>
                <div class="text-muted">•</div>
                <div class="application-meta-item">
                    <i class="bi bi-tag me-1 text-muted"></i>
                    <span><?= getProductTypeText($application['product_type']) ?></span>
                </div>
                <?php if ($application['amount']): ?>
                <div class="text-muted">•</div>
                <div class="application-meta-item">
                    <i class="bi bi-currency-ruble me-1 text-muted"></i>
                    <strong class="text-success"><?= formatAmount($application['amount']) ?></strong>
                </div>
                <?php endif; ?>

           <?php if ($application['product_type'] === 'bg' && $application['term_bg']): ?>
<div class="text-muted">•</div>
<div class="application-meta-item">
    <i class="bi bi-calendar me-1 text-muted"></i>
    <span>до <?= date('d.m.Y', strtotime($application['term_bg'])) ?> (<?= $application['term'] ?> мес.)</span>
</div>
<?php elseif ($application['product_type'] === 'credit' && $application['term']): ?>
<div class="text-muted">•</div>
<div class="application-meta-item">
    <i class="bi bi-calendar me-1 text-muted"></i>
    <span><?= $application['term'] ?> мес.</span>
</div>
<?php endif; ?>
            </div>
            <div class="mt-2">
                <small class="text-muted">
                    <i class="bi bi-clock me-1"></i>
                    Создана: <?= date('d.m.Y H:i', strtotime($application['created_at'])) ?>
                </small>
            </div>
        </div>
        <div class="col-auto">
            <a href="<?= htmlspecialchars($backToApplications) ?>" class="btn btn-primary">
                <i class="bi bi-arrow-left me-2"></i>Назад к заявкам
            </a>
        </div>
    </div>
</div>

<?php if (finbuild_is_manager($userRole) && $possibleDuplicateApplicationId): ?>
<div class="duplicate-suspect-banner alert mb-4 border-0 shadow-sm" role="alert">
    <div class="duplicate-suspect-banner__inner">
        <div class="duplicate-suspect-banner__body">
            <div class="duplicate-suspect-banner__title fw-bold text-dark">
                <i class="bi bi-exclamation-triangle-fill me-2 flex-shrink-0"></i>Подозрение на дубль
            </div>
            <p class="duplicate-suspect-banner__text mb-0 text-dark">
                Совпадают ИНН организации, сумма БГ, вид гарантии и вид ФЗ с заявкой
                <a href="application_details.php?id=<?= $possibleDuplicateApplicationId ?>" target="_blank" rel="noopener noreferrer" class="fw-semibold text-decoration-underline text-break">#<?= $possibleDuplicateApplicationId ?></a>
            </p>
        </div>
        <form method="post" class="duplicate-suspect-banner__form">
            <input type="hidden" name="application_id" value="<?= (int)$applicationId ?>">
            <input type="hidden" name="dismiss_duplicate_warning" value="1">
            <button type="submit" class="btn btn-outline-secondary duplicate-suspect-banner__btn">Это не дубль</button>
        </form>
    </div>
</div>
<?php endif; ?>

<style>

.duplicate-suspect-banner {
    background: linear-gradient(135deg, #fff4e6 0%, #ffe0c2 100%);
    border-left: 4px solid #fd7e14 !important;
    border-radius: 12px;
    padding: 1rem 1.25rem;
}

.duplicate-suspect-banner__inner {
    display: flex;
    flex-direction: column;
    align-items: stretch;
    gap: 1rem;
}

.duplicate-suspect-banner__body {
    flex: 1;
    min-width: 0;
}

.duplicate-suspect-banner__title {
    display: flex;
    align-items: flex-start;
    font-size: 1rem;
    line-height: 1.35;
    margin-bottom: 0.5rem;
}

.duplicate-suspect-banner__text {
    font-size: 0.875rem;
    line-height: 1.45;
    word-break: break-word;
    overflow-wrap: anywhere;
}

.duplicate-suspect-banner__form {
    flex-shrink: 0;
    margin: 0;
}

.duplicate-suspect-banner__btn {
    width: 100%;
    min-height: 44px;
    font-size: 0.9rem;
    padding: 0.5rem 1rem;
}

@media (min-width: 768px) {
    .duplicate-suspect-banner__inner {
        flex-direction: row;
        align-items: flex-end;
        justify-content: space-between;
        gap: 1.25rem;
    }

    .duplicate-suspect-banner__form {
        width: auto;
    }

    .duplicate-suspect-banner__btn {
        width: auto;
        min-height: 38px;
        font-size: 0.875rem;
    }
}

@media (max-width: 767.98px) {
    .duplicate-suspect-banner {
        padding: 0.85rem 1rem;
        margin-bottom: 1rem !important;
        border-radius: 10px;
    }

    .duplicate-suspect-banner__title {
        font-size: 0.95rem;
    }

    .duplicate-suspect-banner__text {
        font-size: 0.8125rem;
    }
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
    padding-top: 2.5rem; /* Добавляем отступ сверху для полосы */
}

/* Остальные стили остаются как были */
.application-meta-item {
    display: flex;
    align-items: center;
}

.btn-primary {
    background: linear-gradient(to right, #3498db, #2980b9);
    border: none;
    padding: 0.5rem 1.25rem;
    box-shadow: 0 2px 8px rgba(52, 152, 219, 0.25);
}

.btn-primary:hover {
    background: linear-gradient(to right, #2980b9, #2471a3);
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(52, 152, 219, 0.35);
}

.btn-text-full {
    display: inline;
}

.btn-text-short {
    display: none;
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

@media (max-width: 768px) {
    .page-header {
        padding: 1rem;
    }

    .page-header h1 {
        font-size: 1.05rem;
    }

    .page-header .row {
        row-gap: 0.75rem;
    }

    .page-header .d-flex.align-items-center {
        gap: 0.4rem !important;
    }

    .page-header .application-meta-item,
    .page-header small {
        font-size: 0.85rem;
    }

    .application-tabs {
        overflow-x: visible;
        white-space: normal;
    }

    .application-tabs .nav {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(0, 1fr));
        border-bottom: 1px solid #e9ecef;
        gap: 0;
    }

    .application-tabs .nav-item {
        min-width: 0;
    }

    .application-tabs .nav-link {
        width: 100%;
        border: none;
        border-bottom: 2px solid transparent;
        border-radius: 0;
        background: transparent;
        padding: 0.6rem 0.4rem;
        font-size: 0.8rem;
        line-height: 1.2;
        text-align: center;
        white-space: normal;
    }

    .application-tabs .nav-link.active {
        color: #3498db;
        border-bottom-color: #3498db;
        background: transparent;
    }

    .tab-content {
        background: #fff;
        border: 1px solid #e9ecef;
        border-radius: 12px;
        box-shadow: 0 2px 12px rgba(0,0,0,0.05);
    }

    .tab-pane {
        padding: 1rem;
    }

    html, body {
        max-width: 100%;
        overflow-x: hidden;
    }

    .page-header .d-flex.align-items-center {
        flex-wrap: wrap;
        align-items: flex-start;
        gap: 0.5rem !important;
    }
    
    .page-header .d-flex.flex-wrap {
        display: grid;
        grid-template-columns: 1fr;
        gap: 0.4rem;
    }
    
    .page-header div.text-muted {
        display: none;
    }
    
    .application-meta-item {
        margin-bottom: 0.25rem;
        font-size: 0.85rem;
        color: #475569;
    }

    .application-meta-item span {
        overflow-wrap: break-word;
        word-break: normal;
    }

    .page-header {
        padding: 1rem;
    }

    .tab-pane {
        padding: 0.5rem 0;
    }

    .application-tabs {
        overflow-x: auto;
        white-space: nowrap;
        padding-bottom: 4px;
    }

    .application-tabs .nav {
        flex-wrap: nowrap;
        gap: 8px;
    }

    .application-tabs .nav-link {
        padding: 0.4rem 0.85rem;
        font-size: 0.85rem;
        border: 1px solid #e2e8f0;
        border-radius: 999px;
        background: #fff;
    }

    .detail-row {
        display: grid;
        gap: 6px;
        padding: 0.6rem 0;
        border-bottom: 1px solid #eef2f7;
        max-width: 100%;
        box-sizing: border-box;
    }

    .detail-row:last-child {
        border-bottom: none;
    }

    .detail-label {
        flex: 0 0 auto;
        width: 100%;
        font-size: 0.7rem;
        text-transform: uppercase;
        letter-spacing: 0.02em;
        color: #94a3b8;
        margin-bottom: 0.1rem;
    }

    .detail-value {
        display: block;
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
        font-size: 0.95rem;
        line-height: 1.5;
        padding: 0;
        background: transparent;
        border: none;
        word-break: break-word;
        overflow-wrap: break-word;
        white-space: normal;
    }

    .detail-value .field-value {
        display: block;
        max-width: 100%;
    }

    .detail-value .edit-input,
    .detail-value textarea {
        width: 100%;
        box-sizing: border-box;
    }

    .application-detail-card {
        margin-top: 0.75rem;
    }

    .table-responsive {
        border-radius: 10px;
    }

    /* App-like cards for mobile */
    .detail-section {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        padding: 0.85rem 0.9rem;
        box-shadow: 0 6px 16px rgba(15, 23, 42, 0.06);
        max-width: 100%;
        box-sizing: border-box;
    }

    .application-detail-card,
    .comment-box,
    .table-responsive,
    .product-card,
    .analytics-card,
    .document-card {
        max-width: 100%;
        box-sizing: border-box;
    }

    .detail-section h5 {
        font-size: 0.95rem;
        margin-bottom: 1rem;
        border-bottom: 1px solid #e2e8f0;
        padding-bottom: 0.5rem;
    }

    .comment-box {
        border-radius: 12px;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
    }

    .application-detail-card {
        border-radius: 14px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 6px 16px rgba(15, 23, 42, 0.06);
    }

    .application-meta-item {
        font-size: 0.85rem;
    }

    .page-header h1 {
        font-size: 1.15rem;
    }

    .page-header .btn {
        width: 100%;
    }

    .page-header .col-auto {
        width: 100%;
    }

    .page-header {
        border-radius: 14px;
        background: #fff;
        box-shadow: 0 6px 16px rgba(15, 23, 42, 0.06);
        border: 1px solid #e2e8f0;
    }

    .application-tabs .nav-link {
        white-space: nowrap;
    }

    .application-tabs .nav-link.active {
        background: #0ea5e9;
        border-color: #0ea5e9;
        color: #fff;
    }

    .tab-content {
        background: transparent;
        border: none;
        box-shadow: none;
    }
}





.application-tabs .nav-link {
    border: none;
    padding: 1rem 1.5rem;
    font-weight: 500;
    color: #6c757d;
    border-bottom: 3px solid transparent;
}

.application-tabs .nav-link.active {
    color: #3498db;
    border-bottom-color: #3498db;
    background: transparent;
}

.application-tabs .nav-link:hover {
    border-bottom-color: #dee2e6;
}

.tab-content {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 20px rgba(0,0,0,0.08);
    border: 1px solid #e9ecef;
}

.tab-pane {
    padding: 2rem;
}

.detail-section {
    margin-bottom: 2rem;
}

.detail-section:last-child {
    margin-bottom: 0;
}

.detail-section h5 {
    color: #2c3e50;
    border-bottom: 2px solid #3498db;
    padding-bottom: 0.75rem;
    margin-bottom: 1.5rem;
    font-weight: 600;
}

.detail-row {
    display: flex;
    margin-bottom: 1rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid #f8f9fa;
}

.detail-row:last-child {
    margin-bottom: 0;
    padding-bottom: 0;
    border-bottom: none;
}

.detail-label {
    flex: 0 0 200px;
    font-weight: 600;
    color: #495057;
}

.detail-value {
    flex: 1;
    color: #2c3e50;
}

.product-card {
    border: 1px solid #e9ecef;
    border-radius: 8px;
    margin-bottom: 1rem;
    transition: all 0.3s ease;
}

.product-card:hover {
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}

.product-card-header {
    background: #f8f9fa;
    padding: 1rem 1.5rem;
    border-bottom: 1px solid #e9ecef;
    border-radius: 8px 8px 0 0;
}

.product-card-body {
    padding: 1.5rem;
}

.app-product-bank-logo,
.app-product-bank-icon {
    width: 64px;
    height: 64px;
    flex-shrink: 0;
}

.app-product-bank-logo {
    display: flex;
    align-items: center;
    justify-content: center;
}

.app-product-bank-logo img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
}

.badge.bg-purple { background-color: #6f42c1; }
.badge.bg-orange { background-color: #fd7e14; }

.chat-notification {
    position: relative;
}

.chat-badge {
    position: absolute;
    top: -5px;
    right: -5px;
    background: #dc3545;
    color: white;
    border-radius: 50%;
    width: 20px;
    height: 20px;
    font-size: 0.7rem;
    display: flex;
    align-items: center;
    justify-content: center;
}

/* ============================================================================
   Chat drawer widget (application_details)
   ========================================================================== */
.app-chat-fab {
    position: fixed;
    right: 38px;
    bottom: 18px;
    z-index: 1050;
    border: none;
    border-radius: 999px;
    padding: 12px 14px;
    background: linear-gradient(135deg, #3498db, #2980b9);
    color: #fff;
    display: inline-flex;
    align-items: center;
    gap: 10px;
    box-shadow: 0 10px 24px rgba(15, 23, 42, 0.18);
}
.app-chat-fab:hover { filter: brightness(1.02); }
.app-chat-fab .app-chat-fab-label { font-weight: 600; font-size: 0.9rem; }
.app-chat-fab .app-chat-fab-badge {
    min-width: 22px;
    height: 22px;
    padding: 0 6px;
    border-radius: 999px;
    background: #dc3545;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.75rem;
    font-weight: 700;
}

/* На практике фиксированное right выглядит лучше и не уезжает к центру. */

.app-chat-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.35);
    z-index: 1049;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.2s ease;
}
.app-chat-drawer {
    position: fixed;
    top: 0;
    right: 0;
    height: 100vh;
    width: min(420px, 92vw);
    background: #fff;
    z-index: 1050;
    transform: translateX(100%);
    transition: transform 0.22s ease;
    box-shadow: -16px 0 40px rgba(15, 23, 42, 0.18);
    display: flex;
    flex-direction: column;
}
body.app-chat-open .app-chat-overlay { opacity: 1; pointer-events: auto; }
body.app-chat-open .app-chat-drawer { transform: translateX(0); }
body.app-chat-open { overflow: hidden; }
body.app-chat-open .app-chat-fab { display: none; }

.app-chat-drawer-header {
    padding: 14px 16px;
    border-bottom: 1px solid #e9ecef;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}
.app-chat-drawer-title {
    margin: 0;
    font-weight: 700;
    font-size: 1rem;
    color: #0f172a;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}
.app-chat-products-tabs {
    padding: 10px 12px;
    border-bottom: 1px solid #e9ecef;
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    position: relative;
}
.app-chat-products-tabs:empty,
.app-chat-products-tabs.is-single {
    display: none;
}
.app-chat-products-tabs::after {
    content: "";
    position: absolute;
    left: 0;
    right: 0;
    bottom: -14px;
    height: 14px;
    pointer-events: none;
    background: linear-gradient(to bottom, rgba(15, 23, 42, 0.18), rgba(15, 23, 42, 0));
    z-index: 0;
}
.app-chat-products-tabs > * {
    position: relative;
    z-index: 1;
}
.app-chat-product-tab {
    border: 1px solid #e9ecef;
    background: #fff;
    border-radius: 999px;
    padding: 6px 10px;
    font-size: 0.82rem;
    color: #334155;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}
.app-chat-product-tab.active {
    background: #3498db;
    border-color: #3498db;
    color: #fff;
    box-shadow: 0 2px 10px rgba(52, 152, 219, 0.18);
}
.app-chat-product-tab.active .text-truncate { color: #fff; }
.app-chat-product-badge {
    min-width: 18px;
    height: 18px;
    padding: 0 6px;
    border-radius: 999px;
    background: #dc3545;
    color: #fff;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.72rem;
    font-weight: 700;
}
.app-chat-body {
    display: flex;
    flex-direction: column;
    min-height: 0;
    flex: 1;
}
.app-chat-messages-wrap {
    position: relative;
    flex: 1;
    min-height: 0;
    display: flex;
    flex-direction: column;
}
.app-chat-messages {
    flex: 1;
    min-height: 0;
    overflow-y: auto;
    overflow-x: hidden;
    padding: 14px 16px;
    background: #fff;
}
.app-chat-new-below-btn {
    position: absolute;
    left: 50%;
    bottom: 10px;
    transform: translateX(-50%);
    z-index: 3;
    white-space: nowrap;
    border-radius: 999px;
    padding-left: 14px;
    padding-right: 14px;
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.15) !important;
}
.app-chat-new-below-btn:focus {
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.2) !important;
}
.app-chat-input {
    padding: 12px 16px;
    background: #f8f9fa;
    border-top: 1px solid #e9ecef;
}
.app-chat-drawer .form-text.small { font-size: 0.72rem; }

/* Reuse the chat message styles from product_details by matching class names */
.app-chat-drawer .message { margin-bottom: 12px; }
.app-chat-drawer .message.own .message-content { background: #3498db; color: #fff; }
.app-chat-drawer .message.other-manager .message-content { background: #e2e8f0; }
.app-chat-drawer .message.from-manager + .message.from-client,
.app-chat-drawer .message.from-client + .message.from-manager {
    /* margin-top схлопывается с margin-bottom предыдущего сообщения, поэтому используем padding */
    padding-top: 16px;
}
.app-chat-drawer .message-header { display: flex; justify-content: space-between; gap: 10px; margin-bottom: 6px; font-size: 0.78rem; color: #64748b; }
.app-chat-drawer .message-sender { font-weight: 700; color: #0f172a; }
.app-chat-drawer .message-content {
    background: #f1f5f9;
    border-radius: 12px;
    padding: 10px 12px;
    color: #0f172a;
    max-width: 100%;
    overflow-wrap: anywhere;
    word-break: break-word;
}
.app-chat-drawer .message-content a {
    color: #0d6efd;
    text-decoration: underline;
    word-break: break-all;
}
.app-chat-drawer .message.own .message-content a {
    color: #fff;
}
.app-chat-drawer .message-files { margin-top: 8px; display: grid; gap: 8px; }
.app-chat-drawer .file-item { border: 1px solid #e9ecef; border-radius: 12px; padding: 10px; display: flex; gap: 10px; text-decoration: none; color: inherit; background: #fff; }
.app-chat-drawer .file-item:hover { background: #f8fafc; }
.app-chat-drawer .file-icon { width: 34px; height: 34px; border-radius: 10px; background: #f8fafc; display: flex; align-items: center; justify-content: center; }
.app-chat-drawer .file-name {
    font-weight: 600;
    font-size: 0.85rem;
    overflow-wrap: anywhere;
    word-break: break-word;
}
.app-chat-drawer .file-size { font-size: 0.75rem; color: #64748b; }
.app-chat-drawer .message-system { display: flex; justify-content: center; padding: 20px 0; color: #64748b; }

.app-chat-drawer .message-time { white-space: nowrap; }

@media (max-width: 768px) {
    .app-chat-drawer .message-header {
        flex-wrap: nowrap;
        gap: 8px;
        font-size: 0.72rem;
    }
    .app-chat-drawer .message-sender {
        min-width: 0;
        white-space: nowrap;
    }
    .app-chat-drawer .message-sender .badge {
        font-size: 0.62rem;
        padding: 0.18rem 0.35rem;
        line-height: 1.1;
    }
}

.empty-state {
    text-align: center;
    padding: 3rem 2rem;
    color: #6c757d;
}

.empty-state i {
    font-size: 4rem;
    margin-bottom: 1.5rem;
}

.status-selector {
    max-width: 200px;
}
/*.status-badge-completed {
    background: linear-gradient(45deg, #28a745, #20c997);
}

.status-badge-in-progress {
    background: linear-gradient(45deg, #007bff, #6f42c1);
}

.status-badge-new {
    background: linear-gradient(45deg, #ffc107, #fd7e14);
}*/

.status-transition {
    transition: all 0.3s ease;
}

.status-transition:hover {
    transform: scale(1.05);
    box-shadow: 0 4px 15px rgba(0,0,0,0.2);
}
.document-card {
    border: 1px solid #e9ecef;
    border-radius: 8px;
    padding: 1rem;
    transition: all 0.3s ease;
}

.document-card:hover {
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    border-color: #3498db;
}

.document-icon {
    flex: 0 0 40px;
}
.editable-field {
    position: relative;
    padding-right: 30px;
    min-height: 30px;
}

.edit-icon {
    position: absolute;
    right: 0;
    top: 0;
    opacity: 0;
    transition: opacity 0.2s;
    cursor: pointer;
    color: #6c757d;
}

.help-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 16px;
    height: 16px;
    margin-left: 6px;
    border-radius: 50%;
    background: #e9ecef;
    color: #6c757d;
    font-size: 11px;
    font-weight: 700;
    cursor: help;
    vertical-align: middle;
}

.help-icon:hover {
    background: #dfe6ee;
    color: #495057;
}

.editable-field:hover .edit-icon {
    opacity: 1;
}

.comment-box {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 12px 14px;
}

.collateral-card {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 14px 16px;
    height: 100%;
}

.collateral-card-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 8px;
}

.collateral-card-title {
    font-weight: 600;
    color: #2c3e50;
    line-height: 1.35;
}

.collateral-details-text {
    font-size: 0.9rem;
    color: #495057;
    margin-bottom: 10px;
    word-break: break-word;
}

.collateral-details-empty {
    font-size: 0.875rem;
    color: #94a3b8;
    font-style: italic;
    margin-bottom: 10px;
}

.collateral-edit-panel {
    display: none;
    padding-top: 4px;
}

.collateral-card.is-editing .collateral-view-panel {
    display: none;
}

.collateral-card.is-editing .collateral-edit-panel {
    display: block;
}

.collateral-edit-details-wrap.is-disabled {
    opacity: 0.55;
}

.comment-empty {
    color: #6c757d;
    font-style: italic;
}

.comment-actions {
    margin-top: 8px;
}

.edit-input {
    display: none;
    width: 100%;
    margin-top: 5px;
}

.edit-buttons {
    display: none;
    margin-top: 5px;
}

/* Стили для кнопки удаления продукта */
.btn-outline-danger {
    border-color: #dc3545;
    color: #dc3545;
}

.btn-outline-danger:hover {
    background-color: #dc3545;
    color: white;
}

.btn-danger {
    background: linear-gradient(135deg, #dc3545, #c82333);
    border: none;
}

.btn-danger:hover {
    background: linear-gradient(135deg, #c82333, #bd2130);
}
/* Стили для бейджа уведомлений на вкладках */
.notification-tab-badge {
    background: linear-gradient(135deg, #dc3545, #c82333);
    border: none;
    padding: 0.25rem 0.5rem;
    font-size: 0.7rem;
    font-weight: 500;
    animation: pulse 2s infinite;
}

.notification-tab-badge i {
    font-size: 0.65rem;
}

/* Анимация пульсации для привлечения внимания */
@keyframes pulse {
    0% {
        box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.7);
    }
    70% {
        box-shadow: 0 0 0 5px rgba(220, 53, 69, 0);
    }
    100% {
        box-shadow: 0 0 0 0 rgba(220, 53, 69, 0);
    }
}

/* Для активной вкладки - убираем анимацию */
.nav-link.active .notification-tab-badge {
    animation: none;
    background: linear-gradient(135deg, #fff, #f8f9fa);
    color: #dc3545;
    border: 1px solid #dc3545;
}



/* Mobile overrides should be last to win */
@media (max-width: 768px) {
    body {
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
        font-size: 14px;
        line-height: 1.45;
        color: #0f172a;
    }

    /* --- Шапка --- */
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

    /* --- Табы --- */
    .application-tabs {
        display: flex;
        flex-wrap: nowrap !important;
        overflow-x: auto;
        white-space: nowrap;
        -webkit-overflow-scrolling: touch;
        border-bottom: 1px solid #e9ecef;
        gap: 0;
        padding: 0;
    }

    .application-tabs::-webkit-scrollbar {
        display: none;
    }

    .application-tabs::after {
        display: none;
    }

    .application-tabs .nav-item {
        flex: 0 0 auto;
    }

    .application-tabs .nav-link {
        border: none;
        border-bottom: 2px solid transparent;
        border-radius: 0;
        background: transparent;
        padding: 0.6rem 0.75rem;
        font-size: 0.82rem;
        font-weight: 500;
        line-height: 1.2;
        white-space: nowrap;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        color: #6c757d;
    }

    .application-tabs .nav-link.active {
        background: transparent;
        border-bottom-color: #3498db;
        color: #3498db;
        font-weight: 600;
    }

    .application-tabs .nav-link .bi {
        display: none;
    }

    .application-tabs .nav-link .badge {
        font-size: 0.6rem;
        padding: 0.12rem 0.35rem;
        line-height: 1;
        border-radius: 999px;
    }

    .application-tabs .nav-link .tab-label {
        display: inline;
    }

    /* --- Контент табов --- */
    .tab-content {
        background: #fff;
        border: 1px solid #e9ecef;
        border-top: none;
        border-radius: 0 0 12px 12px;
        box-shadow: 0 2px 12px rgba(0,0,0,0.04);
    }

    .tab-pane {
        padding: 0.85rem;
        font-size: 0.9rem;
    }

    /* --- Типографика --- */
    .detail-section h5,
    .tab-pane h4,
    .tab-pane h5 {
        font-size: 0.95rem;
        font-weight: 700;
    }

    .btn,
    .btn-sm {
        font-size: 0.85rem;
        font-weight: 600;
        padding: 0.4rem 0.65rem;
    }

    .mobile-compact-btn {
        font-size: 0.8rem;
        padding: 0.35rem 0.55rem;
        white-space: nowrap;
    }

    .mobile-compact-btn .btn-text-full {
        display: none;
    }

    .mobile-compact-btn .btn-text-short {
        display: inline;
    }

    /* --- Детали --- */
    .detail-row {
        display: block;
        padding: 0.55rem 0;
        border-bottom: 1px solid #eef2f7;
    }

    .detail-label {
        width: 100%;
        font-size: 0.72rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.02em;
        color: #94a3b8;
        margin-bottom: 0.2rem;
    }

    .detail-value {
        width: 100%;
        max-width: 100%;
        display: block;
        padding: 0;
        background: transparent;
        border: none;
        line-height: 1.45;
        font-size: 0.9rem;
        word-break: break-word;
        overflow-wrap: anywhere;
        white-space: normal;
    }

    .detail-value .field-value,
    .detail-value .editable-field {
        display: block;
        max-width: 100%;
    }

    .detail-value .editable-field {
        padding-right: 0;
    }

    /* Стили для кнопки "Назначить ответственного" на мобильных */
    .assign-responsible-btn {
        margin-top: 0.5rem;
        width: 100%;
        max-width: 100%;
    }

    .detail-section {
        margin-bottom: 0.85rem;
    }

    /* --- Продукты --- */
    .product-card {
        border-radius: 12px;
        margin-bottom: 0.65rem;
    }

    .product-card-header {
        padding: 0.65rem 0.85rem;
    }

    .product-card-body {
        padding: 0.85rem;
    }

    .product-card-body .btn {
        padding: 0.3rem 0.55rem;
        font-size: 0.8rem;
    }

    /* --- Документы --- */
    .document-card {
        border-radius: 12px;
        padding: 0.65rem;
    }

    .document-card .document-details {
        font-size: 0.85rem;
    }

    .document-icon {
        flex: 0 0 32px;
    }
}
</style>

<div class="row">
    <div class="col-12">
        <!-- Вкладки -->
        <ul class="nav application-tabs mb-4" id="applicationTabs" role="tablist">
            <?php if ($isAnalystView): ?>
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="info-tab" data-bs-toggle="tab" data-bs-target="#info" type="button" role="tab">
                    <i class="bi bi-info-circle me-2"></i><span class="tab-label">Информация</span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link<?= !empty($products) ? ' has-badge' : '' ?>" id="products-tab" data-bs-toggle="tab" data-bs-target="#products" type="button" role="tab">
                    <i class="bi bi-box me-2"></i><span class="tab-label">Продукты</span>
                    <?php if (!empty($products)): ?>
                        <span class="badge bg-primary ms-1"><?= count($products) ?></span>
                    <?php endif; ?>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="documents-tab" data-bs-toggle="tab" data-bs-target="#documents" type="button" role="tab">
                    <i class="bi bi-folder me-2"></i><span class="tab-label">Документы</span>
                </button>
            </li>
            <?php if ($showStructureTab): ?>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="structure-tab" data-bs-toggle="tab" data-bs-target="#structure" type="button" role="tab">
                    <i class="bi bi-diagram-3 me-2"></i><span class="tab-label">Структура</span>
                </button>
            </li>
            <?php endif; ?>
            <?php else: ?>
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="info-tab" data-bs-toggle="tab" data-bs-target="#info" type="button" role="tab">
                    <i class="bi bi-info-circle me-2"></i><span class="tab-label">Информация</span>
                </button>
            </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link<?= (!empty($products) || $totalUnreadMessages > 0) ? ' has-badge' : '' ?>" id="products-tab" data-bs-toggle="tab" data-bs-target="#products" type="button" role="tab">
        <i class="bi bi-box me-2"></i><span class="tab-label">Продукты</span>
        <?php if (!empty($products)): ?>
            <span class="badge bg-primary ms-1"><?= count($products) ?></span>
        <?php endif; ?>
        <?php if ($totalUnreadMessages > 0): ?>
            <span class="badge bg-danger ms-1 notification-tab-badge" 
                  title="Непрочитанных сообщений: <?= $totalUnreadMessages ?>">
                <i class="bi bi-chat-dots me-1"></i><?= $totalUnreadMessages ?>
            </span>
        <?php endif; ?>
    </button>
</li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="documents-tab" data-bs-toggle="tab" data-bs-target="#documents" type="button" role="tab">
                    <i class="bi bi-folder me-2"></i><span class="tab-label">Документы</span>
                </button>
            </li>
                <?php if (finbuild_is_manager($userRole)): ?>
            <li class="nav-item" role="presentation">
    <button class="nav-link has-badge" id="analytics-tab" data-bs-toggle="tab" data-bs-target="#analytics" type="button" role="tab">
        <i class="bi bi-graph-up me-2"></i><span class="tab-label">Аналитика</span>
        <span id="analytics-badge" class="badge bg-info ms-1" style="display: none;">Обновлено</span>
    </button>
</li>
            <?php if ($showStructureTab): ?>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="structure-tab" data-bs-toggle="tab" data-bs-target="#structure" type="button" role="tab">
                    <i class="bi bi-diagram-3 me-2"></i><span class="tab-label">Структура</span>
                </button>
            </li>
            <?php endif; ?>
                <?php if ($showRoadmapTab): ?>
            <li class="nav-item" role="presentation">
                <button class="nav-link has-badge" id="roadmap-tab" data-bs-toggle="tab" data-bs-target="#roadmap" type="button" role="tab">
                    <i class="bi bi-signpost-split me-2"></i><span class="tab-label">Дорожная карта</span>
                    <span id="roadmap-tab-badge" class="badge bg-secondary ms-1 d-none">0</span>
                </button>
            </li>
                <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        </ul>

        <!-- Содержимое вкладок -->
        <div class="tab-content" id="applicationTabsContent">

            <?php
            $intakeStatus = (string) ($application['intake_status'] ?? '');
            $showIntakeReview = finbuild_is_manager($userRole) && $intakeStatus === 'pending_review';
            $showIntakeApprovedControls = finbuild_is_manager($userRole) && $intakeStatus === 'approved';
            $showIntakeInfo = $intakeStatus !== '';
            $intakeAmountSummary = $showIntakeInfo ? finbuild_application_intake_amount_summary($application) : '';
            $intakeCreds = finbuild_is_manager($userRole)
                ? finbuild_intake_peek_created_client_credentials((int) $applicationId)
                : null;
            $defaultAmountMode = (($application['amount_mode'] ?? '') === 'open') ? 'open' : 'fixed';
            $intakeIsLimitOnly = ($defaultAmountMode === 'open');
            $defaultApproveValue = (string) (
                $application['requested_amount']
                ?? $application['amount']
                ?? $application['approved_amount']
                ?? $application['approved_limit']
                ?? ''
            );
            if ($intakeIsLimitOnly && $defaultApproveValue === '' && !empty($application['approved_limit'])) {
                $defaultApproveValue = (string) $application['approved_limit'];
            }
            ?>
            <?php if ($showIntakeInfo): ?>
            <div class="alert <?= $intakeStatus === 'pending_review' ? 'alert-warning' : ($intakeStatus === 'approved' ? 'alert-success' : 'alert-secondary') ?> border-0 shadow-sm mb-4">
                <div class="d-flex flex-wrap justify-content-between gap-2 align-items-start">
                    <div>
                        <strong>Заявка от заказчика</strong>
                        <?php if ($intakeStatus === 'pending_review'): ?>
                            — на рассмотрении
                        <?php elseif ($intakeStatus === 'approved'): ?>
                            — одобрена
                        <?php elseif ($intakeStatus === 'rejected'): ?>
                            — отклонена
                        <?php endif; ?>
                        <div class="small mt-1">
                            <?php if ($intakeAmountSummary !== ''): ?>
                                <?= htmlspecialchars($intakeAmountSummary) ?>
                            <?php endif; ?>
                            <?php if (!empty($application['principal_inn']) || !empty($application['principal_company_name'])): ?>
                                <br>Принципал:
                                <?= htmlspecialchars(trim((string) ($application['principal_company_name'] ?? ''))) ?>
                                <?= !empty($application['principal_inn']) ? ' (ИНН ' . htmlspecialchars((string) $application['principal_inn']) . ')' : '' ?>
                                <?= !empty($application['principal_email']) ? ' · ' . htmlspecialchars((string) $application['principal_email']) : '' ?>
                            <?php else: ?>
                                <br>Принципал ещё не указан
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($showIntakeApprovedControls): ?>
                    <div>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="intakeReopenBtn">
                            Изменить сумму / лимит
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($showIntakeReview): ?>
            <div class="card border-0 shadow-sm mb-4" id="intakeReviewCard">
                <div class="card-body">
                    <h5 class="mb-3">Одобрение запроса</h5>
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label">ИНН принципала *</label>
                            <input type="text" class="form-control" id="intakePrincipalInn" maxlength="12"
                                   value="<?= htmlspecialchars((string) ($application['principal_inn'] ?? '')) ?>">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Компания принципала *</label>
                            <input type="text" class="form-control" id="intakePrincipalCompany"
                                   value="<?= htmlspecialchars((string) ($application['principal_company_name'] ?? '')) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">E-mail принципала<?= empty($application['principal_user_id']) ? ' *' : '' ?></label>
                            <input type="email" class="form-control" id="intakeClientEmail"
                                   placeholder="<?= empty($application['principal_user_id']) ? 'Для создания клиента' : 'Уже привязан' ?>"
                                   value="<?= htmlspecialchars((string) ($application['principal_email'] ?? '')) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Телефон принципала</label>
                            <input type="text" class="form-control" id="intakeClientPhone">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" id="intakeApproveValueLabel">
                                <?= $intakeIsLimitOnly ? 'Лимит, ₽ *' : 'Запрашиваемая сумма, ₽ *' ?>
                            </label>
                            <input type="text" class="form-control" id="intakeApproveValue"
                                   value="<?= htmlspecialchars($defaultApproveValue) ?>">
                            <input type="hidden" id="intakeAmountModeFixed" value="<?= $intakeIsLimitOnly ? 'open' : 'fixed' ?>">
                        </div>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="button" class="btn btn-success btn-sm" id="intakeApproveBtn">
                            <?= $intakeIsLimitOnly ? 'Одобрить лимит' : 'Одобрить' ?>
                        </button>
                        <button type="button" class="btn btn-outline-danger btn-sm" id="intakeRejectBtn">Отклонить</button>
                    </div>
                    <div class="small text-muted mt-2" id="intakeReviewStatus"></div>
                </div>
            </div>
            <?php endif; ?>

            <?php if (finbuild_is_manager($userRole) && ($showIntakeReview || $showIntakeApprovedControls || $intakeCreds)): ?>
            <div class="modal fade" id="intakeCredentialsModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Доступ принципала</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
                        </div>
                        <div class="modal-body">
                            <p class="mb-3">Сохраните логин и пароль — после закрытия окна пароль больше не будет показан.</p>
                            <div class="mb-3">
                                <label class="form-label">Логин (e-mail)</label>
                                <div class="input-group">
                                    <input type="text" class="form-control" id="intakeCredEmail" readonly>
                                    <button type="button" class="btn btn-outline-secondary" id="intakeCopyEmailBtn">Копировать</button>
                                </div>
                            </div>
                            <div class="mb-0">
                                <label class="form-label">Пароль</label>
                                <div class="input-group">
                                    <input type="text" class="form-control" id="intakeCredPassword" readonly>
                                    <button type="button" class="btn btn-outline-secondary" id="intakeCopyPasswordBtn">Копировать</button>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" id="intakeCopyBothBtn">Копировать оба</button>
                            <button type="button" class="btn btn-primary" id="intakeCredDoneBtn">Сохранил, закрыть</button>
                        </div>
                    </div>
                </div>
            </div>
            <script>
            (function () {
                const appId = <?= (int) $applicationId ?>;
                const pendingCreds = <?= json_encode($intakeCreds, JSON_UNESCAPED_UNICODE) ?>;
                const intakeAmountMode = <?= json_encode($defaultAmountMode, JSON_UNESCAPED_UNICODE) ?>;

                function selectedMode() {
                    return intakeAmountMode === 'open' ? 'open' : 'fixed';
                }

                async function postIntake(action, extra) {
                    const statusEl = document.getElementById('intakeReviewStatus');
                    const payload = Object.assign({
                        application_id: appId,
                        action: action,
                        principal_inn: (document.getElementById('intakePrincipalInn') || {}).value || '',
                        principal_company_name: (document.getElementById('intakePrincipalCompany') || {}).value || '',
                        client_email: (document.getElementById('intakeClientEmail') || {}).value || '',
                        client_phone: (document.getElementById('intakeClientPhone') || {}).value || '',
                    }, extra || {});
                    if (statusEl) statusEl.textContent = 'Сохранение…';
                    try {
                        const res = await fetch('api_intake_review.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            credentials: 'same-origin',
                            body: JSON.stringify(payload)
                        });
                        const data = await res.json();
                        if (!data.success) {
                            if (statusEl) statusEl.textContent = data.error || 'Ошибка';
                            return null;
                        }
                        return data;
                    } catch (e) {
                        if (statusEl) statusEl.textContent = 'Сеть или сервер недоступны';
                        return null;
                    }
                }

                function showCredentialsModal(email, password) {
                    const emailEl = document.getElementById('intakeCredEmail');
                    const passEl = document.getElementById('intakeCredPassword');
                    if (emailEl) emailEl.value = email || '';
                    if (passEl) passEl.value = password || '';
                    const modalEl = document.getElementById('intakeCredentialsModal');
                    if (modalEl && window.bootstrap) {
                        bootstrap.Modal.getOrCreateInstance(modalEl).show();
                    } else {
                        alert('Логин: ' + email + '\nПароль: ' + password);
                    }
                }

                async function copyText(text) {
                    try {
                        await navigator.clipboard.writeText(text);
                        return true;
                    } catch (e) {
                        return false;
                    }
                }

                const approveBtn = document.getElementById('intakeApproveBtn');
                const rejectBtn = document.getElementById('intakeRejectBtn');
                const reopenBtn = document.getElementById('intakeReopenBtn');

                if (approveBtn) {
                    approveBtn.addEventListener('click', async function () {
                        const mode = selectedMode();
                        const val = parseFloat(String((document.getElementById('intakeApproveValue') || {}).value || '').replace(/\s/g, '').replace(',', '.'));
                        const extra = { action: 'approve', amount_mode: mode };
                        if (mode === 'open') extra.approved_limit = val;
                        else extra.approved_amount = val;
                        const data = await postIntake('approve', extra);
                        if (!data) return;
                        if (data.created_client && data.plain_password) {
                            const statusEl = document.getElementById('intakeReviewStatus');
                            if (statusEl) statusEl.textContent = 'Клиент создан. Сохраните логин и пароль.';
                            showCredentialsModal(data.client_email || ((document.getElementById('intakeClientEmail') || {}).value || ''), data.plain_password);
                            const done = document.getElementById('intakeCredDoneBtn');
                            if (done) {
                                done.onclick = async function () {
                                    await postIntake('dismiss_credentials', {});
                                    window.location.reload();
                                };
                            }
                            return;
                        }
                        window.location.reload();
                    });
                }

                if (rejectBtn) {
                    rejectBtn.addEventListener('click', async function () {
                        if (!confirm('Отклонить запрос заказчика?')) return;
                        const data = await postIntake('reject', {});
                        if (data) window.location.reload();
                    });
                }

                if (reopenBtn) {
                    reopenBtn.addEventListener('click', async function () {
                        if (!confirm('Снять одобрение, чтобы заново указать сумму или лимит?')) return;
                        const data = await postIntake('reopen', {});
                        if (data) window.location.reload();
                    });
                }

                const copyEmailBtn = document.getElementById('intakeCopyEmailBtn');
                const copyPassBtn = document.getElementById('intakeCopyPasswordBtn');
                const copyBothBtn = document.getElementById('intakeCopyBothBtn');
                if (copyEmailBtn) {
                    copyEmailBtn.addEventListener('click', async function () {
                        const v = (document.getElementById('intakeCredEmail') || {}).value || '';
                        await copyText(v);
                    });
                }
                if (copyPassBtn) {
                    copyPassBtn.addEventListener('click', async function () {
                        const v = (document.getElementById('intakeCredPassword') || {}).value || '';
                        await copyText(v);
                    });
                }
                if (copyBothBtn) {
                    copyBothBtn.addEventListener('click', async function () {
                        const email = (document.getElementById('intakeCredEmail') || {}).value || '';
                        const pass = (document.getElementById('intakeCredPassword') || {}).value || '';
                        await copyText('Логин: ' + email + '\nПароль: ' + pass);
                    });
                }

                const credDoneBtn = document.getElementById('intakeCredDoneBtn');
                if (credDoneBtn && !credDoneBtn.onclick) {
                    credDoneBtn.addEventListener('click', async function () {
                        await postIntake('dismiss_credentials', {});
                        const modalEl = document.getElementById('intakeCredentialsModal');
                        if (modalEl && window.bootstrap) {
                            bootstrap.Modal.getOrCreateInstance(modalEl).hide();
                        }
                        window.location.reload();
                    });
                }

                if (pendingCreds && pendingCreds.email && pendingCreds.password) {
                    document.addEventListener('DOMContentLoaded', function () {
                        showCredentialsModal(pendingCreds.email, pendingCreds.password);
                    });
                    if (document.readyState !== 'loading') {
                        showCredentialsModal(pendingCreds.email, pendingCreds.password);
                    }
                }
            })();
            </script>
            <?php endif; ?>
            
            <!-- Вкладка информации -->
            <div class="tab-pane fade show active" id="info" role="tabpanel">
                <div class="row">
                    <div class="col-lg-8">
                        <!-- Основная информация -->
                        <div class="detail-section">
                            <h5>Основная информация</h5>
                        <!--     <div class="detail-row">
                                <div class="detail-label">Статус заявки</div>
                                <div class="detail-value">
                                    <?php if (finbuild_is_manager($userRole)): ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="update_status" value="1">
                                           <select name="status" class="form-select status-selector" onchange="this.form.submit()">
    <option value="new" <?= $application['status'] === 'new' ? 'selected' : '' ?>>На проверке</option>
    <option value="in_progress" <?= $application['status'] === 'in_progress' ? 'selected' : '' ?>>В работе</option>
    <option value="pending_signing" <?= $application['status'] === 'pending_signing' ? 'selected' : '' ?>>На подписании</option>
    <option value="product_request" <?= $application['status'] === 'product_request' ? 'selected' : '' ?>>Запрос</option>
    <option value="terms_negotiation" <?= $application['status'] === 'terms_negotiation' ? 'selected' : '' ?>>Согласование условий</option>
    <option value="pending_release" <?= $application['status'] === 'pending_release' ? 'selected' : '' ?>>На выпуске</option>
    <option value="completed" <?= $application['status'] === 'completed' ? 'selected' : '' ?>>Завершена</option>
    <option value="failed" <?= $application['status'] === 'failed' ? 'selected' : '' ?>>Провалена</option>
</select>
                                        </form>
                                    <?php else: ?>
                                        <?= getStatusBadge($application['status']) ?>
                                    <?php endif; ?>
                                </div>
                            </div> -->
                            <div class="detail-row">
                                <div class="detail-label">Компания</div>
                                <div class="detail-value"><?= htmlspecialchars($application['company_name']) ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">ИНН</div>
                                <div class="detail-value"><?= htmlspecialchars($application['inn']) ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Тип продукта</div>
                                <div class="detail-value"><?= getProductTypeText($application['product_type']) ?></div>
                            </div>
                            <?php if ($application['product_type'] === 'bg'): ?>
                            <div class="detail-row">
                                <div class="detail-label">
                                    Срок предоставления гарантии
                                    <span class="help-icon" data-bs-toggle="tooltip" data-bs-placement="top" title="Когда крайний срок предоставления гарантии">?</span>
                                </div>
                                <div class="detail-value">
                                    <div class="editable-field" id="guarantee_provision_deadline_field" data-field="guarantee_provision_deadline">
                                        <span class="field-value"><?php
                                            $gpd = trim((string)($application['guarantee_provision_deadline'] ?? ''));
                                            echo $gpd !== '' ? htmlspecialchars($gpd) : '-';
                                        ?></span>
                                        <?php if (finbuild_is_manager($userRole)): ?>
                                        <i class="bi bi-pencil edit-icon" onclick="enableEdit('guarantee_provision_deadline')"></i>
                                        <?php endif; ?>
                                        <input type="text" class="form-control form-control-sm edit-input"
                                               id="guarantee_provision_deadline_input"
                                               value="<?= htmlspecialchars($application['guarantee_provision_deadline'] ?? '') ?>"
                                               placeholder="Точная дата или примерное описание">
                                        <div class="edit-buttons">
                                            <button type="button" class="btn btn-sm btn-success" onclick="saveField('guarantee_provision_deadline')">
                                                <i class="bi bi-check"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-secondary" onclick="cancelEdit('guarantee_provision_deadline')">
                                                <i class="bi bi-x"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            <?php if (finbuild_is_manager($userRole)): ?>
                            <div class="detail-row">
                                <div class="detail-label">Ответственный</div>
                                <div class="detail-value">
                                    <div class="editable-field" id="assigned_to_field" data-field="assigned_to">
                                        <span class="field-value"><?php
                                            if (!empty($application['assigned_to'])) {
                                                $an = trim(($application['assigned_first_name'] ?? '') . ' ' . ($application['assigned_last_name'] ?? ''));
                                                echo $an !== '' ? htmlspecialchars($an) : 'Менеджер #' . (int)$application['assigned_to'];
                                            } else {
                                                echo 'Не назначен';
                                            }
                                        ?></span>
                                        <?php if (!$isSubmanager): ?>
                                        <i class="bi bi-pencil edit-icon" onclick="enableEdit('assigned_to')" style="<?= empty($application['assigned_to']) ? 'display:none;' : '' ?>"></i>
                                        <button type="button" class="btn btn-sm btn-outline-primary ms-1 assign-responsible-btn" onclick="enableEdit('assigned_to')" style="<?= !empty($application['assigned_to']) ? 'display:none;' : '' ?>">Назначить ответственного</button>
                                        <select class="form-select form-select-sm edit-input" id="assigned_to_input" style="display:none;">
                                            <option value="">— Не назначен —</option>
                                            <?php foreach ($managersList as $m): ?>
                                                <option value="<?= (int)$m['id'] ?>" <?= (int)($application['assigned_to'] ?? 0) === (int)$m['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars(trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? ''))) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="edit-buttons" style="display:none;">
                                            <button type="button" class="btn btn-sm btn-success" onclick="saveField('assigned_to')">
                                                <i class="bi bi-check"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-secondary" onclick="cancelEdit('assigned_to')">
                                                <i class="bi bi-x"></i>
                                            </button>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        <!--     <div class="detail-row">
                                <div class="detail-label">Дата создания</div>
                                <div class="detail-value"><?= date('d.m.Y H:i', strtotime($application['created_at'])) ?></div>
                            </div> -->
                        </div>

                        <!-- Детали продукта -->
                        <div class="detail-section">
                            <h5>Детали заявки</h5>
                            <?php if ($application['product_type'] === 'bg'): ?>
                       <div class="detail-row">
                            <div class="detail-label">Вид ФЗ</div>
                            <div class="detail-value">
                                <div class="editable-field" id="fz_type_field" data-field="fz_type">
                                    <span class="field-value"><?= htmlspecialchars($application['fz_type'] ?? '-') ?></span>
                                    <?php if (finbuild_is_manager($userRole)): ?>
                                    <i class="bi bi-pencil edit-icon" onclick="enableEdit('fz_type')"></i>
                                    <?php endif; ?>
                                    <select class="form-select form-select-sm edit-input" id="fz_type_input">
                                   
                                        <option value="44-ФЗ" <?= ($application['fz_type'] ?? '') === '44-ФЗ' ? 'selected' : '' ?>>44-ФЗ</option>
                                        <option value="223-ФЗ" <?= ($application['fz_type'] ?? '') === '223-ФЗ' ? 'selected' : '' ?>>223-ФЗ</option>
                                        <option value="185-ФЗ (615-ПП)" <?= ($application['fz_type'] ?? '') === '185-ФЗ (615-ПП)' ? 'selected' : '' ?>>185-ФЗ (615-ПП)</option>
                                        <option value="Коммерция" <?= ($application['fz_type'] ?? '') === 'Коммерция' ? 'selected' : '' ?>>Коммерция</option>
                                    </select>
                                    <div class="edit-buttons">
                                        <button type="button" class="btn btn-sm btn-success" onclick="saveField('fz_type')">
                                            <i class="bi bi-check"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-secondary" onclick="cancelEdit('fz_type')">
                                            <i class="bi bi-x"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                          <div class="detail-row">
                            <div class="detail-label">Вид гарантии</div>
                            <div class="detail-value">
                                <div class="editable-field" id="guarantee_type_field" data-field="guarantee_type">
                                    <span class="field-value"><?= htmlspecialchars($application['guarantee_type'] ?? '-') ?></span>
                                    <?php if (finbuild_is_manager($userRole)): ?>
                                    <i class="bi bi-pencil edit-icon" onclick="enableEdit('guarantee_type')"></i>
                                    <?php endif; ?>
                                    <select class="form-select form-select-sm edit-input" id="guarantee_type_input">
                                 
                                        <option value="Участие" <?= ($application['guarantee_type'] ?? '') === 'Участие' ? 'selected' : '' ?>>Участие</option>
                                        <option value="Исполнение" <?= ($application['guarantee_type'] ?? '') === 'Исполнение' ? 'selected' : '' ?>>Исполнение</option>
                                        <option value="Исполнение с авансом" <?= ($application['guarantee_type'] ?? '') === 'Исполнение с авансом' ? 'selected' : '' ?>>Исполнение с авансом</option>
                                        <option value="Возврат аванса" <?= ($application['guarantee_type'] ?? '') === 'Возврат аванса' ? 'selected' : '' ?>>Возврат аванса</option>
                                        <option value="Гарантийный период" <?= ($application['guarantee_type'] ?? '') === 'Гарантийный период' ? 'selected' : '' ?>>Гарантийный период</option>
                                        <option value="Платежная" <?= ($application['guarantee_type'] ?? '') === 'Платежная' ? 'selected' : '' ?>>Платежная</option>
                                        <option value="НДС в пользу ФНС" <?= ($application['guarantee_type'] ?? '') === 'НДС в пользу ФНС' ? 'selected' : '' ?>>НДС в пользу ФНС</option>
                                    </select>
                                    <div class="edit-buttons">
                                        <button type="button" class="btn btn-sm btn-success" onclick="saveField('guarantee_type')">
                                            <i class="bi bi-check"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-secondary" onclick="cancelEdit('guarantee_type')">
                                            <i class="bi bi-x"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                               <div class="detail-row">
                                    <div class="detail-label">Сумма гарантии</div>
                                    <div class="detail-value">
                                        <div class="editable-field" id="amount_field" data-field="amount">
                                            <span class="field-value"><?= formatAmount($application['amount']) ?></span>
                                            <?php if (finbuild_is_manager($userRole)): ?>
                                            <i class="bi bi-pencil edit-icon" onclick="enableEdit('amount')"></i>
                                            <?php endif; ?>
                                            <input type="number" class="form-control form-control-sm edit-input" 
                                                   id="amount_input" 
                                                   value="<?= $application['amount'] ?>">
                                            <div class="edit-buttons">
                                                <button type="button" class="btn btn-sm btn-success" onclick="saveField('amount')">
                                                    <i class="bi bi-check"></i>
                                                </button>
                                                <button type="button" class="btn btn-sm btn-secondary" onclick="cancelEdit('amount')">
                                                    <i class="bi bi-x"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="detail-row">
                                 <div class="detail-label">Срок гарантии до</div>
        <div class="detail-value">
            <div class="editable-field" id="term_bg_field" data-field="term_bg">
                <span class="field-value">
                    <?= $application['term_bg'] ? date('d.m.Y', strtotime($application['term_bg'])) : '-' ?>
                </span>
                <?php if (finbuild_is_manager($userRole)): ?>
                <i class="bi bi-pencil edit-icon" onclick="enableEdit('term_bg')"></i>
                <?php endif; ?>
                <input type="date" class="form-control form-control-sm edit-input" 
                       id="term_bg_input" 
                       value="<?= $application['term_bg'] ?>" 
                       min="<?= date('Y-m-d') ?>">
                <div class="edit-buttons">
                    <button type="button" class="btn btn-sm btn-success" onclick="saveField('term_bg')">
                        <i class="bi bi-check"></i>
                    </button>
                    <button type="button" class="btn btn-sm btn-secondary" onclick="cancelEdit('term_bg')">
                        <i class="bi bi-x"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
                                <div class="detail-row">
    <div class="detail-label">Номер закупки</div>
    <div class="detail-value">
        <div class="editable-field" id="purchase_number_field" data-field="purchase_number">
            <span class="field-value"><?= trim((string)($application['purchase_number'] ?? '')) !== '' ? htmlspecialchars($application['purchase_number']) : '-' ?></span>
            <?php if (finbuild_is_manager($userRole)): ?>
            <i class="bi bi-pencil edit-icon" onclick="enableEdit('purchase_number')"></i>
            <div class="input-group edit-input" id="purchase_number_input_group" style="display: none;">
                <input type="text" class="form-control form-control-sm"
                       id="purchase_number_input"
                       value="<?= htmlspecialchars($application['purchase_number'] ?? '') ?>"
                       autocomplete="off">
                <button type="button" class="btn btn-outline-secondary" id="fetchPurchaseContractDetailsBtn" title="Найти данные по номеру закупки">
                    <i class="bi bi-search"></i>
                </button>
            </div>
            <div class="edit-buttons">
                <button type="button" class="btn btn-sm btn-success" onclick="saveField('purchase_number')">
                    <i class="bi bi-check"></i>
                </button>
                <button type="button" class="btn btn-sm btn-secondary" onclick="cancelEdit('purchase_number')">
                    <i class="bi bi-x"></i>
                </button>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
                                <div class="detail-row">
    <div class="detail-label">Ссылка на закупку</div>
    <div class="detail-value">
        <div class="editable-field" id="purchase_link_field" data-field="purchase_link">
            <span class="field-value"><?php
                $pl = trim((string)($application['purchase_link'] ?? ''));
                if ($pl !== '') {
                    $href = htmlspecialchars($pl, ENT_QUOTES, 'UTF-8');
                    echo '<a href="' . $href . '" target="_blank" rel="noopener noreferrer">' . htmlspecialchars($pl) . '</a>';
                } else {
                    echo '-';
                }
            ?></span>
            <?php if (finbuild_is_manager($userRole)): ?>
            <i class="bi bi-pencil edit-icon" onclick="enableEdit('purchase_link')"></i>
            <?php endif; ?>
            <input type="url" class="form-control form-control-sm edit-input"
                   id="purchase_link_input"
                   value="<?= htmlspecialchars($application['purchase_link'] ?? '') ?>"
                   placeholder="https://zakupki.gov.ru/...">
            <div class="edit-buttons">
                <button type="button" class="btn btn-sm btn-success" onclick="saveField('purchase_link')">
                    <i class="bi bi-check"></i>
                </button>
                <button type="button" class="btn btn-sm btn-secondary" onclick="cancelEdit('purchase_link')">
                    <i class="bi bi-x"></i>
                </button>
            </div>
        </div>
    </div>
</div>
                               
                                <div class="detail-row">
    <div class="detail-label">Предмет контракта</div>
    <div class="detail-value">
        <div class="editable-field" id="contract_subject_field" data-field="contract_subject">
            <span class="field-value"><?= trim((string)($application['contract_subject'] ?? '')) !== '' ? htmlspecialchars($application['contract_subject']) : '-' ?></span>
            <?php if (finbuild_is_manager($userRole)): ?>
            <i class="bi bi-pencil edit-icon" onclick="enableEdit('contract_subject')"></i>
            <?php endif; ?>
            <textarea class="form-control form-control-sm edit-input" 
                      id="contract_subject_input" 
                      rows="2"><?= htmlspecialchars($application['contract_subject'] ?? '') ?></textarea>
            <div class="edit-buttons">
                <button type="button" class="btn btn-sm btn-success" onclick="saveField('contract_subject')">
                    <i class="bi bi-check"></i>
                </button>
                <button type="button" class="btn btn-sm btn-secondary" onclick="cancelEdit('contract_subject')">
                    <i class="bi bi-x"></i>
                </button>
            </div>
        </div>
    </div>
</div>
                                <div class="detail-row">
    <div class="detail-label">Цена контракта</div>
    <div class="detail-value">
        <div class="editable-field" id="contract_price_field" data-field="contract_price">
            <span class="field-value"><?= formatAmount($application['contract_price']) ?></span>
            <?php if (finbuild_is_manager($userRole)): ?>
            <i class="bi bi-pencil edit-icon" onclick="enableEdit('contract_price')"></i>
            <?php endif; ?>
            <input type="number" class="form-control form-control-sm edit-input" 
                   id="contract_price_input" 
                   value="<?= $application['contract_price'] !== null && $application['contract_price'] !== '' ? htmlspecialchars((string)$application['contract_price']) : '' ?>">
            <div class="edit-buttons">
                <button type="button" class="btn btn-sm btn-success" onclick="saveField('contract_price')">
                    <i class="bi bi-check"></i>
                </button>
                <button type="button" class="btn btn-sm btn-secondary" onclick="cancelEdit('contract_price')">
                    <i class="bi bi-x"></i>
                </button>
            </div>
        </div>
    </div>
</div>
                    <div class="detail-row">
    <div class="detail-label">ИНН заказчика</div>
    <div class="detail-value">
        <div class="editable-field" id="customer_inn_field" data-field="customer_inn">
            <span class="field-value"><?= trim((string)($application['customer_inn'] ?? '')) !== '' ? htmlspecialchars($application['customer_inn']) : '-' ?></span>
            <?php if (finbuild_is_manager($userRole)): ?>
            <i class="bi bi-pencil edit-icon" onclick="enableEdit('customer_inn')"></i>
            <?php endif; ?>
            <input type="text" class="form-control form-control-sm edit-input" 
                   id="customer_inn_input" 
                   value="<?= htmlspecialchars($application['customer_inn'] ?? '') ?>"
                >
            <div class="edit-buttons">
                <button type="button" class="btn btn-sm btn-success" onclick="saveField('customer_inn')">
                    <i class="bi bi-check"></i>
                </button>
                <button type="button" class="btn btn-sm btn-secondary" onclick="cancelEdit('customer_inn')">
                    <i class="bi bi-x"></i>
                </button>
            </div>
        </div>
    </div>
</div>

<div class="detail-row">
    <div class="detail-label">Наименование заказчика</div>
    <div class="detail-value" id="customer_name_field">
        <span class="field-value">
            <?= !empty($application['customer_name']) ? htmlspecialchars($application['customer_name']) : '-' ?>
        </span>
    </div>
</div>
                            <?php else: ?>
                         <div class="detail-row">
    <div class="detail-label">Вид кредита</div>
    <div class="detail-value">
        <div class="editable-field" id="loan_type_field" data-field="loan_type">
            <span class="field-value"><?= htmlspecialchars($application['loan_type'] ?? '-') ?></span>
            <?php if (finbuild_is_manager($userRole)): ?>
            <i class="bi bi-pencil edit-icon" onclick="enableEdit('loan_type')"></i>
            <?php endif; ?>
            <select class="form-select form-select-sm edit-input" id="loan_type_input">
                <option value="возобновляемая кредитная линия" <?= ($application['loan_type'] ?? '') === 'возобновляемая кредитная линия' ? 'selected' : '' ?>>Возобновляемая кредитная линия</option>
                <option value="невозобновляемая кредитная линия" <?= ($application['loan_type'] ?? '') === 'невозобновляемая кредитная линия' ? 'selected' : '' ?>>Невозобновляемая кредитная линия</option>
                <option value="овердрафт" <?= ($application['loan_type'] ?? '') === 'овердрафт' ? 'selected' : '' ?>>Овердрафт</option>
                <option value="факторинг" <?= ($application['loan_type'] ?? '') === 'факторинг' ? 'selected' : '' ?>>Факторинг</option>
                <option value="другой вид" <?= ($application['loan_type'] ?? '') === 'другой вид' ? 'selected' : '' ?>>Другой вид</option>
            </select>
            <div class="edit-buttons">
                <button type="button" class="btn btn-sm btn-success" onclick="saveField('loan_type')">
                    <i class="bi bi-check"></i>
                </button>
                <button type="button" class="btn btn-sm btn-secondary" onclick="cancelEdit('loan_type')">
                    <i class="bi bi-x"></i>
                </button>
            </div>
        </div>
    </div>
</div>
                               <div class="detail-row">
    <div class="detail-label">Сумма кредита</div>
    <div class="detail-value">
        <div class="editable-field" id="amount_field" data-field="amount">
            <span class="field-value"><?= formatAmount($application['amount']) ?></span>
            <?php if (finbuild_is_manager($userRole)): ?>
            <i class="bi bi-pencil edit-icon" onclick="enableEdit('amount')"></i>
            <?php endif; ?>
            <input type="number" class="form-control form-control-sm edit-input" 
                   id="amount_input" 
                   value="<?= $application['amount'] ?>">
            <div class="edit-buttons">
                <button type="button" class="btn btn-sm btn-success" onclick="saveField('amount')">
                    <i class="bi bi-check"></i>
                </button>
                <button type="button" class="btn btn-sm btn-secondary" onclick="cancelEdit('amount')">
                    <i class="bi bi-x"></i>
                </button>
            </div>
        </div>
    </div>
</div>
                                <div class="detail-row">
    <div class="detail-label">Срок кредита</div>
    <div class="detail-value">
        <div class="editable-field" id="term_field" data-field="term">
            <span class="field-value"><?= $application['term'] ? $application['term'] . ' мес.' : '-' ?></span>
            <?php if (finbuild_is_manager($userRole)): ?>
            <i class="bi bi-pencil edit-icon" onclick="enableEdit('term')"></i>
            <?php endif; ?>
            <input type="number" class="form-control form-control-sm edit-input" 
                   id="term_input" 
                   value="<?= $application['term'] ?>">
            <div class="edit-buttons">
                <button type="button" class="btn btn-sm btn-success" onclick="saveField('term')">
                    <i class="bi bi-check"></i>
                </button>
                <button type="button" class="btn btn-sm btn-secondary" onclick="cancelEdit('term')">
                    <i class="bi bi-x"></i>
                </button>
            </div>
        </div>
    </div>
</div>
                            <?php endif; ?>
                            <?php if ($application['product_type'] === 'bg'): ?>
                            <div class="detail-row">
    <div class="detail-label">В каких банках были отказы</div>
    <div class="detail-value">
        <div class="editable-field" id="declined_banks_field" data-field="declined_banks">
            <span class="field-value"><?php
                $db = trim((string)($application['declined_banks'] ?? ''));
                echo $db !== '' ? nl2br(htmlspecialchars($db)) : '-';
            ?></span>
            <?php if (finbuild_is_manager($userRole)): ?>
            <i class="bi bi-pencil edit-icon" onclick="enableEdit('declined_banks')"></i>
            <?php endif; ?>
            <textarea class="form-control form-control-sm edit-input" 
                      id="declined_banks_input" 
                      rows="2"><?= htmlspecialchars($application['declined_banks'] ?? '') ?></textarea>
            <div class="edit-buttons">
                <button type="button" class="btn btn-sm btn-success" onclick="saveField('declined_banks')">
                    <i class="bi bi-check"></i>
                </button>
                <button type="button" class="btn btn-sm btn-secondary" onclick="cancelEdit('declined_banks')">
                    <i class="bi bi-x"></i>
                </button>
            </div>
        </div>
    </div>
</div>
                            <div class="detail-row">
                                <div class="detail-label">
                                    Продление
                                    <span class="help-icon" data-bs-toggle="tooltip" data-bs-placement="top" title="Продление ранее полученной гарантии.">?</span>
                                </div>
                                <div class="detail-value">
                                    <div class="editable-field" id="is_extension_field" data-field="is_extension">
                                        <span class="field-value"><?= !empty($application['is_extension']) ? 'Да' : 'Нет' ?></span>
                                        <?php if (finbuild_is_manager($userRole)): ?>
                                        <i class="bi bi-pencil edit-icon" onclick="enableEdit('is_extension')"></i>
                                        <?php endif; ?>
                                        <select class="form-select form-select-sm edit-input" id="is_extension_input">
                                            <option value="1" <?= !empty($application['is_extension']) ? 'selected' : '' ?>>Да</option>
                                            <option value="0" <?= empty($application['is_extension']) ? 'selected' : '' ?>>Нет</option>
                                        </select>
                                        <div class="edit-buttons">
                                            <button type="button" class="btn btn-sm btn-success" onclick="saveField('is_extension')">
                                                <i class="bi bi-check"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-secondary" onclick="cancelEdit('is_extension')">
                                                <i class="bi bi-x"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">
                                    Переобеспечение
                                    <span class="help-icon" data-bs-toggle="tooltip" data-bs-placement="top" title="Переобеспечение банковской гарантии другого банка или замена обеспечения денежными средствами на банковскую гарантию.">?</span>
                                </div>
                                <div class="detail-value">
                                    <div class="editable-field" id="is_replacement_field" data-field="is_replacement">
                                        <span class="field-value"><?= !empty($application['is_replacement']) ? 'Да' : 'Нет' ?></span>
                                        <?php if (finbuild_is_manager($userRole)): ?>
                                        <i class="bi bi-pencil edit-icon" onclick="enableEdit('is_replacement')"></i>
                                        <?php endif; ?>
                                        <select class="form-select form-select-sm edit-input" id="is_replacement_input">
                                            <option value="1" <?= !empty($application['is_replacement']) ? 'selected' : '' ?>>Да</option>
                                            <option value="0" <?= empty($application['is_replacement']) ? 'selected' : '' ?>>Нет</option>
                                        </select>
                                        <div class="edit-buttons">
                                            <button type="button" class="btn btn-sm btn-success" onclick="saveField('is_replacement')">
                                                <i class="bi bi-check"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-secondary" onclick="cancelEdit('is_replacement')">
                                                <i class="bi bi-x"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($application['product_type'] === 'bg'): ?>
                        <div class="detail-section" id="collateralDetailSection">
                            <h5>Обеспечение</h5>
                            <?php
                            $collateralItems = [
                                ['item' => 'transport', 'prefix' => 'collateral_transport', 'label' => 'Обеспечение транспортом'],
                                ['item' => 'real_estate', 'prefix' => 'collateral_real_estate', 'label' => 'Обеспечение недвижимостью'],
                                ['item' => 'deposit_note', 'prefix' => 'collateral_deposit_note', 'label' => 'Обеспечение депозит/вексель'],
                                ['item' => 'third_party', 'prefix' => 'collateral_third_party_guarantee', 'label' => 'Поручительство третьих юр. лиц'],
                            ];
                            $renderCollateralCard = static function (array $c, array $application, string $userRole): void {
                                $enField = $c['prefix'] . '_enabled';
                                $dtField = $c['prefix'] . '_details';
                                $enabled = !empty($application[$enField]);
                                $details = trim((string)($application[$dtField] ?? ''));
                                $itemId = htmlspecialchars($c['item'], ENT_QUOTES, 'UTF-8');
                                ?>
                                <div class="col-md-6 mb-3">
                                    <div class="collateral-card" id="collateral_item_<?= $itemId ?>"
                                         data-item="<?= $itemId ?>"
                                         data-enabled-field="<?= htmlspecialchars($enField, ENT_QUOTES, 'UTF-8') ?>"
                                         data-details-field="<?= htmlspecialchars($dtField, ENT_QUOTES, 'UTF-8') ?>"
                                         data-enabled="<?= $enabled ? '1' : '0' ?>"
                                         data-details="<?= htmlspecialchars($details, ENT_QUOTES, 'UTF-8') ?>">
                                        <div class="collateral-view-panel">
                                            <div class="collateral-card-header">
                                                <div class="collateral-card-title"><?= htmlspecialchars($c['label']) ?></div>
                                                <span class="badge collateral-status-badge <?= $enabled ? 'bg-success' : 'bg-secondary' ?>">
                                                    <?= $enabled ? 'Да' : 'Нет' ?>
                                                </span>
                                            </div>
                                            <div class="collateral-details-display">
                                                <?php if ($enabled && $details !== ''): ?>
                                                    <div class="collateral-details-text"><?= nl2br(htmlspecialchars($details)) ?></div>
                                                <?php elseif ($enabled): ?>
                                                    <div class="collateral-details-empty">Детали не указаны</div>
                                                <?php else: ?>
                                                    <div class="collateral-details-empty">Не применяется</div>
                                                <?php endif; ?>
                                            </div>
                                            <?php if (finbuild_is_manager($userRole)): ?>
                                            <button type="button" class="btn btn-sm btn-outline-primary collateral-edit-btn"
                                                    onclick="enableCollateralEdit('<?= $itemId ?>')">
                                                <i class="bi bi-pencil me-1"></i>Изменить
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (finbuild_is_manager($userRole)): ?>
                                        <div class="collateral-edit-panel">
                                            <div class="form-check mb-2">
                                                <input class="form-check-input collateral-edit-enabled" type="checkbox"
                                                       id="collateral_edit_<?= $itemId ?>_enabled"
                                                       <?= $enabled ? 'checked' : '' ?>
                                                       onchange="toggleCollateralDetailsInput('<?= $itemId ?>')">
                                                <label class="form-check-label" for="collateral_edit_<?= $itemId ?>_enabled">Используется</label>
                                            </div>
                                            <div class="collateral-edit-details-wrap mb-2 <?= $enabled ? '' : 'is-disabled' ?>">
                                                <label class="form-label small text-muted mb-1" for="collateral_edit_<?= $itemId ?>_details">Детали</label>
                                                <input type="text" class="form-control form-control-sm collateral-edit-details"
                                                       id="collateral_edit_<?= $itemId ?>_details"
                                                       value="<?= htmlspecialchars($details) ?>"
                                                       placeholder="Опишите детали"
                                                       <?= $enabled ? '' : 'disabled' ?>>
                                            </div>
                                            <div class="d-flex flex-wrap gap-2">
                                                <button type="button" class="btn btn-sm btn-success collateral-save-btn"
                                                        onclick="saveCollateralItem('<?= $itemId ?>')">
                                                    <i class="bi bi-check-lg me-1"></i>Сохранить
                                                </button>
                                                <button type="button" class="btn btn-sm btn-outline-secondary"
                                                        onclick="cancelCollateralEdit('<?= $itemId ?>')">Отмена</button>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php
                            };
                            ?>
                            <div class="row">
                                <?php foreach ($collateralItems as $c) {
                                    $renderCollateralCard($c, $application, $userRole);
                                } ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="detail-section">
                            <h5>Комментарий</h5>
                            <div class="comment-box">
                                <div class="editable-field" id="comment_field" data-field="comment">
                                    <span class="field-value <?= empty($application['comment']) ? 'comment-empty' : '' ?>">
                                        <?= empty($application['comment']) ? 'Комментарий не добавлен' : nl2br(htmlspecialchars($application['comment'])) ?>
                                    </span>
                                    <?php if (finbuild_is_manager($userRole)): ?>
                                    <i class="bi bi-pencil edit-icon" onclick="enableEdit('comment')"></i>
                                    <?php endif; ?>
                                    <textarea class="form-control edit-input" id="comment_input" rows="3"><?= htmlspecialchars($application['comment'] ?? '') ?></textarea>
                                    <div class="edit-buttons">
                                        <button type="button" class="btn btn-sm btn-success" onclick="saveField('comment')">
                                            <i class="bi bi-check"></i> Сохранить
                                        </button>
                                        <button type="button" class="btn btn-sm btn-secondary" onclick="cancelEdit('comment')">
                                            <i class="bi bi-x"></i> Отмена
                                        </button>
                                    </div>
                                </div>
                                <?php if (finbuild_is_manager($userRole) && empty($application['comment'])): ?>
                                <div class="comment-actions">
                                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="enableEdit('comment')">
                                        <i class="bi bi-plus-circle me-1"></i> Добавить комментарий
                                    </button>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if (!$isAnalystView && !$isSubmanager): ?>
                        <!-- Контактная информация -->
                        <div class="detail-section">
                            <h5>Контактная информация</h5>
                            <div class="detail-row">
                                <div class="detail-label">Контактное лицо</div>
                                <div class="detail-value">
                                    <?= htmlspecialchars($application['contact_name'] ?? $application['first_name'] . ' ' . $application['last_name']) ?>
                                </div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Телефон</div>
                                <div class="detail-value"><?= htmlspecialchars($application['contact_phone'] ?? $application['phone'] ?? '-') ?></div>
                            </div>
                            <?php if (finbuild_is_manager($userRole)): ?>
                            <div class="detail-row">
                                <div class="detail-label">Чья заявка</div>
                                <div class="detail-value">
                                    <?php
                                    $roleNames = [
                                        'manager' => 'Менеджер',
                                        'partner' => 'Партнер',
                                        'client' => 'Клиент',
                                        'beneficiary' => 'Заказчик',
                                    ];
                                    $ownerRole = $roleNames[$application['user_role'] ?? 'client'] ?? 'Клиент';
                                    $ownerName = trim(($application['first_name'] ?? '') . ' ' . ($application['last_name'] ?? ''));
                                    $ownerCompany = trim($application['user_company'] ?? '');
                                    ?>
                                    <span class="text-muted"><?= htmlspecialchars($ownerRole) ?></span> —
                                    <?= htmlspecialchars($ownerName ?: '—') ?>
                                    <?php if ($ownerCompany): ?>
                                        (<?= htmlspecialchars($ownerCompany) ?>)
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Кем создана</div>
                                <div class="detail-value">
                                    <?php if (!empty($application['added_by'])): ?>
                                        <?= htmlspecialchars(($application['manager_name'] ?? '') . ' ' . ($application['manager_surname'] ?? '')) ?>
                                    <?php else: ?>
                                        <?= htmlspecialchars($application['first_name'] . ' ' . $application['last_name']) ?>
                                        <?php if ($application['user_company']): ?>
                                            <br><small class="text-muted"><?= htmlspecialchars($application['user_company']) ?></small>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                    </div>
                    
                    <div class="col-lg-4">
                        <!-- Быстрые действия для менеджеров -->
                        <?php if (finbuild_is_manager($userRole)): ?>
                        <div class="application-detail-card">
                            <h5 class="mb-3">Действия</h5>
                            <div class="d-grid gap-2">
                                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addProductModal">
                                    <i class="bi bi-plus-circle me-2"></i>Добавить продукт
                                </button>
                               <!--  <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#contactClientModal">
                                    <i class="bi bi-chat-dots me-2"></i>Написать клиенту
                                </button> -->
                                <?php if ($application['status'] === 'new'): ?>
                                <button type="button" class="btn btn-success" onclick="startApplicationProcessing(<?= $application['id'] ?>)">
                                    <i class="bi bi-play-circle me-2"></i>Начать обработку
                                </button>
                                <?php endif; ?>
                                <?php if (!empty($products) && !in_array($application['status'] ?? '', ['failed', 'completed'], true)): ?>
                                <div class="border rounded p-2 bg-light">
                                    <p class="small text-muted mb-2 mb-0">
                                        Все продукты заявки изменят статус на «Отказано».
                                    </p>
                                    <button type="button" class="btn btn-outline-danger w-100" onclick="markApplicationAsFailed(<?= (int)$applicationId ?>)">
                                        <i class="bi bi-x-octagon me-2"></i>Перенести в проваленные
                                    </button>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                            <!-- В разделе быстрых действий для менеджеров -->
<br>
<div class="application-detail-card">
    <h5 class="mb-3">Документы</h5>
    <div class="d-grid gap-2">
        <a href="application_documents.php?id=<?= $applicationId ?>" class="btn btn-outline-primary">
            <i class="bi bi-folder me-2"></i>Просмотреть документы
        </a>
    </div>
</div>
<br>
                        <!-- Статистика продуктов -->
                        <div class="application-detail-card">
                            <h5 class="mb-3">Статистика продуктов</h5>
                            <div class="row text-center">
                                <div class="col-4">
                                    <div class="fw-bold text-primary fs-4"><?= count($products) ?></div>
                                    <small class="text-muted">Всего</small>
                                </div>
                                <div class="col-4">
                                    <div class="fw-bold text-warning fs-4">
                                        <?= count(array_filter($products, function($p) { return in_array($p['status'], ['В работе', 'На подписании', 'Запрос', 'Согласование условий', 'На выпуске']); })) ?>
                                    </div>
                                    <small class="text-muted">Активные</small>
                                </div>
                                <div class="col-4">
                                    <div class="fw-bold text-success fs-4">
                                        <?= count(array_filter($products, function($p) { return in_array($p['status'], ['БГ выпущена', 'Выдан']); })) ?>
                                    </div>
                                    <small class="text-muted">Успешные</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

           <div class="tab-pane fade" id="products" role="tabpanel">
    
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
        <div>
            <h4 class="mb-0">Продукты</h4>
            <?php if ($isAnalystView): ?>
                <p class="text-muted small mb-0 mt-1">Список для просмотра — переход в карточку продукта недоступен.</p>
            <?php endif; ?>
        </div>
        <?php if (!empty($products) && finbuild_is_manager($userRole)): ?>
            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addProductModal">
                <i class="bi bi-plus-lg me-2"></i>Добавить продукт
            </button>
        <?php endif; ?>
    </div>

    <?php if (empty($products)): ?>
        <!-- Пустое состояние (оставляем почти как у вас, только немного красивее) -->
        <div class="empty-state text-center py-5 border rounded bg-light">
            <i class="bi bi-box display-1 text-muted opacity-25"></i>
            <h5 class="mt-3">Продукты не добавлены</h5>
            <p class="text-muted small">К этой заявке еще не добавлены банковские продукты</p>
            <?php if (finbuild_is_manager($userRole)): ?>
                <button type="button" class="btn btn-primary mt-3 text-nowrap" data-bs-toggle="modal" data-bs-target="#addProductModal" style="padding: 0.5rem 0.9rem; font-size: 0.95rem;">
                    <i class="bi bi-plus-circle me-2" style="font-size: 0.95rem;"></i>Добавить первый продукт
                </button>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <!-- Карточки продуктов (мобильная версия) -->
        <div class="d-md-none">
            <?php foreach ($products as $product): ?>
                <div class="product-card<?= $productNavEnabled ? '' : ' product-card--readonly' ?>"<?= $productNavEnabled ? ' role="button" onclick="if(!event.target.closest(\'.delete-product-btn\')) window.location=\'product_details.php?id=' . (int)$product['id'] . '\'"' : '' ?>>
                    <div class="product-card-header d-flex align-items-center justify-content-between">
                        <div class="d-flex align-items-center flex-grow-1 me-2 min-w-0">
                            <?= renderApplicationProductBankVisual($product) ?>
                            <div class="min-w-0">
                                <div class="fw-bold text-dark mb-0">
                                <?= htmlspecialchars($product['bank_name'] ?? 'Банк не указан') ?>
                                <?php if (($unreadCounts[$product['id']] ?? 0) > 0): ?>
                                    <span class="badge bg-danger rounded-pill ms-2" style="font-size: 0.6em; vertical-align: middle;">
                                        +<?= $unreadCounts[$product['id']] ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="small text-muted">
                                <?= htmlspecialchars($product['product_name'] ?? 'Продукт') ?>
                            </div>
                            </div>
                        </div>
                        <?= getProductStatusBadge($product['status'], $product['product_type']) ?>
                    </div>
                    <div class="product-card-body">
                        <div class="d-flex justify-content-between small text-muted">
                            <span>Добавлен</span>
                            <span><?= date('d.m.Y', strtotime($product['created_at'])) ?></span>
                        </div>
                        <div class="mt-2 small">
                            <div class="text-muted">Примечание</div>
                            <div class="text-dark">
                                <?= !empty($product['notes']) ? nl2br(htmlspecialchars($product['notes'])) : '—' ?>
                            </div>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mt-2">
                            <div class="text-muted small"></div>
                            <?php if ($productNavEnabled): ?>
                            <div class="d-flex align-items-center gap-2">
                                <a href="product_details.php?id=<?= $product['id'] ?>" class="btn btn-sm btn-outline-primary">
                                    Детали
                                </a>
                                <?php if (finbuild_is_manager($userRole)): ?>
                                    <button type="button" class="btn btn-sm btn-outline-danger delete-product-btn"
                                            data-product-id="<?= $product['id'] ?>"
                                            data-product-name="<?= htmlspecialchars($product['product_name']) ?>"
                                            title="Удалить продукт"
                                            onclick="event.stopPropagation()">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Таблица продуктов (десктоп) -->
        <div class="card shadow-sm border-0 overflow-hidden d-none d-md-block">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" style="border-collapse: separate; border-spacing: 0; ">
                    <thead class="bg-light border-0" >
                        <tr style="border-bottom:3px gray !important;">
                            <th class="ps-4 py-3 text-secondary text-uppercase small fw-bold" >Банк / Продукт</th>
                            <th class="py-3 text-secondary text-uppercase small fw-bold" >Добавлен</th>
                            <th class="py-3 text-secondary text-uppercase small fw-bold"  >Статус</th>
                            <th class="py-3 text-secondary text-uppercase small fw-bold" >Примечание</th>
                            <?php if ($productNavEnabled): ?>
                            <th class="pe-4 py-3 text-end text-secondary text-uppercase small fw-bold" >Действия</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $product): ?>
                            <!-- Добавляем кликабельность на всю строку -->
                            <tr class="position-relative bg-white"<?= $productNavEnabled ? ' role="button" onclick="if(!event.target.closest(\'.delete-product-btn\')) window.location=\'product_details.php?id=' . (int)$product['id'] . '\'"' : '' ?>>
                                
                                <!-- Банк и Название -->
                                <td class="ps-4 py-3">
                                    <div class="d-flex align-items-center">
                                        <?= renderApplicationProductBankVisual($product) ?>
                                        <div>
                                            <div class="fw-bold text-dark mb-0">
                                                <?= htmlspecialchars($product['bank_name'] ?? 'Банк не указан') ?>
                                                
                                                <!-- Бейдж непрочитанных сообщений -->
                                                <?php if (($unreadCounts[$product['id']] ?? 0) > 0): ?>
                                                    <span class="badge bg-danger rounded-pill ms-2" style="font-size: 0.6em; vertical-align: middle;">
                                                        +<?= $unreadCounts[$product['id']] ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="small text-muted">
                                                <?= htmlspecialchars($product['product_name'] ?? 'Продукт') ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>

                                <!-- Дата создания -->
                                <td class="py-3">
                                    <small class="text-muted">
                                        <?= date('d.m.Y', strtotime($product['created_at'])) ?>
                                    </small>
                                </td>

                                <!-- Статус -->
                                <td class="py-3">
                                    <?= getProductStatusBadge($product['status'], $product['product_type']) ?>
                                </td>

                                <!-- Примечание -->
                                <td class="py-3">
                                    <span class="text-muted small">
                                        <?= !empty($product['notes']) ? nl2br(htmlspecialchars($product['notes'])) : '—' ?>
                                    </span>
                                </td>

                                <!-- Кнопки действий -->
                                <?php if ($productNavEnabled): ?>
                                <td class="pe-4 py-3 text-end">
                                    <div class="btn-group">
                                        <a href="product_details.php?id=<?= $product['id'] ?>" class="btn btn-sm btn-outline-primary rounded-pill px-3 me-2">
                                            Детали
                                        </a>

                                        <?php if (finbuild_is_manager($userRole)): ?>
                                            <button type="button" class="btn btn-sm btn-outline-danger btn-icon rounded-circle delete-product-btn" 
                                                    style="width: 32px; height: 32px; padding: 0;"
                                                    data-product-id="<?= $product['id'] ?>"
                                                    data-product-name="<?= htmlspecialchars($product['product_name']) ?>"
                                                    title="Удалить продукт"
                                                    onclick="event.stopPropagation()">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>


<!-- Вкладка документов -->
<div class="tab-pane fade" id="documents" role="tabpanel">
    <?php
    // Получаем документы заявки
    $stmtDocs = $pdo->prepare("
        SELECT d.*, u.first_name as uploader_first_name, u.last_name as uploader_last_name
        FROM application_documents d
        LEFT JOIN users u ON d.uploaded_by = u.id
        WHERE d.application_id = ?
        ORDER BY d.created_at DESC
    ");
    $stmtDocs->execute([$applicationId]);
    $documents = $stmtDocs->fetchAll();
    
    // Группируем документы по типам
    $groupedDocuments = [];
    foreach ($documents as $doc) {
        $groupedDocuments[$doc['document_type']][] = $doc;
    }
    
    // Функции для документов
    function getFileIcon($fileType) {
        $icons = [
            'pdf' => 'bi-file-earmark-pdf text-danger',
            'word' => 'bi-file-earmark-word text-primary',
            'excel' => 'bi-file-earmark-spreadsheet text-success',
            'image' => 'bi-file-earmark-image text-warning',
            'archive' => 'bi-file-earmark-zip text-secondary',
            'file' => 'bi-file-earmark text-secondary'
        ];
        return $icons[$fileType] ?? 'bi-file-earmark text-secondary';
    }
    
  
    ?>
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4>Документы заявки</h4>
        <div class="d-flex gap-2">
            <?php if (!empty($documents)): ?>
                <a class="btn btn-outline-primary mobile-compact-btn"
                   href="api_download_application_documents.php?application_id=<?= (int)$applicationId ?>"
                   title="Скачать все документы архивом">
                    <i class="bi bi-file-zip me-2"></i>
                    <span class="btn-text-full">Скачать все</span>
                    <span class="btn-text-short">ZIP</span>
                </a>
            <?php endif; ?>
            <button type="button" class="btn btn-primary mobile-compact-btn" data-bs-toggle="modal" data-bs-target="#uploadDocumentModal">
                <i class="bi bi-cloud-upload me-2"></i>
                <span class="btn-text-full">Загрузить документ</span>
                <span class="btn-text-short">Загрузить</span>
            </button>
        </div>
    </div>
    
    <?php if (empty($groupedDocuments)): ?>
        <div class="empty-state">
            <i class="bi bi-folder"></i>
            <h5>Документы не загружены</h5>
            <p class="text-muted">К этой заявке еще не прикреплены документы</p>
            <button type="button" class="btn btn-primary mt-3 mobile-compact-btn" data-bs-toggle="modal" data-bs-target="#uploadDocumentModal">
                <i class="bi bi-cloud-upload me-2"></i>
                <span class="btn-text-full">Загрузить первый документ</span>
                <span class="btn-text-short">Загрузить</span>
            </button>
        </div>
    <?php else: ?>
        <?php foreach ($groupedDocuments as $documentType => $docs): ?>
            <div class="document-section mb-4">
                <h5 class="text-primary mb-3">
                    <?= htmlspecialchars($documentType) ?>
                    <span class="badge bg-primary ms-2"><?= count($docs) ?></span>
                </h5>
                
                <div class="row">
                    <?php foreach ($docs as $doc): ?>
                        <div class="col-lg-6 mb-3">
                            <div class="document-card">
                                <div class="d-flex align-items-center">
                                    <div class="document-icon me-3">
                                        <i class="bi <?= getFileIcon($doc['file_type']) ?> fs-2"></i>
                                    </div>
                                    <div class="document-info flex-grow-1">
                                        <h6 class="mb-1"><?= htmlspecialchars($doc['original_name']) ?></h6>
                                        <div class="text-muted small">
                                            <?= formatFileSize($doc['file_size']) ?> • 
                                            <?= date('d.m.Y H:i', strtotime($doc['created_at'])) ?>
                                            <?php if (!$isAnalystView && !$isSubmanager && $doc['uploader_first_name']): ?>
                                                • <?= htmlspecialchars($doc['uploader_first_name'] . ' ' . $doc['uploader_last_name']) ?>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($doc['description']): ?>
                                            <div class="mt-1">
                                                <small class="text-muted"><?= htmlspecialchars($doc['description']) ?></small>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="document-actions">
                                        <a href="<?= htmlspecialchars(finbuild_upload_file_url('app_doc', (int) $doc['id'])) ?>" class="btn btn-outline-primary btn-sm" target="_blank" download="<?= htmlspecialchars($doc['original_name']) ?>">
                                            <i class="bi bi-download"></i>
                                        </a>
                                        <a href="<?= htmlspecialchars(finbuild_upload_file_url('app_doc', (int) $doc['id'])) ?>" class="btn btn-outline-secondary btn-sm" target="_blank">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <?php if (finbuild_is_manager($userRole)): ?>
                                        <button type="button" class="btn btn-outline-danger btn-sm" 
                                                onclick="deleteDocument(<?= $doc['id'] ?>)">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
    
    <!-- Кнопка для загрузки дополнительных документов -->
  <!--   <div class="text-center mt-4">
        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#uploadDocumentModal">
            <i class="bi bi-plus-circle me-2"></i>Загрузить дополнительные документы
        </button>
    </div> -->
</div>

<!-- Вкладка аналитики -->
<?php if (finbuild_is_manager($userRole)): ?>
<div class="tab-pane fade" id="analytics" role="tabpanel">
    <?php require __DIR__ . '/includes/partials/company_analytics_tab.php'; ?>
</div>
<?php endif; ?>

<?php if ($showStructureTab && (finbuild_is_manager($userRole) || $isAnalystView)): ?>
<!-- Вкладка «Структура» -->
<div class="tab-pane fade" id="structure" role="tabpanel">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-4">
        <div>
            <h4 class="mb-1">Структура принципала</h4>
            <p class="text-muted small mb-0">Преимущества, стоп-факторы и примечания по компании</p>
        </div>
    </div>
    <?php
    $structureApplicationId = (int) $applicationId;
    $structureProtectBankItems = true;
    // $structureAjaxUrl задан выше (application_details.php?id=…)
    require __DIR__ . '/includes/partials/application_structure_block.php';
    ?>
</div>
<?php endif; ?>

<?php if ($showRoadmapTab): ?>
<!-- Вкладка «Дорожная карта» (только руководитель) -->
<div class="tab-pane fade" id="roadmap" role="tabpanel">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-4">
        <div>
            <h4 class="mb-1">Дорожная карта</h4>
            <p class="text-muted small mb-0">Чек-листы по заявке: отправка в банки, задачи команды.</p>
        </div>
    </div>
    <?php
    $roadmapApplicationId = (int) $applicationId;
    require __DIR__ . '/includes/partials/application_roadmap_block.php';
    ?>
</div>
<?php endif; ?>

        </div><!-- #applicationTabsContent -->

<?php if (!$isAnalystView): ?>
<!-- Модальное окно загрузки документа -->
<div class="modal fade" id="uploadDocumentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Загрузка документа</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="uploadDocumentForm" enctype="multipart/form-data">
                <input type="hidden" name="application_id" value="<?= $applicationId ?>">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Тип документа</label>
                        <select class="form-select" name="document_type" required>
                            <option value="">Выберите тип документа</option>
                            <option value="Карточка организации">Карточка организации</option>
                            <option value="Бухгалтерская отчетность (Ф1 + Ф2)">Бухгалтерская отчетность (Ф1 + Ф2)</option>
                            <option value="Скан-копия паспорта учредителя">Скан-копия паспорта учредителя</option>
                            <option value="Скан-копия паспорта директора">Скан-копия паспорта директора</option>
                            <option value="Договор аренды">Договор аренды</option>
                            <option value="Устав">Устав</option>
                            <option value="Решение о назначении/продлении полномочий директора">Решение о назначении/продлении полномочий директора</option>
                            <option value="Налоговая декларация на прибыль">Налоговая декларация на прибыль</option>
                            <option value="Налоговая декларация НДС">Налоговая декларация НДС</option>
                            <option value="Прочие документы">Прочие документы</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Описание (необязательно)</label>
                        <textarea class="form-control" name="description" rows="2" placeholder="Краткое описание документа"></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Файл</label>
                        <input type="file" class="form-control" name="document_file" required 
                               accept="<?= htmlspecialchars(finbuild_application_document_accept_attribute(), ENT_QUOTES, 'UTF-8') ?>">
                        <div class="form-text">Поддерживаемые форматы: <?= htmlspecialchars(finbuild_application_document_upload_hint(), ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-cloud-upload me-2"></i>Загрузить
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Chat widget (drawer) -->
<?php if (!$isAnalystView && $canUseProductChat): ?>
<div class="app-chat-overlay" id="appChatOverlay"></div>

<div class="app-chat-drawer" id="appChatDrawer" aria-hidden="true">
    <div class="app-chat-drawer-header">
        <h6 class="app-chat-drawer-title">
            <i class="bi bi-chat-dots"></i> Чаты
        </h6>
        <div class="d-flex align-items-center gap-2">
            <?php if (finbuild_is_manager($userRole) && count($chatAllowedThreads) > 1): ?>
                <div class="btn-group btn-group-sm" role="group" id="appChatThreadSwitch">
                    <?php if (in_array('beneficiary', $chatAllowedThreads, true)): ?>
                        <button type="button" class="btn btn-outline-secondary <?= $chatDefaultThread === 'beneficiary' ? 'active' : '' ?>" data-thread="beneficiary">Заказчик</button>
                    <?php endif; ?>
                    <?php if (in_array('principal', $chatAllowedThreads, true)): ?>
                        <button type="button" class="btn btn-outline-secondary <?= $chatDefaultThread === 'principal' ? 'active' : '' ?>" data-thread="principal">Клиент</button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <button type="button" class="btn btn-sm btn-light" id="appChatClose" title="Закрыть">
            <i class="bi bi-x-lg"></i>
        </button>
        </div>
    </div>

    <div class="app-chat-products-tabs<?= empty($showProductChatTabs) || $chatDefaultThread === 'beneficiary' ? ' is-single' : '' ?>" id="appChatProductTabs">
        <button type="button"
                class="app-chat-product-tab active"
                data-chat="application"
                title="Общий чат">
            <span class="text-truncate" style="max-width: 220px;">Общий чат</span>
            <span class="app-chat-product-badge" data-badge-chat="application" style="<?= ($appChatUnreadCurrent ?? 0) > 0 ? '' : 'display:none;' ?>"><?= (int) ($appChatUnreadCurrent ?? 0) ?></span>
        </button>
        <?php if (!empty($viewerHasProductChats)): ?>
        <?php foreach ($products as $p): ?>
            <?php
                $pid = (int)$p['id'];
                $tabName = trim((string)($p['bank_name'] ?? ''));
                $tabLabel = $tabName !== '' ? $tabName : ('Продукт #' . $pid);
                $cnt = (int)($unreadCounts[$pid] ?? 0);
            ?>
            <button type="button"
                    class="app-chat-product-tab"
                    data-chat="product"
                    data-product-id="<?= $pid ?>"
                    title="<?= htmlspecialchars($tabLabel) ?>">
                <span class="text-truncate" style="max-width: 220px;"><?= htmlspecialchars($tabLabel) ?></span>
                <span class="app-chat-product-badge" data-badge-product-id="<?= $pid ?>" style="<?= $cnt > 0 ? '' : 'display:none;' ?>"><?= $cnt ?></span>
            </button>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="app-chat-body">
        <div class="app-chat-messages-wrap">
            <div class="app-chat-messages" id="appChatMessages">
                <div class="message-system">
                    <div class="message-content">
                        <i class="bi bi-chat-dots me-2"></i>
                        Открываем чат…
                    </div>
                </div>
            </div>
            <button type="button" class="btn btn-primary btn-sm app-chat-new-below-btn shadow-sm d-none" id="appChatNewBelowBtn">
                <i class="bi bi-arrow-down-circle me-1"></i> Новые сообщения
            </button>
        </div>

        <div class="app-chat-input" id="appChatInput">
            <form id="appChatForm" enctype="multipart/form-data">
                <input type="hidden" name="application_id" id="appChatApplicationId" value="<?= (int) $applicationId ?>">
                <div class="mb-2">
                    <textarea name="message" class="form-control chat-textarea" id="appChatTextarea" placeholder="Введите сообщение..." rows="2"></textarea>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <div class="flex-grow-1">
                        <div class="file-input-wrapper">
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="appChatFilesBtn">
                                <i class="bi bi-paperclip me-1"></i> Файлы
                            </button>
                            <input type="file" name="chat_files[]" id="appChatFiles" multiple
                                   accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.txt,.zip,.rar"
                                   style="display:none;">
                        </div>
                        <div class="selected-files" id="appChatSelectedFiles"></div>
                    </div>
                    <button type="submit" class="btn btn-primary px-3" id="appChatSendBtn" title="Отправить">
                        <i class="bi bi-send"></i>
                    </button>
                </div>
                <div class="form-text small">
                    Макс. размер файла: 20MB. Разрешены: PDF, DOC, XLS, JPG, PNG, TXT, ZIP, RAR
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!$isAnalystView && $canUseProductChat): ?>
<button class="app-chat-fab" id="appChatFab" type="button">
    <i class="bi bi-chat-dots"></i>
    <span class="app-chat-fab-label"><?= !empty($showProductChatTabs) && $chatDefaultThread !== 'beneficiary' ? 'Чаты' : 'Чат' ?></span>
    <span class="app-chat-fab-badge" id="appChatFabBadge" style="<?= $totalUnreadMessages > 0 ? '' : 'display:none;' ?>"><?= (int)$totalUnreadMessages ?></span>
</button>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="assets/js/company_analytics.js"></script>
<script>
// Глобальная функция для уведомлений
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
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        
        @keyframes slideOutRight {
            from {
                transform: translateX(0);
                opacity: 1;
            }
            to {
                transform: translateX(100%);
                opacity: 0;
            }
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
        
        .alert .btn-close:focus {
            box-shadow: none;
        }
    `;
    document.head.appendChild(style);
}

// Функция для установки cookie
function setCookie(name, value, days) {
    const expires = new Date();
    expires.setTime(expires.getTime() + (days * 24 * 60 * 60 * 1000));
    document.cookie = name + '=' + value + ';expires=' + expires.toUTCString() + ';path=/';
}

// Функция для получения cookie
function getCookie(name) {
    const nameEQ = name + '=';
    const ca = document.cookie.split(';');
    for(let i = 0; i < ca.length; i++) {
        let c = ca[i];
        while (c.charAt(0) === ' ') c = c.substring(1, c.length);
        if (c.indexOf(nameEQ) === 0) return c.substring(nameEQ.length, c.length);
    }
    return null;
}

// Функция для удаления cookie
function deleteCookie(name) {
    document.cookie = name + '=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;';
}

// Обработка формы загрузки документа
document.getElementById('uploadDocumentForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    
    submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i> Загрузка...';
    submitBtn.disabled = true;
    
    // Сохраняем текущую вкладку
    const activeTab = document.querySelector('#applicationTabs .nav-link.active');
    if (activeTab) {
        const target = activeTab.getAttribute('data-bs-target');
        sessionStorage.setItem('activeTab_<?= $applicationId ?>', target);
    }
    
    fetch('api_upload_document.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('Документ успешно загружен!', 'success');
            setTimeout(() => {
                bootstrap.Modal.getInstance(document.getElementById('uploadDocumentModal')).hide();
                location.reload();
            }, 1000);
        } else {
            showNotification('Ошибка: ' + data.error, 'danger');
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
            sessionStorage.removeItem('activeTab_<?= $applicationId ?>');
        }
    })
    .catch(error => {
        showNotification('Ошибка загрузки: ' + error.message, 'danger');
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
        sessionStorage.removeItem('activeTab_<?= $applicationId ?>');
    });
});

// Функция удаления документа (только для менеджеров)
function deleteDocument(documentId) {
    if (confirm('Вы уверены, что хотите удалить этот документ?')) {
        // Сохраняем вкладку в cookie
        setCookie('activeTab', 'documents', 1);
        
        fetch('api_delete_document.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'document_id=' + documentId
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Документ успешно удален!');
                location.reload();
            } else {
                alert('Ошибка: ' + data.error);
                deleteCookie('activeTab');
            }
        })
        .catch(error => {
            alert('Ошибка: ' + error.message);
            deleteCookie('activeTab');
        });
    }
}

// Восстанавливаем активную вкладку при загрузке страницы
document.addEventListener('DOMContentLoaded', function() {
    const activeTab = getCookie('activeTab');
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
        new bootstrap.Tooltip(el);
    });
    if (activeTab) {
        const tabElement = document.getElementById(activeTab + '-tab');
        if (tabElement) {
            const tab = new bootstrap.Tab(tabElement);
            tab.show();
        }
        // Удаляем cookie после использования
        deleteCookie('activeTab');
    }
});

// Обработчики для удаления продуктов
document.addEventListener('DOMContentLoaded', function() {
    document.addEventListener('click', function(event) {
        const btn = event.target.closest('.delete-product-btn');
        if (!btn) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        const productAppId = btn.getAttribute('data-product-id');
        const productName = btn.getAttribute('data-product-name') || '';
        deleteProduct(productAppId, productName, btn);
    }, true);
});

// ============================================================================
// Chat drawer widget (application_details.php)
// ============================================================================
document.addEventListener('DOMContentLoaded', function() {
    const applicationId = <?= (int) $applicationId ?>;
    const products = <?= json_encode(!empty($viewerHasProductChats) ? array_map(static fn($p) => (int)$p['id'], $products) : [], JSON_UNESCAPED_UNICODE) ?>;
    const POLL_MS = 12000;

    const fab = document.getElementById('appChatFab');
    const fabBadge = document.getElementById('appChatFabBadge');
    const overlay = document.getElementById('appChatOverlay');
    const drawer = document.getElementById('appChatDrawer');
    const closeBtn = document.getElementById('appChatClose');

    const tabsWrap = document.getElementById('appChatProductTabs');
    const messagesEl = document.getElementById('appChatMessages');
    const newBelowBtn = document.getElementById('appChatNewBelowBtn');
    const form = document.getElementById('appChatForm');
    const textarea = document.getElementById('appChatTextarea');
    const filesBtn = document.getElementById('appChatFilesBtn');
    const filesInput = document.getElementById('appChatFiles');
    const selectedFiles = document.getElementById('appChatSelectedFiles');
    const sendBtn = document.getElementById('appChatSendBtn');
    const chatInput = document.getElementById('appChatInput');
    let activeThread = <?= json_encode($chatDefaultThread, JSON_UNESCAPED_UNICODE) ?>;
    let activeProductId = null;
    let appUnread = <?= (int) $appChatUnread ?>;
    let productUnreadTotal = <?= (int) $productUnreadTotal ?>;

    if (!fab || !drawer) {
        return;
    }

    let pollTimer = null;
    let isFetching = false;
    let markReadTimer = null;

    function isAppScope() {
        return !activeProductId;
    }

    function threadHasProductChats() {
        return activeThread !== 'beneficiary' && products.length > 0;
    }

    function syncProductTabs() {
        if (!threadHasProductChats()) {
            activeProductId = null;
        }
        if (tabsWrap) {
            tabsWrap.classList.toggle('is-single', !threadHasProductChats());
        }
        const fabLabel = fab.querySelector('.app-chat-fab-label');
        if (fabLabel) {
            fabLabel.textContent = threadHasProductChats() ? 'Чаты' : 'Чат';
        }
    }

    function applyThreadParam(url) {
        url.searchParams.set('thread', activeThread || 'principal');
        return url;
    }

    function appChatUrl(action, extra) {
        const url = new URL('api_application_chat.php', window.location.href);
        url.searchParams.set('action', action);
        url.searchParams.set('application_id', String(applicationId));
        applyThreadParam(url);
        if (extra) {
            Object.keys(extra).forEach(function (k) {
                url.searchParams.set(k, extra[k]);
            });
        }
        return url;
    }

    function productChatUrl(action, extra) {
        const pid = activeProductId || products[0] || 0;
        const url = new URL('api_product_chat.php', window.location.href);
        url.searchParams.set('action', action);
        url.searchParams.set('application_product_id', String(pid));
        url.searchParams.set('thread', 'principal');
        if (extra) {
            Object.keys(extra).forEach(function (k) {
                url.searchParams.set(k, extra[k]);
            });
        }
        return url;
    }

    const threadSwitch = document.getElementById('appChatThreadSwitch');
    if (threadSwitch) {
        threadSwitch.addEventListener('click', function (e) {
            const btn = e.target.closest('[data-thread]');
            if (!btn) return;
            activeThread = btn.getAttribute('data-thread') || 'principal';
            threadSwitch.querySelectorAll('[data-thread]').forEach(function (b) {
                b.classList.toggle('active', b === btn);
            });
            syncProductTabs();
            loadChat({ scroll: 'bottom' });
        });
    }

    function scheduleMarkReadDebounced() {
        if (!isOpen()) return;
        if (!isChatNearBottom(80)) return;
        clearTimeout(markReadTimer);
        markReadTimer = setTimeout(async function() {
            if (!isOpen()) return;
            if (!isChatNearBottom(80)) return;
            try {
                const url = isAppScope() ? appChatUrl('mark_read') : productChatUrl('mark_read');
                const r = await fetch(url.toString(), { credentials: 'same-origin' });
                const data = await r.json();
                if (data && data.success) {
                    applyUnreadFromResponse(data, isAppScope());
                }
            } catch (e) {}
        }, 350);
    }

    function setChatInputVisible(visible) {
        if (!chatInput) return;
        chatInput.classList.toggle('d-none', !visible);
    }

    function isOpen() {
        return document.body.classList.contains('app-chat-open');
    }

    function openDrawer() {
        document.body.classList.add('app-chat-open');
        loadChat({ scroll: 'bottom' });
    }

    function closeDrawer() {
        document.body.classList.remove('app-chat-open');
        hideNewBelowIndicator();
        clearTimeout(markReadTimer);
    }

    function scrollToBottom() {
        if (!messagesEl) return;
        messagesEl.scrollTop = messagesEl.scrollHeight;
        hideNewBelowIndicator();
        scheduleMarkReadDebounced();
    }

    function isChatNearBottom(thresholdPx) {
        if (!messagesEl) return true;
        const th = typeof thresholdPx === 'number' ? thresholdPx : 56;
        return messagesEl.scrollHeight - messagesEl.scrollTop - messagesEl.clientHeight <= th;
    }

    function hideNewBelowIndicator() {
        if (newBelowBtn) newBelowBtn.classList.add('d-none');
    }

    function showNewBelowIndicator() {
        if (newBelowBtn) newBelowBtn.classList.remove('d-none');
    }

    function updateFabBadge() {
        if (!fabBadge) return;
        const t = Number(appUnread || 0) + Number(productUnreadTotal || 0);
        if (t > 0) {
            fabBadge.style.display = '';
            fabBadge.textContent = String(t);
        } else {
            fabBadge.style.display = 'none';
        }
    }

    function updateAppTabBadge(count) {
        const el = document.querySelector('[data-badge-chat="application"]');
        if (!el) return;
        const cnt = Number(count || 0);
        if (cnt > 0) {
            el.style.display = '';
            el.textContent = String(cnt);
        } else {
            el.style.display = 'none';
        }
    }

    function updateProductTabBadges(unreadCounts) {
        if (!unreadCounts) return;
        let sum = 0;
        document.querySelectorAll('[data-badge-product-id]').forEach(function(el) {
            const pid = el.getAttribute('data-badge-product-id');
            const cnt = Number(unreadCounts[pid] || 0);
            sum += cnt;
            if (cnt > 0) {
                el.style.display = '';
                el.textContent = String(cnt);
            } else {
                el.style.display = 'none';
            }
        });
        productUnreadTotal = sum;
    }

    function applyUnreadFromResponse(data, fromApp) {
        if (fromApp) {
            appUnread = Number(data.total_unread || 0);
            updateAppTabBadge(Number(data.unread != null ? data.unread : data.total_unread || 0));
        } else {
            updateProductTabBadges(data.unread_counts || {});
            if (typeof data.total_unread === 'number') {
                productUnreadTotal = Number(data.total_unread || 0);
            }
        }
        updateFabBadge();
    }

    function setActiveTabUi() {
        document.querySelectorAll('.app-chat-product-tab').forEach(function(btn) {
            const isApp = btn.getAttribute('data-chat') === 'application';
            const pid = Number(btn.getAttribute('data-product-id') || 0);
            const active = isAppScope() ? isApp : (!isApp && pid === Number(activeProductId));
            btn.classList.toggle('active', active);
        });
    }

    async function loadChat(options) {
        options = options || {};
        const scrollMode = options.scroll || 'bottom';
        if (!messagesEl) return;
        if (isFetching) return;
        isFetching = true;
        let prevScrollTop = 0;
        let prevScrollHeight = 0;
        let prevMsgCount = 0;
        let atBottomBefore = true;
        if (scrollMode === 'preserve') {
            prevScrollTop = messagesEl.scrollTop;
            prevScrollHeight = messagesEl.scrollHeight;
            prevMsgCount = messagesEl.querySelectorAll('.message').length;
            atBottomBefore = isChatNearBottom(56);
        }
        try {
            if (!threadHasProductChats()) {
                activeProductId = null;
            }
            setActiveTabUi();
            const url = isAppScope()
                ? appChatUrl('get', { mark_read: scrollMode === 'bottom' ? '1' : '0' })
                : productChatUrl('get', { mark_read: scrollMode === 'bottom' ? '1' : '0' });

            const r = await fetch(url.toString(), { credentials: 'same-origin' });
            const data = await r.json();
            if (!data || !data.success) {
                messagesEl.innerHTML = '<div class="message-system"><div class="message-content">Не удалось загрузить чат</div></div>';
                hideNewBelowIndicator();
                setChatInputVisible(false);
                return;
            }
            messagesEl.innerHTML = data.messages_html || '';
            setChatInputVisible(true);
            applyUnreadFromResponse(data, isAppScope());
            if (scrollMode === 'bottom') {
                scrollToBottom();
            } else if (scrollMode === 'preserve') {
                const dh = messagesEl.scrollHeight - prevScrollHeight;
                messagesEl.scrollTop = prevScrollTop + dh;
                const newMsgCount = messagesEl.querySelectorAll('.message').length;
                if (newMsgCount > prevMsgCount && !atBottomBefore) {
                    showNewBelowIndicator();
                }
                if (isChatNearBottom(56)) {
                    hideNewBelowIndicator();
                    scheduleMarkReadDebounced();
                }
            }
        } finally {
            isFetching = false;
        }
    }

    async function refreshCountsOnly() {
        try {
            const appReq = fetch(appChatUrl('counts').toString(), { credentials: 'same-origin' }).then(function (r) { return r.json(); });
            const jobs = [appReq];
            if (products.length) {
                jobs.push(fetch(productChatUrl('counts').toString(), { credentials: 'same-origin' }).then(function (r) { return r.json(); }));
            }
            const results = await Promise.all(jobs);
            if (results[0] && results[0].success) {
                applyUnreadFromResponse(results[0], true);
            }
            if (results[1] && results[1].success) {
                applyUnreadFromResponse(results[1], false);
            }
        } catch (e) {}
    }

    function startPolling() {
        if (pollTimer) return;
        pollTimer = setInterval(async function() {
            try {
                if (isOpen()) {
                    await loadChat({ scroll: 'preserve' });
                    if (isAppScope() && products.length) {
                        const r = await fetch(productChatUrl('counts').toString(), { credentials: 'same-origin' });
                        const data = await r.json();
                        if (data && data.success) applyUnreadFromResponse(data, false);
                    } else if (!isAppScope()) {
                        const r = await fetch(appChatUrl('counts').toString(), { credentials: 'same-origin' });
                        const data = await r.json();
                        if (data && data.success) applyUnreadFromResponse(data, true);
                    }
                } else {
                    await refreshCountsOnly();
                }
            } catch (e) {}
        }, POLL_MS);
    }

    function renderSelectedFiles() {
        if (!selectedFiles || !filesInput) return;
        selectedFiles.innerHTML = '';
        const files = Array.from(filesInput.files || []);
        files.forEach(function(file, index) {
            const el = document.createElement('div');
            el.className = 'selected-file';
            el.innerHTML = `
                <i class="bi bi-file-earmark"></i>
                ${file.name}
                <span class="remove-file" data-remove-index="${index}">
                    <i class="bi bi-x"></i>
                </span>
            `;
            selectedFiles.appendChild(el);
        });
    }

    function removeSelectedFile(index) {
        if (!filesInput) return;
        const dt = new DataTransfer();
        const files = Array.from(filesInput.files || []);
        files.forEach(function(file, i) {
            if (i !== index) dt.items.add(file);
        });
        filesInput.files = dt.files;
        renderSelectedFiles();
    }

    if (fab) fab.addEventListener('click', openDrawer);
    if (overlay) overlay.addEventListener('click', closeDrawer);
    if (closeBtn) closeBtn.addEventListener('click', closeDrawer);

    if (messagesEl) {
        messagesEl.addEventListener('scroll', function() {
            if (isChatNearBottom(72)) {
                hideNewBelowIndicator();
                scheduleMarkReadDebounced();
            }
        }, { passive: true });
    }
    if (newBelowBtn) {
        newBelowBtn.addEventListener('click', function() {
            scrollToBottom();
        });
    }

    if (tabsWrap) {
        tabsWrap.addEventListener('click', function(e) {
            const btn = e.target.closest('.app-chat-product-tab');
            if (!btn) return;
            if (btn.getAttribute('data-chat') === 'application') {
                activeProductId = null;
            } else {
                if (!threadHasProductChats()) return;
                const pid = Number(btn.getAttribute('data-product-id'));
                if (!pid) return;
                activeProductId = pid;
            }
            loadChat({ scroll: 'bottom' });
        });
    }

    if (filesBtn && filesInput) {
        filesBtn.addEventListener('click', function() {
            filesInput.click();
        });
        filesInput.addEventListener('change', renderSelectedFiles);
    }

    if (selectedFiles) {
        selectedFiles.addEventListener('click', function(e) {
            const btn = e.target.closest('[data-remove-index]');
            if (!btn) return;
            const idx = Number(btn.getAttribute('data-remove-index'));
            if (Number.isFinite(idx)) removeSelectedFile(idx);
        });
    }

    if (form) {
        form.addEventListener('submit', async function(e) {
            e.preventDefault();
            if (!isAppScope() && !activeProductId) return;

            if (sendBtn) sendBtn.disabled = true;
            try {
                const fd = new FormData(form);
                fd.set('action', 'send');
                let endpoint = 'api_application_chat.php';
                if (isAppScope()) {
                    fd.set('thread', activeThread || 'principal');
                    fd.set('application_id', String(applicationId));
                } else {
                    endpoint = 'api_product_chat.php';
                    fd.set('thread', 'principal');
                    fd.set('application_product_id', String(activeProductId));
                }

                const r = await fetch(endpoint, {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin',
                });
                const data = await r.json();
                if (!data || !data.success) {
                    if (typeof showNotification === 'function') {
                        showNotification(data?.error || 'Ошибка отправки сообщения', 'error');
                    }
                    return;
                }
                if (messagesEl) messagesEl.innerHTML = data.messages_html || '';
                applyUnreadFromResponse(data, isAppScope());
                if (textarea) textarea.value = '';
                if (filesInput) filesInput.value = '';
                if (selectedFiles) selectedFiles.innerHTML = '';
                scrollToBottom();
            } finally {
                if (sendBtn) sendBtn.disabled = false;
            }
        });
    }

    startPolling();
    syncProductTabs();
    updateFabBadge();
});

</script>

<!-- Модальное окно добавления продукта -->
<div class="modal fade" id="addProductModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Добавить продукт к заявке</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    Поиск продуктов для: 
                    <strong><?= htmlspecialchars($application['company_name']) ?></strong> 
                    (ИНН: <?= htmlspecialchars($application['inn']) ?>)
                </div>
                <div id="productSearchResults">
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary mb-3" role="status">
                            <span class="visually-hidden">Загрузка...</span>
                        </div>
                        <p class="text-muted">Загрузка доступных продуктов...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<!-- Модальное окно изменения статуса продукта -->
<div class="modal fade" id="statusModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Изменение статуса продукта</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="currentProductAppId">
                <div class="mb-3">
                    <label class="form-label">Новый статус</label>
                    <select class="form-select" id="statusSelect">
                        <!-- Опции будут заполнены динамически -->
                    </select>
                </div>
                <div id="statusModalMessage" class="alert alert-info" style="display: none;">
                    <small>При изменении статуса на "БГ выпущена" или "Выдан" заявка автоматически будет завершена.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="button" class="btn btn-primary" onclick="updateProductStatus()">Сохранить</button>
            </div>
        </div>
    </div>
</div>

<script>
function startApplicationProcessing(applicationId) {
    if (confirm('Начать обработку этой заявки? Статус изменится на "В работе".')) {
        // Здесь будет AJAX запрос для изменения статуса
        alert('Функционал изменения статуса будет реализован в следующем шаге');
    }
}

function markApplicationAsFailed(applicationId) {
    if (!confirm('Все продукты заявки будут переведены в статус «Отказано», заявка станет проваленной. Продолжить?')) {
        return;
    }
    const fd = new FormData();
    fd.append('application_id', applicationId);
    fetch('api_fail_application.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                if (typeof showNotification === 'function') {
                    showNotification('Заявка перенесена в проваленные', 'success');
                }
                window.location.reload();
            } else {
                alert(data.error || 'Ошибка');
            }
        })
        .catch(function(err) {
            alert('Ошибка: ' + (err && err.message ? err.message : String(err)));
        });
}

function editProduct(productId) {
    alert('Редактирование продукта ' + productId + ' - функционал в разработке');
}

// Загрузка продуктов при открытии модального окна
document.getElementById('addProductModal').addEventListener('show.bs.modal', function() {
    loadProductSelection();
});

function loadProductSelection() {
    const applicationId = <?= $applicationId ?>;
    const productType = '<?= $application['product_type'] ?>';
    
    fetch('api_product_search.php?application_id=' + applicationId + '&product_type=' + productType)
        .then(response => response.text())
        .then(html => {
            document.getElementById('productSearchResults').innerHTML = html;
        })
        .catch(error => {
            document.getElementById('productSearchResults').innerHTML = '<div class="alert alert-danger">Ошибка загрузки продуктов</div>';
        });
}

function addProductToApplication(productId, productType, buttonElement) {
    const applicationId = <?= $applicationId ?>;
    
    if (confirm('Добавить этот продукт к заявке?')) {
        
        const originalText = buttonElement.innerHTML;
        const originalDisabled = buttonElement.disabled;
        
        buttonElement.innerHTML = '<i class="bi bi-hourglass-split me-1"></i> Добавляем...';
        buttonElement.disabled = true;
        
        const formData = new URLSearchParams();
        formData.append('application_id', applicationId);
        formData.append('product_id', productId);
        formData.append('product_type', productType);
        
        fetch('api_add_product.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification('Продукт успешно добавлен!', 'success');
                setTimeout(() => {
                    location.reload();
                }, 1000);
            } else {
                showNotification('Ошибка: ' + data.error, 'danger');
                buttonElement.innerHTML = originalText;
                buttonElement.disabled = originalDisabled;
                
            }
        })
        .catch(error => {
            showNotification('Ошибка при добавлении продукта: ' + error.message, 'danger');
            buttonElement.innerHTML = originalText;
            buttonElement.disabled = originalDisabled;
           
        });
    }
}


// Статусы для разных типов продуктов
const statusOptions = {
    bg: [
        'В работе',
        'На подписании', 
        'Запрос',
        'Согласование условий',
        'На выпуске',
        'БГ выпущена',
        'Отказано',
        'Не актуален для клиента'
    ],
    credit: [
        'В работе',
        'Запрос',
        'Выдан', 
        'Отказано',
        'Не актуален для клиента'
    ]
};

function showStatusModal(productAppId, productType, currentStatus) {
    document.getElementById('currentProductAppId').value = productAppId;
    
    const statusSelect = document.getElementById('statusSelect');
    statusSelect.innerHTML = '';
    
    // Заполняем опции статусов
    statusOptions[productType].forEach(status => {
        const option = document.createElement('option');
        option.value = status;
        option.textContent = status;
        if (status === currentStatus) {
            option.selected = true;
        }
        statusSelect.appendChild(option);
    });
    
    // Показываем подсказку для финальных статусов
    const finalStatuses = productType === 'bg' ? ['БГ выпущена'] : ['Выдан'];
    const messageDiv = document.getElementById('statusModalMessage');
    if (finalStatuses.includes(currentStatus)) {
        messageDiv.style.display = 'block';
    } else {
        messageDiv.style.display = 'none';
    }
    
    // Показываем модальное окно
    new bootstrap.Modal(document.getElementById('statusModal')).show();
}

function updateProductStatus() {
    const productAppId = document.getElementById('currentProductAppId').value;
    const newStatus = document.getElementById('statusSelect').value;
    
    if (!productAppId || !newStatus) {
        showNotification('Выберите статус', 'danger');
        return;
    }
    
    const saveBtn = document.querySelector('#statusModal .btn-primary');
    const originalText = saveBtn.innerHTML;
    saveBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i> Сохранение...';
    saveBtn.disabled = true;
    
    const formData = new URLSearchParams();
    formData.append('product_app_id', productAppId);
    formData.append('status', newStatus);
    
    fetch('api_update_product_status.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            bootstrap.Modal.getInstance(document.getElementById('statusModal')).hide();
            showNotification('Статус успешно обновлен!', 'success');
            setTimeout(() => {
                location.reload();
            }, 1000);
        } else {
            showNotification('Ошибка: ' + data.error, 'danger');
            saveBtn.innerHTML = originalText;
            saveBtn.disabled = false;
        }
    })
    .catch(error => {
        showNotification('Ошибка при обновлении статуса: ' + error.message, 'danger');
        saveBtn.innerHTML = originalText;
        saveBtn.disabled = false;
    });
}
// Функция удаления документа
function deleteDocument(documentId) {
    if (confirm('Удалить этот документ?')) {
       
        
        fetch('api_delete_document.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'document_id=' + documentId
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification('Документ успешно удален!', 'success');
                setTimeout(() => {
                    location.reload();
                }, 1000);
            } else {
                showNotification('Ошибка: ' + data.error, 'danger');
             
            }
        })
        .catch(error => {
            showNotification('Ошибка: ' + error.message, 'danger');
           
        });
    }
}
// Сохранение активной вкладки в URL (Clean Code)
document.addEventListener('DOMContentLoaded', function() {
    // 1. При клике на вкладку меняем URL без перезагрузки
    var triggerTabList = [].slice.call(document.querySelectorAll('button[data-bs-toggle="tab"], a[data-bs-toggle="tab"]'))
    triggerTabList.forEach(function (triggerEl) {
        triggerEl.addEventListener('shown.bs.tab', function (event) {
            // Получаем чистый ID вкладки (без #)
            var target = event.target.getAttribute('data-bs-target') || event.target.getAttribute('href');
            if (target) {
                var tabName = target.replace('#', '');
                var url = new URL(window.location);
                url.searchParams.set('tab', tabName);
                // replaceState обновляет URL, не засоряя историю браузера
                window.history.replaceState({}, '', url);
            }
        });
    });

    // 2. При загрузке страницы открываем вкладку из URL
    var params = new URLSearchParams(window.location.search);
    var activeTab = params.get('tab');
    if (activeTab) {
        var tabTrigger = document.querySelector('[data-bs-target="#' + activeTab + '"], [href="#' + activeTab + '"]');
        if (tabTrigger) {
            var tab = new bootstrap.Tab(tabTrigger);
            tab.show();
        }
    }
});



// Функции для редактирования полей
let originalValues = {};

const applicationIdForEdit = <?= (int) $applicationId ?>;

function postApplicationField(fieldName, value) {
    const formData = new FormData();
    formData.append('application_id', applicationIdForEdit);
    formData.append('field', fieldName);
    formData.append('value', value);
    return fetch('api_update_application.php', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                throw new Error(data.error || 'Не удалось сохранить');
            }
            return data;
        });
}

function getCollateralCard(itemKey) {
    return document.getElementById('collateral_item_' + itemKey);
}

function toggleCollateralDetailsInput(itemKey) {
    const card = getCollateralCard(itemKey);
    if (!card) return;
    const checkbox = card.querySelector('.collateral-edit-enabled');
    const wrap = card.querySelector('.collateral-edit-details-wrap');
    const input = card.querySelector('.collateral-edit-details');
    if (!checkbox || !wrap || !input) return;
    const on = checkbox.checked;
    wrap.classList.toggle('is-disabled', !on);
    input.disabled = !on;
    if (!on) {
        input.value = '';
    } else {
        input.focus();
    }
}

function enableCollateralEdit(itemKey) {
    const card = getCollateralCard(itemKey);
    if (!card) return;
    const enabled = card.dataset.enabled === '1';
    const details = card.dataset.details || '';
    const checkbox = card.querySelector('.collateral-edit-enabled');
    const input = card.querySelector('.collateral-edit-details');
    if (checkbox) checkbox.checked = enabled;
    if (input) {
        input.value = details;
        input.disabled = !enabled;
    }
    const wrap = card.querySelector('.collateral-edit-details-wrap');
    if (wrap) wrap.classList.toggle('is-disabled', !enabled);
    card.classList.add('is-editing');
}

function cancelCollateralEdit(itemKey) {
    const card = getCollateralCard(itemKey);
    if (!card) return;
    card.classList.remove('is-editing');
}

function updateCollateralCardView(card, enabled, details) {
    const badge = card.querySelector('.collateral-status-badge');
    const display = card.querySelector('.collateral-details-display');
    if (badge) {
        badge.textContent = enabled ? 'Да' : 'Нет';
        badge.classList.toggle('bg-success', enabled);
        badge.classList.toggle('bg-secondary', !enabled);
    }
    if (display) {
        if (enabled && details) {
            display.innerHTML = '<div class="collateral-details-text">' + nl2br(escapeHtml(details)) + '</div>';
        } else if (enabled) {
            display.innerHTML = '<div class="collateral-details-empty">Детали не указаны</div>';
        } else {
            display.innerHTML = '<div class="collateral-details-empty">Не применяется</div>';
        }
    }
    card.dataset.enabled = enabled ? '1' : '0';
    card.dataset.details = details;
}

function saveCollateralItem(itemKey) {
    const card = getCollateralCard(itemKey);
    if (!card) return;
    const enField = card.dataset.enabledField;
    const dtField = card.dataset.detailsField;
    const checkbox = card.querySelector('.collateral-edit-enabled');
    const input = card.querySelector('.collateral-edit-details');
    const saveBtn = card.querySelector('.collateral-save-btn');
    if (!enField || !dtField || !checkbox || !input) return;

    const enabled = checkbox.checked;
    const details = enabled ? input.value.trim() : '';

    if (saveBtn) {
        saveBtn.disabled = true;
        saveBtn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Сохранение…';
    }

    postApplicationField(enField, enabled ? '1' : '0')
        .then(() => postApplicationField(dtField, details))
        .then(() => {
            updateCollateralCardView(card, enabled, details);
            cancelCollateralEdit(itemKey);
            showNotification('Обеспечение обновлено', 'success');
        })
        .catch(err => {
            alert('Ошибка: ' + (err.message || err));
        })
        .finally(() => {
            if (saveBtn) {
                saveBtn.disabled = false;
                saveBtn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Сохранить';
            }
        });
}

function enableEdit(fieldName) {
    const fieldElement = document.getElementById(fieldName + '_field');
    if (!fieldElement) return;
    const valueElement = fieldElement.querySelector('.field-value');
    const inputElement = document.getElementById(fieldName + '_input');
    const editButtons = fieldElement.querySelector('.edit-buttons');
    const editIcon = fieldElement.querySelector('.edit-icon');
    if (!valueElement || !inputElement || !editButtons) return;
    const commentActions = fieldElement.closest('.comment-box')?.querySelector('.comment-actions');
    
    // Сохраняем оригинальное значение
    if (inputElement.type === 'checkbox') {
        originalValues[fieldName] = inputElement.checked;
    } else {
        originalValues[fieldName] = inputElement.value;
    }
    
    // Показываем input и кнопки, скрываем значение и иконку
    valueElement.style.display = 'none';
    if (editIcon) editIcon.style.display = 'none';
    inputElement.style.display = 'block';
    editButtons.style.display = 'block';
    if (commentActions) {
        commentActions.style.display = 'none';
    }
    // Кнопка «Назначить ответственного»: скрываем при открытии формы редактирования
    const assignBtn = fieldElement.querySelector('.assign-responsible-btn');
    if (assignBtn) assignBtn.style.display = 'none';
    
    if (fieldName === 'purchase_number') {
        const grp = document.getElementById('purchase_number_input_group');
        if (grp) {
            grp.style.display = 'flex';
        }
    }

    // Фокусируемся на поле ввода
    inputElement.focus();
    
    // Для textarea авторазмер
    if (inputElement.tagName === 'TEXTAREA') {
        inputElement.style.height = 'auto';
        inputElement.style.height = (inputElement.scrollHeight) + 'px';
    }
}

function cancelEdit(fieldName) {
    const fieldElement = document.getElementById(fieldName + '_field');
    const valueElement = fieldElement ? fieldElement.querySelector('.field-value') : null;
    const inputElement = document.getElementById(fieldName + '_input');
    const editButtons = fieldElement ? fieldElement.querySelector('.edit-buttons') : null;
    const editIcon = fieldElement ? fieldElement.querySelector('.edit-icon') : null;
    if (!fieldElement || !valueElement || !inputElement || !editButtons) return;

    // Восстанавливаем оригинальное значение
    if (originalValues[fieldName] !== undefined) {
        if (inputElement.type === 'checkbox') {
            inputElement.checked = originalValues[fieldName];
        } else {
            inputElement.value = originalValues[fieldName];
        }
    }
    
    // Показываем значение и иконку, скрываем input и кнопки
    valueElement.style.display = (fieldName === 'assigned_to' ? 'inline' : 'block');
    if (editIcon) editIcon.style.display = 'block';
    inputElement.style.display = 'none';
    editButtons.style.display = 'none';
    // Для «Ответственный»: если значение пустое, показываем кнопку «Назначить ответственного»
    if (fieldName === 'assigned_to') {
        const assignBtn = fieldElement.querySelector('.assign-responsible-btn');
        if (assignBtn) assignBtn.style.display = (inputElement.value === '' ? 'inline-block' : 'none');
        if (editIcon) editIcon.style.display = (inputElement.value !== '' ? 'inline' : 'none');
    }

    if (fieldName === 'purchase_number') {
        const grp = document.getElementById('purchase_number_input_group');
        if (grp) {
            grp.style.display = 'none';
        }
    }
    
    // Удаляем из хранилища
    delete originalValues[fieldName];
}

// Обновить функцию saveField для обработки select
function saveField(fieldName) {
    const fieldElement = document.getElementById(fieldName + '_field');
    if (!fieldElement) return;
    const valueElement = fieldElement.querySelector('.field-value');
    const inputElement = document.getElementById(fieldName + '_input');
    const editButtons = fieldElement.querySelector('.edit-buttons');
    const editIcon = fieldElement.querySelector('.edit-icon');
    if (!valueElement || !inputElement || !editButtons) return;

    let value;
    
    // Получаем значение в зависимости от типа элемента
    if (inputElement.type === 'checkbox') {
        value = inputElement.checked ? '1' : '0';
    } else if (inputElement.tagName === 'SELECT') {
        value = inputElement.value;
    } else {
        value = inputElement.value;
    }
    
    // Специальная обработка для числовых полей
    if (fieldName === 'term' && value !== '') {
        value = parseInt(value);
        if (isNaN(value) || value < 1) {
            alert('Введите корректный срок (от 1 месяца)');
            return;
        }
    }

    // В функции saveField добавить в начало:
if (fieldName === 'customer_inn') {
    const input = document.getElementById('customer_inn_input');
    if (input && !checkCustomerInn(input)) {
        return; // Не сохраняем если ИНН невалидный
    }
    // Автоматически ищем название
    setTimeout(autoFetchCustomerName, 100);
}
    
    const applicationId = <?= $applicationId ?>;
    
    // Показываем индикатор загрузки
    const saveBtn = fieldElement.querySelector('.btn-success');
    const originalBtnHtml = saveBtn.innerHTML;
    saveBtn.innerHTML = '<i class="bi bi-hourglass-split"></i>';
    saveBtn.disabled = true;
    
    // Отправляем запрос на сервер
    const formData = new FormData();
    formData.append('application_id', applicationId);
    formData.append('field', fieldName);
    formData.append('value', value);
    
    fetch('api_update_application.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Обновляем отображаемое значение
            if (fieldName === 'term') {
                valueElement.innerHTML = value ? value + ' мес.' : '-';
            } else if (fieldName === 'fz_type' || fieldName === 'guarantee_type' || fieldName === 'loan_type') {
                valueElement.innerHTML = value ? value : '-';
            } else if (fieldName === 'is_extension' || fieldName === 'is_replacement') {
                const isYes = value === '1' || value === 1 || value === true;
                valueElement.innerHTML = isYes ? 'Да' : 'Нет';
            } else if (fieldName === 'assigned_to') {
                const opt = inputElement.options[inputElement.selectedIndex];
                const text = value ? (opt ? opt.text : '') : 'Не назначен';
                valueElement.innerHTML = escapeHtml(text);
                const assignBtn = fieldElement.querySelector('.assign-responsible-btn');
                const editIconEl = fieldElement.querySelector('.edit-icon');
                if (assignBtn) assignBtn.style.display = value ? 'none' : 'inline-block';
                if (editIconEl) editIconEl.style.display = value ? 'inline' : 'none';
            } else if (fieldName === 'comment') {
                const trimmedValue = (value || '').trim();
                if (trimmedValue) {
                    valueElement.innerHTML = nl2br(escapeHtml(trimmedValue));
                    valueElement.classList.remove('comment-empty');
                    const actions = fieldElement.closest('.comment-box')?.querySelector('.comment-actions');
                    if (actions) {
                        actions.style.display = 'none';
                    }
                } else {
                    valueElement.innerHTML = 'Комментарий не добавлен';
                    valueElement.classList.add('comment-empty');
                }
            } else if (fieldName === 'purchase_number') {
                const t = (value || '').trim();
                valueElement.innerHTML = t ? escapeHtml(t) : '-';
            } else if (fieldName === 'contract_subject') {
                const t = (value || '').trim();
                valueElement.innerHTML = t ? escapeHtml(t) : '-';
            } else if (fieldName === 'declined_banks') {
                const t = (value || '').trim();
                valueElement.innerHTML = t ? nl2br(escapeHtml(t)) : '-';
            } else if (fieldName === 'customer_inn') {
                const t = (value || '').trim();
                valueElement.innerHTML = t ? escapeHtml(t) : '-';
            } else if (fieldName === 'contract_price' || fieldName === 'amount') {
                valueElement.innerHTML = formatAmount(value);
            } else if (fieldName === 'purchase_link') {
                const trimmed = (value || '').trim();
                if (trimmed) {
                    const esc = escapeHtml(trimmed);
                    valueElement.innerHTML = '<a href="' + esc + '" target="_blank" rel="noopener noreferrer">' + esc + '</a>';
                } else {
                    valueElement.innerHTML = '-';
                }
            } else if (fieldName === 'guarantee_provision_deadline') {
                const t = (value || '').trim();
                valueElement.innerHTML = t ? escapeHtml(t) : '-';
            } else if (fieldName.endsWith('_details') && fieldName.startsWith('collateral_')) {
                const t = (value || '').trim();
                valueElement.innerHTML = t ? nl2br(escapeHtml(t)) : '-';
            } else {
                valueElement.innerHTML = escapeHtml(value);
            }
            
            // Восстанавливаем отображение
            valueElement.style.display = (fieldName === 'assigned_to' ? 'inline' : 'block');
            inputElement.style.display = 'none';
            editButtons.style.display = 'none';
            if (fieldName === 'purchase_number') {
                const grp = document.getElementById('purchase_number_input_group');
                if (grp) {
                    grp.style.display = 'none';
                }
            }
            // Иконку редактирования показываем только не для assigned_to (там своя логика: иконка или кнопка «Назначить»)
            if (fieldName !== 'assigned_to' && editIcon) {
                editIcon.style.display = 'block';
            }
            
            // Показываем уведомление
            showNotification('Поле успешно обновлено', 'success');
        } else {
            alert('Ошибка: ' + data.error);
            saveBtn.innerHTML = originalBtnHtml;
            saveBtn.disabled = false;
        }
    })
    .catch(error => {
        alert('Ошибка: ' + error.message);
        saveBtn.innerHTML = originalBtnHtml;
        saveBtn.disabled = false;
    });
}

// Вспомогательные функции
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function nl2br(str) {
    return str.replace(/([^>\r\n]?)(\r\n|\n\r|\r|\n)/g, '$1<br>$2');
}

function formatAmount(amount) {
    if (!amount) return '-';
    return Number(amount).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' ₽';
}


// Автоматическое изменение высоты textarea
document.addEventListener('DOMContentLoaded', function() {
    document.addEventListener('input', function(e) {
        if (e.target.tagName === 'TEXTAREA' && e.target.classList.contains('edit-input')) {
            e.target.style.height = 'auto';
            e.target.style.height = (e.target.scrollHeight) + 'px';
        }
    });
});

// Обновленная функция deleteProduct
function deleteProduct(productAppId, productName, buttonElement) {
    if (confirm('Вы точно хотите удалить продукт "' + productName + '"?\n\nЭто действие нельзя отменить!')) {
      
        
        // Показываем индикатор загрузки
        const originalHtml = buttonElement.innerHTML;
        buttonElement.innerHTML = '<i class="bi bi-hourglass-split"></i>';
        buttonElement.disabled = true;
        
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
                    location.reload();
                }, 1000);
            } else {
                showNotification('Ошибка: ' + data.error, 'danger');
                buttonElement.innerHTML = originalHtml;
                buttonElement.disabled = false;
             
            }
        })
        .catch(error => {
            showNotification('Ошибка: ' + error.message, 'danger');
            buttonElement.innerHTML = originalHtml;
            buttonElement.disabled = false;
            
        });
    }
}


// Проверка ИНН при потере фокуса
function checkCustomerInn(input) {
    const inn = input.value.trim();
    if (inn && !/^\d{10,12}$/.test(inn)) {
       showNotification('ИНН должен содержать 10 или 12 цифр', 'danger');
        input.focus();
        return false;
    }
    return true;
}

// Автоматический поиск названия при валидном ИНН
function autoFetchCustomerName() {
    const innInput = document.getElementById('customer_inn_input');
    const nameDisplay = document.querySelector('#customer_name_field .field-value');
    
    if (!innInput || !nameDisplay) return;
    
    const inn = innInput.value.trim();
    if (!inn || !/^\d{10,12}$/.test(inn)) return;
    
    fetch('api_checko_proxy.php?inn=' + inn)
        .then(r => r.json())
        .then(data => {
            if (data.success && data.name) {
                nameDisplay.textContent = data.name;
                showNotification('Название заказчика обновлено', 'success');
            }
        })
        .catch(() => {});
}

<?php if (($application['product_type'] ?? '') === 'bg' && finbuild_is_manager((string)($userRole ?? ''))): ?>
function runFillPurchaseFromChecko() {
    const inp = document.getElementById('purchase_number_input');
    const btn = document.getElementById('fetchPurchaseContractDetailsBtn');
    if (!inp) return;
    const digits = String(inp.value || '').replace(/\D/g, '');
    if (digits.length < 10) {
        alert('Введите номер закупки (не менее 10 цифр).');
        return;
    }
    const fd = new FormData();
    fd.append('application_id', String(<?= (int)$applicationId ?>));
    fd.append('number', digits);
    const orig = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-hourglass-split"></i>';
    }
    fetch('api_checko_proxy.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success) {
                alert(data.error || 'Не удалось получить данные');
                return;
            }
            updatePurchaseDetailsFromFillPayload(data);
            showNotification('Данные по контракту обновлены', 'success');
            const pnf = document.getElementById('purchase_number_field');
            if (pnf) {
                const fv = pnf.querySelector('.field-value');
                const ei = pnf.querySelector('.edit-icon');
                const eb = pnf.querySelector('.edit-buttons');
                if (fv) fv.style.display = 'block';
                if (ei) ei.style.display = 'block';
                if (eb) eb.style.display = 'none';
            }
            const grp = document.getElementById('purchase_number_input_group');
            if (grp) grp.style.display = 'none';
            const pin = document.getElementById('purchase_number_input');
            if (pin) pin.style.display = 'none';
        })
        .catch(function(err) {
            alert('Ошибка: ' + (err && err.message ? err.message : String(err)));
        })
        .finally(function() {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = orig;
            }
        });
}

function updatePurchaseDetailsFromFillPayload(p) {
    const pnv = document.querySelector('#purchase_number_field .field-value');
    if (pnv && p.purchase_number !== undefined) {
        pnv.textContent = p.purchase_number ? String(p.purchase_number) : '-';
    }
    const pin = document.getElementById('purchase_number_input');
    if (pin && p.purchase_number !== undefined) {
        pin.value = p.purchase_number || '';
    }
    const plv = document.querySelector('#purchase_link_field .field-value');
    if (plv && p.purchase_link !== undefined) {
        const u = (p.purchase_link || '').trim();
        if (u) {
            const esc = escapeHtml(u);
            plv.innerHTML = '<a href="' + esc + '" target="_blank" rel="noopener noreferrer">' + esc + '</a>';
        } else {
            plv.innerHTML = '-';
        }
    }
    const pli = document.getElementById('purchase_link_input');
    if (pli && p.purchase_link !== undefined) {
        pli.value = p.purchase_link || '';
    }
    const csv = document.querySelector('#contract_subject_field .field-value');
    if (csv && p.contract_subject !== undefined) {
        const s = (p.contract_subject || '').trim();
        csv.textContent = s ? p.contract_subject : '-';
    }
    const csi = document.getElementById('contract_subject_input');
    if (csi && p.contract_subject !== undefined) {
        csi.value = p.contract_subject || '';
    }
    const cpv = document.querySelector('#contract_price_field .field-value');
    if (cpv && p.contract_price !== undefined) {
        cpv.textContent = formatAmount(p.contract_price);
    }
    const cpi = document.getElementById('contract_price_input');
    if (cpi && p.contract_price !== undefined && p.contract_price !== null) {
        cpi.value = p.contract_price;
    }
    const civ = document.querySelector('#customer_inn_field .field-value');
    if (civ && p.customer_inn !== undefined) {
        const ii = (p.customer_inn || '').trim();
        civ.textContent = ii ? ii : '-';
    }
    const cii = document.getElementById('customer_inn_input');
    if (cii && p.customer_inn !== undefined) {
        cii.value = p.customer_inn || '';
    }
    const cnv = document.querySelector('#customer_name_field .field-value');
    if (cnv && p.customer_name !== undefined) {
        const nm = (p.customer_name || '').trim();
        cnv.textContent = nm ? p.customer_name : '-';
    }
}

document.addEventListener('click', function(e) {
    if (e.target.closest('#fetchPurchaseContractDetailsBtn')) {
        e.preventDefault();
        runFillPurchaseFromChecko();
    }
});

document.addEventListener('focusout', function(e) {
    if (e.target && e.target.id === 'purchase_number_input') {
        const digits = String(e.target.value || '').replace(/\D/g, '');
        if (digits.length >= 10) {
            setTimeout(function() {
                const b = document.getElementById('fetchPurchaseContractDetailsBtn');
                if (b) b.click();
            }, 100);
        }
    }
}, true);
<?php endif; ?>

<?php if (finbuild_is_manager($userRole)): ?>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof initCompanyAnalytics === 'function') {
        initCompanyAnalytics({
            applicationId: <?= (int) $applicationId ?>,
            readOnly: false,
            tabId: 'analytics-tab',
            paneId: 'analytics'
        });
    }
});
<?php endif; ?>

</script>

<?php require_once 'footer.php'; ?>
