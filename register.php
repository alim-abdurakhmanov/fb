<?php
require_once 'config.php';

if (isset($_SESSION['user_id'])) {
    redirectByRole();
}

$pdo = getPDO();
$errors = [];

// Обработка формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $inn = trim($_POST['inn'] ?? '');
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $company_name = trim($_POST['company_name'] ?? ''); 
    $role = $_POST['role'] ?? 'client';
    if (!in_array($role, ['client', 'partner', 'beneficiary'], true)) {
        $role = 'client';
    }

    // Валидация
    if (empty($email)) $errors[] = "E-mail обязателен";
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Некорректный формат e-mail";
    if (empty($password)) $errors[] = "Пароль обязателен";
    if ($password !== $confirm_password) $errors[] = "Пароли не совпадают";
    if (empty($first_name)) $errors[] = "Имя обязательно";
    if (empty($last_name)) $errors[] = "Фамилия обязательна";
    if ($role === 'partner') {
        $inn = '';
    } else {
        if (empty($inn)) {
            $errors[] = $role === 'beneficiary' ? "ИНН обязателен для заказчика" : "ИНН обязателен для клиента";
        } elseif (!preg_match('/^\d{10,12}$/', $inn)) {
            $errors[] = "ИНН должен состоять из 10–12 цифр";
        }
        if ($role === 'beneficiary' && $company_name === '') {
            $errors[] = "Название организации обязательно для заказчика";
        }
    }

    // Проверка уникальности email
    if (empty($errors)) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $errors[] = "Пользователь с таким e-mail уже существует";
        }
    }

    if (empty($errors)) {
        try {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (email, password, inn, first_name, last_name, phone, company_name, role, registration_date, is_active, email_notifications_enabled) VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), 1, 1)");
            $stmt->execute([$email, $hashed_password, $inn, $first_name, $last_name, $phone, $company_name, $role]);

            $user_id = $pdo->lastInsertId();
            $_SESSION['user_id'] = $user_id;
            $_SESSION['email'] = $email;
            $_SESSION['role'] = $role;
            $_SESSION['first_name'] = $first_name;
            $_SESSION['last_name'] = $last_name;
            
            redirectByRole();
        } catch (Exception $e) {
            $errors[] = "Ошибка регистрации: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Регистрация | FINBUILD</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=Montserrat:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        /* === ВАШ ФИНАЛЬНЫЙ CSS СТИЛЬ === */
        :root {
            --primary-color: #0d6efd;
            --primary-hover: #0b5ed7;
            --text-main: #212529;
            --text-muted: #6c757d;
        }

        body {
            font-family: 'Inter', sans-serif;
            margin: 0;
            background-color: #fff;
            height: 100vh;
        }

        .split-screen {
            display: flex;
            height: 100%;
            width: 100%;
        }

                .left-pane {
            flex: 1;
            background: #ffffff;
            display: flex;
            flex-direction: column;
            /* Убираем жесткое центрирование по центру */
            /* justify-content: center; */ 
            align-items: center;
            /* Уменьшаем padding для небольших экранов */
            padding: 2rem; 
            position: relative;
            overflow-y: auto;
            order: 1;
        }
        
        /* Добавим отступ сверху для контента внутри, чтобы он не прилипал, 
           когда justify-content: center убран */
        .login-wrapper {
            width: 100%;
            max-width: 500px;
            /* Добавляем вертикальные отступы для прокрутки */
            margin-top: auto; 
            margin-bottom: auto;
            padding-top: 2rem;
            padding-bottom: 2rem;
        }


        .page-title {
            font-family: 'Montserrat', sans-serif;
            font-weight: 700;
            font-size: 2rem;
            margin-bottom: 0.5rem;
            color: var(--text-main);
            letter-spacing: -0.5px;
        }

        .page-subtitle {
            color: var(--text-muted);
            margin-bottom: 2rem;
            font-size: 1rem;
            line-height: 1.5;
        }

        .form-label {
            font-weight: 600;
            font-size: 0.8rem;
            margin-bottom: 0.5rem;
            color: var(--text-main);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-control {
            padding: 0.7rem 1rem;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            font-size: 0.95rem;
            transition: all 0.2s ease-in-out;
            background-color: #f8fafc;
        }

        .form-control:focus {
            border-color: var(--primary-color);
            background-color: #fff;
            box-shadow: 0 0 0 4px rgba(13, 110, 253, 0.1);
        }

        .btn-primary-custom {
            background-color: var(--primary-color);
            border: none;
            border-radius: 8px;
            padding: 1rem;
            font-weight: 600;
            font-size: 1rem;
            width: 100%;
            margin-top: 1.5rem;
            transition: all 0.2s;
            letter-spacing: 0.3px;
        }

        .btn-primary-custom:hover {
            background-color: var(--primary-hover);
            transform: translateY(-1px);
            box-shadow: 0 10px 20px rgba(13, 110, 253, 0.15);
        }

        .auth-footer {
            margin-top: 2rem;
            text-align: center;
            font-size: 0.9rem;
            color: var(--text-muted);
        }

        .auth-footer a {
            color: var(--text-main);
            text-decoration: none;
            font-weight: 600;
            border-bottom: 1px solid rgba(0,0,0,0.1);
        }
        .auth-footer a:hover { border-bottom-color: var(--primary-color); color: #0d6efd; }

        /* --- СТИЛИ ДЛЯ ПЕРЕКЛЮЧАТЕЛЯ РОЛЕЙ --- */
        .role-switcher {
            background-color: #f1f5f9;
            padding: 0.35rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            display: flex;
            position: relative;
        }
        .role-option {
            flex: 1;
            text-align: center;
            color: #64748b;
            font-weight: 600;
            border-radius: 8px;
            padding: 0.6rem 0.5rem;
            cursor: pointer;
            transition: all 0.2s;
            position: relative;
            z-index: 2;
            white-space: nowrap;
        }
        .role-option.active {
            color: var(--primary-color);
            background-color: #ffffff;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        /* ПРАВАЯ ЧАСТЬ */
        .right-pane {
            flex: 1;
            background-color: #0f172a; 
            background-image: 
                radial-gradient(at 0% 0%, hsla(253,16%,7%,1) 0, transparent 50%), 
                radial-gradient(at 50% 0%, hsla(225,39%,30%,1) 0, transparent 50%), 
                radial-gradient(at 100% 0%, hsla(339,49%,30%,1) 0, transparent 50%);
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 6rem;
            position: relative;
            overflow: hidden;
            color: white;
            order: 2;
        }
        .right-pane::after {
            content: "";
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background-image: linear-gradient(rgba(255, 255, 255, 0.03) 1px, transparent 1px), linear-gradient(90deg, rgba(255, 255, 255, 0.03) 1px, transparent 1px);
            background-size: 50px 50px;
            pointer-events: none;
        }
        .blur-circle { position: absolute; border-radius: 50%; filter: blur(80px); opacity: 0.4; z-index: 1; }
        .bc-1 { width: 300px; height: 300px; background: #4f46e5; top: 10%; right: 10%; }
        .bc-2 { width: 400px; height: 400px; background: #0d6efd; bottom: -10%; left: -10%; }
        .brand-content { position: relative; z-index: 2; }
        .brand-logo-large { font-family: 'Montserrat', sans-serif; font-weight: 800; font-size: 3.5rem; margin-bottom: 2rem; letter-spacing: -2px; background: linear-gradient(to right, #fff, #94a3b8); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .brand-quote { font-family: 'Montserrat', sans-serif; font-size: 2.2rem; font-weight: 700; line-height: 1.2; margin-bottom: 1.5rem; text-shadow: 0 10px 30px rgba(0,0,0,0.5); }
        .brand-desc { font-size: 1.1rem; opacity: 0.8; max-width: 480px; line-height: 1.6; font-weight: 300; }

        @media (max-width: 991.98px) {
            body { height: auto; min-height: 100vh; }
            .split-screen { flex-direction: column; }
            .right-pane { order: 1; flex: 0 0 auto; padding: 3rem 1.5rem; text-align: center; min-height: auto; background-color: #050505; background-image: radial-gradient(at 0% 0%, hsla(253,16%,5%,1) 0, transparent 50%), radial-gradient(at 50% 0%, hsla(225,25%,10%,1) 0, transparent 50%), radial-gradient(at 100% 0%, hsla(339,49%,15%,1) 0, transparent 50%); }
            .blur-circle { opacity: 0.2; }
            .brand-logo-large { font-size: 2.5rem; margin-bottom: 0.5rem; }
            .brand-quote { font-size: 1rem; margin-bottom: 0.5rem; white-space: nowrap; opacity: 0.9; }
            .brand-desc { display: none; }
            .left-pane { order: 2; flex: 0 0 auto; padding: 2rem 1.5rem; border-top-left-radius: 24px; border-top-right-radius: 24px; margin-top: -20px; background: #fff; z-index: 20; box-shadow: 0 -10px 30px rgba(0,0,0,0.05); }
            .login-wrapper { margin: 0 auto; }
            .page-title { text-align: center; font-size: 1.75rem; }
            .page-subtitle { text-align: center; }
        }
    </style>
</head>
<body>

<div class="split-screen">
    <!-- ЛЕВАЯ ЧАСТЬ -->
    <div class="left-pane">
        <div class="login-wrapper">

            <h1 class="page-title">Регистрация</h1>
            <p class="page-subtitle">Создайте аккаунт, выбрав ваш статус</p>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger py-2 border-0 bg-danger bg-opacity-10 text-danger mb-4 rounded-3">
                    <ul class="mb-0 ps-3 small">
                        <?php foreach ($errors as $error): ?>
                            <li><?= htmlspecialchars($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="POST" id="registerForm">
                <!-- Скрытое поле, куда пишем выбранную роль -->
                <input type="hidden" name="role" id="roleInput" value="client">

                <!-- ПЕРЕКЛЮЧАТЕЛЬ РОЛЕЙ (Визуальный) -->
                <div class="role-switcher">
                    <div class="role-option active" onclick="setRole('client', this)">
                        <i class="bi bi-person me-1"></i> Клиент
                    </div>
                    <div class="role-option" onclick="setRole('beneficiary', this)">
                        <i class="bi bi-building me-1"></i> Заказчик
                    </div>
                    <div class="role-option" onclick="setRole('partner', this)">
                        <i class="bi bi-briefcase me-1"></i> Партнер/Агент
                    </div>
                </div>

                <!-- Подсказка, меняется JS-ом -->
                <div class="alert alert-light border text-muted small mb-4" id="roleDescription">
                    <i class="bi bi-info-circle me-1"></i>
                    Вы сможете создавать свои заявки на банковские гарантии, видеть их статусы и получать обратную связь от банков.
                </div>

                <!-- ЕДИНАЯ ФОРМА ПОЛЕЙ -->
                <div class="mb-3" id="regInnWrapper">
                    <label class="form-label">ИНН *</label>
                    <input type="text" name="inn" id="regInn" class="form-control" placeholder="10 или 12 цифр"
                           maxlength="12" required value="<?= htmlspecialchars($_POST['inn'] ?? '') ?>">
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Имя *</label>
                        <input type="text" name="first_name" class="form-control" placeholder="Иван" required value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Фамилия *</label>
                        <input type="text" name="last_name" class="form-control" placeholder="Иванов" required value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Компания</label>
                    <input type="text" name="company_name" id="regCompanyName" class="form-control" placeholder="ООО Ромашка / ИП Иванов" value="<?= htmlspecialchars($_POST['company_name'] ?? '') ?>">
                </div>

                <div class="mb-3">
                    <label class="form-label">Телефон</label>
                    <input type="text" name="phone" id="phone" class="form-control" placeholder="+7 (999) 000-00-00" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
                </div>

                <div class="mb-3">
                    <label class="form-label">Email (Логин) *</label>
                    <input type="email" name="email" class="form-control" placeholder="name@company.com" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Пароль *</label>
                        <input type="password" name="password" class="form-control"  required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Подтверждение *</label>
                        <input type="password" name="confirm_password" class="form-control"  required>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-primary-custom">
                    Зарегистрироваться
                </button>

                <div class="auth-footer">
                    Уже есть аккаунт? <a href="login.php">Войти в систему</a>
                </div>
            </form>
        </div>
    </div>

    <!-- ПРАВАЯ ЧАСТЬ -->
    <div class="right-pane">
        <div class="blur-circle bc-1"></div>
        <div class="blur-circle bc-2"></div>
        <div class="brand-content">
            <div class="brand-logo-large">FINBUILD</div>
             <div class="brand-quote">
                Платформа банковских гарантий
            </div>
            <p class="brand-desc">
                Единая платформа для получения банковских гарантий.<br> Безопасно. Технологично. Прозрачно.
            </p>
        </div>
    </div>
</div>

<script>
    function setRole(role, element) {
        // Меняем значение скрытого инпута
        document.getElementById('roleInput').value = role;
        
        // Меняем визуальное выделение
        document.querySelectorAll('.role-option').forEach(el => el.classList.remove('active'));
        element.classList.add('active');

        // Меняем текст подсказки
        const descBlock = document.getElementById('roleDescription');
        const innWrapper = document.getElementById('regInnWrapper');
        const innInput = document.getElementById('regInn');
        if (role === 'partner') {
            descBlock.innerHTML = '<i class="bi bi-briefcase me-1"></i> Вы сможете создавать заявки на банковские гарантии от своих клиентов, отслеживать их статусы и обратную связь от банков.';
            if (innWrapper) innWrapper.style.display = 'none';
            if (innInput) {
                innInput.value = '';
                innInput.removeAttribute('required');
            }
        } else if (role === 'beneficiary') {
            descBlock.innerHTML = '<i class="bi bi-building me-1"></i> Вы заказчик (бенефициар): размещаете запрос на БГ в свою пользу; после одобрения принципал получит доступ к заявке.';
            if (innWrapper) innWrapper.style.display = 'block';
            if (innInput) innInput.setAttribute('required', 'required');
        } else {
            descBlock.innerHTML = '<i class="bi bi-info-circle me-1"></i> Вы сможете создавать свои заявки на банковские гарантии, видеть их статусы и получать обратную связь от банков.';
            if (innWrapper) innWrapper.style.display = 'block';
            if (innInput) innInput.setAttribute('required', 'required');
        }
    }

    // Маска телефона
    document.getElementById('phone').addEventListener('input', function(e) {
        let x = e.target.value.replace(/\D/g, '').match(/(\d{0,1})(\d{0,3})(\d{0,3})(\d{0,2})(\d{0,2})/);
        if (x) {
             e.target.value = '+7' + (x[2] ? ' (' + x[2] : '') + (x[3] ? ') ' + x[3] : '') + (x[4] ? '-' + x[4] : '') + (x[5] ? '-' + x[5] : '');
        }
    });

    const regInnInput = document.getElementById('regInn');
    const regCompanyInput = document.getElementById('regCompanyName');

    let regInnTimer = null;
    if (regInnInput) {
        regInnInput.addEventListener('input', function() {
            this.value = this.value.replace(/[^\d]/g, '');
            if (regInnTimer) {
                clearTimeout(regInnTimer);
            }
            regInnTimer = setTimeout(() => {
                const inn = this.value.trim();
                if (!/^\d{10,12}$/.test(inn)) {
                    return;
                }
                fetch('api_checko_public.php?inn=' + inn)
                    .then(r => r.json())
                    .then(data => {
                        if (data.success && data.name) {
                            regCompanyInput.value = data.name;
                        }
                    })
                    .catch(() => {});
            }, 600);
        });
        regInnInput.addEventListener('blur', function() {
            const inn = this.value.trim();
            if (!/^\d{10,12}$/.test(inn)) {
                return;
            }
            fetch('api_checko_public.php?inn=' + inn)
                .then(r => r.json())
                .then(data => {
                    if (data.success && data.name) {
                        regCompanyInput.value = data.name;
                    }
                })
                .catch(() => {});
        });
    }

    // Инициализация состояния роли при загрузке
    document.addEventListener('DOMContentLoaded', function() {
        const currentRole = document.getElementById('roleInput')?.value || 'client';
        const activeOption = document.querySelector('.role-option.active');
        if (activeOption) {
            setRole(currentRole, activeOption);
        }
    });
</script>

</body>
</html>
