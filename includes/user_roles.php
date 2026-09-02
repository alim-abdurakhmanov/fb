<?php
/**
 * Роли и проверки доступа (в т.ч. аналитик и партнёр+аналитик).
 */
declare(strict_types=1);

/** Пользователи, которым в интерфейсе показывается плашка «Руководитель». */
const FINBUILD_DIRECTOR_USER_IDS = [1, 23];

function finbuild_is_manager(string $role): bool
{
    return $role === 'manager';
}

/** Основная роль «аналитик» (не партнёр с флагом). */
function finbuild_is_analyst_role(string $role): bool
{
    return $role === 'analyst';
}

/** @deprecated Используйте finbuild_is_analyst_role() или finbuild_has_analyst_capabilities() */
function finbuild_is_analyst(string $role): bool
{
    return finbuild_is_analyst_role($role);
}

function finbuild_user_is_analyst_flag(?array $user): bool
{
    return !empty($user['is_analyst']);
}

/**
 * Ограниченный менеджер: основная роль manager + is_submanager=1.
 * «Руководитель» — это manager без этого флага (полный доступ).
 */
function finbuild_is_submanager(?array $user = null): bool
{
    if ($user === null) {
        return ((string) ($_SESSION['role'] ?? '') === 'manager') && !empty($_SESSION['is_submanager']);
    }
    return (((string) ($user['role'] ?? '')) === 'manager') && !empty($user['is_submanager']);
}

/** Руководитель: role=manager без флага ограничения (полный доступ). */
function finbuild_is_director(?array $user = null): bool
{
    if ($user === null) {
        return ((string) ($_SESSION['role'] ?? '') === 'manager') && empty($_SESSION['is_submanager']);
    }
    return (((string) ($user['role'] ?? '')) === 'manager') && empty($user['is_submanager']);
}

/** Чистый аналитик: role=analyst, без гибрида с партнёром. */
function finbuild_is_pure_analyst(?array $user = null): bool
{
    if ($user === null) {
        return finbuild_is_analyst_role((string) ($_SESSION['role'] ?? 'client'));
    }
    return finbuild_is_analyst_role((string) ($user['role'] ?? ''));
}

/** Право работать как аналитик: role=analyst ИЛИ is_analyst=1 у партнёра. */
function finbuild_has_analyst_capabilities(?array $user = null): bool
{
    if ($user === null) {
        $role = (string) ($_SESSION['role'] ?? 'client');
        if (finbuild_is_analyst_role($role)) {
            return true;
        }
        return !empty($_SESSION['is_analyst']);
    }
    $role = (string) ($user['role'] ?? '');
    if (finbuild_is_analyst_role($role)) {
        return true;
    }
    return finbuild_user_is_analyst_flag($user);
}

/**
 * Список заявок в режиме «все заявки / анализ»:
 * — чистый аналитик и менеджер всегда;
 * — партнёр с is_analyst — только ?scope=all.
 */
function finbuild_is_applications_analyst_scope(?array $user = null): bool
{
    if ($user === null) {
        $user = [
            'role' => (string) ($_SESSION['role'] ?? 'client'),
            'is_analyst' => (int) ($_SESSION['is_analyst'] ?? 0),
        ];
    }
    if (finbuild_is_pure_analyst($user)) {
        return true;
    }
    if (finbuild_user_is_analyst_flag($user) && (string) ($_GET['scope'] ?? '') === 'all') {
        return true;
    }
    return false;
}

/** Менеджер, чистый аналитик или партнёр в scope=all. */
function finbuild_sees_all_applications(string $role, bool $analystScope = false): bool
{
    if (finbuild_is_manager($role)) {
        return true;
    }
    if (finbuild_is_analyst_role($role)) {
        return true;
    }
    return $analystScope;
}

function finbuild_can_edit_application_structure(string $role, bool $analystFlag = false, ?array $user = null): bool
{
    if (finbuild_is_analyst_role($role) || $analystFlag) {
        return true;
    }
    if (finbuild_is_manager($role)) {
        return finbuild_is_director($user);
    }
    return false;
}

/** Страницы ЛК, доступные только чистому аналитику. */
function finbuild_analyst_allowed_scripts(): array
{
    return [
        'applications.php',
        'application_details.php',
        'application_documents.php',
        'profile.php',
        'logout.php',
        'api_download_application_documents.php',
        'api_serve_upload.php',
        'api_application_structure.php',
        'api_saved_filters.php',
    ];
}

function finbuild_enforce_analyst_page_access(string $role, string $currentPage): void
{
    if (!finbuild_is_analyst_role($role)) {
        return;
    }
    if (!in_array($currentPage, finbuild_analyst_allowed_scripts(), true)) {
        header('Location: applications.php');
        exit;
    }
}

/**
 * Доступ к карточке заявки.
 * Партнёр с is_analyst может открыть любую (режим анализа на чужих).
 */
function finbuild_can_access_application(
    PDO $pdo,
    int $applicationId,
    string $role,
    int $userId,
    bool $isAnalystFlag = false
): bool {
    if (finbuild_is_manager($role) || finbuild_is_analyst_role($role) || $isAnalystFlag) {
        $stmt = $pdo->prepare('SELECT id FROM applications WHERE id = ? LIMIT 1');
        $stmt->execute([$applicationId]);
        return (bool) $stmt->fetchColumn();
    }
    $stmt = $pdo->prepare('SELECT created_by FROM applications WHERE id = ? LIMIT 1');
    $stmt->execute([$applicationId]);
    $createdBy = $stmt->fetchColumn();
    if ($createdBy === false) {
        return false;
    }
    return (int) $createdBy === $userId;
}

/** Проваленная заявка недоступна в режиме аналитика (список и карточка). */
function finbuild_analyst_cannot_view_failed_application(
    array $application,
    string $role,
    bool $isAnalystFlag,
    int $userId
): bool {
    if (($application['status'] ?? '') !== 'failed') {
        return false;
    }
    return finbuild_application_details_analyst_mode($role, $isAnalystFlag, (int) ($application['created_by'] ?? 0), $userId);
}

/** Карточка заявки в урезанном режиме аналитика (структура + документы). */
function finbuild_application_details_analyst_mode(
    string $role,
    bool $isAnalystFlag,
    int $applicationOwnerId,
    int $userId
): bool {
    if (finbuild_is_pure_analyst(['role' => $role, 'is_analyst' => 0])) {
        return true;
    }
    if ($isAnalystFlag && $applicationOwnerId !== $userId) {
        return true;
    }
    return false;
}

/** Доступ к документам заявки (просмотр / скачивание). */
function finbuild_can_view_application_documents(
    PDO $pdo,
    int $applicationId,
    string $role,
    int $userId,
    bool $isAnalystFlag = false
): bool {
    return finbuild_can_access_application($pdo, $applicationId, $role, $userId, $isAnalystFlag);
}

/** Подпись роли в интерфейсе. */
function finbuild_role_display_name(?array $user): string
{
    if ($user === null) {
        return 'Клиент';
    }
    $role = (string) ($user['role'] ?? 'client');
    if ($role === 'partner' && finbuild_user_is_analyst_flag($user)) {
        return 'Партнёр, аналитик';
    }
    if ($role === 'manager') {
        // Плашку «Руководитель» показываем только для конкретных пользователей (id 1 и 23),
        // остальные сотрудники с ролью manager отображаются как «Менеджер».
        return in_array((int) ($user['id'] ?? 0), FINBUILD_DIRECTOR_USER_IDS, true)
            ? 'Руководитель'
            : 'Менеджер';
    }
    $map = [
        'partner' => 'Партнер',
        'client' => 'Клиент',
        'bank' => 'Банк',
        'analyst' => 'Аналитик',
    ];
    return $map[$role] ?? 'Клиент';
}

/**
 * Сохранённый фильтр аналитика по умолчанию: «Все, кроме закрытых».
 */
function finbuild_analyst_ensure_default_saved_filter(PDO $pdo, int $userId): void
{
    try {
        $stmt = $pdo->prepare('SELECT saved_filters FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $raw = $stmt->fetchColumn();
        $all = [];
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $all = $decoded;
            }
        }
        if (isset($all['applications']) && is_array($all['applications'])) {
            return;
        }
        $all['applications'] = [
            'status' => 'except_closed',
            'assigned_to' => '',
            'search' => '',
            'per_page' => 20,
        ];
        $upd = $pdo->prepare('UPDATE users SET saved_filters = ? WHERE id = ?');
        $upd->execute([json_encode($all, JSON_UNESCAPED_UNICODE), $userId]);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('finbuild_analyst_ensure_default_saved_filter: ' . $e->getMessage());
        }
    }
}
