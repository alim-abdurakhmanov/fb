<?php
$current_page = 'applications';
require_once 'header.php';
require_once __DIR__ . '/includes/chat_helpers.php';
require_once __DIR__ . '/includes/beneficiary_intake.php';

$pdo = getPDO();
$currentUser = getCurrentUser();
$userRole = $_SESSION['role'] ?? 'client';
$userId = $_SESSION['user_id'];
$userIsAnalystFlag = finbuild_user_is_analyst_flag($currentUser);
$isAnalystScope = finbuild_is_applications_analyst_scope($currentUser);
$isPureAnalyst = finbuild_is_pure_analyst($currentUser);
$seesAllApplications = finbuild_can('applications.view_all', $currentUser) || $isAnalystScope;
$isCaseManager = finbuild_is_case_manager($currentUser) || (
    finbuild_is_manager($userRole) && !finbuild_can('applications.view_all', $currentUser)
);
$isSubmanager = $isCaseManager; // совместимость + маскировка/ограниченный список
if (!$isSubmanager && finbuild_should_mask_owner_identity($currentUser) && finbuild_is_manager($userRole)) {
    $isSubmanager = true; // скрыть контакты в UI, где завязано на этот флаг
}
$isAnalystList = $isAnalystScope && !finbuild_is_manager($userRole);

if ($isAnalystList || $isPureAnalyst) {
    finbuild_analyst_ensure_default_saved_filter($pdo, (int) $userId);
}

// URL для возврата в список с текущими параметрами (фильтры/пагинация)
$returnToApplications = 'applications.php' . (!empty($_SERVER['QUERY_STRING']) ? ('?' . $_SERVER['QUERY_STRING']) : '');

// ============================================================================
// ПАРАМЕТРЫ ПАГИНАЦИИ И ФИЛЬТРОВ ИЗ URL
// ============================================================================

// Опции количества элементов на странице
$perPageOptions = [10, 20, 50, 100];
$perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 20;
if (!in_array($perPage, $perPageOptions, true)) {
    $perPage = 20;
}

// Текущая страница пагинации
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

// ============================================================================
// ЧТЕНИЕ ФИЛЬТРОВ ИЗ URL (только для менеджеров)
// ============================================================================
// Фильтры доступны только менеджерам, клиенты/партнеры видят только свои заявки
$filterStatus = '';
$filterAssignedTo = '';
$filterSearch = '';

if ($seesAllApplications) {
    // Фильтр по статусу заявки
    // Допустимые значения: new, in_progress, completed, failed, except_closed
    $allowedStatuses = [
        'new', 'in_progress', 'pending_signing', 'product_request', 'terms_negotiation', 'pending_release',
        'completed', 'failed', 'except_closed',
    ];
    if ($isAnalystList) {
        $allowedStatuses = array_values(array_diff($allowedStatuses, ['failed']));
    }
    if (isset($_GET['status']) && in_array($_GET['status'], $allowedStatuses, true)) {
        $filterStatus = $_GET['status'];
    }
    
    // Фильтр по ответственному менеджеру (только для менеджера)
    $filterAssignedTo = '';
    if (finbuild_can('applications.assign', $currentUser) && isset($_GET['assigned_to']) && $_GET['assigned_to'] !== '') {
        $assignedToValue = $_GET['assigned_to'];
        // Проверяем сначала специальное значение "unassigned" для не назначенных заявок
        if ($assignedToValue === 'unassigned') {
            $filterAssignedTo = 'unassigned';
        } elseif ($assignedToValue === 'me_or_unassigned') {
            $filterAssignedTo = 'me_or_unassigned';
        } else {
            // Иначе пытаемся преобразовать в ID менеджера
            $assignedToId = (int)$assignedToValue;
            if ($assignedToId > 0) {
                // Проверяем, что это действительно менеджерская роль
                $checkStmt = $pdo->prepare('SELECT id FROM users WHERE id = ? AND role IN (' . finbuild_manager_roles_sql_in() . ') LIMIT 1');
                $checkStmt->execute([$assignedToId]);
                if ($checkStmt->fetch()) {
                    $filterAssignedTo = $assignedToId;
                }
            }
        }
    }
    
    // Поиск по компании или ИНН (текстовый поиск)
    if (isset($_GET['search']) && !empty(trim($_GET['search']))) {
        $filterSearch = trim($_GET['search']);
    }
    
    if (finbuild_can('applications.assign', $currentUser)) {
        $stmtManagers = $pdo->prepare('SELECT id, first_name, last_name FROM users WHERE role IN (' . finbuild_manager_roles_sql_in() . ') AND is_active = 1 ORDER BY last_name ASC, first_name ASC');
        $stmtManagers->execute();
        $managersList = $stmtManagers->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $managersList = [];
    }
} else {
    $filterAssignedTo = '';
    $managersList = [];
}

// Аналитик: по умолчанию «Все, кроме закрытых», если в URL нет явных фильтров
if ($isAnalystList && $filterStatus === '' && !isset($_GET['status']) && !isset($_GET['search'])) {
    $filterStatus = 'except_closed';
}

// ============================================================================
// ФУНКЦИЯ ДЛЯ ПОСТРОЕНИЯ WHERE УСЛОВИЙ С УЧЕТОМ ФИЛЬТРОВ
// ============================================================================
/**
 * Строит WHERE условия для SQL запросов с учетом фильтров
 * @param bool $isManager - является ли пользователь менеджером
 * @param int $userId - ID пользователя (для не-менеджеров)
 * @param string $filterStatus - фильтр по статусу
 * @param string|int $filterAssignedTo - фильтр по ответственному менеджеру (ID или 'unassigned' для не назначенных)
 * @param string $filterSearch - текстовый поиск
 * @return array - массив с 'where' (строка условий) и 'params' (массив параметров для bindValue)
 */
function buildWhereConditions($isManager, $userId, $filterStatus, $filterAssignedTo, $filterSearch, $excludeFailed = false, $submanagerId = null, $includeAsPrincipal = false) {
    $whereParts = [];
    $params = [];
    
    // Базовое условие: руководитель видит все; ограниченный менеджер — только где он ответственный; остальные - только свои заявки
    if ($isManager) {
        $whereParts[] = '1=1';
    } elseif ($submanagerId !== null) {
        $whereParts[] = 'a.assigned_to = :assigned_self';
        $params[':assigned_self'] = (int) $submanagerId;
    } elseif ($includeAsPrincipal) {
        $whereParts[] = '(a.created_by = :created_by OR a.principal_user_id = :principal_uid)';
        $params[':created_by'] = $userId;
        $params[':principal_uid'] = $userId;
    } else {
        $whereParts[] = 'a.created_by = :created_by';
        $params[':created_by'] = $userId;
    }

    if ($excludeFailed) {
        $whereParts[] = "a.status <> 'failed'";
    }
    
    // Фильтр по статусу заявки
    if ($filterStatus) {
        if ($filterStatus === 'except_closed') {
            // Показываем все, кроме завершенных и проваленных
            $whereParts[] = "a.status NOT IN ('completed', 'failed')";
        } else {
            $whereParts[] = 'a.status = :filter_status';
            $params[':filter_status'] = $filterStatus;
        }
    }
    
    // Фильтр по ответственному менеджеру
    if ($filterAssignedTo) {
        if ($filterAssignedTo === 'unassigned') {
            // Заявки без ответственного (assigned_to IS NULL)
            $whereParts[] = 'a.assigned_to IS NULL';
        } elseif ($filterAssignedTo === 'me_or_unassigned') {
            // Заявки где ответственный = я ИЛИ не назначен
            $whereParts[] = '(a.assigned_to IS NULL OR a.assigned_to = :filter_assigned_me)';
            $params[':filter_assigned_me'] = (int)$userId;
        } else {
            // Заявки с конкретным ответственным менеджером
            $whereParts[] = 'a.assigned_to = :filter_assigned_to';
            $params[':filter_assigned_to'] = (int)$filterAssignedTo;
        }
    }
    
    // Текстовый поиск по названию компании или ИНН
    if ($filterSearch) {
        // Используем LIKE для поиска по подстроке (регистронезависимый поиск)
        $whereParts[] = '(a.company_name LIKE :filter_search OR a.inn LIKE :filter_search)';
        $searchPattern = '%' . $filterSearch . '%';
        $params[':filter_search'] = $searchPattern;
    }
    
    return [
        'where' => implode(' AND ', $whereParts),
        'params' => $params
    ];
}

// ============================================================================
// ПРИМЕНЕНИЕ ФИЛЬТРОВ К SQL ЗАПРОСАМ
// ============================================================================

// Получаем WHERE условия с учетом фильтров
$whereData = buildWhereConditions($seesAllApplications, $userId, $filterStatus, $filterAssignedTo, $filterSearch, $isAnalystList, $isSubmanager ? (int) $userId : null, $userRole === 'client');
$whereClause = $whereData['where'];
$whereParams = $whereData['params'];

// ============================================================================
// ПОДСЧЕТ ОБЩЕГО КОЛИЧЕСТВА ЗАЯВОК С УЧЕТОМ ФИЛЬТРОВ
// ============================================================================
$countSql = "SELECT COUNT(*) FROM applications a WHERE {$whereClause}";
$countStmt = $pdo->prepare($countSql);
foreach ($whereParams as $key => $value) {
    $countStmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$countStmt->execute();
$totalCount = (int)$countStmt->fetchColumn();

// Вычисляем общее количество страниц
$totalPages = max(1, (int)ceil($totalCount / $perPage));
// Если текущая страница больше максимальной (например, после применения фильтров), возвращаемся на последнюю
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

// ============================================================================
// СТАТИСТИКА ПО СТАТУСАМ (БЕЗ УЧЕТА ФИЛЬТРОВ - показываем общую статистику)
// ============================================================================
// Для статистики используем базовые условия без фильтров по статусу/ответственному/поиску
// чтобы показывать общее количество заявок по статусам
$statsWhereData = buildWhereConditions($seesAllApplications, $userId, '', '', '', $isAnalystList, $isSubmanager ? (int) $userId : null, $userRole === 'client');
$statsWhereClause = $statsWhereData['where'];
$statsWhereParams = $statsWhereData['params'];

$statsSql = "SELECT status, COUNT(*) as cnt FROM applications a WHERE {$statsWhereClause} GROUP BY status";
$statsStmt = $pdo->prepare($statsSql);
foreach ($statsWhereParams as $key => $value) {
    $statsStmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$statsStmt->execute();
$statsRows = $statsStmt->fetchAll();

// ============================================================================
// ПОДСЧЕТ НЕПРОЧИТАННЫХ СООБЩЕНИЙ С УЧЕТОМ ФИЛЬТРОВ
// ============================================================================
// Для менеджеров непрочитанными считаем сообщения внешних участников в их тредах
$unreadWhereClause = $whereClause;
$unreadUnionSql = finbuild_unread_chat_union_sql();
if (!finbuild_can_use_product_chat($currentUser) || $isAnalystList || finbuild_is_analyst_role($userRole)) {
    $unreadJoinCondition = '0=1';
    $unreadWhereParams = $whereParams;
} else {
    $unreadJoinCondition = finbuild_unread_chat_join_condition((string) $userRole);
    $unreadWhereParams = $whereParams;
    if (!finbuild_is_manager($userRole)) {
        $unreadWhereParams = array_merge($whereParams, [':current_user' => $userId]);
    }
}

$unreadSql = "
    SELECT COUNT(DISTINCT uc.chat_id) as total_unread
    FROM applications a
    LEFT JOIN {$unreadUnionSql} uc ON uc.application_id = a.id
        AND {$unreadJoinCondition}
    WHERE {$unreadWhereClause}
";
$unreadStmt = $pdo->prepare($unreadSql);
foreach ($unreadWhereParams as $key => $value) {
    $unreadStmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$unreadStmt->execute();
$totalUnreadMessages = (int)$unreadStmt->fetchColumn();

// ============================================================================
// ПОЛУЧЕНИЕ СПИСКА ЗАЯВОК С УЧЕТОМ ФИЛЬТРОВ И ПАГИНАЦИИ
// ============================================================================
// Получаем список заявок с применением фильтров, пагинации и сортировки.
// Важно: заявки с непрочитанными сообщениями всегда показываются первыми,
// затем заявки без непрочитанных. Внутри каждой группы сортировка по дате создания.
// Это работает для всех ролей (клиенты, партнеры, менеджеры) и всех устройств
// (десктоп и мобильные), так как сортировка происходит на уровне SQL.

// Объединяем параметры фильтров с параметрами для текущего пользователя и пагинации
// :current_user только для не-менеджеров (в запросе используется в unreadJoinCondition)
$dataWhereParams = array_merge($whereParams, [
    ':limit' => $perPage,
    ':offset' => $offset
]);
if (!finbuild_is_manager($userRole) && !$isAnalystList && !finbuild_is_analyst_role($userRole)) {
    $dataWhereParams[':current_user'] = $userId;
}

// ============================================================================
// ЗНАЧЕНИЯ СТАТУСОВ ДЛЯ СОРТИРОВКИ
// ============================================================================
// Активные статусы (приоритет 1 в сортировке): не «закрытые»
$statusActivePipeline = "'new', 'in_progress', 'pending_signing', 'product_request', 'terms_negotiation', 'pending_release'";

// Формируем часть ORDER BY с конкатенацией для избежания проблем с парсингом
$orderByStatus = "CASE WHEN a.status IN (" . $statusActivePipeline . ") THEN 1 ELSE 2 END";

$dataSql = "
    SELECT a.*, 
           u.first_name, u.last_name, u.company_name as user_company, u.role,
           m.first_name as manager_name, m.last_name as manager_surname,
           assigned_user.first_name as assigned_first_name, assigned_user.last_name as assigned_last_name,
           COUNT(DISTINCT uc.chat_id) as unread_messages,
           MAX(uc.created_at) as last_unread_message_date
    FROM applications a 
    LEFT JOIN users u ON a.created_by = u.id 
    LEFT JOIN users m ON a.added_by = m.id
    LEFT JOIN users assigned_user ON assigned_user.id = a.assigned_to
    LEFT JOIN {$unreadUnionSql} uc ON uc.application_id = a.id
        AND {$unreadJoinCondition}
    WHERE {$whereClause}
    GROUP BY a.id
    -- ============================================================================
    -- СОРТИРОВКА: СНАЧАЛА ЗАЯВКИ С НЕПРОЧИТАННЫМИ СООБЩЕНИЯМИ И АКТИВНЫМИ СТАТУСАМИ
    -- ============================================================================
    -- Первый уровень: (COUNT(DISTINCT apc.id) > 0) DESC
    --   - Сначала заявки с непрочитанными сообщениями (1), потом без (0)
    -- 
    -- Второй уровень: CASE WHEN a.status IN (активные статусы) THEN 1 ELSE 2 END ASC
    --   - Приоритет активным статусам: статус на проверке и в работе = 1
    --   - Завершенные и проваленные: completed и failed = 2
    --   - ASC означает, что сначала идут активные (1), потом закрытые (2)
    -- 
    -- Третий уровень: COALESCE(MAX(apc.created_at), a.created_at) DESC
    --   - Для заявок с непрочитанными: сортировка по дате последнего непрочитанного сообщения
    --   - Для заявок без непрочитанных: сортировка по дате создания заявки
    --   - DESC означает, что новые/свежие сообщения/заявки идут первыми
    -- 
    -- Итоговый порядок:
    --   1. Заявки с непрочитанными + активные статусы (по дате последнего сообщения)
    --   2. Заявки с непрочитанными + закрытые статусы (по дате последнего сообщения)
    --   3. Заявки без непрочитанных + активные статусы (по дате создания)
    --   4. Заявки без непрочитанных + закрытые статусы (по дате создания)
    -- 
    -- Это работает для всех ролей: клиенты, партнеры и менеджеры видят свои непрочитанные
    ORDER BY (COUNT(DISTINCT uc.chat_id) > 0) DESC, 
             {$orderByStatus} ASC,
             COALESCE(MAX(uc.created_at), a.created_at) DESC
    LIMIT :limit OFFSET :offset
";
$stmt = $pdo->prepare($dataSql);
foreach ($dataWhereParams as $key => $value) {
    $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmt->execute();
$applications = $stmt->fetchAll();
$startItem = $totalCount > 0 ? $offset + 1 : 0;
$endItem = $totalCount > 0 ? min($offset + count($applications), $totalCount) : 0;

// Функция для форматирования статуса
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

// Функция для форматирования типа продукта
function getProductTypeBadge($productType) {
    switch ($productType) {
        case 'bg':
            return '<span class="badge bg-info">Банковская гарантия</span>';
        case 'credit':
            return '<span class="badge bg-success">Кредит</span>';
        default:
            return '<span class="badge bg-secondary">' . $productType . '</span>';
    }
}

function getProductTypeText($productType) {
    switch ($productType) {
        case 'bg':
            return 'Банковская гарантия';
        case 'credit':
            return 'Кредит для бизнеса';
        default:
            return $productType;
    }
}

// Функция для форматирования суммы
function formatAmount($amount) {
    if (!$amount) return '-';
    return number_format($amount, 2, ',', ' ') . ' ₽';
}

// Функция для получения короткого названия компании
function getShortCompanyName($name, $length = 30) {
    if (strlen($name) <= $length) return $name;
    return substr($name, 0, $length) . '...';
}

// Считаем статистику
$stats = [
    'total' => $totalCount,
    'new' => 0,
    'in_progress' => 0,
    'pending_signing' => 0,
    'product_request' => 0,
    'terms_negotiation' => 0,
    'pending_release' => 0,
    'completed' => 0,
    'failed' => 0
];
foreach ($statsRows as $row) {
    if (isset($stats[$row['status']])) {
        $stats[$row['status']] = (int)$row['cnt'];
    }
}
// В блоке «В работе» показываем сумму этапов воронки (все активные стадии кроме «на проверке»)
$stats['in_progress'] += $stats['pending_signing'] + $stats['product_request'] + $stats['terms_negotiation'] + $stats['pending_release'];
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1 class="h3 mb-0">
                <?php if (finbuild_can('applications.assign', $currentUser)): ?>
                    Все заявки
                <?php elseif ($isSubmanager): ?>
                    Мои заявки
                <?php elseif ($isPureAnalyst): ?>
                    Заявки
                <?php elseif ($isAnalystScope && $userIsAnalystFlag): ?>
                    Анализ заявок
                <?php else: ?>
                    Мои заявки
                <?php endif; ?>
            </h1>
            <p class="text-muted mb-0">
                <?php if (finbuild_is_manager($userRole)): ?>
                    Управление всеми заявками системы
                <?php elseif ($isPureAnalyst): ?>
                    Список заявок для анализа структуры и документов
                <?php elseif ($isAnalystScope && $userIsAnalystFlag): ?>
                    Все заявки для анализа структуры и документов
                <?php else: ?>
                    История ваших заявок
                <?php endif; ?>
            </p>
        </div>
        <div class="col-auto">
            <?php if (!$isAnalystList): ?>
                <a href="<?= 'create_application.php' ?>" class="btn btn-primary">
                    <i class="bi bi-plus-circle me-2"></i>Создать заявку
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.applications-table {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 20px rgba(0,0,0,0.08);
    overflow: hidden;
    font-size: 0.875rem; /* Уменьшаем размер шрифта */
}

.table th {
    background: #f8f9fa;
    border-bottom: 2px solid #e9ecef;
    font-weight: 600;
    color: #495057;
    padding: 0.75rem; /* Уменьшаем отступы */
    font-size: 0.875rem;
}

.table td {
    padding: 1.25rem; /* Увеличена высота строки для лучшей читаемости */
    vertical-align: middle;
    border-bottom: 1px solid #e9ecef;
    font-size: 0.875rem;
    white-space: nowrap; /* Предотвращаем перенос текста в ячейках */
}

/* Длинные подписи статусов заявки (новые этапы) */
.table td.td-application-status {
    white-space: normal;
    max-width: 220px;
}

.table tbody tr:hover {
    background-color: #f8f9fa;
}

.table tbody tr.unread-row {
    background-color: rgba(220, 53, 69, 0.08);
}

.table tbody tr.unread-row:hover {
    background-color: rgba(220, 53, 69, 0.12);
}

.status-badge {
    font-size: 0.75rem; /* Уменьшаем бейджи */
    padding: 0.25rem 0.5rem;
}

.actions-cell {
    width: 100px; /* Уменьшаем ширину колонки действий */
    text-align: center;
}

.company-name {
    font-weight: 500;
    color: #2c3e50;
    font-size: 0.875rem;
}
.company-name .badge {
    margin-left: 0.35rem;
    vertical-align: middle;
    font-weight: 600;
}

.amount-cell {
    font-weight: 600;
    color: #27ae60;
    font-size: 0.875rem;
}

.empty-state {
    padding: 3rem 2rem;
    text-align: center;
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 20px rgba(0,0,0,0.08);
}


.empty-state-icon {
    font-size: 4rem;
    color: #6c757d;
    margin-bottom: 1.5rem;
}

.filter-section {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 20px rgba(0,0,0,0.08);
}

/* Уменьшаем размер карточек статистики */
.stats-cards {
    margin-bottom: 1rem;
}

.stats-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 1rem;
}

.stats-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 0.4rem 0.7rem;
    border-radius: 999px;
    background: #fff;
    border: 1px solid #e9ecef;
    font-size: 0.8rem;
    color: #495057;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
}

.stats-chip .chip-number {
    font-weight: 700;
}

.stats-chip.stat-total { border-color: #6c757d; color: #6c757d; }
.stats-chip.stat-new { border-color: #ffc107; color: #b8860b; }
.stats-chip.stat-in-progress { border-color: #3498db; color: #2e86c1; }
.stats-chip.stat-completed { border-color: #28a745; color: #1e7e34; }
.stats-chip.stat-failed { border-color: #dc3545; color: #b02a37; }
.stats-chip.stat-messages { border-color: #0d6efd; color: #0d6efd; }

.stat-card {
    background: white;
    border-radius: 10px;
    padding: 0.75rem; /* Уменьшаем отступы */
    text-align: center;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    border-top: 3px solid;
    border-left: none;
    height: 100%;
    transition: transform 0.2s, box-shadow 0.2s;
}

.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 3px 8px rgba(0,0,0,0.15);
}

.stat-card .number {
    font-size: 1.25rem; /* Уменьшаем размер чисел */
    font-weight: 700;
    margin-bottom: 0.25rem;
    line-height: 1.2;
}

.stat-card .label {
    color: #6c757d;
    font-size: 0.75rem; /* Уменьшаем размер текста */
    font-weight: 500;
}

.stat-card .icon {
    font-size: 1rem; /* Уменьшаем размер иконок */
    margin-bottom: 0.5rem;
}

/* Цвета статусов */
.stat-total { border-top-color: #6c757d; }
.stat-total .number { color: #6c757d; }
.stat-total .icon { color: #6c757d; }

.stat-new { border-top-color: #ffc107; }
.stat-new .number { color: #ffc107; }
.stat-new .icon { color: #ffc107; }

.stat-in-progress { border-top-color: #3498db; }
.stat-in-progress .number { color: #3498db; }
.stat-in-progress .icon { color: #3498db; }

.stat-completed { border-top-color: #28a745; }
.stat-completed .number { color: #28a745; }
.stat-completed .icon { color: #28a745; }

.stat-failed { border-top-color: #dc3545; }
.stat-failed .number { color: #dc3545; }
.stat-failed .icon { color: #dc3545; }

/* Градиентные бейджи статусов как в application_details.php */
.status-badge-new {
    background: linear-gradient(135deg, #ffc107, #ff9800);
    border: none;
    padding: 0.35rem 0.65rem;
    font-weight: 500;
    font-size: 0.75rem;
    box-shadow: 0 2px 4px rgba(255, 152, 0, 0.2);
}

.status-badge-in-progress {
    background: linear-gradient(135deg, #3498db, #2980b9);
    border: none;
    padding: 0.35rem 0.65rem;
    font-weight: 500;
    font-size: 0.75rem;
    box-shadow: 0 2px 4px rgba(52, 152, 219, 0.2);
}

.status-badge-completed {
    background: linear-gradient(135deg, #28a745, #20c997);
    border: none;
    padding: 0.35rem 0.65rem;
    font-weight: 500;
    font-size: 0.75rem;
    box-shadow: 0 2px 4px rgba(39, 174, 96, 0.2);
}

.status-badge-failed {
    background: linear-gradient(135deg, #dc3545, #c82333);
    border: none;
    padding: 0.35rem 0.65rem;
    font-weight: 500;
    font-size: 0.75rem;
    box-shadow: 0 2px 4px rgba(220, 53, 69, 0.2);
}

.status-badge-pending-signing {
    background: linear-gradient(135deg, #17a2b8, #138496);
    border: none;
    padding: 0.35rem 0.65rem;
    font-weight: 500;
    font-size: 0.75rem;
    color: #fff;
}

.status-badge-product-request {
    background: linear-gradient(135deg, #ffc107, #e0a800);
    border: none;
    padding: 0.35rem 0.65rem;
    font-weight: 500;
    font-size: 0.75rem;
    color: #fff;
}

.status-badge-terms-negotiation {
    background: linear-gradient(135deg, #6f42c1, #5a32a3);
    border: none;
    padding: 0.35rem 0.65rem;
    font-weight: 500;
    font-size: 0.75rem;
    color: #fff;
}

.status-badge-pending-release {
    background: linear-gradient(135deg, #fd7e14, #e8590c);
    border: none;
    padding: 0.35rem 0.65rem;
    font-weight: 500;
    font-size: 0.75rem;
    color: #fff;
}

/* Стили для бейджа уведомлений */
.notification-badge {
    background: linear-gradient(135deg, #dc3545, #c82333);
    border: none;
    padding: 0.25rem 0.5rem;
    font-size: 0.7rem;
    font-weight: 500;
    box-shadow: 0 2px 4px rgba(220, 53, 69, 0.2);
    min-width: 24px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.notification-badge:hover {
    transform: scale(1.05);
    transition: transform 0.2s;
}

.notification-badge i {
    font-size: 0.65rem;
}

/* Мобильные карточки заявок */
.applications-cards {
    display: grid;
    gap: 12px;
}

.app-card {
    background: #fff;
    border: 1px solid #eef2f7;
    border-radius: 16px;
    padding: 16px;
    box-shadow: 0 10px 24px rgba(15, 23, 42, 0.06);
    transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
}

.app-card:hover {
    transform: translateY(-1px);
    border-color: #e2e8f0;
    box-shadow: 0 14px 28px rgba(15, 23, 42, 0.08);
}

.app-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    margin-bottom: 8px;
}

.app-card-title {
    font-weight: 700;
    font-size: 1rem;
    margin: 0;
    color: #0f172a;
}

.app-card-sub {
    color: #64748b;
    font-size: 0.8rem;
}

.app-card-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 8px 12px;
    margin-top: 10px;
    font-size: 0.8rem;
}

.app-card-row {
    display: flex;
    justify-content: space-between;
    gap: 8px;
}

.app-card-label {
    color: #64748b;
}

.app-card-actions {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 12px;
    padding-top: 10px;
    border-top: 1px dashed #e2e8f0;
}

.app-card-link {
    text-decoration: none;
    color: inherit;
    display: block;
    -webkit-tap-highlight-color: rgba(52, 152, 219, 0.15);
    touch-action: manipulation;
}

.app-card .badge {
    font-size: 0.7rem;
    padding: 0.3rem 0.55rem;
    border-radius: 999px;
}

.pagination-controls {
    background: #fff;
    border: 1px solid #e9ecef;
    border-radius: 12px;
    padding: 0.75rem 1rem;
    box-shadow: 0 2px 12px rgba(0,0,0,0.06);
}

.pagination-controls .form-select {
    border-radius: 999px;
    height: 34px;
    padding: 0.25rem 1.8rem 0.25rem 0.8rem;
    background-position: right 0.6rem center;
}

.pagination-controls form {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.pagination {
    gap: 6px;
}

.pagination .page-link {
    border-radius: 10px;
    border: 1px solid #e9ecef;
    color: #495057;
    padding: 0.35rem 0.65rem;
    min-width: 38px;
    height: 34px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-align: center;
    line-height: 1;
}

.pagination .page-link:hover {
    background: #f1f5f9;
    color: #1f2937;
}

.pagination .page-item.active .page-link {
    background: #3498db;
    border-color: #3498db;
    color: #fff;
}

.pagination .page-item.disabled .page-link {
    background: #f8f9fa;
    color: #adb5bd;
}

.saved-filter-btn {
    white-space: nowrap;
}

@media (max-width: 768px) {
    .page-header {
        padding: 0.85rem 1rem;
        border-radius: 12px;
    }
    .page-header h1 {
        font-size: 1.15rem;
        font-weight: 700;
        line-height: 1.3;
    }

    .page-header p {
        font-size: 0.8rem;
        line-height: 1.2;
        margin-top: 0.25rem;
    }

    .page-header .btn {
        font-size: 0.85rem;
        padding: 0.4rem 0.7rem;
    }

    .page-header .row {
        row-gap: 0.5rem;
    }

    .stats-cards {
        display: none;
    }

    .filter-section {
        padding: 1rem;
    }

    .filter-section .row > [class*="col-"] {
        width: 100%;
    }
    .filter-section .btn,
    .filter-section .form-select,
    .filter-section .form-control {
        width: 100%;
    }
    .filter-section .form-label {
        font-size: 0.8rem;
    }
    .filter-section .btn,
    .filter-section .form-select,
    .filter-section .form-control {
        font-size: 0.85rem;
        padding: 0.4rem 0.7rem;
    }
    .filter-section .btn i {
        font-size: 0.85rem;
    }
    .filter-toggle-btn {
        font-size: 0.85rem;
        padding: 0.4rem 0.7rem;
    }
    .filter-toggle-btn .bi {
        font-size: 0.85rem;
    }

    .pagination-controls {
        padding: 0.75rem;
        gap: 0.5rem;
    }
    .pagination-controls form {
        order: 1;
        width: auto;
        font-size: 0.85rem;
    }
    .pagination-controls form .form-select {
        height: 30px;
        font-size: 0.85rem;
        padding: 0.2rem 1.6rem 0.2rem 0.7rem;
    }
    .pagination-controls .text-muted {
        order: 1;
        width: auto;
        font-size: 0.8rem;
        white-space: nowrap;
    }
    .pagination-controls nav {
        order: 2;
        width: 100%;
        display: flex;
        justify-content: center;
    }
    .pagination {
        justify-content: center;
        flex-wrap: nowrap;
        overflow-x: auto;
        padding-bottom: 4px;
        scrollbar-width: thin;
    }
    .pagination .page-item {
        flex: 0 0 auto;
    }
    .pagination .page-link {
        min-width: 34px;
        height: 32px;
        padding: 0.3rem 0.55rem;
    }
}
</style>

<div class="row">
    <div class="col-12">
        <!-- Статистика для менеджеров и аналитиков -->
        <?php if (finbuild_is_manager($userRole) || $isAnalystList): ?>
        <div class="row stats-cards">
            <div class="col-xl-2 col-md-4 col-6 mb-3">
                <div class="stat-card stat-total">
                    <div class="icon">
                        <i class="bi bi-inboxes"></i>
                    </div>
                    <div class="number">
                        <?= $stats['total'] ?>
                    </div>
                    <div class="label">Всего</div>
                </div>
            </div>
            <div class="col-xl-2 col-md-4 col-6 mb-3">
                <div class="stat-card stat-new">
                    <div class="icon">
                        <i class="bi bi-clock"></i>
                    </div>
                    <div class="number">
                        <?= $stats['new'] ?>
                    </div>
                    <div class="label">На проверке</div>
                </div>
            </div>
            <div class="col-xl-2 col-md-4 col-6 mb-3">
                <div class="stat-card stat-in-progress">
                    <div class="icon">
                        <i class="bi bi-gear"></i>
                    </div>
                    <div class="number">
                        <?= $stats['in_progress'] ?>
                    </div>
                    <div class="label">В работе</div>
                </div>
            </div>
            <div class="col-xl-2 col-md-4 col-6 mb-3">
                <div class="stat-card stat-completed">
                    <div class="icon">
                        <i class="bi bi-check-circle"></i>
                    </div>
                    <div class="number">
                        <?= $stats['completed'] ?>
                    </div>
                    <div class="label">Завершены</div>
                </div>
            </div>
            <?php if (!$isAnalystList): ?>
            <div class="col-xl-2 col-md-4 col-6 mb-3">
                <div class="stat-card stat-failed">
                    <div class="icon">
                        <i class="bi bi-x-circle"></i>
                    </div>
                    <div class="number">
                        <?= $stats['failed'] ?>
                    </div>
                    <div class="label">Провалены</div>
                </div>
            </div>
            <?php endif; ?>
            <?php if (finbuild_is_manager($userRole)): ?>
            <div class="col-xl-2 col-md-4 col-6 mb-3">
                <div class="stat-card stat-total">
                    <div class="icon">
                        <i class="bi bi-chat-dots"></i>
                    </div>
                    <div class="number">
                        <?= $totalUnreadMessages ?>
                    </div>
                    <div class="label">Новых сообщений</div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <div class="stats-chips d-md-none">
            <div class="stats-chip stat-total"><span class="chip-number"><?= $stats['total'] ?></span>Всего</div>
            <div class="stats-chip stat-new"><span class="chip-number"><?= $stats['new'] ?></span>На проверке</div>
            <div class="stats-chip stat-in-progress"><span class="chip-number"><?= $stats['in_progress'] ?></span>В работе</div>
            <div class="stats-chip stat-completed"><span class="chip-number"><?= $stats['completed'] ?></span>Завершены</div>
            <?php if (!$isAnalystList): ?>
            <div class="stats-chip stat-failed"><span class="chip-number"><?= $stats['failed'] ?></span>Провалены</div>
            <?php endif; ?>
            <?php if (finbuild_is_manager($userRole)): ?>
            <div class="stats-chip stat-messages"><span class="chip-number"><?= $totalUnreadMessages ?></span>Новых сообщений</div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Фильтры для менеджеров и аналитиков -->
        <?php if (finbuild_is_manager($userRole) || $isAnalystList): ?>
        <div class="d-md-none mb-2">
            <button class="btn btn-outline-secondary w-100 filter-toggle-btn" type="button" data-bs-toggle="collapse" data-bs-target="#filtersCollapse" aria-expanded="false" aria-controls="filtersCollapse">
                <i class="bi bi-funnel me-2"></i>Фильтры
            </button>
        </div>
        <div class="collapse d-md-block show" id="filtersCollapse">
        <div class="filter-section">
            <div class="row align-items-center">
                <div class="col-md-3 mb-2">
                    <label class="form-label">Статус</label>
                  <select class="form-select" id="statusFilter">
    <option value="" <?= $filterStatus === '' ? 'selected' : '' ?>>Все статусы</option>
    <option value="new" <?= $filterStatus === 'new' ? 'selected' : '' ?>>На проверке</option>
    <option value="in_progress" <?= $filterStatus === 'in_progress' ? 'selected' : '' ?>>В работе</option>
    <option value="pending_signing" <?= $filterStatus === 'pending_signing' ? 'selected' : '' ?>>На подписании</option>
    <option value="product_request" <?= $filterStatus === 'product_request' ? 'selected' : '' ?>>Запрос</option>
    <option value="terms_negotiation" <?= $filterStatus === 'terms_negotiation' ? 'selected' : '' ?>>Согласование условий</option>
    <option value="pending_release" <?= $filterStatus === 'pending_release' ? 'selected' : '' ?>>На выпуске</option>
    <option value="except_closed" <?= $filterStatus === 'except_closed' ? 'selected' : '' ?>>Все, кроме закрытых</option>
    <option value="completed" <?= $filterStatus === 'completed' ? 'selected' : '' ?>>Завершены</option>
    <?php if (!$isAnalystList): ?>
    <option value="failed" <?= $filterStatus === 'failed' ? 'selected' : '' ?>>Провалены</option>
    <?php endif; ?>
</select>
                </div>
                <?php if (finbuild_can('applications.assign', $currentUser)): ?>
                <div class="col-md-3 mb-2">
                    <label class="form-label">Ответственный</label>
                    <select class="form-select" id="assignedToFilter">
                        <option value="" <?= $filterAssignedTo === '' ? 'selected' : '' ?>>Все</option>
                        <option value="me_or_unassigned" <?= $filterAssignedTo === 'me_or_unassigned' ? 'selected' : '' ?>>Я и не назначен</option>
                        <option value="unassigned" <?= $filterAssignedTo === 'unassigned' ? 'selected' : '' ?>>Не назначен</option>
                        <?php foreach ($managersList as $m): ?>
                            <option value="<?= (int)$m['id'] ?>" <?= (int)($filterAssignedTo ?? 0) === (int)$m['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars(trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? ''))) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-md-<?= finbuild_is_manager($userRole) ? '4' : '7' ?> mb-2">
                    <label class="form-label">Поиск</label>
                    <input type="text" class="form-control" id="searchInput" placeholder="Поиск по компании или ИНН..." value="<?= htmlspecialchars($filterSearch) ?>">
                </div>
                <div class="col-md-2 mb-2">
                    <label class="form-label">&nbsp;</label>
                    <div class="d-flex gap-2">
                        <button class="btn btn-outline-primary saved-filter-btn" id="toggleSaveFilter" type="button" title="Сохранить фильтр">
                            <i class="bi bi-bookmark"></i>
                        </button>
                        <button class="btn btn-outline-secondary flex-grow-1" id="resetFilters" title="Сбросить фильтры до сохраненных">
                            <i class="bi bi-arrow-clockwise"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        </div>
        <?php endif; ?>

        <!-- Таблица заявок -->
        <?php if (empty($applications)): ?>
            <?php
            // Определяем, есть ли активные фильтры
            $hasActiveFilters = ((finbuild_is_manager($userRole) || $isAnalystList) && ($filterStatus || $filterAssignedTo || $filterSearch));
            ?>
            <div class="empty-state">
                <div class="empty-state-icon">
                    <i class="bi <?= $hasActiveFilters ? 'bi-search' : 'bi-inbox' ?>"></i>
                </div>
                <?php if ($hasActiveFilters): ?>
                    <h4 class="text-muted mb-3">Ничего не найдено</h4>
                    <p class="text-muted mb-4">
                        Попробуйте изменить фильтры или поиск
                    </p>
                <?php else: ?>
                    <h4 class="text-muted mb-3">Заявок пока нет</h4>
                    <p class="text-muted mb-4">
                        <?php if (finbuild_is_manager($userRole) || $isAnalystList): ?>
                            В системе еще не создано ни одной заявки
                        <?php else: ?>
                            У вас пока нет созданных заявок
                        <?php endif; ?>
                    </p>
                    <?php if (!$isAnalystList): ?>
                    <a href="<?= 'create_application.php' ?>" class="btn btn-primary">
                        <i class="bi bi-plus-circle me-2"></i>Создать первую заявку
                    </a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <!-- Мобильный вид карточками -->
            <div class="applications-cards d-md-none">
                <?php foreach ($applications as $application): ?>
                    <a class="app-card app-card-link"
                       href="application_details.php?id=<?= (int)$application['id'] ?>&return=<?= urlencode($returnToApplications) ?>"
                       data-status="<?= $application['status'] ?>" data-product-type="<?= $application['product_type'] ?>">
                            <div class="app-card-header">
                            <div>
                                <div class="app-card-title">#<?= $application['id'] ?> · <?= htmlspecialchars($application['company_name']) ?> <?= finbuild_application_intake_badge_html($application) ?></div>
                                <div class="app-card-sub">ИНН: <?= htmlspecialchars($application['inn']) ?>
                                    <?php
                                    $intakeMark = finbuild_application_intake_mark($application);
                                    if ($intakeMark['is_intake'] && $intakeMark['customer'] !== '' && $intakeMark['customer'] !== (string) $application['company_name']):
                                    ?>
                                        <div>Заказчик: <?= htmlspecialchars($intakeMark['customer']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?= getStatusBadge($application['status']) ?>
                        </div>
                        <div class="app-card-meta">
                            <?php if (finbuild_can('applications.assign', $currentUser)): ?>
                                <div class="app-card-row">
                                    <span class="app-card-label">Чья заявка:</span>
                                    <div>
                                        <?php
                                        $creatorRole = $application['role'] ?? 'client';
                                        $roleNames = [
                                            'manager' => 'Менеджер',
                                            'partner' => 'Партнер',
                                            'client' => 'Клиент',
                                            'beneficiary' => 'Заказчик'
                                        ];
                                        $roleName = $roleNames[$creatorRole] ?? 'Клиент';
                                        ?>
                                        <span class="badge bg-secondary me-1"><?= $roleName ?></span>
                                        <span><?= htmlspecialchars($application['first_name'] . ' ' . $application['last_name']) ?></span>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <div class="app-card-row">
                                <span class="app-card-label">Продукт:</span>
                                <span><?= getProductTypeText($application['product_type']) ?></span>
                            </div>
                            <?php if ($application['amount']): ?>
                                <div class="app-card-row">
                                    <span class="app-card-label">Сумма:</span>
                                    <span class="fw-semibold text-success"><?= formatAmount($application['amount']) ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if (finbuild_is_manager($userRole) && !empty($application['assigned_to']) && !empty($application['assigned_first_name']) && !empty($application['assigned_last_name'])): ?>
                                <div class="app-card-row">
                                    <span class="app-card-label">Ответственный:</span>
                                    <span><?= htmlspecialchars(trim($application['assigned_first_name'] . ' ' . $application['assigned_last_name'])) ?></span>
                                </div>
                            <?php endif; ?>
                            <div class="app-card-row">
                                <span class="app-card-label">Дата:</span>
                                <span><?= date('d.m.Y H:i', strtotime($application['created_at'])) ?></span>
                            </div>
                        </div>
                        <div class="app-card-actions">
                            <?php if (!$isAnalystList): ?>
                            <?php if ($application['unread_messages'] > 0): ?>
                                <span class="badge bg-danger notification-badge">
                                    <i class="bi bi-chat-dots me-1"></i><?= $application['unread_messages'] ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted small">Без сообщений</span>
                            <?php endif; ?>
                            <?php endif; ?>
                            <span class="text-primary small fw-semibold">Открыть</span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- Табличный вид для десктопа -->
            <div class="applications-table d-none d-md-block">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="applicationsTable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Компания</th>
                                <th>ИНН</th>
                                <?php if (finbuild_can('applications.assign', $currentUser)): ?>
                                    <th>Чья заявка</th>
                                <?php endif; ?>
                                <th>Продукт</th>
                                <th>Сумма</th>
                                <th>Статус</th>
                                <?php if (finbuild_is_manager($userRole)): ?>
                                    <th>Ответственный</th>
                                <?php endif; ?>
                                <th>Дата создания</th>
                                <?php if (!$isAnalystList): ?>
                                 <th>Уведомления</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                      <tbody>
        <?php foreach ($applications as $application): ?>
            <tr data-status="<?= $application['status'] ?>" data-product-type="<?= $application['product_type'] ?>"
                data-has-unread="<?= ($application['unread_messages'] > 0) ? '1' : '0' ?>"
                class="<?= ($application['unread_messages'] > 0) ? 'unread-row' : '' ?>"
                role="button" style="cursor: pointer;"
                onclick="if (!event.target.closest('a, button, .no-row-click')) window.location='application_details.php?id=<?= (int)$application['id'] ?>&return=<?= urlencode($returnToApplications) ?>'">
                <td>
                    <strong>#<?= $application['id'] ?></strong>
                </td>
                <td>
                    <div class="company-name">
                         <?= htmlspecialchars((string) $application['company_name']) ?>
                         <?= finbuild_application_intake_badge_html($application) ?>
                    </div>
                    <?php
                    $intakeMark = finbuild_application_intake_mark($application);
                    if ($intakeMark['is_intake'] && $intakeMark['customer'] !== '' && $intakeMark['customer'] !== (string) $application['company_name']):
                    ?>
                        <div class="small text-muted mt-1">Заказчик: <?= htmlspecialchars($intakeMark['customer']) ?></div>
                    <?php endif; ?>
                </td>
                <td>
                    <small class="text-muted"><?= htmlspecialchars($application['inn']) ?></small>
                </td>
               <?php if (finbuild_can('applications.assign', $currentUser)): ?>
     <td>
        <!-- Плашка с ролью владельца -->
        <div class="role-badge-small mb-1">
            <?php
            $creatorRole = $application['role'] ?? 'client';
            $roleNames = [
                'manager' => 'Менеджер',
                'partner' => 'Партнер', 
                'client' => 'Клиент',
                'beneficiary' => 'Заказчик'
            ];
            $roleName = $roleNames[$creatorRole] ?? 'Клиент';
            ?>
            <span class="badge bg-secondary"><?= $roleName ?></span>
        </div>
        
        <!-- Имя владельца (клиента) -->
        <div class="fw-medium">
            <?= htmlspecialchars($application['first_name'] . ' ' . $application['last_name']) ?>
        </div>
    </td>
<?php endif; ?>
                <td>
    <?= getProductTypeBadge($application['product_type']) ?>
    <?php if ($application['product_type'] === 'bg'): ?>
        <?php if ($application['fz_type'] || $application['guarantee_type']): ?>
            <small class="d-block text-muted mt-1">
                <?php
                $bgDetails = [];
                if ($application['fz_type']) {
                    $bgDetails[] = htmlspecialchars($application['fz_type']);
                }
                if ($application['guarantee_type']) {
                    $bgDetails[] = htmlspecialchars($application['guarantee_type']);
                }
                echo implode(', ', $bgDetails);
                ?>
            </small>
        <?php endif; ?>
    <?php else: ?>
        <?php if ($application['loan_type']): ?>
            <small class="d-block text-muted mt-1">
                <?= htmlspecialchars($application['loan_type']) ?>
            </small>
        <?php endif; ?>
    <?php endif; ?>
</td>
                <td class="amount-cell">
                    <?= formatAmount($application['amount']) ?>
                </td>
                <td class="td-application-status">
                    <?= getStatusBadge($application['status']) ?>
                </td>
                <?php if (finbuild_is_manager($userRole)): ?>
                <td>
                    <?php if (!empty($application['assigned_to']) && !empty($application['assigned_first_name']) && !empty($application['assigned_last_name'])): ?>
                        <span class="text-muted small"><?= htmlspecialchars(trim($application['assigned_first_name'] . ' ' . $application['assigned_last_name'])) ?></span>
                    <?php else: ?>
                        <span class="text-muted small">Не назначен</span>
                    <?php endif; ?>
                </td>
                <?php endif; ?>
                <td>
                    <div><?= date('d.m.Y H:i', strtotime($application['created_at'])) ?></div>
                    <?php if (finbuild_can('applications.assign', $currentUser)): ?>
                        <?php
                        // Если есть added_by, значит заявку создал менеджер
                        if (!empty($application['added_by']) && !empty($application['manager_name']) && !empty($application['manager_surname'])) {
                            $creatorText = '<i class="bi bi-gear me-1"></i>Создано: ' . htmlspecialchars(trim($application['manager_name'] . ' ' . $application['manager_surname']));
                        } else {
                            // Иначе определяем по роли создателя
                            $creatorRole = $application['role'] ?? 'client';
                            if ($creatorRole === 'client') {
                                $creatorText = '<i class="bi bi-gear me-1"></i>Создал клиент';
                            } elseif ($creatorRole === 'partner') {
                                $creatorText = '<i class="bi bi-gear me-1"></i>Создал партнер';
                            } elseif ($creatorRole === 'beneficiary') {
                                $creatorText = '<i class="bi bi-gear me-1"></i>Создал заказчик';
                            } else {
                                $creatorText = '';
                            }
                        }
                        ?>
                        <?php if ($creatorText): ?>
                            <div class="text-muted small mt-1"><?= $creatorText ?></div>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
                <?php if (!$isAnalystList): ?>
                <td>
    <?php if ($application['unread_messages'] > 0): ?>
        <span class="badge bg-danger notification-badge" 
              title="Непрочитанных сообщений: <?= $application['unread_messages'] ?>">
            <i class="bi bi-chat-dots me-1"></i><?= $application['unread_messages'] ?>
        </span>
    <?php else: ?>
        <span class="text-muted small">—</span>
    <?php endif; ?>
</td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
    </tbody>
                    </table>
                </div>
            </div>

            <?php
            $queryParams = $_GET;
            $queryParams['per_page'] = $perPage;
            $maxLinks = 5;
            $startPage = max(1, $page - 2);
            $endPage = min($totalPages, $startPage + $maxLinks - 1);
            $startPage = max(1, $endPage - $maxLinks + 1);
            ?>
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-3 pagination-controls">
                <form method="GET" class="d-flex align-items-center gap-2">
                    <input type="hidden" name="page" value="1">
                    <?php foreach ($_GET as $key => $value): ?>
                        <?php if (in_array($key, ['per_page', 'page'], true)) continue; ?>
                        <input type="hidden" name="<?= htmlspecialchars($key) ?>" value="<?= htmlspecialchars($value) ?>">
                    <?php endforeach; ?>
                    <label class="form-label mb-0">Показывать</label>
                    <select name="per_page" class="form-select form-select-sm" onchange="this.form.submit()">
                        <?php foreach ($perPageOptions as $option): ?>
                            <option value="<?= $option ?>" <?= $perPage === $option ? 'selected' : '' ?>><?= $option ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <div id="pagination-info"
                     class="text-muted small"
                     data-default-text="Показано <?= $startItem ?>–<?= $endItem ?> из <?= $totalCount ?>"
                     data-page-total="<?= count($applications) ?>">
                    Показано <?= $startItem ?>–<?= $endItem ?> из <?= $totalCount ?>
                </div>
                <nav aria-label="Навигация по страницам">
                    <ul class="pagination mb-0">
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <?php $prevParams = $queryParams; $prevParams['page'] = max(1, $page - 1); ?>
                            <a class="page-link" href="applications.php?<?= htmlspecialchars(http_build_query($prevParams)) ?>" aria-label="Назад">
                                <span aria-hidden="true">&laquo;</span>
                            </a>
                        </li>

                        <?php if ($startPage > 1): ?>
                            <?php $firstParams = $queryParams; $firstParams['page'] = 1; ?>
                            <li class="page-item">
                                <a class="page-link" href="applications.php?<?= htmlspecialchars(http_build_query($firstParams)) ?>">1</a>
                            </li>
                            <?php if ($startPage > 2): ?>
                                <li class="page-item disabled"><span class="page-link">…</span></li>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                            <?php $pageParams = $queryParams; $pageParams['page'] = $i; ?>
                            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                <a class="page-link" href="applications.php?<?= htmlspecialchars(http_build_query($pageParams)) ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>

                        <?php if ($endPage < $totalPages): ?>
                            <?php if ($endPage < $totalPages - 1): ?>
                                <li class="page-item disabled"><span class="page-link">…</span></li>
                            <?php endif; ?>
                            <?php $lastParams = $queryParams; $lastParams['page'] = $totalPages; ?>
                            <li class="page-item">
                                <a class="page-link" href="applications.php?<?= htmlspecialchars(http_build_query($lastParams)) ?>"><?= $totalPages ?></a>
                            </li>
                        <?php endif; ?>

                        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                            <?php $nextParams = $queryParams; $nextParams['page'] = min($totalPages, $page + 1); ?>
                            <a class="page-link" href="applications.php?<?= htmlspecialchars(http_build_query($nextParams)) ?>" aria-label="Вперед">
                                <span aria-hidden="true">&raquo;</span>
                            </a>
                        </li>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // ============================================================================
    // СИСТЕМА ФИЛЬТРАЦИИ ЧЕРЕЗ URL ПАРАМЕТРЫ
    // ============================================================================
    // Фильтрация теперь происходит на сервере через SQL запросы.
    // При изменении фильтров обновляем URL и перезагружаем страницу.
    
    <?php if (finbuild_is_manager($userRole) || $isAnalystList): ?>
    const isAnalystList = <?= $isAnalystList ? 'true' : 'false' ?>;
    const statusFilter = document.getElementById('statusFilter');
    const assignedToFilter = document.getElementById('assignedToFilter');
    const searchInput = document.getElementById('searchInput');
    const resetFilters = document.getElementById('resetFilters');
    const toggleSaveFilter = document.getElementById('toggleSaveFilter');
    const filtersCollapse = document.getElementById('filtersCollapse');
    
    // Таймер для debounce поиска (чтобы не отправлять запрос при каждом символе)
    let searchTimeout = null;
    const SEARCH_DEBOUNCE_MS = 500; // Задержка перед отправкой поискового запроса
    
    /**
     * Обновляет URL с параметрами фильтров и перезагружает страницу
     * @param {boolean} resetPage - сбрасывать ли номер страницы на 1 при изменении фильтров
     */
    function updateFilters(resetPage = true) {
        const params = new URLSearchParams(window.location.search);
        // Пользователь вручную меняет фильтры — не автоприменяем сохранённый пресет
        params.set('manual', '1');
        
        // Обновляем параметры фильтров
        if (statusFilter && statusFilter.value) {
            params.set('status', statusFilter.value);
        } else {
            params.delete('status');
        }
        
        if (assignedToFilter && assignedToFilter.value) {
            params.set('assigned_to', assignedToFilter.value);
        } else {
            params.delete('assigned_to');
        }
        
        const searchValue = searchInput ? searchInput.value.trim() : '';
        if (searchValue) {
            params.set('search', searchValue);
        } else {
            params.delete('search');
        }
        
        // При изменении фильтров сбрасываем страницу на первую (если resetPage = true)
        if (resetPage) {
            params.set('page', '1');
        }
        
        // Сохраняем per_page если он был установлен
        const currentPerPage = params.get('per_page');
        if (!currentPerPage) {
            params.set('per_page', '<?= $perPage ?>');
        }
        
        // Переходим на новый URL (перезагружает страницу с новыми фильтрами)
        window.location.href = 'applications.php?' + params.toString();
    }
    
    /**
     * Сбрасывает все фильтры и очищает URL параметры
     */
    function resetAllFilters() {
        const params = new URLSearchParams(window.location.search);
        // Сброс фильтров — возвращаемся к «режиму по умолчанию» (разрешаем автоприменение сохранённого)
        params.delete('manual');
        
        // Удаляем все параметры фильтров
        params.delete('status');
        params.delete('assigned_to');
        params.delete('search');
        params.set('page', '1'); // Возвращаемся на первую страницу
        
        // Сохраняем per_page
        const currentPerPage = params.get('per_page');
        if (!currentPerPage) {
            params.set('per_page', '<?= $perPage ?>');
        }
        
        // Переходим на очищенный URL
        window.location.href = 'applications.php?' + params.toString();
    }

    // ============================================================================
    // SAVED FILTER (one button toggle)
    // ============================================================================
    function urlHasAnyFilters() {
        const p = new URLSearchParams(window.location.search);
        return !!(p.get('status') || p.get('assigned_to') || (p.get('search') || '').trim());
    }

    function isManualMode() {
        const p = new URLSearchParams(window.location.search);
        return p.get('manual') === '1';
    }

    function getCurrentPerPage() {
        const p = new URLSearchParams(window.location.search);
        return p.get('per_page') || '<?= (int)$perPage ?>';
    }

    function getCurrentPayload() {
        return {
            status: statusFilter?.value || '',
            assigned_to: assignedToFilter?.value || '',
            search: (searchInput?.value || '').trim(),
            per_page: getCurrentPerPage(),
        };
    }

    function applyPayloadToUrl(payload) {
        const p = new URLSearchParams(window.location.search);
        if (payload.status) p.set('status', payload.status); else p.delete('status');
        if (!isAnalystList) {
            if (payload.assigned_to) p.set('assigned_to', payload.assigned_to); else p.delete('assigned_to');
        }
        if (payload.search) p.set('search', payload.search); else p.delete('search');
        if (payload.per_page) p.set('per_page', payload.per_page);
        p.set('page', '1');
        window.location.href = 'applications.php?' + p.toString();
    }

    async function apiGetSaved() {
        const r = await fetch('api_saved_filters.php?action=get&type=applications', { credentials: 'same-origin' });
        return await r.json();
    }

    async function apiSave(payload) {
        const fd = new FormData();
        fd.append('action', 'save');
        fd.append('type', 'applications');
        fd.append('payload', JSON.stringify(payload));
        const r = await fetch('api_saved_filters.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        return await r.json();
    }

    async function apiClear() {
        const fd = new FormData();
        fd.append('action', 'clear');
        fd.append('type', 'applications');
        const r = await fetch('api_saved_filters.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        return await r.json();
    }

    function setSaveButtonState(isSaved) {
        if (!toggleSaveFilter) return;
        toggleSaveFilter.classList.toggle('btn-outline-primary', !isSaved);
        toggleSaveFilter.classList.toggle('btn-primary', !!isSaved);
        toggleSaveFilter.title = isSaved ? 'Фильтр сохранён (нажмите, чтобы удалить)' : 'Сохранить фильтр';
        toggleSaveFilter.innerHTML = isSaved
            ? '<i class="bi bi-bookmark-fill"></i>'
            : '<i class="bi bi-bookmark"></i>';
    }
    
    // ============================================================================
    // ОБРАБОТЧИКИ СОБЫТИЙ ДЛЯ ФИЛЬТРОВ
    // ============================================================================
    
    if (statusFilter) {
        statusFilter.addEventListener('change', function() {
            updateFilters(true);
        });
    }
    
    if (assignedToFilter) {
        assignedToFilter.addEventListener('change', function() {
            updateFilters(true);
        });
    }
    
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            if (searchTimeout) {
                clearTimeout(searchTimeout);
            }
            searchTimeout = setTimeout(function() {
                updateFilters(true);
            }, SEARCH_DEBOUNCE_MS);
        });
        
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                if (searchTimeout) {
                    clearTimeout(searchTimeout);
                }
                updateFilters(true);
            }
        });
    }
    
    if (resetFilters) {
        resetFilters.addEventListener('click', function(e) {
            e.preventDefault();
            resetAllFilters();
        });
    }

    if (toggleSaveFilter) {
        toggleSaveFilter.addEventListener('click', async function() {
            try {
                const current = getCurrentPayload();
                const savedRes = await apiGetSaved();
                const savedPayload = savedRes && savedRes.success ? (savedRes.payload || null) : null;
                const same = JSON.stringify(savedPayload || {}) === JSON.stringify(current || {});

                const res = same ? await apiClear() : await apiSave(current);
                if (res && res.success) {
                    setSaveButtonState(!same);
                    if (typeof showNotification === 'function') {
                        showNotification(same ? 'Сохранённый фильтр удалён' : 'Фильтр сохранён', 'success');
                    }
                } else {
                    alert(res?.error || 'Ошибка');
                }
            } catch (e) {
                alert('Ошибка сохранения фильтра');
            }
        });
    }

    // Auto-apply saved filter if URL has no explicit filters
    (async function autoApplySaved() {
        try {
            const res = await apiGetSaved();
            const payload = res && res.success ? (res.payload || null) : null;
            if (!payload) {
                setSaveButtonState(false);
                return;
            }

            const current = getCurrentPayload();
            const same = JSON.stringify(payload || {}) === JSON.stringify(current || {});
            setSaveButtonState(same);

            if (!isManualMode() && !urlHasAnyFilters() && !same) {
                applyPayloadToUrl(payload);
            }
        } catch (e) {
            // ignore
        }
    })();

    // ============================================================================
    // АДАПТИВНОЕ ПОВЕДЕНИЕ ФИЛЬТРОВ НА МОБИЛЬНЫХ
    // ============================================================================
    if (filtersCollapse) {
        // На мобильных фильтры скрыты по умолчанию
        let lastIsMobile = window.innerWidth < 768;
        if (lastIsMobile) {
            filtersCollapse.classList.remove('show');
        } else {
            filtersCollapse.classList.add('show');
        }

        // На мобильных при скролле часто срабатывает resize (адресная строка),
        // поэтому реагируем только на смену режима mobile/desktop по ширине.
        window.addEventListener('resize', function() {
            const isMobile = window.innerWidth < 768;
            if (isMobile === lastIsMobile) {
                return;
            }
            lastIsMobile = isMobile;
            if (isMobile) {
                filtersCollapse.classList.remove('show');
            } else {
                filtersCollapse.classList.add('show');
            }
        });
    }
    <?php endif; ?>
    
    // Подсветка строк при наведении
    const table = document.getElementById('applicationsTable');
    if (table) {
        table.addEventListener('mouseover', function(e) {
            if (e.target.tagName === 'TD') {
                e.target.parentElement.style.backgroundColor = '#f8f9fa';
            }
        });
        
        table.addEventListener('mouseout', function(e) {
            if (e.target.tagName === 'TD') {
                const row = e.target.parentElement;
                const hasUnread = row && row.dataset && row.dataset.hasUnread === '1';
                row.style.backgroundColor = hasUnread ? 'rgba(220, 53, 69, 0.08)' : '';
            }
        });
    }
});


// Функция для показа уведомлений
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
    
    // Автоматически скрыть через 3 секунды
    setTimeout(() => {
        if (notification.parentNode) {
            notification.remove();
        }
    }, 3000);
}
</script>

<?php require_once 'footer.php'; ?>