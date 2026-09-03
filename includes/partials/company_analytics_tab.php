<?php
/**
 * Блок «Аналитика по компании» (вкладка в заявке).
 * Рендер и загрузка — assets/js/company_analytics.js
 *
 * @var array $application
 * @var bool  $analyticsReadOnly без кнопок обновления (по умолчанию false)
 */
$analyticsReadOnly = $analyticsReadOnly ?? false;
$companyName = htmlspecialchars((string) ($application['company_name'] ?? ''));
$companyInn = htmlspecialchars((string) ($application['inn'] ?? ''));
?>
<div class="company-analytics-panel">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <h4 class="mb-0">Аналитика по компании</h4>
        <?php if (!$analyticsReadOnly): ?>
        <div>
            <button type="button" class="btn btn-primary" onclick="loadAnalytics(true)" id="load-analytics-btn">
                <i class="bi bi-arrow-clockwise me-2"></i>Обновить FinScore
            </button>
        </div>
        <?php endif; ?>
    </div>

    <div id="analytics-info" class="mb-3">
        <div class="card">
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h5 class="mb-0">
                            <i class="bi bi-building me-2 text-primary"></i>
                            <span id="company-name"><?= $companyName ?></span>
                        </h5>
                        <div class="text-muted">ИНН: <span id="company-inn"><?= $companyInn ?></span></div>
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
        <p class="text-muted">Загрузка аналитики...</p>
    </div>

    <div id="analytics-error" class="alert alert-danger" style="display: none;">
        <i class="bi bi-exclamation-triangle me-2"></i>
        <span id="error-message"></span>
    </div>

    <div id="analytics-content" style="display: none;"></div>

    <div id="analytics-empty" class="text-center py-5">
        <i class="bi bi-graph-up fs-1 text-muted d-block mb-3"></i>
        <h5 class="text-muted">Аналитика не загружена</h5>
        <?php if ($analyticsReadOnly): ?>
            <p class="text-muted">Данные ещё не были загружены менеджером платформы</p>
        <?php else: ?>
            <p class="text-muted">Нажмите кнопку ниже для получения аналитики по компании</p>
            <button type="button" class="btn btn-primary mt-3" onclick="loadAnalytics(true)">
                <i class="bi bi-search me-2"></i>Получить аналитику
            </button>
        <?php endif; ?>
    </div>
</div>
