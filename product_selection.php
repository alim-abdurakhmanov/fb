<?php 
$current_page = 'product_selection';
require_once 'header.php';

$pdo = getPDO();

// Обработка формы подбора
$products = [];
$searchParams = [];

// Функция для форматирования JSON массива в читаемый вид (такая же как в api_product_search.php)
function formatJsonArray($jsonString) {
    if (empty($jsonString) || $jsonString === '[]') {
        return '';
    }
    
    $array = json_decode($jsonString, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($array)) {
        return $jsonString;
    }
    
    return implode(', ', $array);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $productType = $_POST['product_type'] ?? '';
    $searchParams = $_POST;
    
    if ($productType === 'bg') {
        // Подбор банковских гарантий с полной логикой фильтрации
        $bgType = $_POST['bg_type'] ?? '';
        $fzType = $_POST['fz_type'] ?? '';
        $amount = floatval($_POST['amount'] ?? 0);
        $term = intval($_POST['term'] ?? 0);
        
        $sql = "SELECT * FROM bank_products WHERE 1=1";
        $params = [];
        
        // Фильтруем по сумме (если указана)
        if ($amount > 0) {
            $sql .= " AND (max_amount >= ? OR max_amount IS NULL)";
            $params[] = $amount;
        }
        
        // Фильтруем по сроку (если указан)
        if ($term > 0) {
            $sql .= " AND (max_term >= ? OR max_term IS NULL)";
            $params[] = $term;
        }
        
        // Фильтруем по виду БГ (если указан) - работа с JSON полем
        if (!empty($bgType)) {
            $sql .= " AND (JSON_CONTAINS(bg_types, ?) OR bg_types IS NULL OR bg_types = '[]')";
            $params[] = json_encode($bgType);
        }
        
        // Фильтруем по виду ФЗ (если указан) - работа с JSON полем
        if (!empty($fzType)) {
            $sql .= " AND (JSON_CONTAINS(fz_types, ?) OR fz_types IS NULL OR fz_types = '[]')";
            $params[] = json_encode($fzType);
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $products = $stmt->fetchAll();
        
        // Группируем продукты по банку и выбираем минимально подходящую сумму
        // (такая же логика как в api_product_search.php)
        $groupedProducts = [];
        foreach ($products as $product) {
            $bankName = $product['bank_name'];
            
            if (!isset($groupedProducts[$bankName])) {
                $groupedProducts[$bankName] = $product;
            } else {
                // Выбираем продукт с минимальной подходящей суммой
                $currentAmount = $groupedProducts[$bankName]['max_amount'] ?? 0;
                $newAmount = $product['max_amount'] ?? 0;
                
                if ($newAmount > 0 && ($currentAmount == 0 || $newAmount < $currentAmount)) {
                    $groupedProducts[$bankName] = $product;
                }
            }
        }
        
        $products = array_values($groupedProducts);
        
    } elseif ($productType === 'credit') {
        // Подбор кредитов с полной логикой фильтрации
        $amount = floatval($_POST['credit_amount'] ?? 0);
        $term = intval($_POST['credit_term'] ?? 0);
        
        $sql = "SELECT * FROM credit_products WHERE 1=1";
        $params = [];
        
        if ($amount > 0) {
            $sql .= " AND (amount >= ? OR amount IS NULL)";
            $params[] = $amount;
        }
        
        if ($term > 0) {
            $sql .= " AND (term >= ? OR term IS NULL)";
            $params[] = $term;
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $products = $stmt->fetchAll();
    }
}
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1 class="h3 mb-0">Подбор продуктов</h1>
            <p class="text-muted mb-0">Подбор банковских гарантий и кредитов для бизнеса</p>
        </div>
    </div>
</div>

<style>
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

    .form-control,
    .form-select {
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
                <h5 class="card-title mb-0">Параметры подбора</h5>
            </div>
            <div class="card-body">
                <form method="POST" id="productForm">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Тип продукта</label>
                        <select class="form-select" name="product_type" id="productType" required>
                            <option value="">Выберите тип продукта</option>
                            <option value="bg" <?= ($searchParams['product_type'] ?? '') === 'bg' ? 'selected' : '' ?>>Банковская гарантия</option>
                            <option value="credit" <?= ($searchParams['product_type'] ?? '') === 'credit' ? 'selected' : '' ?>>Кредит для бизнеса</option>
                        </select>
                    </div>
                    
                    <!-- Поля для банковской гарантии -->
                    <div id="bgFields" style="display: none;">
                        <div class="mb-3">
                            <label class="form-label">Вид гарантии</label>
                            <select class="form-select" name="bg_type">
                                <option value="">Любой</option>
                                <option value="Участие" <?= ($searchParams['bg_type'] ?? '') === 'Участие' ? 'selected' : '' ?>>Участие</option>
                                <option value="Исполнение" <?= ($searchParams['bg_type'] ?? '') === 'Исполнение' ? 'selected' : '' ?>>Исполнение</option>
                                <option value="Исполнение с авансом" <?= ($searchParams['bg_type'] ?? '') === 'Исполнение с авансом' ? 'selected' : '' ?>>Исполнение с авансом</option>
                                <option value="Возврат аванса" <?= ($searchParams['bg_type'] ?? '') === 'Возврат аванса' ? 'selected' : '' ?>>Возврат аванса</option>
                                <option value="Гарантийный период" <?= ($searchParams['bg_type'] ?? '') === 'Гарантийный период' ? 'selected' : '' ?>>Гарантийный период</option>
                                <option value="Платежная" <?= ($searchParams['bg_type'] ?? '') === 'Платежная' ? 'selected' : '' ?>>Платежная</option>
                                <option value="НДС в пользу ФНС" <?= ($searchParams['bg_type'] ?? '') === 'НДС в пользу ФНС' ? 'selected' : '' ?>>НДС в пользу ФНС</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Федеральный закон</label>
                            <select class="form-select" name="fz_type">
                                <option value="">Любой</option>
                                <option value="44-ФЗ" <?= ($searchParams['fz_type'] ?? '') === '44-ФЗ' ? 'selected' : '' ?>>44-ФЗ</option>
                                <option value="223-ФЗ" <?= ($searchParams['fz_type'] ?? '') === '223-ФЗ' ? 'selected' : '' ?>>223-ФЗ</option>
                                <option value="185-ФЗ (615-ПП)" <?= ($searchParams['fz_type'] ?? '') === '185-ФЗ (615-ПП)' ? 'selected' : '' ?>>185-ФЗ (615-ПП)</option>
                                <option value="Коммерция" <?= ($searchParams['fz_type'] ?? '') === 'Коммерция' ? 'selected' : '' ?>>Коммерция</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Сумма (руб)</label>
                            <input type="number" class="form-control" name="amount" 
                                   value="<?= htmlspecialchars($searchParams['amount'] ?? '') ?>" 
                                   placeholder="Например: 1000000" min="0">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Срок (месяцев)</label>
                            <input type="number" class="form-control" name="term" 
                                   value="<?= htmlspecialchars($searchParams['term'] ?? '') ?>" 
                                   placeholder="Например: 12" min="0">
                        </div>
                    </div>
                    
                    <!-- Поля для кредита -->
                    <div id="creditFields" style="display: none;">
                        <div class="mb-3">
                            <label class="form-label">Сумма кредита (руб)</label>
                            <input type="number" class="form-control" name="credit_amount" 
                                   value="<?= htmlspecialchars($searchParams['credit_amount'] ?? '') ?>" 
                                   placeholder="Например: 5000000" min="0">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Срок кредита (месяцев)</label>
                            <input type="number" class="form-control" name="credit_term" 
                                   value="<?= htmlspecialchars($searchParams['credit_term'] ?? '') ?>" 
                                   placeholder="Например: 24" min="0">
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary w-100 py-2">
                        <i class="bi bi-search me-2"></i> Найти продукты
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-lg-8">
        <?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        Результаты подбора 
                        <span class="badge bg-primary ms-2"><?= count($products) ?> найдено</span>
                    </h5>
                    <?php if (!empty($products) && $productType === 'bg'): ?>
                        <div class="text-muted small mt-1">
                            <i class="bi bi-info-circle me-1"></i>
                            Показаны только подходящие под условия продукты от каждого банка 
                        </div>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (empty($products)): ?>
                        <div class="alert alert-warning text-center py-4">
                            <i class="bi bi-exclamation-triangle fs-1 d-block mb-3"></i>
                            <h5>Продукты не найдены</h5>
                            <p class="mb-0">Попробуйте изменить параметры поиска</p>
                        </div>
                    <?php else: ?>
                        <div class="row">
                            <?php foreach ($products as $product): ?>
                                <div class="col-md-6 mb-3">
                                    <div class="card h-100 product-card">
                                        <div class="card-header d-flex justify-content-between align-items-center">
                                            <h6 class="mb-0"><?= htmlspecialchars($product['bank_name']) ?></h6>
                                            <span class="badge bg-primary"><?= $productType === 'bg' ? 'БГ' : 'Кредит' ?></span>
                                        </div>
                                        <div class="card-body">
                                            <h6 class="card-title"><?= htmlspecialchars($product['name']) ?></h6>
                                            
                                            <?php if ($productType === 'bg'): ?>
                                                <div class="mb-2">
                                                    <small class="text-muted">Макс. сумма:</small> 
                                                    <div class="fw-medium">
                                                        <?= $product['max_amount'] ? number_format($product['max_amount'], 0, '', ' ') . ' ₽' : 'не ограничена' ?>
                                                    </div>
                                                </div>
                                                <div class="mb-2">
                                                    <small class="text-muted">Макс. срок:</small> 
                                                    <div class="fw-medium">
                                                        <?= $product['max_term'] ? $product['max_term'] . ' мес.' : 'не ограничен' ?>
                                                    </div>
                                                </div>
                                                <?php if ($product['bg_types'] && $product['bg_types'] !== '[]'): ?>
                                                <div class="mb-2">
                                                    <small class="text-muted">Виды БГ:</small> 
                                                    <div class="fw-medium small"><?= htmlspecialchars(formatJsonArray($product['bg_types'])) ?></div>
                                                </div>
                                                <?php endif; ?>
                                                <?php if ($product['fz_types'] && $product['fz_types'] !== '[]'): ?>
                                                <div class="mb-2">
                                                    <small class="text-muted">Виды ФЗ:</small> 
                                                    <div class="fw-medium small"><?= htmlspecialchars(formatJsonArray($product['fz_types'])) ?></div>
                                                </div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <div class="mb-2">
                                                    <small class="text-muted">Сумма:</small> 
                                                    <div class="fw-medium">
                                                        <?= $product['amount'] ? number_format($product['amount'], 0, '', ' ') . ' ₽' : 'не указана' ?>
                                                    </div>
                                                </div>
                                                <div class="mb-2">
                                                    <small class="text-muted">Срок:</small> 
                                                    <div class="fw-medium"><?= htmlspecialchars($product['term'] ?? 'не указан') ?></div>
                                                </div>
                                                <div class="mb-2">
                                                    <small class="text-muted">Ставка:</small> 
                                                    <div class="fw-medium"><?= htmlspecialchars($product['interest_rate'] ?? 'не указана') ?></div>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <?php if ($product['features']): ?>
                                                <div class="mt-3">
                                                    <small class="text-muted">Особенности:</small>
                                                    <div class="small mt-1"><?= htmlspecialchars($product['features']) ?></div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="card-footer bg-transparent">
                                            <div class="btn-group w-100">
                                                <button type="button" class="btn btn-outline-primary btn-sm" 
                                                        onclick="showProductDetails(<?= $product['id'] ?>, '<?= $productType ?>')">
                                                    <i class="bi bi-info-circle me-1"></i> Подробнее
                                                </button>
                                                <button type="button" class="btn btn-success btn-sm" 
                                                        onclick="createApplicationFromProduct(<?= $product['id'] ?>, '<?= $productType ?>')">
                                                    <i class="bi bi-send me-1"></i> Заявка
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <div class="card">
                <div class="card-body text-center py-5">
                    <i class="bi bi-search fs-1 text-muted d-block mb-3"></i>
                    <h5 class="text-muted">Заполните параметры для подбора продуктов</h5>
                    <p class="text-muted">Выберите тип продукта и укажите необходимые параметры</p>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Модальное окно деталей продукта -->
<div class="modal fade" id="productDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Детали продукта</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="productDetailsContent">
                <!-- Контент будет загружен через AJAX -->
            </div>
        </div>
    </div>
</div>

<script>
// Показ/скрытие полей в зависимости от типа продукта
document.getElementById('productType').addEventListener('change', function() {
    const productType = this.value;
    document.getElementById('bgFields').style.display = productType === 'bg' ? 'block' : 'none';
    document.getElementById('creditFields').style.display = productType === 'credit' ? 'block' : 'none';
});

// Инициализация при загрузке страницы
document.addEventListener('DOMContentLoaded', function() {
    const productType = document.getElementById('productType').value;
    document.getElementById('bgFields').style.display = productType === 'bg' ? 'block' : 'none';
    document.getElementById('creditFields').style.display = productType === 'credit' ? 'block' : 'none';
});

// Функция для отображения деталей продукта (как в api_product_search.php)
function showProductDetails(productId, productType) {
    fetch('api_product_details.php?product_id=' + productId + '&product_type=' + productType)
        .then(response => response.text())
        .then(html => {
            document.getElementById('productDetailsContent').innerHTML = html;
            const modal = new bootstrap.Modal(document.getElementById('productDetailsModal'));
            modal.show();
        })
        .catch(error => {
            document.getElementById('productDetailsContent').innerHTML = 
                '<div class="alert alert-danger">Ошибка загрузки деталей продукта</div>';
            const modal = new bootstrap.Modal(document.getElementById('productDetailsModal'));
            modal.show();
        });
}

function createApplicationFromProduct(productId, productType) {
    // Собираем параметры из формы для передачи в заявку
    const form = document.getElementById('productForm');
    const formData = new FormData(form);
    let params = `?product_id=${productId}&product_type=${productType}`;
    
    // Добавляем поисковые параметры для предзаполнения заявки
    if (productType === 'bg') {
        const amount = formData.get('amount');
        const term = formData.get('term');
        const bgType = formData.get('bg_type');
        const fzType = formData.get('fz_type');
        
        if (amount) params += `&amount=${amount}`;
        if (term) params += `&term=${term}`;
        if (bgType) params += `&guarantee_type=${encodeURIComponent(bgType)}`;
        if (fzType) params += `&fz_type=${encodeURIComponent(fzType)}`;
    } else {
        const creditAmount = formData.get('credit_amount');
        const creditTerm = formData.get('credit_term');
        
        if (creditAmount) params += `&amount=${creditAmount}`;
        if (creditTerm) params += `&term=${creditTerm}`;
    }
    
    if (confirm('Создать заявку на этот продукт?')) {
        window.location.href = 'create_application.php' + params;
    }
}
</script>

<?php require_once 'footer.php'; ?>