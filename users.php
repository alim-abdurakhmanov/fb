<?php
require_once 'config.php';
require_once __DIR__ . '/includes/upload_access.php';

$pdo = getPDO();

// 1. Проверка прав
checkAuth();
$currentUser = getCurrentUser();

if ($currentUser['role'] !== 'manager' || finbuild_is_submanager($currentUser)) {
    header('Location: index.php');
    exit();
}

// 2. Логика фильтрации
$role_filter = $_GET['role_filter'] ?? 'all';
$status_filter = $_GET['status_filter'] ?? 'all'; // Новый фильтр
$last_name_search = trim($_GET['last_name'] ?? '');

$sql = "SELECT u.id, u.email, u.inn, u.first_name, u.last_name, u.company_name, u.role, u.is_analyst, u.is_submanager, u.registration_date, u.is_active, u.avatar_path,
               u.created_by,
               creator.first_name AS creator_first_name,
               creator.last_name AS creator_last_name
        FROM users u
        LEFT JOIN users creator ON creator.id = u.created_by
        WHERE 1=1";
$params = [];

// Показываем всех, кроме банков и руководителей (manager без is_submanager).
// Ограниченные менеджеры (manager + is_submanager=1) — отображаются.
$sql .= " AND u.role != 'bank' AND NOT (u.role = 'manager' AND COALESCE(u.is_submanager, 0) = 0)";

// Фильтр по роли
if ($role_filter === 'client') {
    $sql .= " AND u.role = 'client'";
} elseif ($role_filter === 'partner') {
    $sql .= " AND u.role = 'partner'";
} elseif ($role_filter === 'analyst') {
    $sql .= " AND (u.role = 'analyst' OR u.is_analyst = 1)";
} elseif ($role_filter === 'manager') {
    $sql .= " AND u.role = 'manager' AND COALESCE(u.is_submanager, 0) = 1";
}

// Фильтр по статусу (Новый)
if ($status_filter === 'active') {
    $sql .= " AND u.is_active = 1";
} elseif ($status_filter === 'blocked') {
    $sql .= " AND u.is_active = 0";
}

// Поиск по фамилии
if (!empty($last_name_search)) {
    $sql .= " AND (u.last_name LIKE ? OR u.inn LIKE ?)";
    $params[] = "%$last_name_search%";
    $params[] = "%$last_name_search%";
}

$sql .= " ORDER BY u.registration_date DESC, u.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

require_once 'header.php';
?>

<style>
/* Стили те же */
.applications-table { background: white; border-radius: 12px; box-shadow: 0 2px 20px rgba(0,0,0,0.08); overflow: hidden; font-size: 0.875rem; }
.table th { background: #f8f9fa; border-bottom: 2px solid #e9ecef; font-weight: 600; color: #495057; padding: 0.75rem; font-size: 0.875rem; }
.table td { padding: 0.75rem; vertical-align: middle; border-bottom: 1px solid #e9ecef; font-size: 0.875rem; }
.table tbody tr:hover { background-color: #f8f9fa; }
.company-name { font-weight: 500; color: #2c3e50; font-size: 0.875rem; }
.filter-section { background: white; border-radius: 12px; padding: 1.5rem; margin-bottom: 1.5rem; box-shadow: 0 2px 20px rgba(0,0,0,0.08); }
.empty-state { padding: 3rem 2rem; text-align: center; background: white; border-radius: 12px; box-shadow: 0 2px 20px rgba(0,0,0,0.08); }
.empty-state-icon { font-size: 4rem; color: #6c757d; margin-bottom: 1.5rem; }
.badge-custom { border: none; padding: 0.35rem 0.65rem; font-weight: 500; font-size: 0.75rem; color: white !important; border-radius: 6px; }
.badge-role-partner { background: linear-gradient(135deg, #3498db, #2980b9); box-shadow: 0 2px 4px rgba(52, 152, 219, 0.2); }
.badge-role-client { background: linear-gradient(135deg, #6f42c1, #59359a); box-shadow: 0 2px 4px rgba(111, 66, 193, 0.2); }
.badge-role-partner-analyst { background: linear-gradient(135deg, #3498db, #0ca678); box-shadow: 0 2px 4px rgba(52, 152, 219, 0.25); }
.badge-role-analyst { background: linear-gradient(135deg, #20c997, #0ca678); box-shadow: 0 2px 4px rgba(32, 201, 151, 0.2); }
.badge-role-manager { background: linear-gradient(135deg, #fd7e14, #e8590c); box-shadow: 0 2px 4px rgba(253, 126, 20, 0.2); }
.badge-status-active { background: linear-gradient(135deg, #28a745, #20c997); box-shadow: 0 2px 4px rgba(39, 174, 96, 0.2); }
.badge-status-blocked { background: linear-gradient(135deg, #dc3545, #c82333); box-shadow: 0 2px 4px rgba(220, 53, 69, 0.2); }
.user-avatar-circle { width: 36px; height: 36px; border-radius: 50%; background-color: #f1f3f5; color: #495057; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.8rem; margin-right: 0.75rem; border: 1px solid #e9ecef; overflow: hidden; }
.user-avatar-circle img { width: 100%; height: 100%; object-fit: cover; }

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

    .page-header .btn {
        width: 100%;
    }

    .page-header .col-auto {
        width: 100%;
    }

    .filter-section {
        padding: 1rem;
        margin-bottom: 1rem;
        border-radius: 12px;
    }

    .form-label {
        font-size: 0.85rem;
    }

    .form-control,
    .form-select {
        font-size: 0.9rem;
    }

    .btn {
        font-size: 0.9rem;
        padding: 0.45rem 0.75rem;
    }

    .applications-table {
        font-size: 0.85rem;
    }

    .table th,
    .table td {
        padding: 0.6rem;
        font-size: 0.85rem;
    }
}
</style>

<!-- <div class="container-fluid py-4"> -->
    
    <div class="page-header mb-4">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="h3 mb-0">Пользователи</h1>
                <p class="text-muted mb-0">Управление пользователями системы</p>
            </div>
            <div class="col-auto">
                <a href="user_create.php" class="btn btn-primary">
                    <i class="bi bi-plus-circle me-2"></i>Создать пользователя
                </a>
            </div>
        </div>
    </div>

    <!-- Filter Section -->
    <div class="filter-section">
        <form method="GET" class="row align-items-center">
            <!-- Роль -->
            <div class="col-md-3 mb-2 mb-md-0">
                <label class="form-label mb-1">Роль</label>
                <select name="role_filter" class="form-select" onchange="this.form.submit()">
                    <option value="all" <?= $role_filter === 'all' ? 'selected' : '' ?>>Все роли</option>
                    <option value="client" <?= $role_filter === 'client' ? 'selected' : '' ?>>Клиенты</option>
                    <option value="partner" <?= $role_filter === 'partner' ? 'selected' : '' ?>>Партнеры</option>
                    <option value="analyst" <?= $role_filter === 'analyst' ? 'selected' : '' ?>>Аналитики</option>
                    <option value="manager" <?= $role_filter === 'manager' ? 'selected' : '' ?>>Менеджеры</option>
                </select>
            </div>

            <!-- Статус (НОВЫЙ БЛОК) -->
            <div class="col-md-3 mb-2 mb-md-0">
                <label class="form-label mb-1">Статус</label>
                <select name="status_filter" class="form-select" onchange="this.form.submit()">
                    <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>Все статусы</option>
                    <option value="active" <?= $status_filter === 'active' ? 'selected' : '' ?>>Активные</option>
                    <option value="blocked" <?= $status_filter === 'blocked' ? 'selected' : '' ?>>Заблокированные</option>
                </select>
            </div>

            <!-- Поиск -->
            <div class="col-md-4 mb-2 mb-md-0">
                <label class="form-label mb-1">Поиск</label>
                <input type="text" name="last_name" class="form-control" placeholder="Поиск ..." value="<?= htmlspecialchars($last_name_search) ?>">
            </div>

            <div class="col-md-2 d-flex align-items-end gap-2">
                <button type="submit" class="btn btn-outline-primary w-100 mt-4">Найти</button>
                <a href="users.php" class="btn btn-outline-secondary w-100 mt-4" title="Сбросить"><i class="bi bi-arrow-clockwise"></i></a>
            </div>
        </form>
    </div>

    <!-- Users Table -->
    <?php if (empty($users)): ?>
        <div class="empty-state">
            <div class="empty-state-icon">
                <i class="bi bi-people"></i>
            </div>
            <h4 class="text-muted mb-3">Пользователи не найдены</h4>
            <p class="text-muted mb-4">Попробуйте изменить параметры поиска</p>
        </div>
    <?php else: ?>
        <div class="applications-table">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Пользователь</th>
                            <th>Компания</th>
                            <th>ИНН</th>
                            <th>Роль</th>
                            <th>Регистрация</th>
                            <th>Дата регистрации</th>
                            <th>Статус</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                            <tr role="button" style="cursor: pointer;"
                                onclick="if (!event.target.closest('a, button, .no-row-click')) window.location='user_view.php?id=<?= (int)$user['id'] ?>'">
                                <td>
                                    <strong>#<?= $user['id'] ?></strong>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="user-avatar-circle">
                                            <?php if (!empty($user['avatar_path'])): ?>
                                                <img src="<?= htmlspecialchars(finbuild_upload_file_url('avatar', (int) $user['id'])) ?>" alt="Avatar">
                                            <?php else: ?>
                                                <?= mb_substr($user['first_name'], 0, 1) . mb_substr($user['last_name'], 0, 1) ?>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <div class="company-name">
                                                <?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?>
                                            </div>
                                            <div class="small text-muted"><?= htmlspecialchars($user['email']) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?php if (!empty($user['company_name'])): ?>
                                        <span class="text-dark font-weight-500"><?= htmlspecialchars($user['company_name']) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($user['inn'])): ?>
                                        <span class="text-muted"><?= htmlspecialchars($user['inn']) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($user['role'] === 'manager'): ?>
                                        <span class="badge badge-custom badge-role-manager">Менеджер</span>
                                    <?php elseif ($user['role'] === 'partner' && !empty($user['is_analyst'])): ?>
                                        <span class="badge badge-custom badge-role-partner-analyst">Партнёр, аналитик</span>
                                    <?php elseif ($user['role'] === 'partner'): ?>
                                        <span class="badge badge-custom badge-role-partner">Партнер</span>
                                    <?php elseif ($user['role'] === 'analyst'): ?>
                                        <span class="badge badge-custom badge-role-analyst">Аналитик</span>
                                    <?php else: ?>
                                        <span class="badge badge-custom badge-role-client">Клиент</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ((int)$user['id'] <= 61): ?>
                                        <span class="text-muted small">—</span>
                                    <?php elseif (empty($user['created_by'])): ?>
                                        <span class="text-muted small">Зарегистрировано: самостоятельно</span>
                                    <?php else: ?>
                                        <span class="text-muted small">
                                            Зарегистрировано:
                                            <?= htmlspecialchars(trim(($user['creator_first_name'] ?? '') . ' ' . ($user['creator_last_name'] ?? ''))) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= date('d.m.Y', strtotime($user['registration_date'])) ?>
                                </td>
                                <td>
                                    <?php if ($user['is_active']): ?>
                                        <span class="badge badge-custom badge-status-active">Активен</span>
                                    <?php else: ?>
                                        <span class="badge badge-custom badge-status-blocked">Заблокирован</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
<!-- </div> -->

<?php require_once 'footer.php'; ?>
