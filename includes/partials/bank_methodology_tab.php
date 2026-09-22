<?php
/**
 * Вкладка «Оценка по методике».
 * Ожидает:
 * - $caseId (int) — один кейс (ЛК банка), или
 * - $bmCases (list) — список кейсов для выбора (карточка заявки у сотрудников)
 * Опционально: $bmTabId, $bmRootId
 */
declare(strict_types=1);

$bmCaseId = (int) ($caseId ?? ($bmCaseId ?? 0));
$bmCases = is_array($bmCases ?? null) ? $bmCases : [];
$bmTabId = (string) ($bmTabId ?? 'bank-methodology-tab');
$bmRootId = (string) ($bmRootId ?? 'bankMethodologyRoot');

if ($bmCaseId <= 0 && $bmCases !== []) {
    $bmCaseId = (int) ($bmCases[0]['id'] ?? 0);
}
?>
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-3">
    <div>
        <h4 class="mb-1">Оценка по методике</h4>
        <p class="text-muted small mb-0">Внутрибанковская методика экспресс-БГ · отдельно от FinScore</p>
    </div>
    <?php if (count($bmCases) > 1): ?>
        <div style="min-width: 240px;">
            <label class="form-label small text-muted mb-1">Кейс банка</label>
            <select class="form-select form-select-sm" id="<?= htmlspecialchars($bmRootId) ?>CaseSelect">
                <?php foreach ($bmCases as $c): ?>
                    <?php
                    $cid = (int) ($c['id'] ?? 0);
                    $label = trim((string) ($c['bank_name'] ?? '') . ' · ' . (string) ($c['product_name'] ?? ''));
                    if ($label === '·') {
                        $label = 'Кейс #' . $cid;
                    }
                    $st = (string) ($c['status'] ?? '');
                    if ($st !== '') {
                        $label .= ' (' . $st . ')';
                    }
                    ?>
                    <option value="<?= $cid ?>" <?= $cid === $bmCaseId ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>
</div>
<?php if ($bmCaseId <= 0): ?>
    <div class="bm-empty text-muted">Нет кейсов «Работа с банком» по этой заявке. Создайте пакет на странице продукта — тогда здесь появится оценка по методике.</div>
<?php else: ?>
<div id="<?= htmlspecialchars($bmRootId) ?>"></div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof initBankMethodology !== 'function') return;
    var rootId = <?= json_encode($bmRootId, JSON_UNESCAPED_UNICODE) ?>;
    var tabId = <?= json_encode($bmTabId, JSON_UNESCAPED_UNICODE) ?>;
    var caseId = <?= (int) $bmCaseId ?>;
    var sel = document.getElementById(rootId + 'CaseSelect');
    function boot(id) {
        initBankMethodology({
            bankCaseId: id,
            rootId: rootId,
            tabId: tabId
        });
    }
    boot(caseId);
    if (sel) {
        sel.addEventListener('change', function () {
            var next = parseInt(sel.value, 10) || 0;
            if (next > 0) boot(next);
        });
    }
});
</script>
<?php endif; ?>
