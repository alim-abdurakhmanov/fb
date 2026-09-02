<?php
$current_page = 'profile';
require_once 'config.php';
checkAuth();

$pdo = getPDO();
$currentUser = getCurrentUser();
$userRole = $currentUser['role'] ?? ($_SESSION['role'] ?? 'client');
$isBankUser = $userRole === 'bank';
$bankProfileLabel = '';
if ($isBankUser) {
    require_once __DIR__ . '/includes/bank_portals.php';
    $bankProfileLabel = finbank_user_bank_label($pdo, $currentUser);
}
$success = '';
$error = '';

// Обработка формы обновления профиля
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $company_name = trim($_POST['company_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    
    $errors = [];
    
    // Валидация
    if (empty($first_name)) $errors[] = "Имя обязательно";
    if (empty($last_name)) $errors[] = "Фамилия обязательна";

    $email_notifications_enabled = !empty($_POST['email_notifications_enabled']) ? 1 : 0;
    $notification_email = trim($_POST['notification_email'] ?? '');
    if ($notification_email !== '' && !filter_var($notification_email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Некорректный e-mail для уведомлений';
    }

    if (empty($errors)) {
        try {
            if ($isBankUser) {
                $stmt = $pdo->prepare("UPDATE users SET 
                    first_name = ?, 
                    last_name = ?, 
                    phone = ?,
                    email_notifications_enabled = ?,
                    notification_email = ?,
                    updated_at = NOW()
                    WHERE id = ?");
                $stmt->execute([
                    $first_name,
                    $last_name,
                    $phone,
                    $email_notifications_enabled,
                    $notification_email === '' ? null : $notification_email,
                    $currentUser['id']
                ]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET 
                    first_name = ?, 
                    last_name = ?, 
                    company_name = ?, 
                    phone = ?,
                    email_notifications_enabled = ?,
                    notification_email = ?,
                    updated_at = NOW()
                    WHERE id = ?");
                $stmt->execute([
                    $first_name,
                    $last_name,
                    $company_name,
                    $phone,
                    $email_notifications_enabled,
                    $notification_email === '' ? null : $notification_email,
                    $currentUser['id']
                ]);
            }
            
            // Обновляем данные в сессии
            $_SESSION['first_name'] = $first_name;
            $_SESSION['last_name'] = $last_name;
            
            $success = "Профиль успешно обновлен";
            
            // Обновляем текущего пользователя
            $currentUser = getCurrentUser();
            
        } catch (Exception $e) {
            $error = "Ошибка при обновлении профиля: " . $e->getMessage();
        }
    } else {
        $error = implode("<br>", $errors);
    }
}

// Обработка смены пароля
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    $errors = [];
    
    if (empty($current_password)) $errors[] = "Текущий пароль обязателен";
    if (empty($new_password)) $errors[] = "Новый пароль обязателен";
    if ($new_password !== $confirm_password) $errors[] = "Новые пароли не совпадают";
    
    if (empty($errors)) {
        // Проверяем текущий пароль
        if (password_verify($current_password, $currentUser['password'])) {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$hashed_password, $currentUser['id']]);
            $success = "Пароль успешно изменен";
        } else {
            $error = "Текущий пароль неверен";
        }
    } else {
        $error = implode("<br>", $errors);
    }
}

require_once 'header.php';
?>

<style>
.profile-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 20px rgba(0,0,0,0.08);
    padding: 2rem;
    margin-bottom: 2rem;
}

.profile-header {
    text-align: center;
    margin-bottom: 2rem;
}

/* Кнопка загрузки фото */
.avatar-upload-container {
    margin-bottom: 1.5rem;
    text-align: center;
}

.avatar-img {
    width: 150px;
    height: 150px;
    border-radius: 50%;
    object-fit: cover;
    border: 4px solid #fff;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    margin-bottom: 1rem;
}

.upload-photo-btn {
    padding: 10px 25px;
    font-size: 1rem;
    border-radius: 8px;
    transition: all 0.3s;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.upload-photo-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(52, 152, 219, 0.3);
}

.upload-photo-btn.no-photo {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border: none;
    padding: 12px 30px;
    font-weight: 500;
}

.upload-photo-btn.has-photo {
    background: #f8f9fa;
    color: #495057;
    border: 1px solid #dee2e6;
    font-size: 0.9rem;
    padding: 8px 20px;
}

.avatar-preview {
    position: relative;
    display: inline-block;
}

.avatar-change-overlay {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    background: rgba(0,0,0,0.7);
    padding: 8px;
    border-radius: 0 0 75px 75px;
    text-align: center;
    opacity: 0;
    transition: opacity 0.3s;
}

.avatar-preview:hover .avatar-change-overlay {
    opacity: 1;
}

.section-title {
    color: #2c3e50;
    border-bottom: 2px solid #3498db;
    padding-bottom: 0.5rem;
    margin-bottom: 1.5rem;
    font-weight: 600;
}

.readonly-input {
    background-color: #f8f9fa;
    cursor: not-allowed;
}

.user-info p {
    margin-bottom: 0.5rem;
    color: #495057;
}

.user-info i {
    width: 20px;
    color: #6c757d;
}

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

    .profile-card {
        padding: 1rem;
        margin-bottom: 1rem;
        border-radius: 12px;
    }

    .section-title {
        font-size: 1rem;
        margin-bottom: 1rem;
        padding-bottom: 0.5rem;
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

    .avatar-img {
        width: 120px;
        height: 120px;
    }

    .upload-photo-btn {
        width: 100%;
    }
}
</style>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1 class="h3 mb-0">Личный кабинет</h1>
            <p class="text-muted mb-0"><?= $isBankUser ? 'Данные организации и доступ' : 'Управление вашими данными' ?></p>
        </div>
        <div class="col-auto">
            <a href="<?= $isBankUser ? 'bank_applications.php' : 'index.php' ?>" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-2"></i>Назад
            </a>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-4">
        <div class="profile-card">
            <div class="profile-header">
                <div class="avatar-upload-container">
                    <?php
                    require_once __DIR__ . '/includes/upload_access.php';
                    $avatarPath = $currentUser['avatar_path'] ?? '';
                    $hasAvatar = !empty($avatarPath);
                    $avatarUrl = $hasAvatar ? finbuild_upload_file_url('avatar', (int) ($currentUser['id'] ?? 0)) : '';
                    ?>
                    
                    <?php if ($hasAvatar): ?>
                        <div class="avatar-preview">
                            <img src="<?= htmlspecialchars($avatarUrl) ?>?t=<?= time() ?>" 
                                 alt="Аватар" 
                                 class="avatar-img" 
                                 id="avatarPreview">
                           <!--  <div class="avatar-change-overlay">
                                <button type="button" class="btn btn-sm btn-light" onclick="document.getElementById('avatarUpload').click()">
                                    <i class="bi bi-camera me-1"></i> Сменить
                                </button>
                            </div> -->
                        </div>
                        <div class="mt-2">
                            <button type="button" class="btn upload-photo-btn has-photo" onclick="document.getElementById('avatarUpload').click()">
                                <i class="bi bi-camera"></i> Сменить фото профиля
                            </button>
                        </div>
                    <?php else: ?>
                        <!-- <div class="mb-3">
                            <i class="bi bi-person-circle" style="font-size: 80px; color: #6c757d;"></i>
                        </div> -->
                        <button type="button" class="btn upload-photo-btn no-photo" onclick="document.getElementById('avatarUpload').click()">
                            <i class="bi bi-camera-fill me-2"></i> Загрузить фото профиля
                        </button>
                     <!--    <p class="text-muted mt-2 small">Рекомендуемый размер: 400×400 пикселей</p> -->
                    <?php endif; ?>
                    
                    <input type="file" id="avatarUpload" accept="image/*" style="display: none;">
                </div>
                
                <h4 class="mt-3"><?= htmlspecialchars($currentUser['first_name'] . ' ' . $currentUser['last_name']) ?></h4>
                <p class="text-muted mb-1"><?= htmlspecialchars($currentUser['email']) ?></p>
                <small class="text-muted">
                    <?= htmlspecialchars(finbuild_role_display_name($currentUser)) ?>
                </small>
            </div>
            
            <div class="user-info mt-3">
                <?php if ($isBankUser && $bankProfileLabel !== ''): ?>
                    <p><i class="bi bi-bank me-2"></i> <?= htmlspecialchars($bankProfileLabel) ?></p>
                <?php elseif (!$isBankUser && !empty($currentUser['company_name'])): ?>
                    <p><i class="bi bi-building me-2"></i> <?= htmlspecialchars($currentUser['company_name']) ?></p>
                <?php endif; ?>
                <?php if ($currentUser['phone']): ?>
                    <p><i class="bi bi-telephone me-2"></i> <?= htmlspecialchars($currentUser['phone']) ?></p>
                <?php endif; ?>
                <p><i class="bi bi-calendar me-2"></i> Зарегистрирован: <?= date('d.m.Y', strtotime($currentUser['registration_date'])) ?></p>
            </div>
        </div>
    </div>
    
    <div class="col-md-8">
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle me-2"></i><?= $success ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle me-2"></i><?= $error ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- Форма обновления профиля -->
        <div class="profile-card">
            <h5 class="section-title">Основные данные</h5>
            <form method="POST" action="profile.php" id="profileForm">
                <input type="hidden" name="update_profile" value="1">
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Имя *</label>
                        <input type="text" class="form-control" name="first_name" 
                               value="<?= htmlspecialchars($currentUser['first_name'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Фамилия *</label>
                        <input type="text" class="form-control" name="last_name" 
                               value="<?= htmlspecialchars($currentUser['last_name'] ?? '') ?>" required>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label"><?= $isBankUser ? 'Банк' : 'Компания' ?></label>
                        <?php if ($isBankUser): ?>
                            <input type="text" class="form-control readonly-input"
                                   value="<?= htmlspecialchars($bankProfileLabel) ?>" readonly>
                        <?php else: ?>
                            <input type="text" class="form-control" name="company_name" 
                                   value="<?= htmlspecialchars($currentUser['company_name'] ?? '') ?>">
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Телефон</label>
                        <input type="tel" class="form-control" name="phone" 
                               value="<?= htmlspecialchars($currentUser['phone'] ?? '') ?>">
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-12 mb-3">
                        <label class="form-label">E-mail</label>
                        <input type="email" class="form-control readonly-input" 
                               value="<?= htmlspecialchars($currentUser['email'] ?? '') ?>" readonly>
                        <div class="form-text">E-mail нельзя изменить</div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-12 mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="email_notifications_enabled" id="email_notifications_enabled" value="1"
                                <?= !empty($currentUser['email_notifications_enabled']) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="email_notifications_enabled">
                                Получать уведомления на почту о заявках и сообщениях
                            </label>
                        </div>
                        <div class="form-text">Можно отключить в любой момент. Письма не содержат паролей.</div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-12 mb-3">
                        <label class="form-label">E-mail для уведомлений (необязательно)</label>
                        <input type="email" class="form-control" name="notification_email"
                            value="<?= htmlspecialchars($currentUser['notification_email'] ?? '') ?>"
                            placeholder="Если пусто — используется e-mail учётной записи">
                        <div class="form-text">Укажите, если вход в систему не через тот же адрес, куда должны приходить письма.</div>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save me-2"></i>Сохранить изменения
                </button>
            </form>
        </div>
        
        <!-- Форма смены пароля -->
        <div class="profile-card">
            <h5 class="section-title">Смена пароля</h5>
            <form method="POST" action="profile.php" id="passwordForm">
                <input type="hidden" name="change_password" value="1">
                
                <div class="mb-3">
                    <label class="form-label">Текущий пароль</label>
                    <input type="password" class="form-control" name="current_password" required>
                </div>
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Новый пароль</label>
                        <input type="password" class="form-control" name="new_password" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Подтверждение пароля</label>
                        <input type="password" class="form-control" name="confirm_password" required>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-warning">
                    <i class="bi bi-key me-2"></i>Сменить пароль
                </button>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const avatarUpload = document.getElementById('avatarUpload');
    
    // Маска для телефона
    const phoneInput = document.querySelector('input[name="phone"]');
    if (phoneInput) {
        phoneInput.addEventListener('input', function(e) {
            let x = e.target.value.replace(/\D/g, '').match(/(\d{0,1})(\d{0,3})(\d{0,3})(\d{0,2})(\d{0,2})/);
            e.target.value = '+7' + (x[2] ? ' (' + x[2] : '') + (x[3] ? ') ' + x[3] : '') + (x[4] ? '-' + x[4] : '') + (x[5] ? '-' + x[5] : '');
        });
    }
    
    // Загрузка аватара
    if (avatarUpload) {
        avatarUpload.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (!file) return;
            
            // Проверка типа файла
            if (!file.type.match('image.*')) {
                alert('Пожалуйста, выберите изображение (JPG, PNG, GIF)');
                return;
            }
            
            // Проверка размера (2MB максимум)
            if (file.size > 2 * 1024 * 1024) {
                alert('Размер файла не должен превышать 2MB');
                return;
            }
            
            // Показываем превью
            const reader = new FileReader();
            reader.onload = function(e) {
                const avatarContainer = document.querySelector('.avatar-upload-container');
                const previewUrl = e.target.result;
                
                // Создаем новую структуру с аватаром
                avatarContainer.innerHTML = `
                    <div class="avatar-preview">
                        <img src="${previewUrl}" 
                             alt="Аватар" 
                             class="avatar-img" 
                             id="avatarPreview">
                        <div class="avatar-change-overlay">
                            <button type="button" class="btn btn-sm btn-light" onclick="document.getElementById('avatarUpload').click()">
                                <i class="bi bi-camera me-1"></i> Сменить
                            </button>
                        </div>
                    </div>
                    <div class="mt-2">
                        <button type="button" class="btn upload-photo-btn has-photo" onclick="document.getElementById('avatarUpload').click()">
                            <i class="bi bi-camera"></i> Сменить фото профиля
                        </button>
                    </div>
                    <input type="file" id="avatarUpload" accept="image/*" style="display: none;">
                `;
                
                // Перепривязываем событие
                document.getElementById('avatarUpload').addEventListener('change', arguments.callee);
            };
            reader.readAsDataURL(file);
            
            // Отправка на сервер
            uploadAvatar(file);
        });
    }
    
    function uploadAvatar(file) {
        const formData = new FormData();
        formData.append('avatar', file);
        
        // Показываем индикатор загрузки
        showLoadingIndicator();
        
        fetch('api_upload_avatar.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
        .then(response => response.json())
        .then(data => {
            hideLoadingIndicator();
            
            if (data.success) {
                showNotification('Фото профиля успешно обновлено', 'success');
                // Обновляем изображение с таймстампом
                setTimeout(() => {
                    const avatarImg = document.getElementById('avatarPreview');
                    if (avatarImg) {
                        avatarImg.src = (data.avatar_url || data.avatar_path) + '?t=' + new Date().getTime();
                    }
                }, 100);
            } else {
                showNotification(data.error || 'Ошибка при загрузке фото', 'error');
                // Восстанавливаем кнопку при ошибке
                restoreUploadButton();
            }
        })
        .catch(error => {
            hideLoadingIndicator();
            console.error('Error:', error);
            showNotification('Ошибка при загрузке фото', 'error');
            restoreUploadButton();
        });
    }
    
    function restoreUploadButton() {
        const avatarContainer = document.querySelector('.avatar-upload-container');
        if (avatarContainer) {
            avatarContainer.innerHTML = `
                <div class="mb-3">
                    <i class="bi bi-person-circle" style="font-size: 80px; color: #6c757d;"></i>
                </div>
                <button type="button" class="btn upload-photo-btn no-photo" onclick="document.getElementById('avatarUpload').click()">
                    <i class="bi bi-camera-fill me-2"></i> Загрузить фото профиля
                </button>
                <p class="text-muted mt-2 small">Рекомендуемый размер: 400×400 пикселей</p>
                <input type="file" id="avatarUpload" accept="image/*" style="display: none;">
            `;
            document.getElementById('avatarUpload').addEventListener('change', arguments.callee);
        }
    }
});

function showLoadingIndicator() {
    let indicator = document.getElementById('loadingIndicator');
    if (!indicator) {
        indicator = document.createElement('div');
        indicator.id = 'loadingIndicator';
        indicator.style.cssText = `
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 9999;
            background: rgba(255,255,255,0.9);
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            text-align: center;
        `;
        indicator.innerHTML = `
            <div class="spinner-border text-primary"></div>
            <div class="mt-2">Загрузка фото...</div>
        `;
        document.body.appendChild(indicator);
    }
}

function hideLoadingIndicator() {
    const indicator = document.getElementById('loadingIndicator');
    if (indicator) {
        indicator.remove();
    }
}

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
</script>

<?php require_once 'footer.php'; ?>