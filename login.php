<?php
require_once 'config.php';

if (isset($_SESSION['user_id'])) {
    redirectByRole();
}

$pdo = getPDO();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = "Пожалуйста, заполните все поля";
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND is_active = 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['first_name'] = $user['first_name'];
            $_SESSION['last_name'] = $user['last_name'];
            if (($user['role'] ?? '') === 'bank') {
                header('Location: bank_applications.php');
                exit;
            }
            redirectByRole();
        } else {
            $error = "Неверный e-mail или пароль";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вход | FINBUILD</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=Montserrat:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
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

        /* --- ЛЕВАЯ ЧАСТЬ (ФОРМА) --- */
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
            margin-bottom: 2.5rem;
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
            padding: 0.8rem 1rem;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            font-size: 1rem;
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
            margin-top: 1rem;
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

        /* --- ПРАВАЯ ЧАСТЬ (БРЕНД) --- */
        .right-pane {
            flex: 1;
            /* Десктопный фон (сине-фиолетовый Mesh Gradient) */
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
            background-image: 
                linear-gradient(rgba(255, 255, 255, 0.03) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255, 255, 255, 0.03) 1px, transparent 1px);
            background-size: 50px 50px;
            pointer-events: none;
        }

        .blur-circle {
            position: absolute;
            border-radius: 50%;
            filter: blur(80px);
            opacity: 0.4;
            z-index: 1;
        }
        .bc-1 { width: 300px; height: 300px; background: #4f46e5; top: 10%; right: 10%; }
        .bc-2 { width: 400px; height: 400px; background: #0d6efd; bottom: -10%; left: -10%; }

        .brand-content { position: relative; z-index: 2; }

        .brand-logo-large {
            font-family: 'Montserrat', sans-serif;
            font-weight: 800;
            font-size: 3.5rem;
            margin-bottom: 2rem;
            letter-spacing: -2px;
            background: linear-gradient(to right, #fff, #94a3b8);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .brand-quote {
            font-family: 'Montserrat', sans-serif;
            font-size: 2.2rem;
            font-weight: 700;
            line-height: 1.2;
            margin-bottom: 1.5rem;
            text-shadow: 0 10px 30px rgba(0,0,0,0.5);
        }

        .brand-desc {
            font-size: 1.1rem;
            opacity: 0.8;
            max-width: 480px;
            line-height: 1.6;
            font-weight: 300;
        }

        /* --- АДАПТИВНОСТЬ (MOBILE) --- */
        @media (max-width: 991.98px) {
            body { height: auto; min-height: 100vh; }
            .split-screen { flex-direction: column; }

            .right-pane {
                order: 1;
                flex: 0 0 auto;
                padding: 3rem 1.5rem;
                text-align: center;
                min-height: auto;
                
                /* Переопределяем фон специально для мобильных: делаем темнее и менее насыщенным */
                background-color: #050505; 
                background-image: 
                    radial-gradient(at 0% 0%, hsla(253,16%,5%,1) 0, transparent 50%), 
                    radial-gradient(at 50% 0%, hsla(225,25%,10%,1) 0, transparent 50%), 
                    radial-gradient(at 100% 0%, hsla(339,49%,15%,1) 0, transparent 50%);
            }

            /* На мобильных размытые круги можно сделать прозрачнее или убрать для чистоты */
            .blur-circle { opacity: 0.2; }

            .brand-logo-large { font-size: 2.5rem; margin-bottom: 0.5rem; }
            
            .brand-quote { 
                font-size: 1rem; 
                margin-bottom: 0.5rem; 
                white-space: nowrap; 
                opacity: 0.9; 
            }
            
            .brand-desc { display: none; }
            
            .left-pane {
                order: 2;
                flex: 0 0 auto;
                padding: 2rem 1.5rem;
                border-top-left-radius: 24px;
                border-top-right-radius: 24px;
                margin-top: -20px; 
                background: #fff;
                z-index: 20; 
                box-shadow: 0 -10px 30px rgba(0,0,0,0.05);
            }

            .login-wrapper { margin: 0 auto; }
            .page-title { text-align: center; font-size: 1.75rem; }
            .page-subtitle { text-align: center; }
        }
    </style>
</head>
<body>

<div class="split-screen">
    <!-- ЛЕВАЯ ЧАСТЬ (ФОРМА) -->
    <div class="left-pane">
        <div class="login-wrapper">

            <h1 class="page-title">Добро пожаловать</h1>
            <p class="page-subtitle">Войдите в свой аккаунт</p>

            <?php if ($error): ?>
                <div class="alert alert-danger py-2 border-0 bg-danger bg-opacity-10 text-danger mb-4 rounded-3 d-flex align-items-center">
                    <i class="bi bi-exclamation-circle-fill me-2"></i> <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <div class="mb-3">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" placeholder="name@company.com" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                </div>

                <div class="mb-4">
                    <label class="form-label">Пароль</label>
                    <input type="password" name="password" class="form-control" placeholder="••••••••" required>
                </div>

                <button type="submit" class="btn btn-primary btn-primary-custom">
                    Войти
                </button>

                <div class="auth-footer">
                    Впервые у нас? <a href="register.php">Зарегистрироваться</a>
                </div>
            </form>
        </div>
    </div>

    <!-- ПРАВАЯ ЧАСТЬ (БРЕНД / ШАПКА) -->
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

</body>
</html>
