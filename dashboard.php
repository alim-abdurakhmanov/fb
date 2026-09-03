<?php 
$current_page = 'dashboard';
require_once 'header.php';

$pdo = getPDO();
$userRole = $_SESSION['role'] ?? 'client';
$userId = $_SESSION['user_id'];
$userName = $_SESSION['first_name'] ?? 'Пользователь';

// --- ЛОГИКА ПРИВЕТСТВИЯ ПО ВРЕМЕНИ СУТОК ---
$hour = (int)date('H'); 
$greeting = 'Здравствуйте'; 

if ($hour >= 5 && $hour < 11) {
    $greeting = 'Доброе утро';
} elseif ($hour >= 11 && $hour < 16) {
    $greeting = 'Добрый день';
} elseif ($hour >= 16 && $hour < 22) {
    $greeting = 'Добрый вечер';
} else {
    $greeting = 'Доброй ночи';
}

// --- СТАТИСТИКА ---
$isSubmanager = finbuild_is_case_manager(); // Показатели только по его заявкам (assigned_to)
if (finbuild_has_full_manager_access()) {
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM applications");
    $totalApplications = $stmt->fetch()['total'];
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM applications WHERE status = 'new'");
    $newApplications = $stmt->fetch()['total'];
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM applications WHERE status IN ('in_progress','pending_signing','product_request','terms_negotiation','pending_release')");
    $inProgressApplications = $stmt->fetch()['total'];
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM applications WHERE status = 'completed'");
    $completedApplications = $stmt->fetch()['total'];
} elseif ($isSubmanager) {
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM applications WHERE assigned_to = ?");
    $stmt->execute([$userId]);
    $totalApplications = $stmt->fetch()['total'];
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM applications WHERE assigned_to = ? AND status = 'new'");
    $stmt->execute([$userId]);
    $newApplications = $stmt->fetch()['total'];
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM applications WHERE assigned_to = ? AND status IN ('in_progress','pending_signing','product_request','terms_negotiation','pending_release')");
    $stmt->execute([$userId]);
    $inProgressApplications = $stmt->fetch()['total'];
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM applications WHERE assigned_to = ? AND status = 'completed'");
    $stmt->execute([$userId]);
    $completedApplications = $stmt->fetch()['total'];
} else {
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM applications WHERE created_by = ?");
    $stmt->execute([$userId]);
    $totalApplications = $stmt->fetch()['total'];
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM applications WHERE created_by = ? AND status = 'new'");
    $stmt->execute([$userId]);
    $newApplications = $stmt->fetch()['total'];
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM applications WHERE created_by = ? AND status IN ('in_progress','pending_signing','product_request','terms_negotiation','pending_release')");
    $stmt->execute([$userId]);
    $inProgressApplications = $stmt->fetch()['total'];
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM applications WHERE created_by = ? AND status = 'completed'");
    $stmt->execute([$userId]);
    $completedApplications = $stmt->fetch()['total'];
}

// --- Оперативная сводка (заявки с непрочитанными чатами, новые для менеджера, последние в работе) ---
$activeStatusListSql = "'new', 'in_progress', 'pending_signing', 'product_request', 'terms_negotiation', 'pending_release'";
$dashboardAppsWithUnread = [];
$dashboardNewForManager = [];
$dashboardRecentActive = [];

if (finbuild_has_full_manager_access()) {
    $stmt = $pdo->query("
        SELECT a.id, a.company_name, a.inn, a.status, MAX(apc.created_at) AS activity_at
        FROM applications a
        INNER JOIN application_products ap ON ap.application_id = a.id
        INNER JOIN application_product_chats apc ON apc.application_product_id = ap.id
        WHERE apc.is_read = 0 AND apc.user_id = a.created_by
        GROUP BY a.id, a.company_name, a.inn, a.status
        ORDER BY activity_at DESC
        LIMIT 5
    ");
    $dashboardAppsWithUnread = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
} elseif ($isSubmanager) {
    $stmt = $pdo->prepare("
        SELECT a.id, a.company_name, a.inn, a.status, MAX(apc.created_at) AS activity_at
        FROM applications a
        INNER JOIN application_products ap ON ap.application_id = a.id
        INNER JOIN application_product_chats apc ON apc.application_product_id = ap.id
        WHERE a.assigned_to = ? AND apc.is_read = 0 AND apc.user_id = a.created_by
        GROUP BY a.id, a.company_name, a.inn, a.status
        ORDER BY activity_at DESC
        LIMIT 5
    ");
    $stmt->execute([$userId]);
    $dashboardAppsWithUnread = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->prepare("
        SELECT a.id, a.company_name, a.inn, a.status, MAX(apc.created_at) AS activity_at
        FROM applications a
        INNER JOIN application_products ap ON ap.application_id = a.id
        INNER JOIN application_product_chats apc ON apc.application_product_id = ap.id
        WHERE a.created_by = ? AND apc.is_read = 0 AND apc.user_id != ?
        GROUP BY a.id, a.company_name, a.inn, a.status
        ORDER BY activity_at DESC
        LIMIT 5
    ");
    $stmt->execute([$userId, $userId]);
    $dashboardAppsWithUnread = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if (finbuild_has_full_manager_access()) {
    $excludeUnreadIds = array_map('intval', array_column($dashboardAppsWithUnread, 'id'));
    if ($excludeUnreadIds === []) {
        $stmt = $pdo->query("
            SELECT a.id, a.company_name, a.inn, a.status, a.created_at AS activity_at
            FROM applications a
            WHERE a.status = 'new'
            ORDER BY a.created_at DESC
            LIMIT 3
        ");
        $dashboardNewForManager = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } else {
        $ph = implode(',', array_fill(0, count($excludeUnreadIds), '?'));
        $stmt = $pdo->prepare("
            SELECT a.id, a.company_name, a.inn, a.status, a.created_at AS activity_at
            FROM applications a
            WHERE a.status = 'new' AND a.id NOT IN ($ph)
            ORDER BY a.created_at DESC
            LIMIT 3
        ");
        $stmt->execute($excludeUnreadIds);
        $dashboardNewForManager = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} elseif ($isSubmanager) {
    $excludeUnreadIds = array_map('intval', array_column($dashboardAppsWithUnread, 'id'));
    if ($excludeUnreadIds === []) {
        $stmt = $pdo->prepare("
            SELECT a.id, a.company_name, a.inn, a.status, a.created_at AS activity_at
            FROM applications a
            WHERE a.status = 'new' AND a.assigned_to = ?
            ORDER BY a.created_at DESC
            LIMIT 3
        ");
        $stmt->execute([$userId]);
        $dashboardNewForManager = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $ph = implode(',', array_fill(0, count($excludeUnreadIds), '?'));
        $stmt = $pdo->prepare("
            SELECT a.id, a.company_name, a.inn, a.status, a.created_at AS activity_at
            FROM applications a
            WHERE a.status = 'new' AND a.assigned_to = ? AND a.id NOT IN ($ph)
            ORDER BY a.created_at DESC
            LIMIT 3
        ");
        $stmt->execute(array_merge([$userId], $excludeUnreadIds));
        $dashboardNewForManager = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$dashboardAttentionIds = array_unique(array_merge(
    array_map('intval', array_column($dashboardAppsWithUnread, 'id')),
    array_map('intval', array_column($dashboardNewForManager, 'id'))
));

if (finbuild_has_full_manager_access()) {
    if ($dashboardAttentionIds === []) {
        $stmt = $pdo->query("
            SELECT a.id, a.company_name, a.inn, a.status, a.updated_at AS activity_at
            FROM applications a
            WHERE a.status IN ($activeStatusListSql)
            ORDER BY a.updated_at DESC
            LIMIT 8
        ");
        $dashboardRecentActive = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } else {
        $ph = implode(',', array_fill(0, count($dashboardAttentionIds), '?'));
        $stmt = $pdo->prepare("
            SELECT a.id, a.company_name, a.inn, a.status, a.updated_at AS activity_at
            FROM applications a
            WHERE a.status IN ($activeStatusListSql) AND a.id NOT IN ($ph)
            ORDER BY a.updated_at DESC
            LIMIT 8
        ");
        $stmt->execute($dashboardAttentionIds);
        $dashboardRecentActive = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} elseif ($isSubmanager) {
    if ($dashboardAttentionIds === []) {
        $stmt = $pdo->prepare("
            SELECT a.id, a.company_name, a.inn, a.status, a.updated_at AS activity_at
            FROM applications a
            WHERE a.assigned_to = ? AND a.status IN ($activeStatusListSql)
            ORDER BY a.updated_at DESC
            LIMIT 8
        ");
        $stmt->execute([$userId]);
        $dashboardRecentActive = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $ph = implode(',', array_fill(0, count($dashboardAttentionIds), '?'));
        $stmt = $pdo->prepare("
            SELECT a.id, a.company_name, a.inn, a.status, a.updated_at AS activity_at
            FROM applications a
            WHERE a.assigned_to = ? AND a.status IN ($activeStatusListSql) AND a.id NOT IN ($ph)
            ORDER BY a.updated_at DESC
            LIMIT 8
        ");
        $stmt->execute(array_merge([$userId], $dashboardAttentionIds));
        $dashboardRecentActive = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} else {
    if ($dashboardAttentionIds === []) {
        $stmt = $pdo->prepare("
            SELECT a.id, a.company_name, a.inn, a.status, a.updated_at AS activity_at
            FROM applications a
            WHERE a.created_by = ? AND a.status IN ($activeStatusListSql)
            ORDER BY a.updated_at DESC
            LIMIT 8
        ");
        $stmt->execute([$userId]);
        $dashboardRecentActive = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $ph = implode(',', array_fill(0, count($dashboardAttentionIds), '?'));
        $stmt = $pdo->prepare("
            SELECT a.id, a.company_name, a.inn, a.status, a.updated_at AS activity_at
            FROM applications a
            WHERE a.created_by = ? AND a.status IN ($activeStatusListSql) AND a.id NOT IN ($ph)
            ORDER BY a.updated_at DESC
            LIMIT 8
        ");
        $stmt->execute(array_merge([$userId], $dashboardAttentionIds));
        $dashboardRecentActive = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$dashboardHasAttention = $dashboardAppsWithUnread !== [] || $dashboardNewForManager !== [];

if (!function_exists('finbuild_dashboard_format_activity')) {
    function finbuild_dashboard_format_activity(?string $dt): string
    {
        if ($dt === null || $dt === '') {
            return '—';
        }
        $ts = strtotime($dt);
        if ($ts === false) {
            return '—';
        }
        return date('d.m.Y H:i', $ts);
    }
}

if (!function_exists('finbuild_dashboard_company_label')) {
    function finbuild_dashboard_company_label(array $row): string
    {
        $name = trim((string) ($row['company_name'] ?? ''));
        if ($name !== '') {
            return $name;
        }
        return 'Заявка №' . (int) ($row['id'] ?? 0);
    }
}

if (!function_exists('finbuild_dashboard_status_badge')) {
    function finbuild_dashboard_status_badge(string $status): string
    {
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
                return '<span class="badge bg-secondary">' . htmlspecialchars($status, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</span>';
        }
    }
}

// --- НОВОСТИ ---
$newsItems = [
    [
        'id' => 1,
        'title' => 'Релиз 1.5',
        'date' => '08.05.2026',
        'icon' => 'bi-stars',
        'color' => 'primary',
        'is_new' => true,
        'short' => 'Новые чаты в заявке, скачивание документов архивом, устранение ошибок.',
        'full' => '<ul>'
            . '<li>В этом релизе существенно доработали чаты. Теперь в заявке есть виджет «Чаты» в углу, где собраны все чаты заявки.</li>'
            . '<li>Также теперь можно скачать архивом все документы заявки.</li>'
            . '<li>Устранили некоторые ошибки.</li>'
            . '</ul>',
    ],
    [
        'id' => 2,
        'title' => 'Релиз 1.4',
        'date' => '24.03.2026',
        'icon' => 'bi-stars',
        'color' => 'primary',
        'short' => 'Поддержка, автозаполнение по закупке, статусы заявок, уведомления на почту.',
        'full' => '<p><strong>1. Новая страница «Поддержка» в левом меню сайта</strong></p><ul><li>Все пользователи могут создавать тикеты по любым вопросам и получать ответы в чате тикета на странице поддержки.</li><li>Реализована индикация о новых сообщениях.</li></ul>'
            . '<p><strong>2. По номеру закупки</strong> теперь автоматически подтягиваются: предмет закупки, цена контракта, ссылка на закупку, заказчик (ИНН и наименование).</p>'
            . '<p><strong>3. Подсказки</strong> для полей «Предмет закупки» и «ИНН заказчика».</p>'
            . '<p><strong>4. Новые статусы заявок.</strong></p><ul><li>Добавились статусы «На подписании», «Запрос», «Согласование условий», «На выпуске».</li><li>Логика статуса заявки связана со статусами продуктов в ней. Если хотя бы один продукт имеет более «дальний» статус, заявка автоматически переходит к соответствующему этапу.</li></ul>'
            . '<p><strong>5. Система уведомлений на почту</strong> для всех пользователей.</p><ul><li>Клиентам и агентам приходят уведомления о смене статуса заявки, о новых сообщениях, о новых запросах документов в продуктах.</li><li>У всех в профиле есть переключатель «Получать уведомления на почту» и поле с почтой (если нужно указать другой адрес для уведомлений). По умолчанию у всех выключено получение уведомлений на тестовый период (но можно включить).</li></ul>',
    ],
    [
        'id' => 3,
        'title' => 'Релиз 1.3',
        'date' => '10.02.2026',
        'icon' => 'bi-box-seam',
        'color' => 'primary',
        'short' => 'Пагинация, улучшения мобильного интерфейса и автозаполнение ИНН.',
        'full' => '<p>В релизе 1.3 добавлена серверная пагинация заявок с выбором количества на странице, обновлены мобильные стили и адаптации, улучшена аналитика и фильтры, а также реализовано автозаполнение ИНН и названия организации при создании заявки.</p>'
    ],
    [
        'id' => 4,
        'title' => 'Мобильная версия',
        'date' => '10.02.2026',
        'icon' => 'bi-phone',
        'color' => 'info',
        'short' => 'Полная мобильная адаптация ключевых страниц.',
        'full' => '<p>Обновили интерфейсы для телефонов: улучшены шрифты, сетки, карточки и удобство работы на небольших экранах.</p>'
    ],
    [
        'id' => 5,
        'title' => 'Релиз 1.2',
        'date' => '02.02.2026',
        'icon' => 'bi-box-seam',
        'color' => 'success',
        'short' => 'Обновления формы заявки и блока комментария.',
        'full' => '<p><strong>Заявки</strong></p><ul><li>В форме создания заявки появились новые поля: «Номер закупки/Ссылка» и «В каких банках были отказы».</li><li>В форме создания заявки добавлено автоформатирование суммы БГ с пробелами и ограничение в 2 знака после запятой (например: 1 000 000,50).</li><li>В заявке появился блок «Комментарий».</li></ul>'
    ]
];

// Разбиваем новости на группы по 3 штуки
$newsChunks = array_chunk($newsItems, 3);
?>

<style>
    /* Hero-баннер */
    .hero-card {
        border: none;
        border-radius: 16px;
        overflow: hidden;
        background-color: #0f172a; 
        background-image: 
            radial-gradient(at 0% 0%, hsla(253,16%,7%,1) 0, transparent 50%), 
            radial-gradient(at 50% 0%, hsla(225,39%,30%,1) 0, transparent 50%), 
            radial-gradient(at 100% 0%, hsla(339,49%,30%,1) 0, transparent 50%);
        color: white;
        position: relative;
        box-shadow: 0 10px 30px rgba(0,0,0,0.15);
    }
    .hero-card::after {
        content: ""; position: absolute; top: 0; left: 0; right: 0; bottom: 0;
        background-image: linear-gradient(rgba(255, 255, 255, 0.05) 1px, transparent 1px), linear-gradient(90deg, rgba(255, 255, 255, 0.05) 1px, transparent 1px);
        background-size: 40px 40px; pointer-events: none;
    }
    .hero-content { position: relative; z-index: 2; }

    /* Статистика */
    .stat-card {
        border: none; border-radius: 16px; background: #fff;
        box-shadow: 0 4px 20px rgba(0,0,0,0.03);
        transition: transform 0.3s ease, box-shadow 0.3s ease; height: 100%;
    }
    a.stat-card-link:hover .stat-card,
    a.stat-card-link:focus .stat-card {
        transform: translateY(-5px);
        box-shadow: 0 10px 25px rgba(0,0,0,0.08);
    }
    .icon-box { width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; }
    
    .bg-blue-light { background: #e3f2fd; color: #0d6efd; }
    .bg-orange-light { background: #fff3cd; color: #ffc107; }
    .bg-green-light { background: #d1e7dd; color: #198754; }
    .bg-info-light { background: #cff4fc; color: #0dcaf0; }

    /* Ссылки на карточках статистики */
    a.stat-card-link {
        text-decoration: none;
        color: inherit;
        display: block;
        height: 100%;
    }
    a.stat-card-link:focus-visible {
        outline: 2px solid #3498db;
        outline-offset: 2px;
        border-radius: 16px;
    }

    /* Оперативная сводка */
    .dashboard-op-card {
        border: none;
        border-radius: 16px;
        background: #fff;
        box-shadow: 0 4px 24px rgba(15, 23, 42, 0.06);
        overflow: hidden;
    }
    .dashboard-op-head {
        border-bottom: none;
    }
    .dashboard-op-head h4 {
        font-weight: 700;
        color: #1e293b;
        letter-spacing: -0.02em;
    }
    .dashboard-op-sub {
        font-size: 0.9rem;
        color: #64748b;
        max-width: 42rem;
    }
    .dashboard-op-section-title {
        font-size: 0.7rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: #94a3b8;
        margin-bottom: 0.75rem;
    }
    .dashboard-op-col {
        padding: 1.25rem 1.5rem 1.5rem;
    }
    @media (min-width: 992px) {
        .dashboard-op-col--attention {
            border-right: 1px solid #e9ecef;
        }
    }
    @media (max-width: 991.98px) {
        .dashboard-op-col--attention {
            border-bottom: 1px solid #e9ecef;
        }
    }
    .dashboard-op-list {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
    }
    .dashboard-op-item {
        display: block;
        text-decoration: none;
        color: inherit;
        border: 1px solid #e9ecef;
        border-radius: 12px;
        padding: 0.85rem 1rem;
        transition: background-color 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease;
    }
    .dashboard-op-item:hover {
        background: #f8fafc;
        border-color: #dee2e6;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
    }
    .dashboard-op-item:focus-visible {
        outline: 2px solid #3498db;
        outline-offset: 2px;
    }
    .dashboard-op-item-title {
        font-weight: 600;
        font-size: 0.95rem;
        color: #0f172a;
        line-height: 1.35;
        word-break: break-word;
    }
    .dashboard-op-item-meta {
        font-size: 0.78rem;
        color: #64748b;
        margin-top: 0.35rem;
    }
    .dashboard-op-item-foot {
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        gap: 0.45rem;
        margin-top: 0.5rem;
    }
    @media (min-width: 576px) {
        .dashboard-op-item-foot {
            flex-direction: row;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.5rem 0.75rem;
        }
        .dashboard-op-item-foot .dashboard-op-item-time {
            margin-left: auto;
        }
    }
    .dashboard-op-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        font-size: 0.72rem;
        font-weight: 600;
        padding: 0.2rem 0.5rem;
        border-radius: 999px;
        background: #fee2e2;
        color: #b91c1c;
    }
    .dashboard-op-pill--new {
        background: #fff7ed;
        color: #c2410c;
    }
    .dashboard-op-empty {
        text-align: center;
        padding: 1.75rem 1rem;
        border: 1px dashed #dee2e6;
        border-radius: 12px;
        background: #fafafa;
    }
    .dashboard-op-empty-icon {
        font-size: 2rem;
        color: #cbd5e1;
        margin-bottom: 0.5rem;
    }
    .dashboard-op-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(185px, 1fr));
        gap: 0.6rem;
    }
    .dashboard-op-chip {
        display: flex;
        flex-direction: column;
        gap: 0.3rem;
        text-decoration: none;
        color: inherit;
        border: 1px solid #e9ecef;
        border-radius: 12px;
        padding: 0.6rem 0.75rem;
        transition: background-color 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease;
    }
    .dashboard-op-chip:hover {
        background: #f8fafc;
        border-color: #dee2e6;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
    }
    .dashboard-op-chip:focus-visible {
        outline: 2px solid #3498db;
        outline-offset: 2px;
    }
    .dashboard-op-chip-title {
        font-weight: 600;
        font-size: 0.85rem;
        color: #0f172a;
        line-height: 1.3;
        word-break: break-word;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }
    .dashboard-op-chip-meta {
        font-size: 0.72rem;
        color: #64748b;
    }
    .dashboard-op-chip-foot {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 0.35rem 0.5rem;
        margin-top: 0.1rem;
    }
    .dashboard-op-chip-time {
        font-size: 0.7rem;
        width: 100%;
    }
    .dashboard-op-footer-link {
        font-size: 0.875rem;
        font-weight: 600;
    }

    /* Бейджи статусов (как на странице заявок) */
    .dashboard-op-card .status-badge-new {
        background: linear-gradient(135deg, #ffc107, #ff9800);
        border: none;
        padding: 0.3rem 0.55rem;
        font-weight: 500;
        font-size: 0.72rem;
        box-shadow: 0 2px 4px rgba(255, 152, 0, 0.2);
        color: #1a1a1a;
    }
    .dashboard-op-card .status-badge-in-progress {
        background: linear-gradient(135deg, #3498db, #2980b9);
        border: none;
        padding: 0.3rem 0.55rem;
        font-weight: 500;
        font-size: 0.72rem;
        color: #fff;
        box-shadow: 0 2px 4px rgba(52, 152, 219, 0.2);
    }
    .dashboard-op-card .status-badge-completed {
        background: linear-gradient(135deg, #28a745, #20c997);
        border: none;
        padding: 0.3rem 0.55rem;
        font-weight: 500;
        font-size: 0.72rem;
        color: #fff;
    }
    .dashboard-op-card .status-badge-failed {
        background: linear-gradient(135deg, #dc3545, #c82333);
        border: none;
        padding: 0.3rem 0.55rem;
        font-weight: 500;
        font-size: 0.72rem;
        color: #fff;
    }
    .dashboard-op-card .status-badge-pending-signing {
        background: linear-gradient(135deg, #17a2b8, #138496);
        border: none;
        padding: 0.3rem 0.55rem;
        font-weight: 500;
        font-size: 0.72rem;
        color: #fff;
    }
    .dashboard-op-card .status-badge-product-request {
        background: linear-gradient(135deg, #ffc107, #e0a800);
        border: none;
        padding: 0.3rem 0.55rem;
        font-weight: 500;
        font-size: 0.72rem;
        color: #fff;
    }
    .dashboard-op-card .status-badge-terms-negotiation {
        background: linear-gradient(135deg, #6f42c1, #5a32a3);
        border: none;
        padding: 0.3rem 0.55rem;
        font-weight: 500;
        font-size: 0.72rem;
        color: #fff;
    }
    .dashboard-op-card .status-badge-pending-release {
        background: linear-gradient(135deg, #fd7e14, #e8590c);
        border: none;
        padding: 0.3rem 0.55rem;
        font-weight: 500;
        font-size: 0.72rem;
        color: #fff;
    }

    /* Новости */
    .news-carousel-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 1.5rem;
        height: 100%;
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
        display: flex;
        flex-direction: column;
    }
    .news-carousel-card:hover { border-color: #cbd5e1; box-shadow: 0 10px 30px rgba(0,0,0,0.05); transform: translateY(-2px); }
    .news-bg-icon { position: absolute; top: -10px; right: -10px; font-size: 5rem; opacity: 0.05; transform: rotate(15deg); pointer-events: none; }
    .news-header { display: flex; align-items: center; margin-bottom: 1rem; }
    .news-icon-badge { width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; margin-right: 12px; }
    
    .badge-primary { background: #e0e7ff; color: #4f46e5; }
    .badge-info { background: #e0f2fe; color: #0284c7; }
    .badge-danger { background: #fee2e2; color: #dc2626; }
    .badge-success { background: #dcfce7; color: #16a34a; }
    .badge-warning { background: #fef3c7; color: #d97706; }

    .news-date { font-size: 0.8rem; color: #94a3b8; font-weight: 500; }
    .news-title { font-weight: 700; font-size: 1.1rem; color: #1e293b; margin-bottom: 0.75rem; line-height: 1.4; }
    .news-desc { font-size: 0.9rem; color: #64748b; margin-bottom: 1.5rem; flex-grow: 1; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }
    .news-link { font-size: 0.9rem; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; margin-top: auto; }
    .news-link:hover { text-decoration: underline; }
    
    .carousel-control-prev, .carousel-control-next {
        width: 40px; height: 40px; background: #fff; border-radius: 50%;
        top: 50%; transform: translateY(-50%); opacity: 1; box-shadow: 0 4px 10px rgba(0,0,0,0.1); border: 1px solid #e2e8f0;
    }
    .carousel-control-prev { left: -20px; }
    .carousel-control-next { right: -20px; }
    .carousel-control-prev-icon, .carousel-control-next-icon { filter: invert(0.3); width: 1.2rem; height: 1.2rem; }
    .carousel-control-prev:hover, .carousel-control-next:hover { background: #f8fafc; }

    /* --- АДАПТИВНОСТЬ --- */
    @media (max-width: 991.98px) {
        .carousel-control-prev { left: -10px; }
        .carousel-control-next { right: -10px; }
    }

    @media (max-width: 768px) {
        .hero-content h2 {
            white-space: nowrap;
            font-size: 1.35rem;
        }

        .hero-subtitle {
            font-size: 0.9rem !important;
            line-height: 1.3;
        }

        .hero-card .card-body {
            padding: 1.25rem;
        }

        .carousel-control-prev, .carousel-control-next {
            display: none;
        }
        
        .hero-buttons {
            flex-direction: column;
            width: 100%;
        }
        
        .hero-buttons .btn {
            width: 100%;
        }

        .dashboard-op-head {
            padding-left: 1rem !important;
            padding-right: 1rem !important;
        }
        .dashboard-op-col {
            padding-left: 1rem !important;
            padding-right: 1rem !important;
        }
    }
</style>

<!-- HERO БАННЕР -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card hero-card">
            <div class="card-body p-4 p-lg-5">
                <div class="hero-content row align-items-center">
                    <div class="col-md-8">
                        <h2 class="fw-bold mb-2"><?= $greeting ?>, <?= htmlspecialchars($userName) ?>! 👋</h2>
                        
                        <p class="mb-4 opacity-75 fs-5 hero-subtitle">
                            <?php if ($newApplications > 0): ?>
                                Количество новых заявок, требующих внимания - <strong><?= $newApplications ?></strong>.
                            <?php else: ?>
                                В системе всё спокойно. Отличное время для планирования новых сделок.
                            <?php endif; ?>
                        </p>
                        
                        <div class="d-flex gap-3 hero-buttons">
                            <?php if (finbuild_is_manager($userRole)): ?>
                                <button type="button" class="btn btn-light text-primary fw-bold px-4 py-2" onclick="location.href='applications.php'">
                                    <i class="bi bi-list-check me-2"></i> Просмотреть заявки
                                </button>
                            <?php else: ?>
                                <button type="button" class="btn btn-light text-primary fw-bold px-4 py-2" onclick="location.href='applications.php?action=create'">
                                    <i class="bi bi-plus-lg me-2"></i> Создать заявку
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-4 d-none d-md-block text-end">
                        <i class="bi bi-graph-up-arrow" style="font-size: 8rem; opacity: 0.1;"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- СТАТИСТИКА -->
<div class="row g-4 mb-4">
    <div class="col-xl-3 col-md-6">
        <a href="applications.php" class="stat-card-link" aria-label="Все заявки">
            <div class="card stat-card">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <p class="text-muted mb-1 text-uppercase fw-bold small">Всего заявок</p>
                            <h2 class="fw-bold mb-0 text-dark"><?= (int) $totalApplications ?></h2>
                        </div>
                        <div class="icon-box bg-blue-light"><i class="bi bi-folder2-open"></i></div>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-xl-3 col-md-6">
        <a href="applications.php?status=new" class="stat-card-link" aria-label="Заявки на проверке">
            <div class="card stat-card">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <p class="text-muted mb-1 text-uppercase fw-bold small">На проверке</p>
                            <h2 class="fw-bold mb-0 text-dark"><?= (int) $newApplications ?></h2>
                        </div>
                        <div class="icon-box bg-orange-light"><i class="bi bi-star"></i></div>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-xl-3 col-md-6">
        <a href="applications.php?status=except_closed" class="stat-card-link" aria-label="Заявки в работе">
            <div class="card stat-card">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <p class="text-muted mb-1 text-uppercase fw-bold small">В работе</p>
                            <h2 class="fw-bold mb-0 text-dark"><?= (int) $inProgressApplications ?></h2>
                        </div>
                        <div class="icon-box bg-info-light"><i class="bi bi-gear-wide-connected"></i></div>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-xl-3 col-md-6">
        <a href="applications.php?status=completed" class="stat-card-link" aria-label="Завершённые заявки">
            <div class="card stat-card">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <p class="text-muted mb-1 text-uppercase fw-bold small">Одобрено</p>
                            <h2 class="fw-bold mb-0 text-dark"><?= (int) $completedApplications ?></h2>
                        </div>
                        <div class="icon-box bg-green-light"><i class="bi bi-check-lg"></i></div>
                    </div>
                </div>
            </div>
        </a>
    </div>
</div>

<!-- Оперативная сводка -->
<div class="row mb-5">
    <div class="col-12">
        <div class="card dashboard-op-card">
            <div class="dashboard-op-head px-4 pt-4 pb-2">
                <h4 class="mb-0">Сводка</h4>
            </div>
            <div class="row g-0">
                <div class="col-lg-5 dashboard-op-col dashboard-op-col--attention">
                    <p class="dashboard-op-section-title mb-3">Требует внимания</p>
                    <?php if (!$dashboardHasAttention): ?>
                        <div class="dashboard-op-empty">
                            <div class="dashboard-op-empty-icon"><i class="bi bi-check2-circle"></i></div>
                            <p class="text-muted small mb-3 mb-lg-2">Нет заявок с непрочитанными сообщениями<?php if (finbuild_is_manager($userRole)): ?> и новых заявок в очереди<?php endif; ?>.</p>
                            <?php if (finbuild_is_manager($userRole)): ?>
                                <a href="applications.php" class="btn btn-primary btn-sm">Открыть заявки</a>
                            <?php else: ?>
                                <div class="d-flex flex-column flex-sm-row gap-2 justify-content-center">
                                    <a href="applications.php" class="btn btn-outline-primary btn-sm">Все заявки</a>
                                    <a href="applications.php?action=create" class="btn btn-primary btn-sm">Создать заявку</a>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="dashboard-op-list">
                            <?php foreach ($dashboardAppsWithUnread as $row): ?>
                                <a class="dashboard-op-item" href="application_details.php?id=<?= (int) $row['id'] ?>">
                                    <div class="dashboard-op-item-title"><?= htmlspecialchars(finbuild_dashboard_company_label($row), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></div>
                                    <?php if (trim((string) ($row['inn'] ?? '')) !== ''): ?>
                                        <div class="dashboard-op-item-meta">ИНН <?= htmlspecialchars((string) $row['inn'], ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                    <div class="dashboard-op-item-foot">
                                        <span class="dashboard-op-pill"><i class="bi bi-chat-dots-fill"></i> Непрочитанные в чате</span>
                                        <?= finbuild_dashboard_status_badge((string) ($row['status'] ?? '')) ?>
                                        <span class="dashboard-op-item-time text-muted small"><?= htmlspecialchars(finbuild_dashboard_format_activity($row['activity_at'] ?? null), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></span>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                            <?php foreach ($dashboardNewForManager as $row): ?>
                                <a class="dashboard-op-item" href="application_details.php?id=<?= (int) $row['id'] ?>">
                                    <div class="dashboard-op-item-title"><?= htmlspecialchars(finbuild_dashboard_company_label($row), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></div>
                                    <?php if (trim((string) ($row['inn'] ?? '')) !== ''): ?>
                                        <div class="dashboard-op-item-meta">ИНН <?= htmlspecialchars((string) $row['inn'], ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                    <div class="dashboard-op-item-foot">
                                        <span class="dashboard-op-pill dashboard-op-pill--new"><i class="bi bi-stars"></i> Новая заявка</span>
                                        <?= finbuild_dashboard_status_badge((string) ($row['status'] ?? '')) ?>
                                        <span class="dashboard-op-item-time text-muted small"><?= htmlspecialchars(finbuild_dashboard_format_activity($row['activity_at'] ?? null), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></span>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="col-lg-7 dashboard-op-col">
                    <p class="dashboard-op-section-title mb-3">Последние в работе</p>
                    <?php if ($dashboardRecentActive === []): ?>
                        <div class="dashboard-op-empty">
                            <div class="dashboard-op-empty-icon"><i class="bi bi-inboxes"></i></div>
                            <p class="text-muted small mb-0">Нет других активных заявок для показа или все уже в блоке слева.</p>
                        </div>
                    <?php else: ?>
                        <div class="dashboard-op-grid">
                            <?php foreach ($dashboardRecentActive as $row): ?>
                                <a class="dashboard-op-chip" href="application_details.php?id=<?= (int) $row['id'] ?>">
                                    <div class="dashboard-op-chip-title"><?= htmlspecialchars(finbuild_dashboard_company_label($row), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></div>
                                    <?php if (trim((string) ($row['inn'] ?? '')) !== ''): ?>
                                        <div class="dashboard-op-chip-meta">ИНН <?= htmlspecialchars((string) $row['inn'], ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                    <div class="dashboard-op-chip-foot">
                                        <?= finbuild_dashboard_status_badge((string) ($row['status'] ?? '')) ?>
                                        <span class="dashboard-op-chip-time text-muted">Обновлено <?= htmlspecialchars(finbuild_dashboard_format_activity($row['activity_at'] ?? null), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></span>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <div class="text-center text-lg-end mt-3">
                        <a href="applications.php?status=except_closed" class="dashboard-op-footer-link text-primary">Все активные заявки <i class="bi bi-arrow-right ms-1"></i></a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- НОВОСТИ -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="fw-bold mb-0">Новости и обновления</h4>
</div>

<div id="newsCarousel" class="carousel slide" data-bs-ride="false" data-bs-interval="false">
    <div class="carousel-inner p-1">
        <?php foreach ($newsChunks as $index => $chunk): ?>
            <div class="carousel-item <?= $index === 0 ? 'active' : '' ?>">
                <div class="row g-4">
                    <?php foreach ($chunk as $news): ?>
                        <div class="col-md-6 col-lg-4">
                            <div class="news-carousel-card">
                                <i class="bi <?= $news['icon'] ?> news-bg-icon"></i>
                                <div class="news-header">
                                    <div class="news-icon-badge badge-<?= $news['color'] ?>">
                                        <i class="bi <?= $news['icon'] ?>"></i>
                                    </div>
                                    <div class="news-date"><?= $news['date'] ?></div>
                                </div>
                                <h5 class="news-title">
                                    <?= htmlspecialchars($news['title']) ?>
                                    <?php if (!empty($news['is_new'])): ?>
                                        <span class="badge bg-danger ms-2" style="font-size: 0.7rem; vertical-align: middle;">NEW</span>
                                    <?php endif; ?>
                                </h5>
                                <p class="news-desc"><?= htmlspecialchars($news['short']) ?></p>
                                <a href="#" class="news-link text-<?= $news['color'] ?> open-news-modal"
                                    data-bs-toggle="modal" 
                                    data-bs-target="#newsModal"
                                    data-title="<?= htmlspecialchars($news['title']) ?>"
                                    data-date="<?= $news['date'] ?>"
                                    data-content="<?= htmlspecialchars($news['full']) ?>">
                                    Подробнее <i class="bi bi-arrow-right ms-2"></i>
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    
    <?php if (count($newsChunks) > 1): ?>
        <button class="carousel-control-prev" type="button" data-bs-target="#newsCarousel" data-bs-slide="prev">
            <span class="carousel-control-prev-icon" aria-hidden="true"></span>
            <span class="visually-hidden">Previous</span>
        </button>
        <button class="carousel-control-next" type="button" data-bs-target="#newsCarousel" data-bs-slide="next">
            <span class="carousel-control-next-icon" aria-hidden="true"></span>
            <span class="visually-hidden">Next</span>
        </button>
    <?php endif; ?>
</div>

<div class="modal fade" id="newsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header border-0 pb-0">
                <p class="text-muted small mb-0 fw-bold" id="modalNewsDate">...</p>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body pt-2">
                <h3 class="fw-bold mb-4" id="modalNewsTitle">Заголовок</h3>
                <div class="news-content-full text-secondary" id="modalNewsContent" style="line-height: 1.7;"></div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Закрыть</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const newsModal = document.getElementById('newsModal');
    if (newsModal) {
        newsModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            const title = button.getAttribute('data-title');
            const date = button.getAttribute('data-date');
            const content = button.getAttribute('data-content');
            
            newsModal.querySelector('#modalNewsTitle').textContent = title;
            newsModal.querySelector('#modalNewsDate').textContent = date;
            newsModal.querySelector('#modalNewsContent').innerHTML = content;
        });
    }
});
</script>

<?php require_once 'footer.php'; ?>
