<?php 
  
$currentPage = basename($_SERVER['PHP_SELF']); 
   
require_once 'config.php'; 
checkAuth(); 
$applicationsWithUnreadMessages = getApplicationsWithUnreadMessagesCount();
$unreadSupportCount = getUnreadSupportCount();
$currentUser = getCurrentUser();
$userRole = (string) ($currentUser['role'] ?? ($_SESSION['role'] ?? 'client'));

if ($userRole === 'bank') {
    $bankAllowedScripts = [
        'bank_applications.php',
        'bank_application_detail.php',
        'profile.php',
        'support.php',
        'logout.php',
        'api_application_structure.php',
        'api_download_application_documents.php',
        'api_serve_upload.php',
        'api_support_create.php',
        'api_support_message.php',
        'api_support_status.php',
        'load_analytics_cache.php',
        'api_get_company_analytics.php',
        'save_analytics.php',
    ];
    if (!in_array($currentPage, $bankAllowedScripts, true)) {
        header('Location: bank_applications.php');
        exit;
    }
}

finbuild_enforce_analyst_page_access($userRole, $currentPage);

// Ограниченный менеджер: нет доступа к админ-страницам (Продукты, Пользователи, статистика).
$isCaseManager = finbuild_is_case_manager($currentUser);
$hasFullManagerAccess = finbuild_has_full_manager_access($currentUser);
$isSubmanager = $isCaseManager; // совместимость со старыми шаблонами
if ($isCaseManager) {
    $caseManagerBlockedScripts = [
        'products_admin.php',
        'users.php',
        'user_create.php',
        'user_view.php',
        'applications_monthly_stats.php',
        'access_rights.php',
    ];
    if (in_array($currentPage, $caseManagerBlockedScripts, true)) {
        header('Location: applications.php');
        exit;
    }
}

// Название роли для отображения (слева от почты в бейдже)
$isPureAnalyst = finbuild_is_pure_analyst($currentUser);
$isHybridAnalystPartner = ($userRole === 'partner' && finbuild_user_is_analyst_flag($currentUser));
$applicationsListScope = (string) ($_GET['scope'] ?? '');

// Название роли для отображения (слева от почты в бейdже)
$roleName = finbuild_role_display_name($currentUser);
if ($userRole === 'bank') {
    require_once __DIR__ . '/includes/bank_portal.php';
    $roleName = finbank_user_bank_label(getPDO(), $currentUser);
}

/** Имя в шапке (крупная строка): всегда ФИО из профиля */
$headerUserDisplayName = trim((string) (($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? '')));

// Получаем инициалы для аватара
$initials = '';
if (!empty($currentUser['first_name']) && !empty($currentUser['last_name'])) {
    if (function_exists('mb_substr')) {
        $initials = mb_substr($currentUser['first_name'], 0, 1, 'UTF-8') . 
                    mb_substr($currentUser['last_name'], 0, 1, 'UTF-8');
    } else {
        $initials = substr($currentUser['first_name'], 0, 1) . 
                   substr($currentUser['last_name'], 0, 1);
    }
    $initials = mb_strtoupper($initials, 'UTF-8');
} elseif (!empty($currentUser['first_name'])) {
    $initials = function_exists('mb_substr') 
        ? mb_substr($currentUser['first_name'], 0, 1, 'UTF-8')
        : substr($currentUser['first_name'], 0, 1);
    $initials = mb_strtoupper($initials, 'UTF-8');
} else {
    $initials = '?';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FinBuild - Финансовый сервис для получения банковской гарантии</title>
    <link rel="icon" href="assets/favicons/favicon.ico" sizes="any">
    <link rel="apple-touch-icon" sizes="180x180" href="assets/favicons/apple-touch-icon-180x180.png">
    <!-- <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16.png">
    -->
 
    
    <!-- Подключаем Montserrat ТОЛЬКО для логотипа -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500&family=Montserrat:wght@600;700;800&display=swap" rel="stylesheet">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --sidebar-width: 252px;
            --sidebar-bg: #2c3e50;
            --sidebar-header-bg: #1a252f; /* Переопределится ниже */
            --sidebar-active: #3498db;
            --top-navbar-height: 60px;
        }
        
        body {
            /* Ваш оригинальный шрифт */
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f8f9fa;
            overflow-x: hidden;
            color: #333;
        }
        
        /* ЛАКОНИЧНЫЙ САЙДБАР */
        .sidebar {
            background: var(--sidebar-bg);
            color: white;
            min-height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            width: var(--sidebar-width);
            z-index: 1050;
            box-shadow: 2px 0 8px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            transform: translateX(-100%);
        }
        
        .sidebar.show {
            transform: translateX(0);
        }
        
        .sidebar-header {
            margin: 0.85rem 0.55rem 0.55rem;
            padding: 1rem 0.8rem 0.9rem;
            text-align: left;
            position: relative;
            overflow: hidden;
            isolation: isolate;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.08);
            background: rgba(15, 23, 42, 0.34);
            box-shadow: 0 10px 24px rgba(0, 0, 0, 0.14);
        }

        .sidebar-header::before {
            content: '';
            position: absolute;
            inset: 0;
            pointer-events: none;
            background:
                radial-gradient(120% 90% at 0% -20%, rgba(52, 152, 219, 0.22), transparent 55%),
                radial-gradient(80% 70% at 100% 120%, rgba(37, 99, 235, 0.08), transparent 50%);
            z-index: 0;
        }
        
        .brand-logo {
            font-family: 'Montserrat', system-ui, sans-serif;
            font-weight: 800;
            font-size: 1.32rem;
            margin-bottom: 0.35rem;
            letter-spacing: -0.04em;
            line-height: 1;
            display: block;
            color: #f8fafc;
        }

        .brand-tagline {
            display: block;
            margin: 0.35rem 0 0;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            font-size: 0.7rem;
            font-weight: 500;
            line-height: 1.25;
            letter-spacing: 0.01em;
            color: rgba(148, 163, 184, 0.95);
            white-space: nowrap;
        }

        .sidebar-header-link {
            display: block;
            text-decoration: none;
            color: inherit;
            cursor: pointer;
            position: relative;
            z-index: 1;
            transition: opacity 0.2s ease;
        }

        .sidebar-header-link:hover {
            opacity: 0.96;
        }
        
        .sidebar .nav {
            padding: 1rem 0;
        }
        
        /* Заголовки разделов */
        .nav-section-title {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: rgba(255, 255, 255, 0.5);
            padding: 0.8rem 1.2rem 0.3rem;
            margin-top: 0.5rem;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            font-weight: 500;
        }
        
        /* Первый заголовок без границы сверху */
        .nav-section-title:first-child {
            border-top: none;
            margin-top: 0;
        }
        
        .sidebar .nav-link {
            color: #bdc3c7;
            padding: 0.8rem 1.2rem;
            margin: 0.1rem 0.5rem;
            border-radius: 6px;
            transition: all 0.2s ease;
            font-weight: 500;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
        }
        
        .sidebar .nav-link:hover {
            color: white;
            background: rgba(255, 255, 255, 0.05);
        }
        
        .sidebar .nav-link.active {
            color: white;
            background: var(--sidebar-active);
            font-weight: 600;
        }
        
        .sidebar .nav-link i {
            width: 20px;
            margin-right: 10px;
            font-size: 1rem;
        }
        
        /* Кнопка закрытия сайдбара для мобильных */
        .sidebar-close-btn {
            position: absolute;
            right: 10px;
            top: 10px; /* Чуть поправил позицию, так как шапка стала выше */
            background: transparent;
            border: none;
            color: rgba(255,255,255,0.5);
            font-size: 1.5rem;
            padding: 5px;
            display: none;
            z-index: 15;
        }
        
        /* Основной контент */
        .main-content {
            min-height: 100vh;
            transition: all 0.3s ease;
        }
        
        /* Верхняя навигация */
        .top-navbar {
            background: #fff !important;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            border-bottom: 1px solid #e9ecef;
            position: sticky;
            top: 0;
            z-index: 1040;
            height: var(--top-navbar-height);
            padding: 0 1rem;
        }
        
        .content-wrapper {
            padding: 1.5rem;
            margin-top: 0;
        }
        
        .page-header {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            margin-bottom: 1.5rem;
            border: 1px solid #e9ecef;
        }
        
        /* Аватар и роль в верхнем баре */
        .user-info-container {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .avatar-header {
            width: 43px;
            height: 43px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #e9ecef;
            background-color: #f8f9fa;
        }
        
        .avatar-initials {
            width: 43px;
            height: 43px;
            border-radius: 50%;
            background: #3498db;
            color: white;
            font-size: 14px;
            font-weight: 600;
            border: 2px solid #fff;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .user-info-text {
            display: flex;
            flex-direction: column;
        }
        
        .user-name {
            font-weight: 600;
            font-size: 0.9rem;
            color: #2c3e50;
        }
        
        .user-role {
            font-size: 0.75rem;
            color: #6c757d;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        .role-badge {
            background: #f8f9fa;
            color: #6c757d;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.7rem;
            border: 1px solid #dee2e6;
        }
        
        .user-info-dropdown {
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none !important;
        }
        
        .user-info-dropdown:hover {
            opacity: 0.8;
        }
        
        /* Дропдаун меню */
        .dropdown-menu {
            border: none;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            border-radius: 8px;
            padding: 0.5rem 0;
            min-width: 200px;
            border: 1px solid #e9ecef;
        }
        
        .dropdown-item {
            padding: 0.6rem 1.2rem;
            font-size: 0.85rem;
            transition: all 0.2s;
            color: #495057;
        }
        
        .dropdown-item:hover {
            background-color: #f8f9fa;
            color: #3498db;
        }
        
        .dropdown-divider {
            margin: 0.4rem 0;
        }
        
        /* Оверлей для мобильных */
        .sidebar-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1040;
            display: none;
            pointer-events: none;
            transition: opacity 0.3s ease;
        }
        
        .sidebar-overlay.show {
            display: block;
            pointer-events: auto;
            animation: fadeIn 0.3s ease;
        }
        
        /* Кнопка открытия сайдбара для мобильных */
        .sidebar-toggle-btn {
            background: transparent;
            border: none;
            color: #2c3e50;
            font-size: 1.5rem;
            padding: 0.5rem;
            display: none;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }
        
        /* Анимации */
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes slideIn {
            from { transform: translateX(-100%); }
            to { transform: translateX(0); }
        }
        
        /* Адаптивность */
        @media (max-width: 768px) {
            .sidebar-toggle-btn {
                display: flex;
            }

            .sidebar {
                height: 100vh;
                overflow-y: auto;
            }

            .sidebar .nav-link {
                font-size: 0.85rem;
                padding: 0.6rem 1rem;
                margin: 0.1rem 0.4rem;
            }

            .sidebar .nav-link i {
                font-size: 0.95rem;
                margin-right: 8px;
            }

            .nav-section-title {
                font-size: 0.7rem;
                padding: 0.6rem 1rem 0.25rem;
            }
            
            .sidebar-close-btn {
                display: block;
                top: 2px;
            }
            
            .content-wrapper {
                padding: 1rem;
            }
            
            .page-header {
                padding: 1rem;
            }
            
            /* Адаптация информации пользователя для мобильных */
            .user-info-container {
                gap: 8px;
            }
            
            .user-info-text {
                max-width: calc(100vw - 150px);
            }
            
            .user-name {
                font-size: 0.8rem;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            
            .user-role {
                font-size: 0.7rem;
                flex-wrap: wrap;
                gap: 4px;
            }
            
            .user-role span:last-child {
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
                max-width: 120px;
            }
            
            .role-badge {
                font-size: 0.65rem;
                padding: 1px 4px;
            }
        }
        
        @media (min-width: 769px) {
            .sidebar {
                transform: translateX(0) !important;
            }
            
            .main-content {
                margin-left: var(--sidebar-width);
            }
        }

        .bg-primary { border-left-color: #3498db !important; }
        .bg-warning { border-left-color: #f39c12 !important; }
        .bg-success { border-left-color: #27ae60 !important; }
        .bg-info { border-left-color: #17a2b8 !important; }
        
        /* Улучшенные кнопки */
        .btn {
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #3498db, #2980b9);
            border: none;
        }
        
        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 15px rgba(52, 152, 219, 0.4);
        }

        .sidebar-badge {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: linear-gradient(135deg, #dc3545, #c82333);
            border: none;
            padding: 0.1rem 0.4rem;
            font-size: 0.65rem;
            font-weight: 600;
            box-shadow: 0 2px 4px rgba(220, 53, 69, 0.2);
            min-width: 18px;
            height: 18px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 9px;
            color: white;
            animation: pulse 2s infinite;
        }

        .notification-dot {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            width: 8px;
            height: 8px;
            background: #dc3545;
            border-radius: 50%;
            animation: pulse 2s infinite;
        }

        .nav-item {
            position: relative;
        }

        .nav-link {
            position: relative;
            padding-right: 30px !important; /* Меньше отступ для точечки */
        }

        @keyframes pulse {
            0% {
                box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.7);
            }
            70% {
                box-shadow: 0 0 0 3px rgba(220, 53, 69, 0);
            }
            100% {
                box-shadow: 0 0 0 0 rgba(220, 53, 69, 0);
            }
        }

        /* Убираем анимацию для активной ссылки */
        .nav-link.active .sidebar-badge,
        .nav-link.active .notification-dot {
            animation: none;
        }

        .nav-link.active .sidebar-badge {
            background: linear-gradient(135deg, #fff, #f8f9fa);
            color: #dc3545;
            border: 1px solid #dc3545;
        }

        .nav-link.active .notification-dot {
            background: #dc3545;
            opacity: 0.7;
        }
    </style>
    <!-- jQuery (необходим для Select2) -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<!-- Select2 CSS -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<!-- Select2 Bootstrap 5 Theme (чтобы стиль совпадал с вашим дизайном) -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" />

<!-- Select2 JS -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<!-- Русификация (чтобы писало "Ничего не найдено" по-русски) -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/i18n/ru.js"></script>

</head>
<body>
    <!-- Оверлей для мобильных -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <!-- ОБНОВЛЕННАЯ ШАПКА -->
        <div class="sidebar-header">
            <a href="index.php" class="sidebar-header-link">
                <span class="brand-logo">FINBUILD</span>
                <span class="brand-tagline">Платформа банковских гарантий</span>
            </a>
            <button class="sidebar-close-btn" id="sidebarClose">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        
        <div class="nav flex-column">
            <!-- Раздел: Основное -->
            <div class="nav-section-title">Основное</div>
            <?php if ($userRole !== 'bank'): ?>
             <?php if (!$isPureAnalyst): ?>
             <a class="nav-link <?= $current_page == 'dashboard' ? 'active' : '' ?>" href="index.php">
                <i class="bi bi-speedometer2"></i> Главная
            </a>
             <?php endif; ?>
            <!-- Пункт "Заявки" на первом месте -->
            <a class="nav-link <?= ($current_page == 'applications' && (!$isHybridAnalystPartner || $applicationsListScope !== 'all')) ? 'active' : '' ?>" href="applications.php">
                <i class="bi bi-list-check"></i> 
                <?php if (finbuild_is_manager($userRole) || $isPureAnalyst): ?>
                    Заявки
                <?php else: ?>
                    Мои заявки
                <?php endif; ?>
                
                <?php if (finbuild_is_manager($userRole) && $applicationsWithUnreadMessages > 0): ?>
                    <span class="sidebar-badge" title="Заявок с непрочитанными сообщениями: <?= (int) $applicationsWithUnreadMessages ?>">
                        <?= $applicationsWithUnreadMessages > 9 ? '9+' : $applicationsWithUnreadMessages ?>
                    </span>
                <?php endif; ?>
            </a>
            <?php if ($isHybridAnalystPartner): ?>
            <a class="nav-link <?= ($current_page == 'applications' && $applicationsListScope === 'all') ? 'active' : '' ?>" href="applications.php?scope=all">
                <i class="bi bi-diagram-3"></i> Анализ заявок
            </a>
            <?php endif; ?>
            <?php else: ?>
            <a class="nav-link <?= ($current_page ?? '') == 'bank_applications' || ($current_page ?? '') == 'bank_application_detail' ? 'active' : '' ?>" href="bank_applications.php">
                <i class="bi bi-bank"></i> Заявки
            </a>
            <?php endif; ?>
            
           
            
            <!-- Раздел: Инструменты -->
            <?php if ($userRole !== 'bank' && !$isPureAnalyst): ?>
            <div class="nav-section-title">Инструменты</div>
            
            <a class="nav-link <?= $current_page == 'analytics' ? 'active' : '' ?>" href="analytics.php">
                <i class="bi bi-search"></i> Аналитика по ИНН
            </a>
            
            <a class="nav-link <?= $current_page == 'product_selection' ? 'active' : '' ?>" href="product_selection.php">
                <i class="bi bi-box-seam"></i> Подбор продуктов
            </a>
            
            <a class="nav-link <?= $current_page == 'limit_calculation' ? 'active' : '' ?>" href="limit_calculation.php">
                <i class="bi bi-calculator"></i> Расчет лимитов
            </a>
            <?php endif; ?>
            
            <!-- Раздел: Аккаунт -->
            <div class="nav-section-title">Аккаунт</div>
            
            <a class="nav-link <?= $current_page == 'profile' ? 'active' : '' ?>" href="profile.php">
                <i class="bi bi-person"></i> Личный кабинет
            </a>

            <?php if (!$isPureAnalyst): ?>
            <a class="nav-link <?= $current_page == 'support' ? 'active' : '' ?>" href="support.php">
                <i class="bi bi-life-preserver"></i> Поддержка
                <?php if ($unreadSupportCount > 0): ?>
                    <span class="sidebar-badge" title="<?= $unreadSupportCount ?> новых сообщений поддержки">
                        <?= $unreadSupportCount > 9 ? '9+' : $unreadSupportCount ?>
                    </span>
                <?php endif; ?>
            </a>
            <?php endif; ?>
            
            <!-- Администрирование -->
            <?php if (
                finbuild_can('admin.users', $currentUser)
                || finbuild_can('admin.products', $currentUser)
                || finbuild_can('stats.monthly', $currentUser)
                || finbuild_can('access_rights.manage', $currentUser)
            ): ?>
          <div class="nav-section-title">Администрирование</div>
            
            <?php if (finbuild_can('admin.users', $currentUser)): ?>
            <a class="nav-link <?= (in_array($currentPage, ['users.php', 'user_create.php', 'user_view.php'])) ? 'active' : '' ?>" href="users.php">
                <i class="bi bi-people"></i> Пользователи
            </a>
            <?php endif; ?>

            <?php if (finbuild_can('admin.products', $currentUser)): ?>
            <a class="nav-link <?= $currentPage === 'products_admin.php' ? 'active' : '' ?>" href="products_admin.php">
                <i class="bi bi-grid-3x3-gap"></i> Продукты
            </a>
            <?php endif; ?>

            <?php if (finbuild_can('stats.monthly', $currentUser)): ?>
            <a class="nav-link <?= $currentPage === 'applications_monthly_stats.php' ? 'active' : '' ?>" href="applications_monthly_stats.php">
                <i class="bi bi-bar-chart-line"></i> Статистика
            </a>
            <?php endif; ?>

            <?php if (finbuild_can('access_rights.manage', $currentUser)): ?>
            <a class="nav-link <?= $currentPage === 'access_rights.php' ? 'active' : '' ?>" href="access_rights.php">
                <i class="bi bi-shield-lock"></i> Права доступа
            </a>
            <?php endif; ?>
            
         
            <?php endif; ?>
        </div>
    </div>

    <!-- Main content -->
    <div class="main-content" id="mainContent">
        <!-- Top Navbar -->
        <nav class="navbar navbar-expand-lg top-navbar">
            <div class="container-fluid">
                <!-- Кнопка открытия сайдбара для мобильных -->
                <button class="sidebar-toggle-btn" id="sidebarToggle">
                    <i class="bi bi-list"></i>
                </button>
          
                <div class="d-flex align-items-center ms-auto">
                    <div class="nav-item dropdown">
                        <!-- Убрали класс dropdown-toggle и стрелку -->
                        <a class="nav-link d-flex align-items-center user-info-dropdown dropdown-toggle p-0" 
                           href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown">
                            <div class="user-info-container">
                                <?php
                                require_once __DIR__ . '/includes/upload_access.php';
                                $avatarPath = $currentUser['avatar_path'] ?? '';
                                $hasAvatar = !empty($avatarPath);
                                $avatarUrl = $hasAvatar ? finbuild_upload_file_url('avatar', (int) ($currentUser['id'] ?? 0)) : '';
                                ?>
                                
                                <?php if ($hasAvatar): ?>
                                    <img src="<?= htmlspecialchars($avatarUrl) ?>?t=<?= time() ?>" 
                                         class="avatar-header" 
                                         alt="Аватар"
                                         onerror="this.onerror=null; this.src=''; this.className='avatar-initials'; this.innerHTML='<?= htmlspecialchars($initials) ?>'">
                                <?php else: ?>
                                    <div class="avatar-initials">
                                        <?= htmlspecialchars($initials) ?>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="user-info-text">
                                    <span class="user-name"><?= htmlspecialchars($headerUserDisplayName) ?></span>
                                    <span class="user-role">
                                        <span class="role-badge"><?= htmlspecialchars($roleName) ?></span>
                                        <span><?= htmlspecialchars($currentUser['email']) ?></span>
                                    </span>
                                </div>
                            </div>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li>
                                <a class="dropdown-item" href="profile.php">
                                    <i class="bi bi-person me-2"></i>Личный кабинет
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item" href="logout.php">
                                    <i class="bi bi-box-arrow-right me-2"></i>Выйти
                                </a>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </nav>

        <!-- Content Wrapper -->
        <div class="content-wrapper">

<script>
(function () {
    const MOBILE_BREAKPOINT = 769;

    function getSidebarEls() {
        return {
            sidebar: document.getElementById('sidebar'),
            sidebarToggle: document.getElementById('sidebarToggle'),
            sidebarClose: document.getElementById('sidebarClose'),
            sidebarOverlay: document.getElementById('sidebarOverlay'),
        };
    }

    function resetSidebarState() {
        const { sidebar, sidebarOverlay } = getSidebarEls();
        if (!sidebar) {
            document.body.style.overflow = '';
            return;
        }
        sidebar.classList.remove('show', 'mobile-show');
        if (sidebarOverlay) {
            sidebarOverlay.classList.remove('show');
        }
        document.body.style.overflow = '';
    }

    function openSidebar() {
        const { sidebar, sidebarOverlay } = getSidebarEls();
        if (!sidebar || window.innerWidth >= MOBILE_BREAKPOINT) {
            return;
        }
        sidebar.classList.remove('mobile-show');
        sidebar.classList.add('show');
        if (sidebarOverlay) {
            sidebarOverlay.classList.add('show');
        }
        document.body.style.overflow = 'hidden';
    }

    function closeSidebar() {
        resetSidebarState();
    }

    function initSidebar() {
        const { sidebar, sidebarToggle, sidebarClose, sidebarOverlay } = getSidebarEls();
        if (!sidebar) {
            return;
        }

        resetSidebarState();

        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', function (e) {
                e.preventDefault();
                if (sidebar.classList.contains('show')) {
                    closeSidebar();
                } else {
                    openSidebar();
                }
            });
        }

        if (sidebarClose) {
            sidebarClose.addEventListener('click', function (e) {
                e.preventDefault();
                closeSidebar();
            });
        }

        if (sidebarOverlay) {
            sidebarOverlay.addEventListener('click', function (e) {
                if (e.target === sidebarOverlay) {
                    closeSidebar();
                }
            });
        }

        sidebar.querySelectorAll('.nav-link').forEach(function (link) {
            link.addEventListener('click', function () {
                if (window.innerWidth < MOBILE_BREAKPOINT) {
                    closeSidebar();
                }
            });
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                closeSidebar();
            }
        });

        window.addEventListener('resize', function () {
            if (window.innerWidth >= MOBILE_BREAKPOINT) {
                closeSidebar();
            }
        });
    }

    document.addEventListener('DOMContentLoaded', initSidebar);

    window.addEventListener('pageshow', function () {
        resetSidebarState();
    });

    window.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            resetSidebarState();
        }
    });
})();
</script>