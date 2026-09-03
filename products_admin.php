<?php
$current_page = 'products_admin';
require_once 'config.php';
checkAuth();

if (!finbuild_can('admin.products')) {
    header('Location: index.php');
    exit;
}

$pdo = getPDO();

function format_json_list(?string $jsonString): string
{
    if (empty($jsonString) || $jsonString === '[]') {
        return '—';
    }
    $array = json_decode($jsonString, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($array)) {
        return htmlspecialchars(mb_substr($jsonString, 0, 80)) . (mb_strlen($jsonString) > 80 ? '…' : '');
    }
    $s = implode(', ', $array);
    if (mb_strlen($s) > 80) {
        return htmlspecialchars(mb_substr($s, 0, 77)) . '…';
    }
    return htmlspecialchars($s);
}

$bankProducts = $pdo->query('SELECT * FROM bank_products ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
$creditProducts = $pdo->query('SELECT * FROM credit_products ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);

require_once 'header.php';
?>

<div class="page-header mb-4">
    <div class="row align-items-center">
        <div class="col">
            <h1 class="h3 mb-0">Продукты</h1>
            <p class="text-muted mb-0">Справочник банковских гарантий и кредитов для подбора и заявок</p>
        </div>
        <div class="col-auto d-flex gap-2 flex-wrap">
            <button type="button" class="btn btn-primary" onclick="openProductModal('bg')">
                <i class="bi bi-plus-lg me-1"></i> БГ
            </button>
            <button type="button" class="btn btn-outline-primary" onclick="openProductModal('credit')">
                <i class="bi bi-plus-lg me-1"></i> Кредит
            </button>
        </div>
    </div>
</div>

<style>
/* Запасная прокрутка длинной формы (flex + modal-dialog-scrollable) */
#productModal.modal .modal-body {
    min-height: 0;
    overflow-y: auto;
    max-height: min(75vh, calc(100vh - 10rem));
}
</style>

<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between">
        <h5 class="mb-0"><i class="bi bi-bank me-2 text-primary"></i>Банковские гарантии</h5>
        <span class="badge bg-secondary rounded-pill"><?= count($bankProducts) ?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">ID</th>
                        <th>Банк</th>
                        <th>Название</th>
                        <th class="text-end text-nowrap">Макс. сумма</th>
                        <th class="text-end">Макс. срок (мес.)</th>
                        <th>Виды БГ</th>
                        <th>ФЗ</th>
                        <th class="text-end pe-3">Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($bankProducts)): ?>
                        <tr><td colspan="8" class="text-center text-muted py-4">Нет записей</td></tr>
                    <?php else: ?>
                        <?php foreach ($bankProducts as $p): ?>
                            <tr>
                                <td class="ps-3"><?= (int) $p['id'] ?></td>
                                <td><?= htmlspecialchars($p['bank_name'] ?? '') ?></td>
                                <td><?= htmlspecialchars($p['name'] ?? '') ?></td>
                                <td class="text-end text-nowrap"><?= isset($p['max_amount']) && $p['max_amount'] !== null && $p['max_amount'] !== '' ? number_format((float) $p['max_amount'], 0, '', ' ') : '—' ?></td>
                                <td class="text-end"><?= isset($p['max_term']) && $p['max_term'] !== null && $p['max_term'] !== '' ? (int) $p['max_term'] : '—' ?></td>
                                <td class="small text-muted"><?= format_json_list($p['bg_types'] ?? null) ?></td>
                                <td class="small text-muted"><?= format_json_list($p['fz_types'] ?? null) ?></td>
                                <td class="text-end pe-3">
                                    <button type="button" class="btn btn-sm btn-outline-primary btn-edit-product" data-type="bg" data-product="<?= htmlspecialchars(json_encode($p, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-pencil"></i></button>
                                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteProduct(<?= (int) $p['id'] ?>, 'bg')"><i class="bi bi-trash"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between">
        <h5 class="mb-0"><i class="bi bi-cash-coin me-2 text-success"></i>Кредиты для бизнеса</h5>
        <span class="badge bg-secondary rounded-pill"><?= count($creditProducts) ?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">ID</th>
                        <th>Банк</th>
                        <th>Название</th>
                        <th class="text-end text-nowrap">Сумма</th>
                        <th class="text-end">Срок (мес.)</th>
                        <th>Ставка</th>
                        <th class="text-end pe-3">Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($creditProducts)): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">Нет записей</td></tr>
                    <?php else: ?>
                        <?php foreach ($creditProducts as $p): ?>
                            <tr>
                                <td class="ps-3"><?= (int) $p['id'] ?></td>
                                <td><?= htmlspecialchars($p['bank_name'] ?? '') ?></td>
                                <td><?= htmlspecialchars($p['name'] ?? '') ?></td>
                                <td class="text-end text-nowrap"><?= isset($p['amount']) && $p['amount'] !== null && $p['amount'] !== '' ? number_format((float) $p['amount'], 0, '', ' ') : '—' ?></td>
                                <td class="text-end"><?= isset($p['term']) && $p['term'] !== null && $p['term'] !== '' ? htmlspecialchars((string) $p['term']) : '—' ?></td>
                                <td class="small"><?= htmlspecialchars((string) ($p['interest_rate'] ?? '')) ?: '—' ?></td>
                                <td class="text-end pe-3">
                                    <button type="button" class="btn btn-sm btn-outline-primary btn-edit-product" data-type="credit" data-product="<?= htmlspecialchars(json_encode($p, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-pencil"></i></button>
                                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteProduct(<?= (int) $p['id'] ?>, 'credit')"><i class="bi bi-trash"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="productModal" tabindex="-1" aria-labelledby="productModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="productModalLabel">Продукт</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
            </div>
            <div class="modal-body">
                <form id="productForm">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" id="pf_id" value="0">
                <input type="hidden" name="type" id="pf_type" value="bg">
                    <p class="text-muted small mb-3">Поля совпадают с блоками в карточке продукта в заявке. Пустые необязательные поля сохраняются как пустые.</p>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Банк <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="bank_name" id="pf_bank_name" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Название продукта <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" id="pf_name" required>
                        </div>
                    </div>

                    <div id="pf_bg_fields">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Макс. сумма (₽)</label>
                                <input type="number" class="form-control" name="max_amount" id="pf_max_amount" min="0" step="0.01" placeholder="Пусто — без ограничения">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Макс. срок (месяцев)</label>
                                <input type="number" class="form-control" name="max_term" id="pf_max_term" min="0" placeholder="Пусто — без ограничения">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Виды БГ</label>
                            <textarea class="form-control font-monospace small" name="bg_types" id="pf_bg_types" rows="3" placeholder="По одному в строке или через запятую"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Виды ФЗ</label>
                            <textarea class="form-control font-monospace small" name="fz_types" id="pf_fz_types" rows="2" placeholder="44-ФЗ, 223-ФЗ…"></textarea>
                        </div>
                        <hr class="my-3">
                        <h6 class="text-secondary mb-3">Дополнительно (БГ)</h6>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Лимитная сумма</label>
                                <input type="text" class="form-control" name="limit_amount" id="pf_limit_amount">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Возраст компании</label>
                                <input type="text" class="form-control" name="company_age" id="pf_company_age">
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label d-block">СПФС</label>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="spfs_yes" id="pf_spfs_yes" value="1" role="switch">
                                    <label class="form-check-label" for="pf_spfs_yes">Да</label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label d-block">Оплата третьих лиц</label>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="third_party_yes" id="pf_third_party_yes" value="1" role="switch">
                                    <label class="form-check-label" for="pf_third_party_yes">Да</label>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Заказчики</label>
                            <textarea class="form-control" name="customers" id="pf_customers" rows="2"></textarea>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label d-block">Работа с ИП</label>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="works_ie_yes" id="pf_works_ie_yes" value="1" role="switch">
                                    <label class="form-check-label" for="pf_works_ie_yes">Да</label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label d-block">Работа с госпредприятиями</label>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="works_gos_yes" id="pf_works_gos_yes" value="1" role="switch">
                                    <label class="form-check-label" for="pf_works_gos_yes">Да</label>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Стоп-регионы принципала</label>
                            <textarea class="form-control" name="stop_regions_principal" id="pf_stop_principal" rows="2"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Стоп-регионы бенефициара</label>
                            <textarea class="form-control" name="stop_regions_beneficiary" id="pf_stop_beneficiary" rows="2"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Стоп-факторы</label>
                            <textarea class="form-control" name="stop_factors" id="pf_stop_factors" rows="2"></textarea>
                        </div>
                        <hr class="my-3">
                        <h6 class="text-secondary mb-3">Только для менеджеров (БГ)</h6>
                        <div class="mb-3">
                            <label class="form-label">Ссылка на паспорт продукта</label>
                            <input type="text" class="form-control" name="product_passport_link" id="pf_product_passport_link" placeholder="https://...">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Куратор</label>
                            <textarea class="form-control" name="curator_bg" id="pf_curator_bg" rows="2"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Данные для входа в площадку</label>
                            <textarea class="form-control" name="platform_access_bg" id="pf_platform_access_bg" rows="2"></textarea>
                        </div>
                    </div>

                    <div id="pf_credit_fields" class="d-none">
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label class="form-label">Сумма (₽)</label>
                                <input type="number" class="form-control" name="amount" id="pf_amount" min="0" step="0.01">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Срок</label>
                                <input type="text" class="form-control" name="term" id="pf_term" placeholder="месяцев или текст">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Ставка</label>
                                <input type="text" class="form-control" name="interest_rate" id="pf_interest_rate">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Вид продукта</label>
                            <input type="text" class="form-control" name="credit_kind" id="pf_credit_kind" placeholder="как в БД, поле type">
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Вид кредитной линии</label>
                                <input type="text" class="form-control" name="credit_line_type" id="pf_credit_line_type">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Срок транша</label>
                                <input type="text" class="form-control" name="tranch_term" id="pf_tranch_term">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Залог</label>
                            <input type="text" class="form-control" name="collateral" id="pf_collateral">
                        </div>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="individual_entrepreneur" id="pf_individual_entrepreneur" value="1" role="switch">
                            <label class="form-check-label" for="pf_individual_entrepreneur">ИП (допускается)</label>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Документы</label>
                            <textarea class="form-control" name="documents" id="pf_documents" rows="2"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Стопы</label>
                            <textarea class="form-control" name="stops" id="pf_stops" rows="2"></textarea>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Срок рассмотрения</label>
                                <input type="text" class="form-control" name="consideration_term" id="pf_consideration_term">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Комментарий</label>
                                <textarea class="form-control" name="comments" id="pf_comments" rows="2"></textarea>
                            </div>
                        </div>
                        <hr class="my-3">
                        <h6 class="text-secondary mb-3">Только для менеджеров (кредит)</h6>
                        <div class="mb-3">
                            <label class="form-label">Паспорт продукта (URL)</label>
                            <input type="text" class="form-control" name="passport_link" id="pf_passport_link" placeholder="https://...">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Куратор</label>
                            <textarea class="form-control" name="curator_credit" id="pf_curator_credit" rows="2"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Этапы работы</label>
                            <textarea class="form-control" name="work_stages" id="pf_work_stages" rows="2"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Данные для входа в площадку</label>
                            <textarea class="form-control" name="platform_access_credit" id="pf_platform_access_credit" rows="2"></textarea>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Особенности</label>
                        <textarea class="form-control" name="features" id="pf_features" rows="3"></textarea>
                    </div>
                </form>
            </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="submit" form="productForm" class="btn btn-primary" id="pf_submit">Сохранить</button>
                </div>
        </div>
    </div>
</div>

<script>
(function () {
    const modalEl = document.getElementById('productModal');
    const form = document.getElementById('productForm');

    function jsonArrayToText(jsonStr) {
        if (!jsonStr || jsonStr === '[]') return '';
        try {
            const a = JSON.parse(jsonStr);
            if (Array.isArray(a)) return a.join('\n');
        } catch (e) {}
        return jsonStr;
    }

    window.openProductModal = function (type) {
        form.reset();
        document.getElementById('pf_id').value = '0';
        document.getElementById('pf_type').value = type;
        document.getElementById('pf_individual_entrepreneur').checked = false;
        toggleFields(type);
        document.getElementById('productModalLabel').textContent = type === 'bg' ? 'Новая банковская гарантия' : 'Новый кредит';
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    };

    function setField(id, val) {
        var el = document.getElementById(id);
        if (!el) return;
        el.value = val != null && val !== undefined ? String(val) : '';
    }

    /** Соответствует «да» для старых текстовых значений в БД */
    function ynToBool(val) {
        if (val == null || val === '') return false;
        var s = String(val).trim().toLowerCase();
        if (s === 'нет' || s === 'no' || s === '0' || s === 'false' || s === '-' || s === 'н') return false;
        if (s === 'да' || s === 'д' || s === 'yes' || s === '1' || s === 'true' || s === '+') return true;
        return s.length > 0;
    }

    function fillFormFromRow(row, type) {
        document.getElementById('pf_id').value = String(row.id);
        document.getElementById('pf_type').value = type;
        setField('pf_bank_name', row.bank_name);
        setField('pf_name', row.name);
        setField('pf_features', row.features);
        toggleFields(type);
        if (type === 'bg') {
            setField('pf_max_amount', row.max_amount != null && row.max_amount !== '' ? row.max_amount : '');
            setField('pf_max_term', row.max_term != null && row.max_term !== '' ? row.max_term : '');
            document.getElementById('pf_bg_types').value = jsonArrayToText(row.bg_types);
            document.getElementById('pf_fz_types').value = jsonArrayToText(row.fz_types);
            setField('pf_limit_amount', row.limit_amount);
            setField('pf_company_age', row.company_age);
            document.getElementById('pf_spfs_yes').checked = ynToBool(row.spfs);
            document.getElementById('pf_third_party_yes').checked = ynToBool(row.third_party_payment);
            setField('pf_customers', row.customers);
            document.getElementById('pf_works_ie_yes').checked = ynToBool(row.works_with_individual_entrepreneurs);
            document.getElementById('pf_works_gos_yes').checked = ynToBool(row.works_with_state_enterprises);
            setField('pf_stop_principal', row.stop_regions_principal);
            setField('pf_stop_beneficiary', row.stop_regions_beneficiary);
            setField('pf_stop_factors', row.stop_factors);
            setField('pf_product_passport_link', row.product_passport_link);
            setField('pf_curator_bg', row.curator);
            setField('pf_platform_access_bg', row.platform_access);
        } else {
            setField('pf_amount', row.amount != null && row.amount !== '' ? row.amount : '');
            setField('pf_term', row.term != null && row.term !== '' ? row.term : '');
            setField('pf_interest_rate', row.interest_rate);
            setField('pf_credit_kind', row.type);
            setField('pf_credit_line_type', row.credit_line_type);
            setField('pf_tranch_term', row.tranch_term);
            setField('pf_collateral', row.collateral);
            document.getElementById('pf_individual_entrepreneur').checked = !!row.individual_entrepreneur;
            setField('pf_documents', row.documents);
            setField('pf_stops', row.stops);
            setField('pf_consideration_term', row.consideration_term);
            setField('pf_comments', row.comments);
            setField('pf_passport_link', row.passport_link);
            setField('pf_curator_credit', row.curator);
            setField('pf_work_stages', row.work_stages);
            setField('pf_platform_access_credit', row.platform_access);
        }
        document.getElementById('productModalLabel').textContent = type === 'bg' ? 'Редактирование БГ' : 'Редактирование кредита';
    }

    document.querySelectorAll('.btn-edit-product').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var type = btn.getAttribute('data-type');
            try {
                var row = JSON.parse(btn.getAttribute('data-product'));
                fillFormFromRow(row, type);
                bootstrap.Modal.getOrCreateInstance(modalEl).show();
            } catch (e) {
                alert('Ошибка данных строки');
            }
        });
    });

    function toggleFields(type) {
        const bg = document.getElementById('pf_bg_fields');
        const cr = document.getElementById('pf_credit_fields');
        if (type === 'bg') {
            bg.classList.remove('d-none');
            cr.classList.add('d-none');
        } else {
            bg.classList.add('d-none');
            cr.classList.remove('d-none');
        }
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        const fd = new FormData(form);
        const btn = document.getElementById('pf_submit');
        btn.disabled = true;
        fetch('api_products_admin.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    window.location.reload();
                } else {
                    alert(data.error || 'Ошибка сохранения');
                }
            })
            .catch(function () { alert('Сеть или сервер недоступны'); })
            .finally(function () { btn.disabled = false; });
    });

    window.deleteProduct = function (id, type) {
        if (!confirm('Удалить продукт #' + id + '?')) return;
        const fd = new FormData();
        fd.append('action', 'delete');
        fd.append('id', String(id));
        fd.append('type', type);
        fetch('api_products_admin.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    window.location.reload();
                } else {
                    alert(data.error || 'Не удалось удалить');
                }
            })
            .catch(function () { alert('Сеть или сервер недоступны'); });
    };
})();
</script>

<?php require_once 'footer.php'; ?>
