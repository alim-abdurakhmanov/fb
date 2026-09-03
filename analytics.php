<?php
$current_page = 'analytics'; 
require_once 'header.php';

$pdo = getPDO();

// Обработка формы
$inn = '';
$companyData = null;
$financeData = null;
$enforcementsData = null;
$lawsuitsData = null;
$finscoreResult = null;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $inn = trim($_POST['inn'] ?? '');
    
    if (empty($inn)) {
        $error = "Пожалуйста, введите ИНН";
    } elseif (!preg_match('/^\d{10,12}$/', $inn)) {
        $error = "ИНН должен состоять из 10–12 цифр";
    } else {
        require_once __DIR__ . '/includes/finscore.php';
        try {
            $built = finscore_build_for_inn($inn, ['product_type' => 'bg']);
            if (empty($built['ok'])) {
                $error = $built['error'] ?? 'Не удалось получить данные';
            } else {
                $raw = $built['raw'] ?? [];
                $companyData = $raw['company'] ?? null;
                $financeData = $raw['finance'] ?? null;
                $enforcementsData = $raw['enforcements'] ?? null;
                $lawsuitsData = $raw['lawsuits'] ?? null;
                $finscoreResult = $built['result'] ?? null;

                $stmt = $pdo->prepare("INSERT INTO analytics_requests (user_id, inn, response_data, created_at) VALUES (?, ?, ?, NOW())");
                $responseData = json_encode([
                    'company' => $companyData,
                    'finance' => $financeData,
                    'enforcements' => $enforcementsData,
                    'lawsuits' => $lawsuitsData,
                    'finscore' => $finscoreResult,
                ], JSON_UNESCAPED_UNICODE);
                $stmt->execute([$_SESSION['user_id'], $inn, $responseData]);
            }
        } catch (Exception $e) {
            $error = "Ошибка при получении данных: " . $e->getMessage();
        }
    }
}

// Функция для получения CSS-кружков статуса (исправленная логика)
function getStatusCircle($value, $type = 'default') {
    if ($value === null || $value === '' || $value === '-') {
        return '<span class="status-circle gray" title="Неизвестно"></span>';
    }
    
    // Логика из бота: getEmojiCircle("🔴", "🟢", "🟡", $condition)
    // В боте: красный для проблем, зеленый для нормы, желтый для предупреждений
    
    if ($type === 'boolean_negative') {
        // true = проблема (красный), false = норма (зеленый)
        return $value ? '<span class="status-circle red" title="Проблема"></span>' : '<span class="status-circle green" title="Норма"></span>';
    }
    
    if ($type === 'boolean_positive') {
        // true = норма (зеленый), false = проблема (красный)
        return $value ? '<span class="status-circle green" title="Норма"></span>' : '<span class="status-circle red" title="Проблема"></span>';
    }
    
    if ($type === 'tax_debt') {
        // Налоговая задолженность: есть = желтый, нет = зеленый
        return $value > 0 ? '<span class="status-circle yellow" title="Задолженность"></span>' : '<span class="status-circle green" title="Нет задолженности"></span>';
    }
    
    if ($type === 'enforcement_debt') {
        // Исполнительные производства: есть = красный, нет = зеленый
        return $value > 0 ? '<span class="status-circle red" title="Задолженность"></span>' : '<span class="status-circle green" title="Нет задолженности"></span>';
    }
    
    if ($type === 'numeric_positive') {
        // Числовые значения: >0 = зеленый, <=0 = желтый (для выручки, прибыли)
        return $value > 0 ? '<span class="status-circle green" title="Норма"></span>' : '<span class="status-circle yellow" title="Нет данных/нулевые"></span>';
    }
    
    if ($type === 'numeric_negative') {
        // Числовые значения: >0 = желтый (для количества дел, исков)
        return $value > 0 ? '<span class="status-circle yellow" title="Требует внимания"></span>' : '<span class="status-circle green" title="Норма"></span>';
    }
    
    if ($type === 'company_age') {
        // Возраст компании: <1 года = желтый, >=1 года = зеленый
        return $value < 1 ? '<span class="status-circle yellow" title="Молодая компания"></span>' : '<span class="status-circle green" title="Норма"></span>';
    }
    
    // По умолчанию - зеленый
    return '<span class="status-circle green" title="Норма"></span>';
}

// Функция для форматирования чисел
function formatNumberWeb($number) {
    if (!$number || $number == 0) return '0';
    return number_format($number, 0, '', ' ');
}

// Функция для форматирования даты
function formatDateWeb($date) {
    if (!$date) return '-';
    return date('d.m.Y', strtotime($date));
}
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1 class="h3 mb-0">Аналитика по ИНН</h1>
            <p class="text-muted mb-0">Получение аналитики по ИНН компании</p>
        </div>
    </div>
</div>

<style>
.status-circle {
    display: inline-block;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    margin-right: 8px;
}

.status-circle.green {
    background-color: #28a745;
    box-shadow: 0 0 0 2px rgba(40, 167, 69, 0.2);
}

.status-circle.yellow {
    background-color: #ffc107;
    box-shadow: 0 0 0 2px rgba(255, 193, 7, 0.2);
}

.status-circle.red {
    background-color: #dc3545;
    box-shadow: 0 0 0 2px rgba(220, 53, 69, 0.2);
}

.status-circle.gray {
    background-color: #6c757d;
    box-shadow: 0 0 0 2px rgba(108, 117, 125, 0.2);
}

.table-sm td {
    padding: 8px 4px;
    vertical-align: middle;
}

.analytics-table tr:hover {
    background-color: #f8f9fa;
}

.analytics-section {
    margin-bottom: 1.5rem;
}

.analytics-section h6 {
    color: #495057;
    border-bottom: 2px solid #e9ecef;
    padding-bottom: 0.5rem;
    margin-bottom: 1rem;
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

    .card .card-body,
    .card .card-header {
        padding: 1rem;
    }

    .form-label {
        font-size: 0.85rem;
    }

    .form-control {
        font-size: 0.9rem;
    }

    .btn {
        font-size: 0.9rem;
        padding: 0.45rem 0.75rem;
    }

    .table-sm td {
        padding: 6px 4px;
        font-size: 0.85rem;
    }
}
</style>

<div class="row">
    <div class="col-lg-4 mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Поиск компании</h5>
            </div>
            <div class="card-body">
                <form method="POST" id="analyticsForm">
                    <div class="mb-3">
                        <label class="form-label fw-bold">ИНН компании</label>
                        <input type="text" class="form-control" name="inn" 
                               value="<?= htmlspecialchars($inn) ?>" 
                               placeholder="Введите 10 или 12 цифр" 
                               maxlength="12"
                               required>
                        <div class="form-text">Введите ИНН компании для получения аналитики</div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary w-100 py-2">
                        <i class="bi bi-search me-2"></i> Получить аналитику
                    </button>
                </form>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger mt-3">
                        <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Блок с подсказками -->
        <div class="card mt-4">
            <div class="card-header">
                <h6 class="card-title mb-0">Легенда статусов для банков</h6>
            </div>
            <div class="card-body">
                <div class="d-flex align-items-center mb-2">
                    <span class="status-circle green me-2"></span>
                    <small class="text-muted">Нормальная ситуация</small>
                </div>
                <div class="d-flex align-items-center mb-2">
                    <span class="status-circle yellow me-2"></span>
                    <small class="text-muted">Требует внимания</small>
                </div>
                <div class="d-flex align-items-center mb-2">
                    <span class="status-circle red me-2"></span>
                    <small class="text-muted">Критическая ситуация</small>
                </div>
                <div class="d-flex align-items-center">
                    <span class="status-circle gray me-2"></span>
                    <small class="text-muted">Неизвестно/нет данных</small>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-lg-8">
        <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error) && $companyData): ?>
            <?php if (is_array($finscoreResult)): ?>
            <div class="card mb-4 border-0" style="background:linear-gradient(135deg,#f4f7fb,#e8f0ea);">
                <div class="card-body">
                    <div class="d-flex flex-wrap align-items-center gap-3">
                        <div class="text-center" style="min-width:88px;">
                            <div class="fw-bold" style="font-size:2rem;color:<?= htmlspecialchars((string) $finscoreResult['grade_color']) ?>;">
                                <?= htmlspecialchars((string) $finscoreResult['grade']) ?>
                            </div>
                            <div class="text-muted small"><?= (int) $finscoreResult['score'] ?>/100</div>
                        </div>
                        <div class="flex-grow-1">
                            <h5 class="mb-1">FinScore · <?= htmlspecialchars((string) $finscoreResult['grade_label']) ?></h5>
                            <div class="fw-semibold mb-1">
                                <?php if (!empty($finscoreResult['individual_only'])): ?>
                                    Лимит БГ: индивидуально
                                <?php else: ?>
                                    Ориентир БГ: <?= number_format((float) ($finscoreResult['limits']['bg']['value'] ?? 0), 0, '', ' ') ?> ₽
                                    <span class="text-muted fw-normal">
                                        (<?= number_format((float) ($finscoreResult['limits']['bg']['low'] ?? 0), 0, '', ' ') ?>
                                        –
                                        <?= number_format((float) ($finscoreResult['limits']['bg']['high'] ?? 0), 0, '', ' ') ?>)
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="text-muted small"><?= htmlspecialchars((string) ($finscoreResult['recommendation'] ?? '')) ?></div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            <!-- Основная информация о компании -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-building me-2"></i>Основная информация
                        <?php if (!empty($companyData['data']['НаимПолн'])): ?>
                            <small class="text-muted">- <?= htmlspecialchars($companyData['data']['НаимПолн']) ?></small>
                        <?php endif; ?>
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($companyData['data'])): ?>
                        <?php $d = $companyData['data']; ?>
                        <?php $now = new DateTime(); ?>
                        <?php $regDate = isset($d['ДатаРег']) ? new DateTime($d['ДатаРег']) : null; ?>
                        <?php $interval = $regDate ? $regDate->diff($now)->y : 0; ?>
                        <?php $taxDebt = (float) ($d['Налоги']['СумНедоим'] ?? 0); ?>
                        
                        <div class="analytics-section">
                            <h6>Общие сведения</h6>
                            <div class="table-responsive">
                                <table class="table table-sm analytics-table">
                                    <tbody>
                                        <tr>
                                            <td width="40" style="border: none;"><?= getStatusCircle($d['НаимПолн'] ?? '', 'boolean_positive') ?></td>
                                            <td style="border: none;"><strong>Полное наименование:</strong></td>
                                            <td style="border: none;"><?= htmlspecialchars($d['НаимПолн'] ?? '-') ?></td>
                                        </tr>
                                        <tr>
                                            <td style="border: none;"><?= getStatusCircle($interval, 'company_age') ?></td>
                                            <td style="border: none;"><strong>Дата регистрации:</strong></td>
                                            <td style="border: none;"><?= formatDateWeb($d['ДатаРег'] ?? '') ?> (<?= $interval ?> лет)</td>
                                        </tr>
                                        <tr>
                                            <td style="border: none;"><?= getStatusCircle($d['Регион']['Наим'] ?? '', 'boolean_positive') ?></td>
                                            <td style="border: none;"><strong>Регион:</strong></td>
                                            <td style="border: none;"><?= htmlspecialchars($d['Регион']['Наим'] ?? '-') ?></td>
                                        </tr>
                                        <tr>
                                            <td style="border: none;"><?= getStatusCircle($d['ЮрАдрес']['Недост'] ?? '', 'boolean_negative') ?></td>
                                            <td style="border: none;"><strong>Юридический адрес недостоверен:</strong></td>
                                            <td style="border: none;">
                                                <?= $d['ЮрАдрес']['Недост'] ? 'Да (' . htmlspecialchars($d['ЮрАдрес']['НедостОпис'] ?? '') . ')' : 'Нет' ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="border: none;"><?= getStatusCircle($taxDebt, 'tax_debt') ?></td>
                                            <td style="border: none;"><strong>Задолженность по налогам:</strong></td>
                                            <td style="border: none;"><?= formatNumberWeb($taxDebt) ?> руб. (на <?= $d['Налоги']['НедоимДата'] ?? '-' ?>)</td>
                                        </tr>
                                        <tr>
                                            <td style="border: none;"><?= getStatusCircle($d['РМСП']['Кат'] ?? '', 'boolean_positive') ?></td>
                                            <td style="border: none;"><strong>Субъект МСП:</strong></td>
                                            <td style="border: none;"><?= htmlspecialchars($d['РМСП']['Кат'] ?? '-') ?></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="analytics-section">
                            <h6>Риски и нарушения</h6>
                            <div class="table-responsive">
                                <table class="table table-sm analytics-table">
                                    <tbody>
                                        <tr>
                                            <td width="40" style="border: none;"><?= getStatusCircle(!empty($d['ПоддержМСП'][0]['Наруш']) ?? '', 'boolean_negative') ?></td>
                                            <td style="border: none;"><strong>Нарушения требований МСП:</strong></td>
                                            <td style="border: none;"><?= !empty($d['ПоддержМСП'][0]['Наруш']) ? 'Да' : 'Нет' ?></td>
                                        </tr>
                                        <tr>
                                            <td style="border: none;"><?= getStatusCircle($d['НедобПост'] ?? '', 'boolean_negative') ?></td>
                                            <td style="border: none;"><strong>В реестре недобросовестных поставщиков:</strong></td>
                                            <td style="border: none;"><?= $d['НедобПост'] ? 'Да' : 'Нет' ?></td>
                                        </tr>
                                        <tr>
                                            <td style="border: none;"><?= getStatusCircle($d['ДисквЛица'] ?? '', 'boolean_negative') ?></td>
                                            <td style="border: none;"><strong>Есть дисквалифицированные лица:</strong></td>
                                            <td style="border: none;"><?= $d['ДисквЛица'] ? 'Да' : 'Нет' ?></td>
                                        </tr>
                                        <tr>
                                            <td style="border: none;"><?= getStatusCircle($d['МассРуковод'] ?? '', 'boolean_negative') ?></td>
                                            <td style="border: none;"><strong>Массовые руководители:</strong></td>
                                            <td style="border: none;"><?= $d['МассРуковод'] ? 'Да' : 'Нет' ?></td>
                                        </tr>
                                        <tr>
                                            <td style="border: none;"><?= getStatusCircle($d['МассУчред'] ?? '', 'boolean_negative') ?></td>
                                            <td style="border: none;"><strong>Массовые учредители:</strong></td>
                                            <td style="border: none;"><?= $d['МассУчред'] ? 'Да' : 'Нет' ?></td>
                                        </tr>
                                        <tr>
                                            <td style="border: none;"><?= getStatusCircle($d['НелегалФин'] ?? '', 'boolean_negative') ?></td>
                                            <td style="border: none;"><strong>Финансовая нелегалка:</strong></td>
                                            <td style="border: none;"><?= $d['НелегалФин'] ? 'Да (' . ($d['НелегалФинСтатус'] ?? '') . ')' : 'Нет' ?></td>
                                        </tr>
                                        <tr>
                                            <td style="border: none;"><?= getStatusCircle($d['Санкции'] ?? '', 'boolean_negative') ?></td>
                                            <td style="border: none;"><strong>Санкции:</strong></td>
                                            <td style="border: none;"><?= $d['Санкции'] ? 'Да' : 'Нет' ?></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle me-2"></i>Данные о компании не найдены
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Финансовые показатели -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-graph-up me-2"></i>Финансовые показатели
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($financeData['data'])): ?>
                        <?php $f = $financeData['data']; ?>
                        <?php 
                        $rev24 = (int) ($f[2024]['2110'] ?? 0);
                        $profit24 = (int) ($f[2024]['2400'] ?? 0);
                        $rev25 = (int) ($f[2025]['2110'] ?? 0);
                        $profit25 = (int) ($f[2025]['2400'] ?? 0);
                        ?>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <h6>2024 год</h6>
                                <table class="table table-sm analytics-table">
                                    <tbody>
                                        <tr>
                                            <td width="40" style="border: none;"><?= getStatusCircle($rev24, 'numeric_positive') ?></td>
                                            <td style="border: none;"><strong>Выручка:</strong></td>
                                            <td style="border: none;"><?= formatNumberWeb($rev24) ?> руб.</td>
                                        </tr>
                                        <tr>
                                            <td style="border: none;"><?= getStatusCircle($profit24, 'numeric_positive') ?></td>
                                            <td style="border: none;"><strong>Чистая прибыль:</strong></td>
                                            <td style="border: none;"><?= formatNumberWeb($profit24) ?> руб.</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <h6>2025 год</h6>
                                <table class="table table-sm analytics-table">
                                    <tbody>
                                        <tr>
                                            <td width="40" style="border: none;"><?= getStatusCircle($rev25, 'numeric_positive') ?></td>
                                            <td style="border: none;"><strong>Выручка:</strong></td>
                                            <td style="border: none;"><?= formatNumberWeb($rev25) ?> руб.</td>
                                        </tr>
                                        <tr>
                                            <td style="border: none;"><?= getStatusCircle($profit25, 'numeric_positive') ?></td>
                                            <td style="border: none;"><strong>Чистая прибыль:</strong></td>
                                            <td style="border: none;"><?= formatNumberWeb($profit25) ?> руб.</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>Финансовая отчетность не найдена
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Исполнительные производства -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-shield-exclamation me-2"></i>Исполнительные производства
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($enforcementsData['data'])): ?>
                        <?php $e = $enforcementsData['data']; ?>
                        <?php $debt = (int) ($e['ОстЗадолж'] ?? 0); ?>
                        
                        <table class="table table-sm analytics-table">
                            <tbody>
                                <tr>
                                    <td width="40" style="border: none;"><?= getStatusCircle($debt, 'enforcement_debt') ?></td>
                                    <td style="border: none;"><strong>Остаток задолженности:</strong></td>
                                    <td style="border: none;"><?= formatNumberWeb($debt) ?> руб.</td>
                                </tr>
                                <tr>
                                    <td style="border: none;"><?= getStatusCircle($e['КолвоИП'] ?? 0, 'numeric_negative') ?></td>
                                    <td style="border: none;"><strong>Количество ИП:</strong></td>
                                    <td style="border: none;"><?= $e['КолвоИП'] ?? 0 ?></td>
                                </tr>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="alert alert-success">
                            <i class="bi bi-check-circle me-2"></i>Исполнительные производства не найдены
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Арбитражные дела -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-journal-text me-2"></i>Арбитражные дела
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($lawsuitsData['data'])): ?>
                        <?php $l = $lawsuitsData['data']; ?>
                        <?php $count = (int) ($l['ЗапВсего'] ?? 0); ?>
                        <?php $claimSum = (int) ($l['СуммИск'] ?? 0); ?>
                        
                        <table class="table table-sm analytics-table">
                            <tbody>
                                <tr>
                                    <td width="40" style="border: none;"><?= getStatusCircle($count, 'numeric_negative') ?></td>
                                    <td style="border: none;"><strong>Общее количество дел:</strong></td>
                                    <td style="border: none;"><?= $count ?></td>
                                </tr>
                                <tr>
                                    <td style="border: none;"><?= getStatusCircle($claimSum, 'numeric_negative') ?></td>
                                    <td style="border: none;"><strong>Сумма исковых требований:</strong></td>
                                    <td style="border: none;"><?= formatNumberWeb($claimSum) ?> руб.</td>
                                </tr>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="alert alert-success">
                            <i class="bi bi-check-circle me-2"></i>Арбитражные дела не найдены
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Кнопка для нового поиска -->
            <div class="text-center mb-4">
                <button type="button" class="btn btn-outline-primary" onclick="document.getElementById('analyticsForm').scrollIntoView()">
                    <i class="bi bi-search me-2"></i>Новый поиск
                </button>
            </div>
            
        <?php elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error) && empty($companyData)): ?>
            <div class="card">
                <div class="card-body text-center py-5">
                    <i class="bi bi-search fs-1 text-muted d-block mb-3"></i>
                    <h5 class="text-muted">Компания не найдена</h5>
                    <p class="text-muted">Попробуйте проверить правильность ИНН</p>
                    <button type="button" class="btn btn-primary mt-3" onclick="document.getElementById('analyticsForm').scrollIntoView()">
                        <i class="bi bi-arrow-repeat me-2"></i>Попробовать снова
                    </button>
                </div>
            </div>
        <?php else: ?>
            <div class="card">
                <div class="card-body text-center py-5">
                    <i class="bi bi-building fs-1 text-muted d-block mb-3"></i>
                    <h5 class="text-muted">Введите ИНН для получения аналитики</h5>
                    <p class="text-muted">Система проверит компанию по базам данных и предоставит подробную аналитику</p>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Автофокус на поле ввода ИНН
document.addEventListener('DOMContentLoaded', function() {
    document.querySelector('input[name="inn"]').focus();
});

// Ограничение ввода только цифр
document.querySelector('input[name="inn"]').addEventListener('input', function(e) {
    this.value = this.value.replace(/[^\d]/g, '');
});
</script>

<?php require_once 'footer.php'; ?>