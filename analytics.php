<?php
$current_page = 'analytics';
require_once 'header.php';

$prefillInn = preg_replace('/\D+/', '', (string) ($_GET['inn'] ?? ''));
if (strlen($prefillInn) !== 10 && strlen($prefillInn) !== 12) {
    $prefillInn = '';
}
?>
<link rel="stylesheet" href="assets/css/company_analytics.css">

<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1 class="h3 mb-0">Аналитика по ИНН</h1>
            <p class="text-muted mb-0">Тот же FinScore и разбор компании, что во вкладке заявки</p>
        </div>
        <div class="col-auto">
            <a href="analytics_history.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-clock-history me-1"></i>История запросов
            </a>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-lg-4 mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Поиск компании</h5>
            </div>
            <div class="card-body">
                <form id="standalone-analytics-form" autocomplete="off">
                    <div class="mb-3">
                        <label class="form-label fw-bold" for="standalone-inn">ИНН компании</label>
                        <input type="text" class="form-control" id="standalone-inn" name="inn"
                               value="<?= htmlspecialchars($prefillInn) ?>"
                               placeholder="10 или 12 цифр"
                               maxlength="12"
                               inputmode="numeric"
                               required>
                        <div class="form-text">FinScore, лимит и факторы риска по открытым данным</div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100 py-2" id="standalone-analytics-submit">
                        <i class="bi bi-search me-2"></i>Получить аналитику
                    </button>
                </form>
            </div>
        </div>

        <div class="card mt-4">
            <div class="card-header">
                <h6 class="card-title mb-0">Легенда статусов</h6>
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
                    <small class="text-muted">Неизвестно / нет данных</small>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="company-analytics-panel">
            <div id="analytics-info" class="mb-3">
                <div class="card">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h5 class="mb-0">
                                    <i class="bi bi-building me-2 text-primary"></i>
                                    <span id="company-name">Компания не выбрана</span>
                                </h5>
                                <div class="text-muted">ИНН: <span id="company-inn"><?= $prefillInn !== '' ? htmlspecialchars($prefillInn) : '—' ?></span></div>
                            </div>
                            <div class="col-md-6 text-md-end">
                                <small class="text-muted" id="last-update">
                                    <i class="bi bi-clock me-1"></i>
                                    <span>Данные не загружены</span>
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div id="analytics-loading" class="text-center py-5" style="display: none;">
                <div class="spinner-border text-primary mb-3" role="status">
                    <span class="visually-hidden">Загрузка...</span>
                </div>
                <p class="text-muted">Считаем FinScore и загружаем данные Checko...</p>
            </div>

            <div id="analytics-error" class="alert alert-danger" style="display: none;">
                <i class="bi bi-exclamation-triangle me-2"></i>
                <span id="error-message"></span>
            </div>

            <div id="analytics-content" style="display: none;"></div>

            <div id="analytics-empty" class="text-center py-5">
                <i class="bi bi-graph-up fs-1 text-muted d-block mb-3"></i>
                <h5 class="text-muted">Введите ИНН слева</h5>
                <p class="text-muted mb-0">Результат будет таким же, как во вкладке «Аналитика» в заявке</p>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="assets/js/company_analytics.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof initCompanyAnalytics === 'function') {
        initCompanyAnalytics({
            mode: 'inn',
            formId: 'standalone-analytics-form',
            innInputId: 'standalone-inn',
            submitButtonId: 'standalone-analytics-submit',
            autoLoadInn: <?= json_encode($prefillInn) ?>
        });
    }
});
</script>

<?php require_once 'footer.php'; ?>
