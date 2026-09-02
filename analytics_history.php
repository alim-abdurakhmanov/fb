<?php 
require_once 'header.php';

$pdo = getPDO();

// Получаем историю запросов текущего пользователя
$stmt = $pdo->prepare("
    SELECT ar.*, u.first_name, u.last_name 
    FROM analytics_requests ar 
    LEFT JOIN users u ON ar.user_id = u.id 
    WHERE ar.user_id = ? 
    ORDER BY ar.created_at DESC 
    LIMIT 20
");
$stmt->execute([$_SESSION['user_id']]);
$history = $stmt->fetchAll();
?>

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1 class="h3 mb-0">История запросов аналитики</h1>
            <p class="text-muted mb-0">История ваших запросов аналитики по ИНН</p>
        </div>
        <div class="col-auto">
            <a href="analytics.php" class="btn btn-primary">
                <i class="bi bi-plus-circle me-2"></i>Новый запрос
            </a>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h5 class="card-title mb-0">Последние запросы</h5>
    </div>
    <div class="card-body">
        <?php if (empty($history)): ?>
            <div class="text-center py-4">
                <i class="bi bi-clock-history fs-1 text-muted d-block mb-3"></i>
                <h5 class="text-muted">История запросов пуста</h5>
                <p class="text-muted">Вы еще не делали запросов аналитики</p>
                <a href="analytics.php" class="btn btn-primary mt-3">
                    <i class="bi bi-search me-2"></i>Сделать первый запрос
                </a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>ИНН</th>
                            <th>Дата запроса</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $item): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($item['inn']) ?></strong>
                                </td>
                                <td>
                                    <?= date('d.m.Y H:i', strtotime($item['created_at'])) ?>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-outline-primary" 
                                            onclick="viewAnalyticsResult(<?= $item['id'] ?>)">
                                        <i class="bi bi-eye me-1"></i>Просмотреть
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function viewAnalyticsResult(requestId) {
    // Здесь можно реализовать просмотр сохраненных результатов
    alert('Просмотр результата запроса #' + requestId + ' - функционал в разработке');
}
</script>

<?php require_once 'footer.php'; ?>