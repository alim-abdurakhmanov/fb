<?php
/**
 * Вкладка «Банковская методика».
 * Ожидает: $applicationId (int)
 * Опционально: $bmTabId, $bmRootId
 */
declare(strict_types=1);

$bmApplicationId = (int) ($applicationId ?? ($bmApplicationId ?? 0));
$bmTabId = (string) ($bmTabId ?? 'bank-methodology-tab');
$bmRootId = (string) ($bmRootId ?? 'bankMethodologyRoot');
?>
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-3">
    <div>
        <h4 class="mb-1">Банковская методика</h4>
        <p class="text-muted small mb-0">Экспресс-оценка по внутренней шкале банка</p>
    </div>
</div>
<?php if ($bmApplicationId <= 0): ?>
    <div class="bm-empty text-muted">Не указана заявка.</div>
<?php else: ?>
<div id="<?= htmlspecialchars($bmRootId) ?>"></div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof initBankMethodology !== 'function') return;
    initBankMethodology({
        applicationId: <?= (int) $bmApplicationId ?>,
        rootId: <?= json_encode($bmRootId, JSON_UNESCAPED_UNICODE) ?>,
        tabId: <?= json_encode($bmTabId, JSON_UNESCAPED_UNICODE) ?>
    });
});
</script>
<?php endif; ?>
