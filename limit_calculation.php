<?php 
require_once 'config.php';
require_once __DIR__ . '/includes/mail.php';
checkAuth();

$pdo = getPDO();

// Сбрасываем расчет при обычном заходе на страницу (не через POST)
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !isset($_GET['reset'])) {
    $_SESSION['calculation_data'] = [];
    $_SESSION['calculation_step'] = 1;
}

// Обработка сброса расчета ДО любого вывода
if (isset($_GET['reset'])) {
    $_SESSION['calculation_data'] = [];
    $_SESSION['calculation_step'] = 1;
    header('Location: limit_calculation.php');
    exit();
}

// Инициализация сессии для расчета
if (!isset($_SESSION['calculation_data'])) {
    $_SESSION['calculation_data'] = [];
}
if (!isset($_SESSION['calculation_step'])) {
    $_SESSION['calculation_step'] = 1;
}

// Получаем данные из сессии
$calculationData = $_SESSION['calculation_data'];
$step = $_SESSION['calculation_step'];
$result = null;
$error = '';

// Функции расчета лимитов
function calculateBGLimit($revenue) {
    if ($revenue <= 0) return 0;
    return round($revenue / 3);
}

function calculateLoanLimit($revenue, $existingCredits) {
    if ($revenue <= 0) return 0;
    $limit = round($revenue / 3) - $existingCredits;
    return max(0, $limit); // Не может быть отрицательным
}

// Функция получения выручки по ИНН
function getRevenueByInn($inn) {
    $apiKey = "BXLApjLYuoc0nGvM";
    $apiUrl = "https://api.checko.ru/v2/finances?key=$apiKey&inn=$inn";

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $apiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && $response) {
        $data = json_decode($response, true);
        if (isset($data['data']['2025']['2110'])) {
            return $data['data']['2025']['2110'];
        }
    }

    return null;
}

// Обработка формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'next';
    
    switch ($action) {
        case 'select_product':
            $productType = $_POST['product_type'] ?? '';
            if (in_array($productType, ['bg', 'credit'])) {
                $calculationData['product_type'] = $productType;
                $step = 2;
            } else {
                $error = "Пожалуйста, выберите тип продукта";
            }
            break;
            
        case 'enter_inn':
            $inn = trim($_POST['inn'] ?? '');
            if (empty($inn)) {
                $error = "Пожалуйста, введите ИНН компании";
            } elseif (!preg_match('/^\d{10,12}$/', $inn)) {
                $error = "ИНН должен состоять из 10–12 цифр";
            } else {
                $calculationData['inn'] = $inn;
                
                // Пытаемся получить выручку автоматически
                $revenue = getRevenueByInn($inn);
                if ($revenue !== null) {
                    $calculationData['revenue'] = $revenue;
                    $calculationData['revenue_source'] = 'auto';
                    
                    if ($calculationData['product_type'] === 'credit') {
                        $step = 3; // Для кредита запрашиваем существующие кредиты
                    } else {
                        $step = 4; // Для БГ переходим к расчету
                    }
                } else {
                    $step = 2.5; // Запрашиваем выручку вручную
                }
            }
            break;
            
        case 'enter_revenue':
            $revenue = floatval($_POST['revenue'] ?? 0);
            if ($revenue <= 0) {
                $error = "Пожалуйста, введите корректную выручку";
            } else {
                $calculationData['revenue'] = $revenue;
                $calculationData['revenue_source'] = 'manual';
                
                if ($calculationData['product_type'] === 'credit') {
                    $step = 3;
                } else {
                    $step = 4;
                }
            }
            break;
            
        case 'enter_credits':
            $existingCredits = floatval($_POST['existing_credits'] ?? 0);
            if ($existingCredits < 0) {
                $error = "Пожалуйста, введите корректную сумму кредитов";
            } else {
                $calculationData['existing_credits'] = $existingCredits;
                $step = 4;
            }
            break;
            
        case 'calculate':
            // Выполняем расчет
            if ($calculationData['product_type'] === 'bg') {
                $limit = calculateBGLimit($calculationData['revenue']);
                $result = [
                    'limit' => $limit,
                    'product_name' => 'Банковская гарантия',
                    'product_text' => 'банковскую гарантию'
                ];
            } else {
                $limit = calculateLoanLimit(
                    $calculationData['revenue'], 
                    $calculationData['existing_credits'] ?? 0
                );
                $result = [
                    'limit' => $limit,
                    'product_name' => 'Кредит для бизнеса',
                    'product_text' => 'кредит для бизнеса'
                ];
            }
            $calculationData['calculated_limit'] = $result['limit'];
            $step = 5;
            break;
            
        case 'individual_request':
            $phone = trim($_POST['phone'] ?? '');
            if (empty($phone)) {
                $error = "Пожалуйста, введите номер телефона";
            } else {
                $calculationData['phone'] = $phone;
                
                // Сохраняем запрос в базу данных
                $stmt = $pdo->prepare("INSERT INTO limit_requests 
                    (user_id, product_type, inn, revenue, existing_credits, calculated_limit, phone, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
                
                $stmt->execute([
                    $_SESSION['user_id'],
                    $calculationData['product_type'],
                    $calculationData['inn'],
                    $calculationData['revenue'],
                    $calculationData['existing_credits'] ?? 0,
                    $calculationData['calculated_limit'] ?? 0,
                    $phone
                ]);

                $productLabel = ($calculationData['product_type'] ?? '') === 'bg'
                    ? 'Банковская гарантия'
                    : 'Кредит для бизнеса';
                $userId = (int) $_SESSION['user_id'];
                $stmtUser = $pdo->prepare(
                    'SELECT first_name, last_name, email, phone, company_name FROM users WHERE id = ?'
                );
                $stmtUser->execute([$userId]);
                $user = $stmtUser->fetch(PDO::FETCH_ASSOC) ?: [];

                $userName = trim(
                    ((string) ($user['first_name'] ?? '')) . ' ' . ((string) ($user['last_name'] ?? ''))
                );
                $companyName = trim((string) ($user['company_name'] ?? ''));
                $userEmail = trim((string) ($user['email'] ?? ''));
                $profilePhone = trim((string) ($user['phone'] ?? ''));

                $subject = 'Запрос индивидуального расчета лимита';
                $html = '<p>Новый запрос индивидуального расчета лимита.</p>';
                $html .= '<p><strong>Пользователь ID:</strong> ' . $userId . '<br>';
                if ($userName !== '') {
                    $html .= '<strong>ФИО:</strong> ' . htmlspecialchars($userName, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '<br>';
                }
                if ($companyName !== '') {
                    $html .= '<strong>Компания:</strong> ' . htmlspecialchars($companyName, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '<br>';
                }
                if ($userEmail !== '') {
                    $html .= '<strong>Email:</strong> ' . htmlspecialchars($userEmail, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '<br>';
                }
                if ($profilePhone !== '') {
                    $html .= '<strong>Телефон в профиле:</strong> ' . htmlspecialchars($profilePhone, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '<br>';
                }
                $html .= '<strong>Телефон для связи:</strong> ' . htmlspecialchars($phone, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '<br>';
                $html .= '<strong>Продукт:</strong> ' . htmlspecialchars($productLabel, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '<br>';
                $html .= '<strong>ИНН:</strong> ' . htmlspecialchars((string) ($calculationData['inn'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8') . '<br>';
                $html .= '<strong>Выручка:</strong> ' . formatNumber((float) ($calculationData['revenue'] ?? 0)) . ' руб.</p>';
                if (($calculationData['product_type'] ?? '') === 'credit') {
                    $html .= '<p><strong>Текущие кредиты:</strong> '
                        . formatNumber((float) ($calculationData['existing_credits'] ?? 0)) . ' руб.</p>';
                }
                if (isset($calculationData['calculated_limit'])) {
                    $html .= '<p><strong>Расчетный лимит:</strong> '
                        . formatNumber((float) $calculationData['calculated_limit']) . ' руб.</p>';
                }

                finbuild_send_mail('info@p-fg.com', $subject, $html);
                
                $step = 6; // Финальный шаг
            }
            break;
            
        case 'back':
            $step = max(1, $step - 1);
            break;
    }
    
    // Сохраняем данные в сессию только при POST-запросах
    $_SESSION['calculation_data'] = $calculationData;
    $_SESSION['calculation_step'] = $step;
}

// Функция форматирования чисел
function formatNumber($number) {
    return number_format($number, 0, '', ' ');
}

// Определяем, на каком шаге находимся
$currentStep = $step;

// ТЕПЕРЬ подключаем header.php после всей обработки
$current_page = 'limit_calculation';
require_once 'header.php';
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1 class="h3 mb-0">Расчет лимитов</h1>
            <p class="text-muted mb-0">Расчет лимитов по банковским гарантиям и кредитам для бизнеса</p>
        </div>
        <?php if ($currentStep > 1): ?>
        <div class="col-auto">
            <a href="limit_calculation.php?reset=1" class="btn btn-outline-danger btn-sm">
                <i class="bi bi-arrow-clockwise me-1"></i>Начать заново
            </a>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
.calculation-steps {
    display: flex;
    justify-content: space-between;
    margin-bottom: 30px;
    position: relative;
}

.calculation-steps::before {
    content: '';
    position: absolute;
    top: 20px;
    left: 0;
    right: 0;
    height: 2px;
    background: #e9ecef;
    z-index: 1;
}

.step {
    text-align: center;
    position: relative;
    z-index: 2;
    flex: 1;
}

.step-circle {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: #e9ecef;
    color: #6c757d;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 10px;
    font-weight: bold;
    border: 3px solid white;
    transition: all 0.3s ease;
}

.step.active .step-circle {
    background: #3498db;
    color: white;
    transform: scale(1.1);
}

.step.completed .step-circle {
    background: #28a745;
    color: white;
}

.step-label {
    font-size: 12px;
    color: #6c757d;
    font-weight: 500;
}

.step.active .step-label {
    color: #3498db;
    font-weight: bold;
}

.step.completed .step-label {
    color: #28a745;
}

.limit-result {
    font-size: 2.5rem;
    font-weight: bold;
    color: #28a745;
    text-align: center;
    margin: 20px 0;
    padding: 20px;
    background: #f8f9fa;
    border-radius: 10px;
    border: 2px solid #e9ecef;
}

.product-badge {
    font-size: 1rem;
}

.calculation-summary {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 20px;
}

.calculation-summary h6 {
    color: #495057;
    margin-bottom: 10px;
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

    .card .card-body,
    .card .card-header {
        padding: 1rem;
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

    .step-circle {
        width: 32px;
        height: 32px;
        font-size: 0.8rem;
    }

    .step-label {
        font-size: 0.7rem;
    }

    .limit-result {
        font-size: 1.75rem;
        padding: 14px;
    }
}
</style>

<div class="row">
    <div class="col-lg-10 mx-auto">
        <!-- Шаги расчета -->
        <div class="calculation-steps">
            <div class="step <?= $currentStep >= 1 ? 'active' : '' ?> <?= $currentStep > 1 ? 'completed' : '' ?>">
                <div class="step-circle">1</div>
                <div class="step-label">Продукт</div>
            </div>
            <div class="step <?= $currentStep >= 2 ? 'active' : '' ?> <?= $currentStep > 2 ? 'completed' : '' ?>">
                <div class="step-circle">2</div>
                <div class="step-label">Данные</div>
            </div>
            <div class="step <?= $currentStep >= 3 ? 'active' : '' ?> <?= $currentStep > 3 ? 'completed' : '' ?>">
                <div class="step-circle">3</div>
                <div class="step-label">Дополнительно</div>
            </div>
            <div class="step <?= $currentStep >= 4 ? 'active' : '' ?> <?= $currentStep > 4 ? 'completed' : '' ?>">
                <div class="step-circle">4</div>
                <div class="step-label">Результат</div>
            </div>
        </div>

        <!-- Форма расчета -->
        <div class="card">
            <div class="card-body">
                <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <!-- Шаг 1: Выбор продукта -->
                <?php if ($currentStep == 1): ?>
                    <div class="text-center mb-4">
                        <h4>Выберите продукт для расчета</h4>
                        <p class="text-muted">Выберите тип продукта для расчета лимита</p>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-4">
                            <div class="card product-card h-100 border-primary">
                                <div class="card-body text-center p-4">
                                    <i class="bi bi-shield-check fs-1 text-primary mb-3"></i>
                                    <h5>Банковская гарантия</h5>
                                    <p class="text-muted">Расчет лимита по банковским гарантиям на основе выручки компании</p>
                                    <form method="POST" class="mt-3">
                                        <input type="hidden" name="action" value="select_product">
                                        <button type="submit" name="product_type" value="bg" class="btn btn-primary btn-lg">
                                            Выбрать БГ
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 mb-4">
                            <div class="card product-card h-100 border-success">
                                <div class="card-body text-center p-4">
                                    <i class="bi bi-cash-coin fs-1 text-success mb-3"></i>
                                    <h5>Кредит для бизнеса</h5>
                                    <p class="text-muted">Расчет лимита по кредитам с учетом текущей задолженности</p>
                                    <form method="POST" class="mt-3">
                                        <input type="hidden" name="action" value="select_product">
                                        <button type="submit" name="product_type" value="credit" class="btn btn-success btn-lg">
                                            Выбрать кредит
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>

                <!-- Шаг 2: Ввод ИНН -->
                <?php elseif ($currentStep == 2): ?>
                    <div class="text-center mb-4">
                        <h4>Введите ИНН компании</h4>
                        <p class="text-muted">Система автоматически получит данные о выручке компании из открытых источников</p>
                    </div>
                    
                    <form method="POST">
                        <input type="hidden" name="action" value="enter_inn">
                        
                        <div class="mb-4">
                            <label class="form-label fw-bold">ИНН компании</label>
                            <input type="text" class="form-control form-control-lg" name="inn" 
                                   value="<?= htmlspecialchars($calculationData['inn'] ?? '') ?>" 
                                   placeholder="Введите 10 или 12 цифр" 
                                   maxlength="12"
                                   required
                                   autofocus>
                            <div class="form-text">Введите ИНН компании для получения данных о выручке</div>
                        </div>
                        
                        <div class="d-flex justify-content-between">
                            <button type="submit" name="action" value="back" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-left me-2"></i>Назад
                            </button>
                            <button type="submit" class="btn btn-primary">
                                Продолжить <i class="bi bi-arrow-right ms-2"></i>
                            </button>
                        </div>
                    </form>

                <!-- Шаг 2.5: Ручной ввод выручки -->
                <?php elseif ($currentStep == 2.5): ?>
                    <div class="text-center mb-4">
                        <h4>Введите выручку компании</h4>
                        <p class="text-muted">Не удалось автоматически получить данные о выручке по ИНН</p>
                    </div>
                    
                    <form method="POST">
                        <input type="hidden" name="action" value="enter_revenue">
                        
                        <div class="mb-4">
                            <label class="form-label fw-bold">Выручка за 2025 год (руб)</label>
                            <input type="number" class="form-control form-control-lg" name="revenue" 
                                   value="<?= htmlspecialchars($calculationData['revenue'] ?? '') ?>" 
                                   placeholder="Например: 5000000" 
                                   min="0"
                                   step="1000"
                                   required
                                   autofocus>
                            <div class="form-text">Введите выручку компании за 2025 год в рублях</div>
                        </div>
                        
                        <div class="d-flex justify-content-between">
                            <button type="submit" name="action" value="back" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-left me-2"></i>Назад
                            </button>
                            <button type="submit" class="btn btn-primary">
                                Продолжить <i class="bi bi-arrow-right ms-2"></i>
                            </button>
                        </div>
                    </form>

                <!-- Шаг 3: Существующие кредиты (только для кредита) -->
                <?php elseif ($currentStep == 3 && $calculationData['product_type'] === 'credit'): ?>
                    <div class="text-center mb-4">
                        <h4>Существующие кредиты</h4>
                        <p class="text-muted">Укажите сумму текущих кредитов компании для точного расчета</p>
                    </div>
                    
                    <form method="POST">
                        <input type="hidden" name="action" value="enter_credits">
                        
                        <div class="calculation-summary">
                            <h6>Введенные данные:</h6>
                            <p class="mb-1"><strong>ИНН:</strong> <?= htmlspecialchars($calculationData['inn']) ?></p>
                            <p class="mb-0"><strong>Выручка:</strong> <?= formatNumber($calculationData['revenue']) ?> руб.</p>
                        </div>
                        
                        <div class="mb-4">
                            <label class="form-label fw-bold">Сумма текущих кредитов (руб)</label>
                            <input type="number" class="form-control form-control-lg" name="existing_credits" 
                                   value="<?= htmlspecialchars($calculationData['existing_credits'] ?? '') ?>" 
                                   placeholder="Например: 1000000" 
                                   min="0"
                                   step="1000"
                                   required
                                   autofocus>
                            <div class="form-text">Введите общую сумму текущих кредитов компании (0, если кредитов нет)</div>
                        </div>
                        
                        <div class="d-flex justify-content-between">
                            <button type="submit" name="action" value="back" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-left me-2"></i>Назад
                            </button>
                            <button type="submit" class="btn btn-primary">
                                Рассчитать лимит <i class="bi bi-calculator ms-2"></i>
                            </button>
                        </div>
                    </form>

                <!-- Шаг 4: Расчет -->
                <?php elseif ($currentStep == 4): ?>
                    <form method="POST">
                        <input type="hidden" name="action" value="calculate">
                        
                        <div class="text-center mb-4">
                            <h4>Проверка данных</h4>
                            <p class="text-muted">Проверьте введенные данные перед расчетом</p>
                        </div>
                        
                        <div class="calculation-summary">
                            <h6>Данные для расчета:</h6>
                            <p class="mb-1"><strong>Продукт:</strong> 
                                <?= $calculationData['product_type'] === 'bg' ? 'Банковская гарантия' : 'Кредит для бизнеса' ?>
                            </p>
                            <p class="mb-1"><strong>ИНН:</strong> <?= htmlspecialchars($calculationData['inn']) ?></p>
                            <p class="mb-1"><strong>Выручка:</strong> <?= formatNumber($calculationData['revenue']) ?> руб.</p>
                            <?php if ($calculationData['product_type'] === 'credit'): ?>
                                <p class="mb-0"><strong>Текущие кредиты:</strong> <?= formatNumber($calculationData['existing_credits'] ?? 0) ?> руб.</p>
                            <?php endif; ?>
                        </div>
                        
                        <div class="d-flex justify-content-between">
                            <button type="submit" name="action" value="back" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-left me-2"></i>Назад
                            </button>
                            <button type="submit" class="btn btn-success">
                                Выполнить расчет <i class="bi bi-calculator ms-2"></i>
                            </button>
                        </div>
                    </form>

                <!-- Шаг 5: Результат расчета -->
                <?php elseif ($currentStep == 5 && $result): ?>
                    <div class="text-center mb-4">
                        <h4>Результат расчета</h4>
                        <p class="text-muted">Расчетный лимит по выбранному продукту</p>
                    </div>
                    
                    <div class="limit-result">
                        <?= formatNumber($result['limit']) ?> ₽
                    </div>
                    
                    <div class="text-center mb-4">
                        <span class="badge bg-primary product-badge fs-6 p-2">
                            <?= $result['product_name'] ?>
                        </span>
                    </div>
                    
                    <?php if ($result['limit'] < 50000): ?>
                        <div class="alert alert-warning text-center">
                            <h5><i class="bi bi-exclamation-triangle me-2"></i>Индивидуальный расчет</h5>
                            <p class="mb-3">Для вас определение лимита доступно только на индивидуальных условиях. Это бесплатно и быстро.</p>
                            <button type="button" class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#individualModal">
                                <i class="bi bi-telephone me-2"></i>Запросить индивидуальный расчет
                            </button>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info text-center">
                            <h5><i class="bi bi-info-circle me-2"></i>Уточнение лимита</h5>
                            <p class="mb-3">Для точного определения лимита наш менеджер может провести для вас индивидуальный расчет в более чем 50 банках.</p>
                            <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#individualModal">
                                <i class="bi bi-telephone me-2"></i>Запросить индивидуальный расчет
                            </button>
                        </div>
                    <?php endif; ?>
                    
                    <div class="text-center mt-4">
                        <a href="limit_calculation.php?reset=1" class="btn btn-outline-primary me-2">
                            <i class="bi bi-arrow-repeat me-2"></i>Новый расчет
                        </a>
                        <a href="index.php" class="btn btn-outline-secondary">
                            <i class="bi bi-house me-2"></i>На главную
                        </a>
                    </div>

                <!-- Шаг 6: Финальный шаг -->
                <?php elseif ($currentStep == 6): ?>
                    <div class="text-center py-4">
                        <div class="mb-4">
                            <i class="bi bi-check-circle text-success" style="font-size: 4rem;"></i>
                        </div>
                        <h4 class="text-success mb-3">Заявка отправлена!</h4>
                        <p class="text-muted mb-4">Менеджер свяжется с вами в ближайшее время для индивидуального расчета</p>
                        
                        <div class="card bg-light mb-4 mx-auto" style="max-width: 500px;">
                            <div class="card-body text-start">
                                <h6 class="card-title">Детали заявки:</h6>
                                <p class="mb-1"><strong>Продукт:</strong> <?= $calculationData['product_type'] === 'bg' ? 'Банковская гарантия' : 'Кредит для бизнеса' ?></p>
                                <p class="mb-1"><strong>ИНН:</strong> <?= htmlspecialchars($calculationData['inn']) ?></p>
                                <p class="mb-1"><strong>Выручка:</strong> <?= formatNumber($calculationData['revenue']) ?> руб.</p>
                                <?php if ($calculationData['product_type'] === 'credit'): ?>
                                    <p class="mb-1"><strong>Текущие кредиты:</strong> <?= formatNumber($calculationData['existing_credits']) ?> руб.</p>
                                <?php endif; ?>
                                <p class="mb-1"><strong>Расчетный лимит:</strong> <?= formatNumber($calculationData['calculated_limit']) ?> руб.</p>
                                <p class="mb-0"><strong>Телефон:</strong> <?= htmlspecialchars($calculationData['phone']) ?></p>
                            </div>
                        </div>
                        
                        <div class="d-grid gap-2 d-md-block">
                            <a href="limit_calculation.php?reset=1" class="btn btn-primary me-md-2 mb-2">
                                <i class="bi bi-calculator me-2"></i>Новый расчет
                            </a>
                            <a href="index.php" class="btn btn-outline-secondary mb-2">
                                <i class="bi bi-house me-2"></i>На главную
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Модальное окно для индивидуального расчета -->
<div class="modal fade" id="individualModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Индивидуальный расчет</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="individual_request">
                <div class="modal-body">
                    <p>Оставьте ваш номер телефона, и менеджер свяжется с вами для индивидуального расчета:</p>
                    
                    <div class="mb-3">
                        <label class="form-label">Номер телефона</label>
                        <input type="tel" class="form-control" name="phone" 
                               value="<?= htmlspecialchars($calculationData['phone'] ?? '') ?>" 
                               placeholder="+7 (XXX) XXX-XX-XX" 
                               required>
                        <div class="form-text">Менеджер свяжется в течение 15 минут</div>
                    </div>
                    
                    <div class="alert alert-info">
                        <h6><i class="bi bi-info-circle me-2"></i>Что вас ждет:</h6>
                        <ul class="mb-0">
                            <li>Бесплатная консультация</li>
                            <li>Расчет в 50+ банках</li>
                            <li>Персональные условия</li>
                            <li>Быстрое рассмотрение</li>
                        </ul>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-send me-2"></i>Отправить заявку
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Ограничение ввода только цифр для ИНН
document.addEventListener('DOMContentLoaded', function() {
    const innInput = document.querySelector('input[name="inn"]');
    if (innInput) {
        innInput.addEventListener('input', function(e) {
            this.value = this.value.replace(/[^\d]/g, '');
        });
    }
});

// Автофокус на первом поле формы
document.addEventListener('DOMContentLoaded', function() {
    const firstInput = document.querySelector('form input[type="text"], form input[type="number"]');
    if (firstInput && firstInput.type !== 'hidden') {
        firstInput.focus();
    }
});
</script>

<?php require_once 'footer.php'; ?>