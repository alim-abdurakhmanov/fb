<?php 
require_once 'config.php';
require_once __DIR__ . '/includes/mail.php';
require_once __DIR__ . '/includes/finscore.php';
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

/**
 * Fallback, если FinScore/Checko недоступны.
 */
function calculateBGLimit($revenue) {
    if ($revenue <= 0) return 0;
    return (float) round($revenue / 3, -3);
}

function calculateLoanLimit($revenue, $existingCredits) {
    if ($revenue <= 0) return 0;
    $limit = round($revenue / 3, -3) - $existingCredits;
    return max(0.0, (float) $limit);
}

/**
 * @return array{ok:bool,revenue:?float,finscore:?array,error?:string,warnings?:list<string>}
 */
function fetchFinScoreForCalculation(string $inn, string $productType, float $existingCredits = 0.0): array
{
    $built = finscore_build_for_inn($inn, [
        'product_type' => $productType === 'credit' ? 'credit' : 'bg',
        'existing_credits' => $existingCredits,
    ]);
    if (empty($built['ok']) || empty($built['result'])) {
        return [
            'ok' => false,
            'revenue' => null,
            'finscore' => null,
            'error' => $built['error'] ?? 'Не удалось получить данные компании',
        ];
    }
    $fs = $built['result'];
    $revenue = (float) ($fs['finance']['revenue'] ?? 0);
    return [
        'ok' => true,
        'revenue' => $revenue > 0 ? $revenue : null,
        'finscore' => $fs,
        'warnings' => $built['warnings'] ?? [],
    ];
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
                $fetch = fetchFinScoreForCalculation(
                    $inn,
                    (string) ($calculationData['product_type'] ?? 'bg')
                );
                if ($fetch['ok'] && $fetch['revenue'] !== null) {
                    $calculationData['revenue'] = $fetch['revenue'];
                    $calculationData['revenue_source'] = 'auto';
                    $calculationData['finscore'] = $fetch['finscore'];
                    $calculationData['company_name'] = $fetch['finscore']['company_name'] ?? '';
                    if ($calculationData['product_type'] === 'credit') {
                        $step = 3;
                    } else {
                        $step = 4;
                    }
                } elseif ($fetch['ok'] && !empty($fetch['finscore'])) {
                    // Есть карточка компании, но нет выручки — FinScore всё равно полезен
                    $calculationData['finscore'] = $fetch['finscore'];
                    $calculationData['company_name'] = $fetch['finscore']['company_name'] ?? '';
                    $step = 2.5;
                } else {
                    unset($calculationData['finscore']);
                    $step = 2.5;
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
                // Пересчитываем FinScore с учётом кредитов
                if (!empty($calculationData['inn'])) {
                    $fetch = fetchFinScoreForCalculation(
                        (string) $calculationData['inn'],
                        'credit',
                        $existingCredits
                    );
                    if ($fetch['ok'] && !empty($fetch['finscore'])) {
                        $calculationData['finscore'] = $fetch['finscore'];
                        if ($fetch['revenue'] !== null) {
                            $calculationData['revenue'] = $fetch['revenue'];
                        }
                    }
                }
                $step = 4;
            }
            break;
            
        case 'calculate':
            $productType = ($calculationData['product_type'] ?? 'bg') === 'credit' ? 'credit' : 'bg';
            $productName = $productType === 'bg' ? 'Банковская гарантия' : 'Кредит для бизнеса';
            $productText = $productType === 'bg' ? 'банковскую гарантию' : 'кредит для бизнеса';
            $existingCredits = (float) ($calculationData['existing_credits'] ?? 0);
            $finscore = is_array($calculationData['finscore'] ?? null) ? $calculationData['finscore'] : null;

            // Актуализируем FinScore перед показом результата
            if (!empty($calculationData['inn']) && ($finscore === null || $productType === 'credit')) {
                $fetch = fetchFinScoreForCalculation(
                    (string) $calculationData['inn'],
                    $productType,
                    $existingCredits
                );
                if ($fetch['ok'] && !empty($fetch['finscore'])) {
                    $finscore = $fetch['finscore'];
                    $calculationData['finscore'] = $finscore;
                    if ($fetch['revenue'] !== null) {
                        $calculationData['revenue'] = $fetch['revenue'];
                    }
                }
            }

            if ($finscore !== null) {
                $active = $finscore['limits']['active'] ?? ['value' => 0, 'low' => 0, 'high' => 0];
                $limit = (float) ($active['value'] ?? 0);
                $result = [
                    'limit' => $limit,
                    'limit_low' => (float) ($active['low'] ?? 0),
                    'limit_high' => (float) ($active['high'] ?? 0),
                    'product_name' => $productName,
                    'product_text' => $productText,
                    'finscore' => $finscore,
                    'individual_only' => !empty($finscore['individual_only']),
                ];
            } else {
                $revenue = (float) ($calculationData['revenue'] ?? 0);
                $limit = $productType === 'bg'
                    ? calculateBGLimit($revenue)
                    : calculateLoanLimit($revenue, $existingCredits);
                $result = [
                    'limit' => $limit,
                    'limit_low' => $limit > 0 ? max(0, round($limit * 0.75, -3)) : 0,
                    'limit_high' => $limit > 0 ? round($limit * 1.25, -3) : 0,
                    'product_name' => $productName,
                    'product_text' => $productText,
                    'finscore' => null,
                    'individual_only' => $limit < 50000,
                ];
            }
            $calculationData['calculated_limit'] = $result['limit'];
            $calculationData['result_snapshot'] = $result;
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

.finscore-hero {
    display: grid;
    grid-template-columns: 140px 1fr;
    gap: 1.25rem;
    align-items: center;
    padding: 1.25rem;
    border-radius: 16px;
    background: linear-gradient(135deg, #f4f7fb 0%, #eef3f9 55%, #e8f0ea 100%);
    border: 1px solid #d9e2ec;
    margin-bottom: 1.25rem;
    text-align: left;
}

.finscore-ring {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    display: grid;
    place-items: center;
    background: conic-gradient(var(--fs-color, #2f6fed) calc(var(--fs-score, 0) * 1%), #dbe4ee 0);
    position: relative;
}

.finscore-ring::before {
    content: '';
    position: absolute;
    inset: 10px;
    border-radius: 50%;
    background: #fff;
}

.finscore-ring-inner {
    position: relative;
    z-index: 1;
    text-align: center;
}

.finscore-grade {
    font-size: 1.75rem;
    font-weight: 700;
    line-height: 1;
    color: var(--fs-color, #2f6fed);
}

.finscore-score {
    font-size: 0.85rem;
    color: #5c6b7a;
}

.finscore-meta h5 {
    margin: 0 0 0.25rem;
    font-weight: 700;
}

.finscore-range {
    font-size: 2rem;
    font-weight: 700;
    letter-spacing: -0.02em;
    color: #1b2838;
}

.finscore-range-sub {
    color: #5c6b7a;
    font-size: 0.95rem;
}

.finscore-factors {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 0.75rem;
    margin: 1rem 0 1.25rem;
}

.finscore-factor {
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 0.75rem 0.9rem;
    background: #fff;
    text-align: left;
    cursor: pointer;
    transition: border-color .15s ease, transform .15s ease;
}

.finscore-factor:hover {
    border-color: #94a3b8;
    transform: translateY(-1px);
}

.finscore-factor.is-active {
    border-color: #2f6fed;
    box-shadow: 0 0 0 3px rgba(47, 111, 237, 0.12);
}

.finscore-factor .tone {
    display: inline-block;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    margin-right: 0.4rem;
}

.finscore-factor .tone.good { background: #1a7f4b; }
.finscore-factor .tone.warn { background: #c48a00; }
.finscore-factor .tone.bad { background: #c92a2a; }

.finscore-factor-detail {
    display: none;
    margin-top: 0.5rem;
    color: #5c6b7a;
    font-size: 0.85rem;
}

.finscore-factor.is-active .finscore-factor-detail {
    display: block;
}

.finscore-kpis {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 0.75rem;
    margin-bottom: 1rem;
}

.finscore-kpi {
    background: #f8fafc;
    border-radius: 12px;
    padding: 0.85rem;
    text-align: left;
}

.finscore-kpi .label {
    font-size: 0.75rem;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.finscore-kpi .value {
    font-weight: 600;
    color: #1b2838;
}

.fs-summary {
    margin-top: 1rem;
    padding: 1rem 1.1rem;
    border-radius: 14px;
    background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
    border: 1px solid #e2e8f0;
}

.fs-summary-head {
    margin-bottom: 0.75rem;
}

.fs-summary-text {
    color: #334155;
    font-size: 0.95rem;
    line-height: 1.45;
}

.fs-summary-items {
    display: grid;
    gap: 0.65rem;
}

.fs-summary-item {
    display: grid;
    grid-template-columns: 160px 1fr;
    gap: 0.75rem;
    align-items: start;
    padding: 0.65rem 0.75rem;
    border-radius: 10px;
    background: #f8fafc;
    border: 1px solid #eef2f7;
}

.fs-summary-label {
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: #64748b;
}

.fs-summary-value {
    color: #1e293b;
    font-size: 0.95rem;
    line-height: 1.4;
    font-weight: 500;
}

@media (max-width: 768px) {
    .fs-summary-item {
        grid-template-columns: 1fr;
        gap: 0.25rem;
    }
}

@media (max-width: 768px) {
    .finscore-hero {
        grid-template-columns: 1fr;
        text-align: center;
        justify-items: center;
    }
    .finscore-kpis {
        grid-template-columns: 1fr;
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
                        <p class="text-muted">Не удалось автоматически получить выручку по ИНН — укажите вручную</p>
                    </div>
                    
                    <form method="POST">
                        <input type="hidden" name="action" value="enter_revenue">
                        
                        <div class="mb-4">
                            <label class="form-label fw-bold">Выручка за последний отчётный год (руб)</label>
                            <input type="number" class="form-control form-control-lg" name="revenue" 
                                   value="<?= htmlspecialchars((string) ($calculationData['revenue'] ?? '')) ?>" 
                                   placeholder="Например: 5000000" 
                                   min="0"
                                   step="1000"
                                   required
                                   autofocus>
                            <div class="form-text">Введите выручку компании в рублях</div>
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
                            <p class="text-muted">Проверьте данные перед расчётом FinScore и лимита</p>
                        </div>
                        
                        <div class="calculation-summary">
                            <h6>Данные для расчета:</h6>
                            <p class="mb-1"><strong>Продукт:</strong> 
                                <?= ($calculationData['product_type'] ?? '') === 'bg' ? 'Банковская гарантия' : 'Кредит для бизнеса' ?>
                            </p>
                            <?php if (!empty($calculationData['company_name'])): ?>
                                <p class="mb-1"><strong>Компания:</strong> <?= htmlspecialchars((string) $calculationData['company_name']) ?></p>
                            <?php endif; ?>
                            <p class="mb-1"><strong>ИНН:</strong> <?= htmlspecialchars((string) ($calculationData['inn'] ?? '')) ?></p>
                            <p class="mb-1"><strong>Выручка:</strong> <?= formatNumber((float) ($calculationData['revenue'] ?? 0)) ?> руб.
                                <?php if (($calculationData['revenue_source'] ?? '') === 'auto'): ?>
                                    <span class="badge bg-success-subtle text-success">авто</span>
                                <?php endif; ?>
                            </p>
                            <?php if (($calculationData['product_type'] ?? '') === 'credit'): ?>
                                <p class="mb-0"><strong>Текущие кредиты:</strong> <?= formatNumber((float) ($calculationData['existing_credits'] ?? 0)) ?> руб.</p>
                            <?php endif; ?>
                        </div>
                        
                        <div class="d-flex justify-content-between">
                            <button type="submit" name="action" value="back" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-left me-2"></i>Назад
                            </button>
                            <button type="submit" class="btn btn-success">
                                Рассчитать FinScore <i class="bi bi-calculator ms-2"></i>
                            </button>
                        </div>
                    </form>

                <!-- Шаг 5: Результат расчета -->
                <?php elseif ($currentStep == 5):
                    if ($result === null && is_array($calculationData['result_snapshot'] ?? null)) {
                        $result = $calculationData['result_snapshot'];
                    }
                    $fs = is_array($result['finscore'] ?? null) ? $result['finscore'] : null;
                    $score = (int) ($fs['score'] ?? 0);
                    $grade = (string) ($fs['grade'] ?? '—');
                    $gradeLabel = (string) ($fs['grade_label'] ?? 'Ориентировочный лимит');
                    $gradeColor = (string) ($fs['grade_color'] ?? '#2f6fed');
                    $limitVal = (float) ($result['limit'] ?? 0);
                    $limitLow = (float) ($result['limit_low'] ?? $limitVal);
                    $limitHigh = (float) ($result['limit_high'] ?? $limitVal);
                    $individualOnly = !empty($result['individual_only']) || $limitVal < 50000;
                    $factors = is_array($fs['factors'] ?? null) ? $fs['factors'] : [];
                    $hardStops = is_array($fs['hard_stops'] ?? null) ? $fs['hard_stops'] : [];
                ?>
                    <div class="text-center mb-3">
                        <h4 class="mb-1">FinScore и лимит</h4>
                        <p class="text-muted mb-0"><?= htmlspecialchars((string) ($result['product_name'] ?? 'Продукт')) ?></p>
                    </div>

                    <div class="finscore-hero" style="--fs-score: <?= max(0, min(100, $score)) ?>; --fs-color: <?= htmlspecialchars($gradeColor) ?>;">
                        <div class="finscore-ring" aria-hidden="true">
                            <div class="finscore-ring-inner">
                                <div class="finscore-grade"><?= htmlspecialchars($grade) ?></div>
                                <div class="finscore-score"><?= $score ?>/100</div>
                            </div>
                        </div>
                        <div class="finscore-meta">
                            <h5><?= htmlspecialchars((string) ($fs['company_name'] ?? $calculationData['company_name'] ?? 'Компания')) ?></h5>
                            <div class="text-muted mb-1">
                                ИНН <?= htmlspecialchars((string) ($calculationData['inn'] ?? '')) ?>
                                · FinScore <?= $score ?> из 100 · класс <?= htmlspecialchars($grade) ?>
                            </div>
                            <div class="fw-semibold mb-2"><?= htmlspecialchars($gradeLabel) ?></div>
                            <?php if (!empty($fs['confidence']['label'])): ?>
                                <div class="text-warning small mb-2"><?= htmlspecialchars((string) $fs['confidence']['label']) ?></div>
                            <?php endif; ?>
                            <?php if ($individualOnly || $limitVal <= 0): ?>
                                <div class="finscore-range">Индивидуально</div>
                                <div class="finscore-range-sub">Автолимит недоступен — нужен ручной разбор</div>
                            <?php else: ?>
                                <div class="finscore-range"><?= formatNumber($limitVal) ?> ₽</div>
                                <div class="finscore-range-sub">
                                    Диапазон <?= formatNumber($limitLow) ?> – <?= formatNumber($limitHigh) ?> ₽
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($fs && (!empty($fs['summary']) || !empty($fs['summary_items']))): ?>
                    <div class="fs-summary text-start mb-3">
                        <div class="fs-summary-head">
                            <strong>Резюме</strong>
                        </div>
                        <?php if (!empty($fs['summary_items']) && is_array($fs['summary_items'])): ?>
                            <div class="fs-summary-items">
                                <?php foreach ($fs['summary_items'] as $item): ?>
                                    <?php if (!is_array($item)) { continue; } ?>
                                    <div class="fs-summary-item">
                                        <div class="fs-summary-label"><?= htmlspecialchars((string) ($item['label'] ?? '')) ?></div>
                                        <div class="fs-summary-value"><?= htmlspecialchars((string) ($item['text'] ?? '')) ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="fs-summary-text mb-0"><?= htmlspecialchars((string) $fs['summary']) ?></p>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($fs): ?>
                    <div class="finscore-kpis">
                        <div class="finscore-kpi">
                            <div class="label">Выручка<?= !empty($fs['finance']['year']) ? ' ' . (int) $fs['finance']['year'] : '' ?></div>
                            <div class="value"><?= formatNumber((float) ($fs['finance']['revenue'] ?? $calculationData['revenue'] ?? 0)) ?> ₽</div>
                        </div>
                        <div class="finscore-kpi">
                            <div class="label">Прибыль</div>
                            <div class="value"><?= formatNumber((float) ($fs['finance']['profit'] ?? 0)) ?> ₽</div>
                        </div>
                        <div class="finscore-kpi">
                            <div class="label">Рекомендация</div>
                            <div class="value"><?= htmlspecialchars((string) ($fs['recommendation'] ?? '—')) ?></div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($hardStops !== []): ?>
                        <div class="alert alert-danger">
                            <strong>Стоп-факторы:</strong>
                            <ul class="mb-0 mt-2">
                                <?php foreach ($hardStops as $stop): ?>
                                    <li><?= htmlspecialchars((string) $stop) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if ($factors !== []): ?>
                        <h6 class="text-start">Ключевые факторы <small class="text-muted">(нажмите для деталей)</small></h6>
                        <div class="finscore-factors" id="finscore-factors">
                            <?php foreach ($factors as $factor): ?>
                                <button type="button" class="finscore-factor" data-factor>
                                    <span class="tone <?= htmlspecialchars((string) ($factor['tone'] ?? 'warn')) ?>"></span>
                                    <strong><?= htmlspecialchars((string) ($factor['label'] ?? '')) ?></strong>
                                    <div class="finscore-factor-detail"><?= htmlspecialchars((string) ($factor['detail'] ?? '')) ?></div>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($individualOnly || $limitVal < 50000): ?>
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
                            <p class="mb-3">FinScore даёт ориентир. Для точного лимита менеджер может провести расчёт в 50+ банках.</p>
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
document.addEventListener('DOMContentLoaded', function() {
    const innInput = document.querySelector('input[name="inn"]');
    if (innInput) {
        innInput.addEventListener('input', function() {
            this.value = this.value.replace(/[^\d]/g, '');
        });
    }

    const firstInput = document.querySelector('form input[type="text"], form input[type="number"]');
    if (firstInput && firstInput.type !== 'hidden') {
        firstInput.focus();
    }

    document.querySelectorAll('[data-factor]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const wasActive = btn.classList.contains('is-active');
            document.querySelectorAll('[data-factor]').forEach(function (el) {
                el.classList.remove('is-active');
            });
            if (!wasActive) {
                btn.classList.add('is-active');
            }
        });
    });

});
</script>

<?php require_once 'footer.php'; ?>