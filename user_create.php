<?php
require_once 'config.php';

$pdo = getPDO(); 

// Проверка доступа
checkAuth();
$currentUser = getCurrentUser();
$accessRole = (string) ($currentUser['role'] ?? '');
if ($accessRole !== 'manager' || finbuild_is_submanager($currentUser)) {
    if ($accessRole === 'bank') {
        header('Location: bank_applications.php');
    } else {
        header('Location: dashboard.php');
    }
    exit();
}

$success_data = null; 
$errors = [];

// Обработка формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $inn = trim($_POST['inn'] ?? '');
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $role = $_POST['role'] ?? 'client';
    if (!in_array($role, ['client', 'partner', 'analyst', 'manager'], true)) {
        $role = 'client';
    }
    // У партнёра, аналитика и менеджера нет компании/ИНН
    $company_name = in_array($role, ['partner', 'analyst', 'manager'], true) ? '' : trim($_POST['company_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $is_analyst = ($role === 'partner' && !empty($_POST['is_analyst'])) ? 1 : 0;
    // Через админку можно создать только ограниченного менеджера (submanager), не руководителя
    $is_submanager = ($role === 'manager') ? 1 : 0;

    // Валидация
    if (empty($email) || empty($password) || empty($first_name) || empty($last_name)) {
        $errors[] = "Заполните все обязательные поля";
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Некорректный формат E-mail";
    }
    
    if ($role === 'partner' || $role === 'analyst' || $role === 'manager') {
        $inn = '';
    } else {
        if (empty($inn)) {
            $errors[] = "ИНН обязателен для клиента";
        } elseif (!preg_match('/^\d{10,12}$/', $inn)) {
            $errors[] = "ИНН должен состоять из 10–12 цифр";
        }
    }

    // Проверка дубликата email
    if (empty($errors)) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $errors[] = "Пользователь с таким E-mail уже существует";
        }
    }

    // Создание
    if (empty($errors)) {
        try {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $sql = "INSERT INTO users (email, password, inn, first_name, last_name, phone, company_name, role, is_analyst, is_submanager, registration_date, is_active, email_notifications_enabled, created_by) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), 1, 1, ?)";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$email, $hashed_password, $inn, $first_name, $last_name, $phone, $company_name, $role, $is_analyst, $is_submanager, (int)$currentUser['id']]);
            
            // Сохраняем данные для отображения
            $success_data = [
                'email' => $email,
                'password' => $password,
                'name' => "$first_name $last_name",
                'role' => $role
            ];
            
            $_POST = [];
            
        } catch (Exception $e) {
            $errors[] = "Ошибка при создании: " . $e->getMessage();
        }
    }
}

require_once 'header.php';
?>

<style>
.content-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 20px rgba(0,0,0,0.08);
    overflow: hidden;
    border: none;
}

.role-selector {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 1.5rem;
    border: 1px solid #e9ecef;
}

.form-check-input:checked {
    background-color: #0d6efd;
    border-color: #0d6efd;
}

.form-check-label {
    cursor: pointer;
    font-weight: 500;
}

.copy-area {
    background: #f8f9fa;
    border: 1px dashed #ced4da;
    border-radius: 8px;
    padding: 1.5rem;
    font-family: monospace;
    white-space: pre-wrap; /* Сохраняет переносы строк */
    color: #495057;
    position: relative;
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

    .content-card .card-body {
        padding: 1rem !important;
    }

    .role-selector {
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

    .btn-primary,
    .btn-outline-secondary,
    .btn-light {
        width: 100%;
    }

    .copy-area {
        padding: 1rem;
        font-size: 0.85rem;
    }
}
</style>

<!-- <div class="container-fluid py-4"> -->
    <!-- Page Header -->
    <div class="page-header mb-4">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="h3 mb-0">Новый пользователь</h1>
                <p class="text-muted mb-0">Создание учетной записи клиента или партнера</p>
            </div>
            <div class="col-auto">
                <a href="users.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-2"></i>Назад к списку
                </a>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12"> <!-- Полная ширина -->
            
            <!-- Ошибки -->
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger shadow-sm border-0 mb-4">
                    <ul class="mb-0">
                        <?php foreach ($errors as $error): ?>
                            <li><?= htmlspecialchars($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <!-- Успешное создание -->
            <?php if ($success_data): ?>
                <div class="card content-card mb-4">
                    <div class="card-body p-5 text-center">
                        <div class="mb-4">
                            <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-success bg-opacity-10 text-success" style="width: 72px; height: 72px;">
                                <i class="bi bi-check-lg fs-1"></i>
                            </div>
                        </div>
                        <h3 class="fw-bold text-success mb-2">Пользователь успешно создан!</h3>
                        <p class="text-muted mb-4">Скопируйте сообщение ниже и отправьте его пользователю.</p>
                        
                        <div class="row justify-content-center">
                            <div class="col-md-8 col-lg-6">
                                <div class="position-relative">
                                    <textarea id="accessMessage" class="form-control copy-area mb-3" rows="6" readonly>Доступ в платформу Finbuild.
https://finbuild.ru

Логин: <?= $success_data['email'] ?>

Пароль: <?= $success_data['password'] ?></textarea>
                                    
                                    <button onclick="copyFullMessage(this)" class="btn btn-primary w-100 py-2">
                                        <i class="bi bi-clipboard me-2"></i> Скопировать сообщение
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="mt-5 border-top pt-4">
                            <a href="users.php" class="btn btn-outline-secondary px-4 me-2">Вернуться к списку</a>
                            <a href="user_create.php" class="btn btn-link text-decoration-none">Создать еще одного</a>
                        </div>
                    </div>
                </div>
                
                <script>
                function copyFullMessage(btn) {
                    var copyText = document.getElementById("accessMessage");
                    copyText.select();
                    copyText.setSelectionRange(0, 99999); 
                    navigator.clipboard.writeText(copyText.value);
                    
                    var originalText = btn.innerHTML;
                    btn.innerHTML = '<i class="bi bi-check2 me-2"></i> Скопировано!';
                    btn.classList.remove('btn-primary');
                    btn.classList.add('btn-success');
                    
                    setTimeout(function() { 
                        btn.innerHTML = originalText; 
                        btn.classList.add('btn-primary');
                        btn.classList.remove('btn-success');
                    }, 2000);
                }
                </script>

            <?php else: ?>
                <!-- Форма создания -->
                <div class="card content-card">
                    <div class="card-body p-4 p-md-5">
                        <form method="POST">
                            
                            <!-- Выбор роли (Первым пунктом) -->
                            <div class="mb-5">
                                <label class="h5 mb-3 d-block">1. Выберите роль пользователя</label>
                                <div class="role-selector">
                                    <div class="row g-3">
                                        <div class="col-md-3">
                                            <div class="form-check">
                                                <input class="form-check-input" type="radio" name="role" id="roleClient" value="client" 
                                                    <?= (($_POST['role'] ?? 'client') === 'client') ? 'checked' : '' ?> 
                                                    onchange="toggleCompanyField()">
                                                <label class="form-check-label h6 mb-0" for="roleClient">
                                                    Клиент
                                                </label>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-check">
                                                <input class="form-check-input" type="radio" name="role" id="rolePartner" value="partner" 
                                                    <?= (($_POST['role'] ?? '') === 'partner') ? 'checked' : '' ?>
                                                    onchange="toggleCompanyField()">
                                                <label class="form-check-label h6 mb-0" for="rolePartner">
                                                    Партнер (агент)
                                                </label>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-check">
                                                <input class="form-check-input" type="radio" name="role" id="roleAnalyst" value="analyst" 
                                                    <?= (($_POST['role'] ?? '') === 'analyst') ? 'checked' : '' ?>
                                                    onchange="toggleCompanyField()">
                                                <label class="form-check-label h6 mb-0" for="roleAnalyst">
                                                    Аналитик
                                                </label>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-check">
                                                <input class="form-check-input" type="radio" name="role" id="roleManager" value="manager" 
                                                    <?= (($_POST['role'] ?? '') === 'manager') ? 'checked' : '' ?>
                                                    onchange="toggleCompanyField()">
                                                <label class="form-check-label h6 mb-0" for="roleManager">
                                                    Менеджер
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="form-check mt-3" id="partnerAnalystWrapper" style="display:none;">
                                        <input class="form-check-input" type="checkbox" name="is_analyst" id="partnerAnalystFlag" value="1"
                                            <?= !empty($_POST['is_analyst']) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="partnerAnalystFlag">
                                            Доступ к анализу заявок (партнёр, аналитик)
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <hr class="my-4 text-muted opacity-25">

                            <div class="row">
                                <!-- Левая колонка: Личные данные -->
                                <div class="col-lg-6 pe-lg-5 border-end-lg">
                                    <label class="h5 mb-4 d-block">2. Данные</label>
                                    
                                    <div class="mb-3" id="innFieldWrapper">
                                        <label class="form-label">ИНН <span class="text-danger">*</span></label>
                                        <input type="text" name="inn" id="userInn" class="form-control" maxlength="12" required
                                               value="<?= htmlspecialchars($_POST['inn'] ?? '') ?>" placeholder="Введите 10 или 12 цифр">
                                        
                                    </div>
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label class="form-label">Имя <span class="text-danger">*</span></label>
                                            <input type="text" name="first_name" class="form-control" required value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>" placeholder="Иван">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Фамилия <span class="text-danger">*</span></label>
                                            <input type="text" name="last_name" class="form-control" required value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>" placeholder="Иванов">
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Телефон</label>
                                        <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" placeholder="+7 (999) 000-00-00">
                                    </div>

                                    <!-- Поле компании (скрывается JS) -->
                                    <div class="mb-3" id="companyFieldWrapper">
                                        <label class="form-label">Компания</label>
                                        <input type="text" name="company_name" id="companyName" class="form-control" value="<?= htmlspecialchars($_POST['company_name'] ?? '') ?>" placeholder="ООО Вектор / ИП Иванов">
                                    </div>
                                </div>

                                <!-- Правая колонка: Доступы -->
                                <div class="col-lg-6 ps-lg-5 mt-4 mt-lg-0">
                                    <label class="h5 mb-4 d-block">3. Данные для входа</label>

                                    <div class="mb-3">
                                        <label class="form-label">E-mail (Логин) <span class="text-danger">*</span></label>
                                        <input type="email" name="email" class="form-control" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" placeholder="mail@example.com">
                                    </div>

                                    <div class="mb-4">
                                        <label class="form-label">Пароль <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <input type="text" name="password" class="form-control font-monospace" required minlength="6" id="passwordInput" 
                                                   value="<?= htmlspecialchars($_POST['password'] ?? substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 10)) ?>">
                                            <button class="btn btn-outline-secondary" type="button" onclick="generatePassword()">
                                                <i class="bi bi-arrow-clockwise"></i>
                                            </button>
                                        </div>
                                        <div class="form-text">Используйте автоматический пароль или введите свой.</div>
                                    </div>

                                    <div class="alert alert-light border d-flex align-items-center" role="alert">
                                        <i class="bi bi-info-circle text-primary me-2"></i>
                                        <div class="small">
                                            После создания вы сможете скопировать готовое приглашение с паролем.
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <hr class="my-4 text-muted opacity-25">

                            <div class="d-flex justify-content-end">
                                <a href="users.php" class="btn btn-light me-3 px-4">Отмена</a>
                                <button type="submit" class="btn btn-primary px-5 py-2">Создать пользователя</button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <script>
                // Логика переключения поля Компании
                function toggleCompanyField() {
                    const isPartner = document.getElementById('rolePartner').checked;
                    const isAnalyst = document.getElementById('roleAnalyst').checked;
                    const managerRadio = document.getElementById('roleManager');
                    const isManager = managerRadio ? managerRadio.checked : false;
                    const hideOrgFields = isPartner || isAnalyst || isManager;
                    const innWrapper = document.getElementById('innFieldWrapper');
                    const innInput = document.getElementById('userInn');
                    const companyWrapper = document.getElementById('companyFieldWrapper');
                    const companyInputEl = document.getElementById('companyName');
                    const partnerAnalystWrap = document.getElementById('partnerAnalystWrapper');
                    
                    if (hideOrgFields) {
                        if (innWrapper) {
                            innWrapper.style.display = 'none';
                            if (innInput) {
                                innInput.value = '';
                                innInput.removeAttribute('required');
                            }
                        }
                        if (companyWrapper) {
                            companyWrapper.style.display = 'none';
                            if (companyInputEl) {
                                companyInputEl.value = '';
                            }
                        }
                    } else {
                        if (innWrapper) {
                            innWrapper.style.display = 'block';
                            if (innInput) {
                                innInput.setAttribute('required', 'required');
                            }
                        }
                        if (companyWrapper) {
                            companyWrapper.style.display = 'block';
                        }
                    }
                    if (partnerAnalystWrap) {
                        partnerAnalystWrap.style.display = isPartner ? 'block' : 'none';
                        if (!isPartner) {
                            const cb = document.getElementById('partnerAnalystFlag');
                            if (cb) cb.checked = false;
                        }
                    }
                }
                
                // Запускаем при загрузке, чтобы восстановить состояние (если форма вернулась с ошибкой)
                document.addEventListener('DOMContentLoaded', toggleCompanyField);
                
                const innInput = document.getElementById('userInn');
                const companyInput = document.getElementById('companyName');
                let innFetchTimer = null;

                if (innInput) {
                    innInput.addEventListener('input', function() {
                        this.value = this.value.replace(/[^\d]/g, '');
                    });
                }

                function fetchCompanyByInn() {
                    const inn = innInput.value.trim();
                    if (!/^\d{10,12}$/.test(inn)) {
                        return;
                    }

                    fetch('api_checko_proxy.php?inn=' + inn)
                        .then(r => r.json())
                        .then(data => {
                            if (data.success && data.name) {
                                companyInput.value = data.name;
                            }
                        })
                        .catch(() => {});
                }

                if (innInput) {
                    innInput.addEventListener('input', function() {
                        if (innFetchTimer) {
                            clearTimeout(innFetchTimer);
                        }
                        innFetchTimer = setTimeout(fetchCompanyByInn, 600);
                    });
                    innInput.addEventListener('blur', fetchCompanyByInn);
                }

                // Генератор пароля
                function generatePassword() {
                    const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%';
                    let password = '';
                    for (let i = 0; i < 10; i++) {
                        password += chars.charAt(Math.floor(Math.random() * chars.length));
                    }
                    document.getElementById('passwordInput').value = password;
                }
                </script>
            <?php endif; ?>
        </div>
    </div>
<!-- </div> -->

<?php require_once 'footer.php'; ?>
