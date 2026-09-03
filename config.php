<?php
// Надёжные параметры cookie сессии (важно для поддоменов и HTTPS),
// чтобы не было циклов редиректа "логин → кабинет → логин".
if (session_status() === PHP_SESSION_NONE) {
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $host = preg_replace('/:\d+$/', '', $host) ?? $host;
    $forwardedProto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443')
        || $forwardedProto === 'https'; // на проде за прокси/Cloudflare HTTPS виден только так

    // Cookie только для текущего хоста на dev/stage; на prod — .finbuild.ru (не смешивать dev и prod).
    $hostLower = strtolower($host);
    $cookieDomain = '';
    if ($hostLower === 'finbuild.ru' || $hostLower === 'www.finbuild.ru') {
        $cookieDomain = '.finbuild.ru';
    }

    // Отдельное имя сессии на dev — иначе PHPSESSID с prod «ломает» вход на dev.finbuild.ru.
    if (str_starts_with($hostLower, 'dev.') && str_ends_with($hostLower, '.finbuild.ru')) {
        session_name('FINBUILD_DEV_SESSID');
    }

    // Чтобы авторизация не слетала после закрытия браузера:
    // делаем cookie сессии постоянной (например, 30 дней).
    // Важно: серверная жизнь сессии тоже должна быть >= этому сроку.
    $sessionTtlSeconds = 30 * 24 * 60 * 60; // 30 дней
    @ini_set('session.gc_maxlifetime', (string) $sessionTtlSeconds);
    @ini_set('session.gc_probability', '1');
    @ini_set('session.gc_divisor', '100');

    session_set_cookie_params([
        'lifetime' => $sessionTtlSeconds,
        'path' => '/',
        'domain' => $cookieDomain ?? '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();

    // На боевом finbuild.ru канонический cookie сессии — для домена .finbuild.ru.
    // Если у пользователя осталась старая host-only кука того же имени (домен finbuild.ru,
    // без точки) от прежней версии, браузер шлёт сразу две PHPSESSID, и PHP может прочитать
    // не ту — отсюда «ввожу логин/пароль, но снова кидает на страницу входа». Мигрируем
    // текущий идентификатор сессии в канонический домен и гасим host-only дубль (без разлогина).
    if (($cookieDomain === '.finbuild.ru') && isset($_COOKIE[session_name()])) {
        $sid = session_id();
        if (is_string($sid) && $sid !== '') {
            setcookie(session_name(), $sid, [
                'expires' => time() + $sessionTtlSeconds,
                'path' => '/',
                'domain' => '.finbuild.ru',
                'secure' => $isHttps,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            // Удаление host-only варианта: Domain НЕ указываем.
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => '/',
                'secure' => $isHttps,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    }
}

/**
 * Синхронизирует $_SESSION с актуальной записью users (роль, ФИО, активность).
 * Устраняет ситуацию «в сессии bank, в БД partner» — из-за неё партнёр видел ЛК банка.
 */
function finbuild_sync_session_user(): bool
{
    if (!isset($_SESSION['user_id'])) {
        return false;
    }
    $pdo = getPDO();
    $stmt = $pdo->prepare(
        'SELECT id, email, role, is_analyst, is_submanager, first_name, last_name, is_active FROM users WHERE id = ? LIMIT 1'
    );
    $stmt->execute([(int) $_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user || empty($user['is_active'])) {
        $_SESSION = [];
        return false;
    }
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['email'] = (string) $user['email'];
    $_SESSION['role'] = (string) $user['role'];
    $_SESSION['is_analyst'] = (int) ($user['is_analyst'] ?? 0);
    // Совместимость: раньше флаг is_submanager, теперь роль case_manager
    $_SESSION['is_submanager'] = ((string) $user['role'] === 'case_manager') ? 1 : 0;
    $_SESSION['first_name'] = (string) $user['first_name'];
    $_SESSION['last_name'] = (string) $user['last_name'];
    return true;
}

/** Завершение сессии: гасим cookie во всех областях (host-only и .finbuild.ru), чтобы не оставалось дублей. */
function finbuild_destroy_session(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        $name = session_name();
        $secure = (bool) $params['secure'];
        $httponly = (bool) $params['httponly'];

        // Набор областей домена, в которых могла остаться кука сессии.
        $domains = ['', (string) ($params['domain'] ?? '')];
        $host = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')) ?? '');
        if ($host === 'finbuild.ru' || $host === 'www.finbuild.ru') {
            $domains[] = '.finbuild.ru';
            $domains[] = 'finbuild.ru';
        }

        foreach (array_unique($domains) as $domain) {
            $options = [
                'expires' => time() - 42000,
                'path' => $params['path'] ?: '/',
                'secure' => $secure,
                'httponly' => $httponly,
                'samesite' => 'Lax',
            ];
            if ($domain !== '') {
                $options['domain'] = $domain;
            }
            setcookie($name, '', $options);
        }
    }
    session_destroy();
}

/** Абсолютный путь к корню проекта (для includes и assets). */
if (!defined('FINBUILD_ROOT')) {
    define('FINBUILD_ROOT', __DIR__);
}

// Секреты и окружение — из файла .env (см. .env.example). Сам config.php можно коммитить.
require_once __DIR__ . '/includes/load_env.php';
finbuild_load_env(__DIR__ . DIRECTORY_SEPARATOR . '.env');

// Настройки базы данных (до getPDO() и finbuild_sync_session_user())
define('DB_HOST', finbuild_env('DB_HOST', 'localhost'));
define('DB_NAME', finbuild_env('DB_NAME', 'finbuild_web_dev'));
define('DB_USER', finbuild_env('DB_USER', 'finbuild'));
define('DB_PASS', finbuild_env('DB_PASS', ''));
define('SUPPORT_USER_ID', finbuild_env_int('SUPPORT_USER_ID', 1)); // ID пользователя поддержки в таблице users

if (isset($_SESSION['user_id'])) {
    finbuild_sync_session_user();
}

/** Ключ API Checko (ЕГРЮЛ / контракты ЕИС и т.д.) */
define('CHECKO_API_KEY', finbuild_env('CHECKO_API_KEY', ''));

// --- Почтовые уведомления (SMTP). Пока MAIL_SMTP_USER пуст — письма не отправляются. ---
// Mail.ru: smtp.mail.ru, порт 465 (SSL) или 587 (STARTTLS). Включите доступ по протоколу SMTP и используйте «пароль для внешнего приложения».
// Логин — полный адрес ящика; пароль — пароль приложения (в .env, не в git).
define('MAIL_ENABLED', finbuild_env_bool('MAIL_ENABLED', false));
define('MAIL_SMTP_HOST', finbuild_env('MAIL_SMTP_HOST', 'smtp.mail.ru'));
define('MAIL_SMTP_PORT', finbuild_env_int('MAIL_SMTP_PORT', 465));
define('MAIL_SMTP_SECURE', finbuild_env('MAIL_SMTP_SECURE', 'ssl')); // для 465: ssl; для 587: tls
define('MAIL_SMTP_USER', finbuild_env('MAIL_SMTP_USER', ''));
define('MAIL_SMTP_PASSWORD', finbuild_env('MAIL_SMTP_PASSWORD', ''));
define('MAIL_FROM_ADDRESS', finbuild_env('MAIL_FROM_ADDRESS', ''));
define('MAIL_FROM_NAME', finbuild_env('MAIL_FROM_NAME', 'FinBuild'));
/** Запасные e-mail менеджеров, если у заявки не указан ответственный (через запятую) */
define('MAIL_MANAGER_FALLBACK', finbuild_env('MAIL_MANAGER_FALLBACK', ''));
/** Явный URL сайта для ссылок в письмах (если пусто — берётся из запроса) */
define('SITE_BASE_URL', finbuild_env('SITE_BASE_URL', 'https://finbuild.ru'));

// Создаем глобальную переменную для PDO
function getPDO() {
    static $pdo = null;
    
    if ($pdo === null) {
        try {
            $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            die('Database connection failed: ' . $e->getMessage());
        }
    }
    
    return $pdo;
}

// Функция проверки авторизации
function checkAuth() {
    if (!isset($_SESSION['user_id']) || !finbuild_sync_session_user()) {
        header('Location: login.php');
        exit();
    }
}

/**
 * Количество заявок, в которых есть хотя бы одно непрочитанное сообщение в чатах продуктов
 * (та же логика «кто считается автором непрочитанного», что на applications.php).
 */
function getApplicationsWithUnreadMessagesCount(): int {
    if (!isset($_SESSION['user_id'])) {
        return 0;
    }

    if (!finbuild_can_use_product_chat()) {
        return 0;
    }

    $pdo = getPDO();
    $userId = (int) $_SESSION['user_id'];
    $userRole = $_SESSION['role'] ?? 'client';

    if (finbuild_is_case_manager()) {
        // Менеджер по заявкам: только заявки, где он ответственный
        $sql = "
            SELECT COUNT(DISTINCT a.id) AS unread_count
            FROM application_product_chats apc
            INNER JOIN application_products ap ON apc.application_product_id = ap.id
            INNER JOIN applications a ON ap.application_id = a.id
            WHERE a.assigned_to = ? AND apc.is_read = 0 AND apc.user_id = a.created_by
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
    } elseif (finbuild_is_manager($userRole)) {
        // Director / manager: непрочитанными считаем только сообщения от владельца заявки
        $sql = "
            SELECT COUNT(DISTINCT a.id) AS unread_count
            FROM application_product_chats apc
            INNER JOIN application_products ap ON apc.application_product_id = ap.id
            INNER JOIN applications a ON ap.application_id = a.id
            WHERE apc.is_read = 0 AND apc.user_id = a.created_by
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
    } else {
        // Клиенты и партнёры: непрочитанные от других участников в своих заявках
        $sql = "
            SELECT COUNT(DISTINCT a.id) AS unread_count
            FROM application_product_chats apc
            INNER JOIN application_products ap ON apc.application_product_id = ap.id
            INNER JOIN applications a ON ap.application_id = a.id
            WHERE a.created_by = ? AND apc.is_read = 0 AND apc.user_id != ?
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId, $userId]);
    }

    $result = $stmt->fetch();
    return (int) ($result['unread_count'] ?? 0);
}

// Функция для получения количества непрочитанных сообщений поддержки
function getUnreadSupportCount() {
    if (!isset($_SESSION['user_id'])) {
        return 0;
    }

    $pdo = getPDO();
    $userId = $_SESSION['user_id'];
    $isSupportUser = defined('SUPPORT_USER_ID') && (int)SUPPORT_USER_ID === (int)$userId;

    if ($isSupportUser) {
        $sql = "
            SELECT COUNT(sm.id) as unread_count
            FROM support_messages sm
            LEFT JOIN support_tickets st ON st.id = sm.ticket_id
            WHERE sm.is_read = 0 AND sm.author_id != ?
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
    } else {
        $sql = "
            SELECT COUNT(sm.id) as unread_count
            FROM support_messages sm
            LEFT JOIN support_tickets st ON st.id = sm.ticket_id
            WHERE st.created_by = ? AND sm.is_read = 0 AND sm.author_id != ?
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId, $userId]);
    }

    $result = $stmt->fetch();
    return $result['unread_count'] ?? 0;
}
// Функция для получения текущего пользователя
function getCurrentUser() {
    $pdo = getPDO();
    if (isset($_SESSION['user_id'])) {
        $stmt = $pdo->prepare("SELECT id, email, password, first_name, last_name, phone, company_name, bank_code, role, is_analyst, is_submanager, registration_date, is_active, avatar_path, updated_at, email_notifications_enabled, notification_email FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        return $stmt->fetch();
    }
    return null;
}

// Функция редиректа по роли (публичная главная — лендинг index.php для гостей)
function redirectByRole() {
    $role = $_SESSION['role'] ?? 'client';
    if ($role === 'bank') {
        header('Location: bank_applications.php');
        exit();
    }
    if ($role === 'analyst') {
        header('Location: applications.php');
        exit();
    }
    header('Location: dashboard.php');
    exit();
}
// Функция для автоматического обновления статуса заявки на основе продуктов
// $notifyOwner — письмо владельцу заявки при смене статуса; для CLI-пересчёта передавайте false
function updateApplicationStatus($applicationId, $isNewProductAdded = false, $notifyOwner = true) {
    $pdo = getPDO();
    require_once __DIR__ . '/includes/notification_events.php';

    // Сначала получаем текущий статус заявки
    $stmt = $pdo->prepare("SELECT status FROM applications WHERE id = ?");
    $stmt->execute([$applicationId]);
    $currentApplication = $stmt->fetch();
    
    if (!$currentApplication) {
        return null;
    }
    
    $currentStatus = $currentApplication['status'];
    
    // Получаем все продукты заявки
    $stmt = $pdo->prepare("
        SELECT status, product_type 
        FROM application_products 
        WHERE application_id = ?
    ");
    $stmt->execute([$applicationId]);
    $products = $stmt->fetchAll();
    
    if (empty($products)) {
        // Если продуктов нет, оставляем статус «На проверке» (код new)
        return finbuild_apply_application_status_change($pdo, (int) $applicationId, $currentStatus, 'new', $notifyOwner);
    }

    // ОСОБЫЙ СЛУЧАЙ: Если заявка была провалена И добавляется новый продукт
    if ($currentStatus === 'failed' && $isNewProductAdded) {
        // Меняем статус на «В работе» независимо от состояния продуктов
        return finbuild_apply_application_status_change($pdo, (int) $applicationId, $currentStatus, 'in_progress', $notifyOwner);
    }
    
    // Обычная логика определения статуса
    $allCompleted = true;
    $hasSuccess = false;
    $allRejectedOrIrrelevant = true;
    
    foreach ($products as $product) {
        $status = $product['status'];
        $productType = $product['product_type'];
        
        // Проверяем, есть ли успешные продукты
        if (($productType === 'bg' && $status === 'БГ выпущена') || 
            ($productType === 'credit' && $status === 'Выдан')) {
            $hasSuccess = true;
        }
        
        // Проверяем, все ли продукты завершены (успешно или нет)
        if (!in_array($status, ['БГ выпущена', 'Выдан', 'Отказано', 'Не актуален для клиента'])) {
            $allCompleted = false;
        }
        
        // Проверяем, все ли продукты отказаны или не актуальны
        if (!in_array($status, ['Отказано', 'Не актуален для клиента'])) {
            $allRejectedOrIrrelevant = false;
        }
    }
    
    // Порядок стадий воронки (чем больше число — тем «дальше» заявка)
    $pipelineRank = [
        'В работе' => 0,
        'На подписании' => 1,
        'Запрос' => 2,
        'Согласование условий' => 3,
        'На выпуске' => 4,
    ];
    $rankToApplicationStatus = [
        0 => 'in_progress',
        1 => 'pending_signing',
        2 => 'product_request',
        3 => 'terms_negotiation',
        4 => 'pending_release',
    ];
    
    // Определяем новый статус заявки
    if ($hasSuccess) {
        $newStatus = 'completed';
    } elseif ($allRejectedOrIrrelevant) {
        $newStatus = 'failed';
    } elseif (!$allCompleted) {
        $maxRank = -1;
        foreach ($products as $product) {
            $status = $product['status'];
            if (isset($pipelineRank[$status])) {
                $maxRank = max($maxRank, $pipelineRank[$status]);
            }
        }
        if ($maxRank >= 0) {
            $newStatus = $rankToApplicationStatus[$maxRank];
        } else {
            $newStatus = 'in_progress';
        }
    } else {
        $newStatus = 'completed';
    }
    
    return finbuild_apply_application_status_change($pdo, (int) $applicationId, $currentStatus, $newStatus, $notifyOwner);
}

require_once __DIR__ . '/includes/user_roles.php';
?>