<?php
require_once 'config.php';

header('Content-Type: text/html; charset=utf-8');

if (!isset($_SESSION['user_id']) || !finbuild_is_manager((string) ($_SESSION['role'] ?? 'client'))) {
    echo '<div class="alert alert-danger">Доступ запрещен</div>';
    exit;
}

$applicationId = intval($_GET['application_id'] ?? 0);
$productType = $_GET['product_type'] ?? '';

if (!$applicationId || !in_array($productType, ['bg', 'credit'])) {
    echo '<div class="alert alert-danger">Неверные параметры</div>';
    exit;
}

// Получаем данные заявки для фильтрации
$pdo = getPDO();
$stmt = $pdo->prepare("SELECT * FROM applications WHERE id = ?");
$stmt->execute([$applicationId]);
$application = $stmt->fetch();

if (!$application) {
    echo '<div class="alert alert-danger">Заявка не найдена</div>';
    exit;
}

// Получаем уже добавленные продукты к этой заявке
$stmt = $pdo->prepare("SELECT product_id, product_type FROM application_products WHERE application_id = ?");
$stmt->execute([$applicationId]);
$addedProducts = $stmt->fetchAll();

// Создаем массив ID уже добавленных продуктов данного типа
$addedProductIds = [];
foreach ($addedProducts as $addedProduct) {
    if ($addedProduct['product_type'] === $productType) {
        $addedProductIds[] = $addedProduct['product_id'];
    }
}

// Ищем подходящие продукты, исключая уже добавленные
if ($productType === 'bg') {
    $sql = "SELECT * FROM bank_products WHERE 1=1";
    $params = [];
    
    // Исключаем уже добавленные продукты
    if (!empty($addedProductIds)) {
        $placeholders = str_repeat('?,', count($addedProductIds) - 1) . '?';
        $sql .= " AND id NOT IN ($placeholders)";
        $params = array_merge($params, $addedProductIds);
    }
    
    // Фильтруем по сумме (если указана в заявке)
    if ($application['amount'] > 0) {
        $sql .= " AND (max_amount >= ? OR max_amount IS NULL)";
        $params[] = $application['amount'];
    }
    
    // Фильтруем по сроку (если указан в заявке)
    if ($application['term'] > 0) {
        $sql .= " AND (max_term >= ? OR max_term IS NULL)";
        $params[] = $application['term'];
    }
    
    // Фильтруем по виду БГ (если указан в заявке) - работа с JSON полем
    if (!empty($application['guarantee_type'])) {
        $sql .= " AND (JSON_CONTAINS(bg_types, ?) OR bg_types IS NULL OR bg_types = '[]')";
        $params[] = json_encode($application['guarantee_type']);
    }
    
    // Фильтруем по виду ФЗ (если указан в заявке) - работа с JSON полем
    if (!empty($application['fz_type'])) {
        $sql .= " AND (JSON_CONTAINS(fz_types, ?) OR fz_types IS NULL OR fz_types = '[]')";
        $params[] = json_encode($application['fz_type']);
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll();
    
    // Группируем продукты по банку и выбираем минимально подходящую сумму
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
    
} else {
    // Логика для кредитов (без изменений)
    $sql = "SELECT * FROM credit_products WHERE 1=1";
    $params = [];
    
    // Исключаем уже добавленные продукты
    if (!empty($addedProductIds)) {
        $placeholders = str_repeat('?,', count($addedProductIds) - 1) . '?';
        $sql .= " AND id NOT IN ($placeholders)";
        $params = array_merge($params, $addedProductIds);
    }
    
    // Фильтруем по сумме (если указана в заявке)
    if ($application['amount'] > 0) {
        $sql .= " AND (amount >= ? OR amount IS NULL)";
        $params[] = $application['amount'];
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll();
}

if (empty($products)) {
    if (!empty($addedProductIds)) {
        echo '<div class="alert alert-info">Все доступные продукты уже добавлены к заявке</div>';
    } else {
        echo '<div class="alert alert-info">Подходящие продукты не найдены</div>';
    }
    exit;
}

// Функция для форматирования JSON массива в читаемый вид
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
?>

<div class="row">
    <?php foreach ($products as $product): ?>
    <div class="col-md-6 mb-4">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><?= htmlspecialchars($product['bank_name']) ?></h6>
                <span class="badge bg-primary"><?= $productType === 'bg' ? 'БГ' : 'Кредит' ?></span>
            </div>
            <div class="card-body">
                <h5 class="card-title"><?= htmlspecialchars($product['name']) ?></h5>
                
                <?php if ($productType === 'bg'): ?>
                    <p class="card-text">
                        <small class="text-muted">Макс. сумма:</small> 
                        <strong><?= $product['max_amount'] ? number_format($product['max_amount'], 0, '', ' ') . ' ₽' : 'не ограничена' ?></strong>
                    </p>
                    <p class="card-text">
                        <small class="text-muted">Макс. срок:</small> 
                        <strong><?= $product['max_term'] ? $product['max_term'] . ' мес.' : 'не ограничен' ?></strong>
                    </p>
                    <?php if ($product['bg_types'] && $product['bg_types'] !== '[]'): ?>
                    <p class="card-text">
                        <small class="text-muted">Виды БГ:</small> 
                        <strong><?= htmlspecialchars(formatJsonArray($product['bg_types'])) ?></strong>
                    </p>
                    <?php endif; ?>
                    <?php if ($product['fz_types'] && $product['fz_types'] !== '[]'): ?>
                    <p class="card-text">
                        <small class="text-muted">Виды ФЗ:</small> 
                        <strong><?= htmlspecialchars(formatJsonArray($product['fz_types'])) ?></strong>
                    </p>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="card-text">
                        <small class="text-muted">Сумма:</small> 
                        <strong><?= $product['amount'] ? number_format($product['amount'], 0, '', ' ') . ' ₽' : 'не указана' ?></strong>
                    </p>
                    <p class="card-text">
                        <small class="text-muted">Срок:</small> 
                        <strong><?= htmlspecialchars($product['term'] ?? 'не указан') ?></strong>
                    </p>
                    <p class="card-text">
                        <small class="text-muted">Ставка:</small> 
                        <strong><?= htmlspecialchars($product['interest_rate'] ?? 'не указана') ?></strong>
                    </p>
                <?php endif; ?>
                
                <?php if ($product['features']): ?>
                    <p class="card-text">
                        <small class="text-muted">Особенности:</small><br>
                        <?= htmlspecialchars($product['features']) ?>
                    </p>
                <?php endif; ?>
            </div>
            <div class="card-footer bg-transparent">
                <div class="btn-group w-100">
                    <button type="button" class="btn btn-outline-primary btn-sm" 
                            onclick="showProductDetails(<?= $product['id'] ?>, '<?= $productType ?>')">
                        <i class="bi bi-info-circle me-1"></i> Подробнее
                    </button>
                    <button type="button" class="btn btn-success btn-sm" 
                           onclick="addProductToApplication(<?= $product['id'] ?>, '<?= $productType ?>', this)">
                        <i class="bi bi-plus-circle me-1"></i> Добавить
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
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
function showProductDetails(productId, productType) {
    fetch('api_product_details.php?product_id=' + productId + '&product_type=' + productType)
        .then(response => response.text())
        .then(html => {
            document.getElementById('productDetailsContent').innerHTML = html;
            new bootstrap.Modal(document.getElementById('productDetailsModal')).show();
        })
        .catch(error => {
            document.getElementById('productDetailsContent').innerHTML = '<div class="alert alert-danger">Ошибка загрузки деталей продукта</div>';
            new bootstrap.Modal(document.getElementById('productDetailsModal')).show();
        });
}
</script>