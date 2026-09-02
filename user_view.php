<?php
require_once 'config.php';
require_once __DIR__ . '/includes/upload_access.php';

$pdo = getPDO(); 

// Проверка доступа
checkAuth();
$currentUser = getCurrentUser();
if ($currentUser['role'] !== 'manager' || finbuild_is_submanager($currentUser)) {
    header('Location: index.php');
    exit();
}

$userId = (int)($_GET['id'] ?? 0);
if ($userId <= 0 && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $userId = (int)($_POST['user_id'] ?? 0);
}
if ($userId <= 0) {
    header('Location: users.php');
    exit();
}

// Получаем данные пользователя
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    die("Пользователь не найден");
}

if ($user['role'] === 'bank') {
    header('Location: users.php');
    exit();
}

$success_msg = '';
$error_msg = '';

// Обработка форм
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_profile'])) {
        $inn = trim($_POST['inn'] ?? '');
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $company_name = trim($_POST['company_name'] ?? '');
        // Роль менеджера через эту форму не понижаем (manager не входит в список опций)
        $isManagerTarget = ($user['role'] === 'manager');
        $role = $_POST['role'] ?? 'client';
        if ($isManagerTarget) {
            $role = 'manager';
        } elseif (!in_array($role, ['client', 'partner'], true)) {
            $role = 'client';
        }
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $is_analyst = ($role === 'partner' && isset($_POST['is_analyst'])) ? 1 : 0;

        $profileError = '';
        if (empty($first_name) || empty($last_name)) {
            $profileError = "Имя и Фамилия обязательны";
        } elseif ($role === 'partner' || $role === 'manager') {
            $inn = '';
            if ($role === 'manager') {
                $company_name = '';
            }
        } else {
            $inn = preg_replace('/\D/', '', $inn);
            if ($inn === '') {
                $profileError = "ИНН обязателен для клиента";
            } elseif (!preg_match('/^\d{10,12}$/', $inn)) {
                $profileError = "ИНН должен состоять из 10–12 цифр";
            }
        }

        $email_notifications_enabled = isset($_POST['email_notifications_enabled']) ? 1 : 0;
        $notification_email = trim($_POST['notification_email'] ?? '');
        if ($profileError === '' && $notification_email !== '' && !filter_var($notification_email, FILTER_VALIDATE_EMAIL)) {
            $profileError = 'Некорректный e-mail для уведомлений';
        }

        if ($profileError !== '') {
            $error_msg = $profileError;
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE users SET inn=?, first_name=?, last_name=?, phone=?, company_name=?, role=?, is_analyst=?, is_active=?, email_notifications_enabled=?, notification_email=?, updated_at=NOW() WHERE id=?");
                $stmt->execute([
                    $inn,
                    $first_name,
                    $last_name,
                    $phone,
                    $company_name,
                    $role,
                    $is_analyst,
                    $is_active,
                    $email_notifications_enabled,
                    $notification_email === '' ? null : $notification_email,
                    $userId,
                ]);

                $user['inn'] = $inn;
                $user['first_name'] = $first_name;
                $user['last_name'] = $last_name;
                $user['phone'] = $phone;
                $user['company_name'] = $company_name;
                $user['role'] = $role;
                $user['is_analyst'] = $is_analyst;
                $user['is_active'] = $is_active;
                $user['email_notifications_enabled'] = $email_notifications_enabled;
                $user['notification_email'] = $notification_email === '' ? null : $notification_email;

                $success_msg = "Данные пользователя обновлены";
            } catch (Exception $e) {
                $error_msg = "Не удалось сохранить: " . $e->getMessage();
            }
        }
    }

    if (isset($_POST['change_password'])) {
        $new_password = (string)($_POST['new_password'] ?? '');
        if (strlen($new_password) < 6) {
            $error_msg = "Пароль должен быть не менее 6 символов";
        } else {
            try {
                $hashed = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET password=? WHERE id=?");
                $stmt->execute([$hashed, $userId]);
                $success_msg = "Пароль успешно изменен";
            } catch (Exception $e) {
                $error_msg = "Не удалось сменить пароль: " . $e->getMessage();
            }
        }
    }
}

// Заявки
$stmt = $pdo->prepare("SELECT * FROM applications WHERE created_by = ? ORDER BY created_at DESC");
$stmt->execute([$userId]);
$userApplications = $stmt->fetchAll();

function getStatusBadgeLocal($status) {
    switch ($status) {
        case 'new': return '<span class="badge badge-custom badge-status-new">На проверке</span>';
        case 'in_progress': return '<span class="badge badge-custom badge-status-in-progress">В работе</span>';
        case 'pending_signing': return '<span class="badge badge-custom badge-status-pending-signing">На подписании</span>';
        case 'product_request': return '<span class="badge badge-custom badge-status-product-request">Запрос</span>';
        case 'terms_negotiation': return '<span class="badge badge-custom badge-status-terms-negotiation">Согласование условий</span>';
        case 'pending_release': return '<span class="badge badge-custom badge-status-pending-release">На выпуске</span>';
        case 'completed': return '<span class="badge badge-custom badge-status-completed">Завершена</span>';
        case 'failed': return '<span class="badge badge-custom badge-status-failed">Провалена</span>';
        default: return htmlspecialchars((string)$status);
    }
}
function formatAmountLocal($amount) {
    if (!$amount) return '-';
    return number_format($amount, 2, ',', ' ') . ' ₽';
}

require_once 'header.php';
?>

<style>
/* Общие стили */
.content-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 20px rgba(0,0,0,0.08);
    overflow: hidden;
    border: none;
    margin-bottom: 1.5rem;
}

.card-header-custom {
    background: white;
    padding: 1.5rem;
    border-bottom: 1px solid #e9ecef;
}

.card-header-custom h5 {
    margin: 0;
    font-weight: 600;
    color: #343a40;
}

/* Аватар (Стиль как в users.php) */
.avatar-large {
    width: 90px; 
    height: 90px;
    border-radius: 50%;
    background-color: #f1f3f5; /* Светло-серый фон */
    color: #495057; /* Темно-серый текст */
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 1.5rem; 
    border: 1px solid #e9ecef; /* Тонкая рамка */
    text-transform: uppercase;
    object-fit: cover;
    overflow: hidden;
}

.avatar-large img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

/* Бейджи */
.badge-custom { border: none; padding: 0.35rem 0.65rem; font-weight: 500; font-size: 0.75rem; color: white !important; border-radius: 6px; }

/* Статусы заявок */
.badge-status-new { background: linear-gradient(135deg, #ffc107, #ff9800); }
.badge-status-in-progress { background: linear-gradient(135deg, #3498db, #2980b9); }
.badge-status-pending-signing { background: linear-gradient(135deg, #17a2b8, #138496); }
.badge-status-product-request { background: linear-gradient(135deg, #ffc107, #e0a800); color: #fff !important; }
.badge-status-terms-negotiation { background: linear-gradient(135deg, #6f42c1, #5a32a3); }
.badge-status-pending-release { background: linear-gradient(135deg, #fd7e14, #e8590c); }
.badge-status-completed { background: linear-gradient(135deg, #28a745, #20c997); }
.badge-status-failed { background: linear-gradient(135deg, #dc3545, #c82333); }

/* Статусы пользователя */
.badge-status-active {
    background: linear-gradient(135deg, #28a745, #20c997);
    box-shadow: 0 2px 4px rgba(39, 174, 96, 0.2);
    border: none;
    color: white !important;
}
.badge-status-blocked {
    background: linear-gradient(135deg, #dc3545, #c82333);
    box-shadow: 0 2px 4px rgba(220, 53, 69, 0.2);
    border: none;
    color: white !important;
}

.table th { font-weight: 600; color: #495057; background: #f8f9fa; border-bottom: 2px solid #e9ecef; }
.table td { vertical-align: middle; }

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

    .content-card .card-body,
    .card-header-custom {
        padding: 1rem;
    }

    .form-label {
        font-size: 0.85rem;
    }

    .form-control,
    .form-select,
    .input-group .form-control {
        font-size: 0.9rem;
    }

    .btn {
        font-size: 0.9rem;
        padding: 0.45rem 0.75rem;
    }

    .avatar-large {
        width: 72px;
        height: 72px;
        font-size: 1.1rem;
    }

    .table th,
    .table td {
        padding: 0.6rem;
        font-size: 0.85rem;
    }
}
</style>

<!-- <div class="container-fluid py-4"> -->
    
    <!-- Page Header -->
    <div class="page-header mb-4">
        <div class="row align-items-center">
            <div class="col">
                <div class="d-flex align-items-center">
                    <!-- Стрелка назад: Синяя -->
                    <a href="users.php" class="btn btn-outline-primary btn-sm me-3 rounded-circle" style="width: 32px; height: 32px; padding: 0; display: flex; align-items: center; justify-content: center;">
                        <i class="bi bi-arrow-left"></i>
                    </a>
                    <div>
                        <h1 class="h3 mb-0"><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></h1>
                        <p class="text-muted mb-0"><?= htmlspecialchars($user['email']) ?></p>
                        <?php if ($user['role'] !== 'manager' && (!empty($user['company_name']) || !empty($user['inn']))): ?>
                            <div class="small text-muted">
                                <?php if (!empty($user['company_name'])): ?>
                                    <?= htmlspecialchars($user['company_name']) ?>
                                <?php endif; ?>
                                <?php if (!empty($user['inn'])): ?>
                                    <?php if (!empty($user['company_name'])): ?> • <?php endif; ?>
                                    ИНН: <?= htmlspecialchars($user['inn']) ?>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-auto">
                <?php if ($user['is_active']): ?>
                    <span class="badge badge-custom badge-status-active px-3 py-2">Активен</span>
                <?php else: ?>
                    <span class="badge badge-custom badge-status-blocked px-3 py-2">Заблокирован</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert alert-success alert-dismissible fade show shadow-sm border-0" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i> <?= $success_msg ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error_msg): ?>
        <div class="alert alert-danger alert-dismissible fade show shadow-sm border-0" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= $error_msg ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <!-- Левая колонка -->
        <div class="col-lg-4">
            
            <!-- Карточка профиля -->
            <form method="POST" class="card content-card" action="user_view.php?id=<?= (int)$userId ?>">
                <input type="hidden" name="user_id" value="<?= (int)$userId ?>">
                <input type="hidden" name="update_profile" value="1">
                <div class="card-body p-4">
                    <div class="text-center mb-4 pt-2">
                        <div class="avatar-large mx-auto mb-3">
                            <?php if (!empty($user['avatar_path'])): ?>
                                <img src="<?= htmlspecialchars(finbuild_upload_file_url('avatar', (int) $user['id'])) ?>" alt="Avatar">
                            <?php else: ?>
                                <!-- Используем тот же код, что и в users.php -->
                                <?= mb_substr($user['first_name'], 0, 1) . mb_substr($user['last_name'], 0, 1) ?>
                            <?php endif; ?>
                        </div>
                        <div class="mb-2">
                            <span class="badge bg-light text-secondary border me-1 font-monospace">ID: <?= $user['id'] ?></span>
                            <span class="badge bg-light text-secondary border">Рег: <?= date('d.m.Y', strtotime($user['registration_date'])) ?></span>
                        </div>
                    </div>

                    <?php if ($user['role'] !== 'manager'): ?>
                    <div class="mb-3" id="editInnWrapper">
                        <label class="form-label small text-muted">ИНН</label>
                        <input type="text" name="inn" id="editInn" class="form-control" maxlength="12" required value="<?= htmlspecialchars($user['inn'] ?? '') ?>">
                    </div>
                    <?php endif; ?>
                    <div class="row mb-3">
                        <div class="col-6">
                            <label class="form-label small text-muted">Имя</label>
                            <input type="text" name="first_name" class="form-control" value="<?= htmlspecialchars($user['first_name']) ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label small text-muted">Фамилия</label>
                            <input type="text" name="last_name" class="form-control" value="<?= htmlspecialchars($user['last_name']) ?>">
                        </div>
                    </div>

                    <?php if ($user['role'] !== 'manager'): ?>
                    <div class="mb-3">
                        <label class="form-label small text-muted">Компания</label>
                        <input type="text" name="company_name" id="editCompanyName" class="form-control" value="<?= htmlspecialchars($user['company_name'] ?? '') ?>">
                    </div>
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label small text-muted">Телефон</label>
                        <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($user['phone'] ?? '') ?>">
                    </div>

                    <div class="mb-4">
                        <label class="form-label small text-muted">Роль</label>
                        <?php if ($user['role'] === 'manager'): ?>
                        <select name="role" id="editRole" class="form-select" disabled>
                            <option value="manager" selected>Менеджер</option>
                        </select>
                        <div class="form-text small">Роль менеджера меняется только через БД.</div>
                        <?php else: ?>
                        <select name="role" id="editRole" class="form-select">
                            <option value="client" <?= $user['role'] === 'client' ? 'selected' : '' ?>>Клиент</option>
                            <option value="partner" <?= $user['role'] === 'partner' ? 'selected' : '' ?>>Партнер</option>
                        </select>
                        <?php endif; ?>
                    </div>

                    <div class="form-check form-switch mb-4 p-3 bg-light rounded" id="isAnalystWrapper" style="<?= $user['role'] === 'partner' ? '' : 'display:none;' ?>">
                        <input class="form-check-input ms-0 me-2" type="checkbox" name="is_analyst" id="isAnalystSwitch" value="1" <?= !empty($user['is_analyst']) ? 'checked' : '' ?>>
                        <label class="form-check-label fw-medium" for="isAnalystSwitch">Доступ к анализу заявок</label>
                        <div class="form-text small mt-1">Партнёр сохраняет своё ЛК и дополнительно видит все заявки для работы со структурой и документами.</div>
                    </div>

                    <div class="form-check form-switch mb-4 p-3 bg-light rounded">
                        <input class="form-check-input ms-0 me-2" type="checkbox" name="is_active" id="activeSwitch" <?= $user['is_active'] ? 'checked' : '' ?>>
                        <label class="form-check-label fw-medium" for="activeSwitch">Активный пользователь</label>
                        <div class="form-text small mt-1">Отключите, чтобы запретить вход.</div>
                    </div>

                    <div class="form-check form-switch mb-3 p-3 bg-light rounded">
                        <input class="form-check-input ms-0 me-2" type="checkbox" name="email_notifications_enabled" id="emailNotificationsSwitch" value="1" <?= !empty($user['email_notifications_enabled']) ? 'checked' : '' ?>>
                        <label class="form-check-label fw-medium" for="emailNotificationsSwitch">Получать уведомления на почту</label>
                        <div class="form-text small mt-1">О заявках, сообщениях в чатах и запросах документов.</div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label small text-muted" for="notificationEmailInput">E-mail для уведомлений</label>
                        <input type="email" class="form-control" name="notification_email" id="notificationEmailInput" value="<?= htmlspecialchars($user['notification_email'] ?? '') ?>" placeholder="Пусто — используется e-mail учётной записи" autocomplete="off">
                        <div class="form-text small">Укажите, если письма должны идти на другой адрес, чем логин.</div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100">Сохранить изменения</button>
                </div>
            </form>

            <!-- Карточка безопасности -->
            <div class="card content-card">
                <div class="card-header-custom">
                    <h5 class="d-flex align-items-center"><i class="bi bi-shield-lock me-2 text-muted"></i> Безопасность</h5>
                </div>
                <div class="card-body p-4">
                    <form method="POST" action="user_view.php?id=<?= (int)$userId ?>">
                        <input type="hidden" name="user_id" value="<?= (int)$userId ?>">
                        <input type="hidden" name="change_password" value="1">
                        <div class="mb-3">
                            <label class="form-label small text-muted">Новый пароль</label>
                            <div class="input-group">
                                <input type="text" name="new_password" class="form-control font-monospace" minlength="6" id="newPassInput" required placeholder="Введите новый пароль">
                                <button class="btn btn-outline-secondary" type="button" onclick="document.getElementById('newPassInput').value = Math.random().toString(36).slice(-8)">
                                    <i class="bi bi-arrow-clockwise"></i>
                                </button>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-outline-danger w-100">Сменить пароль</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Правая колонка: История заявок -->
        <div class="col-lg-8">
            <div class="card content-card h-100">
                <div class="card-header-custom d-flex justify-content-between align-items-center">
                    <h5 class="d-flex align-items-center"><i class="bi bi-journal-text me-2 text-muted"></i> История заявок</h5>
                    <span class="badge bg-secondary rounded-pill"><?= count($userApplications) ?></span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th class="ps-4">ID</th>
                                    <th>Компания / ИНН</th>
                                    <th>Продукт</th>
                                    <th>Сумма</th>
                                    <th>Статус</th>
                                    <th>Дата</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($userApplications)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-5">
                                            <div class="text-muted mb-2"><i class="bi bi-inbox fs-1 opacity-25"></i></div>
                                            <p class="text-muted mb-0">У пользователя нет созданных заявок</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($userApplications as $app): ?>
                                        <tr>
                                            <td class="ps-4"><strong>#<?= $app['id'] ?></strong></td>
                                            <td>
                                                <div class="fw-medium text-dark"><?= htmlspecialchars($app['company_name']) ?></div>
                                                <div class="small text-muted"><?= htmlspecialchars($app['inn']) ?></div>
                                            </td>
                                            <td>
                                                <?= $app['product_type'] === 'bg' ? 'Банковская гарантия' : 'Кредит' ?>
                                            </td>
                                            <td class="text-success fw-medium">
                                                <?= formatAmountLocal($app['amount']) ?>
                                            </td>
                                            <td>
                                                <?= getStatusBadgeLocal($app['status']) ?>
                                            </td>
                                            <td class="text-muted small">
                                                <?= date('d.m.Y', strtotime($app['created_at'])) ?>
                                            </td>
                                            <td class="text-end pe-4">
                                                <a href="application_details.php?id=<?= $app['id'] ?>" class="btn btn-sm btn-light border" title="Открыть заявку">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
<!-- </div> -->

<script>
const editInnInput = document.getElementById('editInn');
const editCompanyInput = document.getElementById('editCompanyName');
const editRoleSelect = document.getElementById('editRole');
const editInnWrapper = document.getElementById('editInnWrapper');
let editInnTimer = null;

if (editInnInput) {
    editInnInput.addEventListener('input', function() {
        this.value = this.value.replace(/[^\d]/g, '');
    });
}

function fetchCompanyForEdit() {
    const inn = editInnInput.value.trim();
    if (!inn) {
        alert('Введите ИНН');
        return;
    }
    if (!/^\d{10,12}$/.test(inn)) {
        alert('ИНН должен состоять из 10–12 цифр');
        return;
    }
    fetch('api_checko_proxy.php?inn=' + inn)
        .then(r => r.json())
        .then(data => {
            if (data.success && data.name) {
                editCompanyInput.value = data.name;
            }
        })
        .catch(() => {});
}

if (editInnInput) {
    editInnInput.addEventListener('input', function() {
        if (editInnTimer) {
            clearTimeout(editInnTimer);
        }
        editInnTimer = setTimeout(fetchCompanyForEdit, 600);
    });
    editInnInput.addEventListener('blur', function() {
        if (/^\d{10,12}$/.test(this.value.trim())) {
            fetchCompanyForEdit();
        }
    });
}

function toggleInnByRole() {
    if (!editRoleSelect || !editInnWrapper) return;
    const analystWrap = document.getElementById('isAnalystWrapper');
    if (editRoleSelect.value === 'partner') {
        editInnWrapper.style.display = 'none';
        if (editInnInput) {
            editInnInput.value = '';
            editInnInput.removeAttribute('required');
        }
        if (analystWrap) analystWrap.style.display = '';
    } else {
        editInnWrapper.style.display = 'block';
        if (editInnInput) {
            editInnInput.setAttribute('required', 'required');
        }
        if (analystWrap) analystWrap.style.display = 'none';
    }
}

if (editRoleSelect) {
    editRoleSelect.addEventListener('change', toggleInnByRole);
    toggleInnByRole();
}
</script>
<?php require_once 'footer.php'; ?>
