<?php
require_once 'config.php';

header('Content-Type: text/html; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo '<div class="alert alert-danger">Доступ запрещен</div>';
    exit;
}

$productId = intval($_GET['product_id'] ?? 0);
$productType = $_GET['product_type'] ?? '';

if (!$productId || !in_array($productType, ['bg', 'credit'])) {
    echo '<div class="alert alert-danger">Неверные параметры</div>';
    exit;
}

$pdo = getPDO();

if ($productType === 'bg') {
    $stmt = $pdo->prepare("SELECT * FROM bank_products WHERE id = ?");
} else {
    $stmt = $pdo->prepare("SELECT * FROM credit_products WHERE id = ?");
}
$stmt->execute([$productId]);
$product = $stmt->fetch();

if (!$product) {
    echo '<div class="alert alert-danger">Продукт не найден</div>';
    exit;
}
?>

<div class="product-details">
    <div class="row">
        <div class="col-md-6">
            <h4><?= htmlspecialchars($product['name']) ?></h4>
            <p class="text-muted"><?= htmlspecialchars($product['bank_name']) ?></p>
            
            <div class="mb-3">
                <strong>Тип продукта:</strong> 
                <?= $productType === 'bg' ? 'Банковская гарантия' : 'Кредит для бизнеса' ?>
            </div>
            
            <?php if ($productType === 'bg'): ?>
                <div class="mb-2">
                    <strong>Максимальная сумма:</strong> 
                    <?= $product['max_amount'] ? number_format($product['max_amount'], 0, '', ' ') . ' ₽' : 'не ограничена' ?>
                </div>
                <div class="mb-2">
                    <strong>Максимальный срок:</strong> 
                    <?= $product['max_term'] ? $product['max_term'] . ' месяцев' : 'не ограничен' ?>
                </div>
            <?php else: ?>
                <div class="mb-2">
                    <strong>Сумма:</strong> 
                    <?= $product['amount'] ? number_format($product['amount'], 0, '', ' ') . ' ₽' : 'не указана' ?>
                </div>
                <div class="mb-2">
                    <strong>Срок:</strong> 
                    <?= htmlspecialchars($product['term'] ?? 'не указан') ?>
                </div>
                <div class="mb-2">
                    <strong>Процентная ставка:</strong> 
                    <?= htmlspecialchars($product['interest_rate'] ?? 'не указана') ?>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="col-md-6">
            <?php if ($product['features']): ?>
                <h5>Особенности продукта</h5>
                <p><?= nl2br(htmlspecialchars($product['features'])) ?></p>
            <?php endif; ?>
            
            <?php if ($productType === 'bg'): ?>
                <div class="alert alert-info">
                    <h6><i class="bi bi-info-circle me-2"></i>О банковской гарантии</h6>
                    <p class="mb-0">Банковская гарантия - это обязательство банка выплатить определенную сумму в случае невыполнения условий контракта.</p>
                </div>
            <?php else: ?>
                <div class="alert alert-info">
                    <h6><i class="bi bi-info-circle me-2"></i>О кредите для бизнеса</h6>
                    <p class="mb-0">Кредит для бизнеса предоставляется на развитие компании, пополнение оборотных средств или инвестиционные цели.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>