<?php
/**
 * Вкладка «Оценка по методике» в ЛК банка.
 * Ожидает: $caseId (int)
 */
declare(strict_types=1);
$bmCaseId = (int) ($caseId ?? 0);
?>
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-3">
    <div>
        <h4 class="mb-1">Оценка по методике</h4>
        <p class="text-muted small mb-0">Внутрибанковская методика экспресс-БГ · отдельно от FinScore</p>
    </div>
</div>
<div id="bankMethodologyRoot"></div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof initBankMethodology === 'function') {
        initBankMethodology({
            bankCaseId: <?= $bmCaseId ?>,
            rootId: 'bankMethodologyRoot',
            tabId: 'bank-methodology-tab'
        });
    }
});
</script>
