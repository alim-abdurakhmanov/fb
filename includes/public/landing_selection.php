<?php
/** @var array{searched: bool, product_type: string, products: list<array<string, mixed>>, params: array<string, string>, error: ?string} $landingCalc */
if (!isset($landingCalc)) {
    $landingCalc = [
        'searched' => false,
        'product_type' => 'bg',
        'products' => [],
        'params' => [],
        'error' => null,
    ];
}
$p = $landingCalc['params'];
?>
<section id="selection" class="landing-section landing-section--calc">
    <div class="section-landing">
        <div class="landing-section-head">
            <h2 class="section-title">Подбор банков</h2>
            <p class="section-sub landing-max-w">
                Укажите параметры необходимой банковской гарантии и получите предложения банков-партнеров
            </p>
        </div>

        <div class="landing-calc-form-wrap mb-4">
            <h3 class="landing-calc-form-title">Параметры</h3>
            <form method="get" action="index.php" id="landingCalcForm">
                <input type="hidden" name="finbuild_landing_calc" value="1">

                <div class="row g-3 g-xl-4 align-items-end landing-calc-form-fields">
                    <div class="col-12 col-md-6 col-xl-3">
                        <label class="form-label landing-calc-label" for="landingBgType">Вид гарантии</label>
                        <select class="form-select" name="bg_type" id="landingBgType">
                            <option value="">Любой</option>
                            <?php
                            $bgOpts = ['Участие', 'Исполнение', 'Исполнение с авансом', 'Возврат аванса', 'Гарантийный период', 'Платежная', 'НДС в пользу ФНС'];
                            foreach ($bgOpts as $opt) {
                                $sel = (($p['bg_type'] ?? '') === $opt) ? ' selected' : '';
                                echo '<option value="' . htmlspecialchars($opt, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"' . $sel . '>' . htmlspecialchars($opt, ENT_QUOTES | ENT_HTML5, 'UTF-8') . "</option>\n";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <label class="form-label landing-calc-label" for="landingFzType">Федеральный закон</label>
                        <select class="form-select" name="fz_type" id="landingFzType">
                            <option value="">Любой</option>
                            <?php
                            $fzOpts = ['44-ФЗ', '223-ФЗ', '185-ФЗ (615-ПП)', 'Коммерция'];
                            foreach ($fzOpts as $opt) {
                                $sel = (($p['fz_type'] ?? '') === $opt) ? ' selected' : '';
                                echo '<option value="' . htmlspecialchars($opt, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"' . $sel . '>' . htmlspecialchars($opt, ENT_QUOTES | ENT_HTML5, 'UTF-8') . "</option>\n";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <label class="form-label landing-calc-label" for="landingAmount">Сумма гарантии, ₽</label>
                        <input type="number" class="form-control" name="amount" id="landingAmount" min="0" step="any"
                               placeholder="Например, 1 500 000"
                               value="<?= htmlspecialchars($p['amount'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>">
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <label class="form-label landing-calc-label" for="landingTerm">Срок, мес.</label>
                        <input type="number" class="form-control" name="term" id="landingTerm" min="0"
                               placeholder="Например, 18"
                               value="<?= htmlspecialchars($p['term'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>">
                    </div>
                </div>

                <div class="mt-3">
                    <button type="submit" class="landing-btn landing-btn-primary landing-calc-submit w-100 py-2">
                        <i class="bi bi-calculator me-2" aria-hidden="true"></i> Показать банки
                    </button>
                </div>
            </form>
        </div>

        <div class="landing-calc-results" id="selection-results">
                    <?php if ($landingCalc['error'] ?? null): ?>
                        <div class="landing-calc-alert landing-calc-alert-warn">
                            <i class="bi bi-info-circle me-2"></i><?= htmlspecialchars((string) $landingCalc['error'], ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>
                        </div>
                    <?php elseif (!$landingCalc['searched']): ?>
                        <div class="landing-calc-empty">
                            <i class="bi bi-grid-3x3-gap landing-calc-empty-icon" aria-hidden="true"></i>
                            <h3 class="h5 fw-bold">Настройте фильтры и нажмите «Показать банки»</h3>
                        </div>
                    <?php elseif (empty($landingCalc['products'])): ?>
                        <div class="landing-calc-empty">
                            <i class="bi bi-inbox landing-calc-empty-icon" aria-hidden="true"></i>
                            <h3 class="h5 fw-bold">Нет программ по этим условиям</h3>
                            <p class="text-muted mb-0">Ослабьте фильтры по сумме, сроку, виду БГ или полю ФЗ. Убедитесь, что в каталоге есть активные продукты под ваш сценарий.</p>
                        </div>
                    <?php else: ?>
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                            <h3 class="h6 fw-bold mb-0">Банки по вашим параметрам</h3>
                            <span class="badge rounded-pill text-bg-primary">Найдено: <?= count($landingCalc['products']) ?></span>
                        </div>
                        <div class="landing-selection-grid">
                            <?php foreach ($landingCalc['products'] as $product):
                                $bankName = (string) ($product['bank_name'] ?? '');
                                $bankEnc = htmlspecialchars($bankName, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                                $bankLogoUrl = finbuild_landing_bank_logo_url_for_bank_name($bankName);
                                $registerHref = htmlspecialchars(
                                    finbuild_landing_register_url([
                                        'role' => 'partner',
                                        'from' => 'calc',
                                        'bank' => $bankName,
                                    ]),
                                    ENT_QUOTES | ENT_HTML5,
                                    'UTF-8'
                                );
                                ?>
                                <article class="landing-bank-card">
                                    <div class="landing-bank-card-head">
                                        <h4><?= $bankEnc ?></h4>
                                        <?php if ($bankLogoUrl !== null): ?>
                                            <img class="landing-bank-card-logo"
                                                 src="<?= htmlspecialchars($bankLogoUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>"
                                                 alt=""
                                                 loading="lazy"
                                                 decoding="async">
                                        <?php endif; ?>
                                    </div>
                                    <ul class="list-unstyled small text-muted landing-calc-product-meta mb-3">
                                        <li><strong class="text-body-secondary">Лимит:</strong>
                                            <?= !empty($product['max_amount']) ? number_format((float) $product['max_amount'], 0, '', ' ') . ' ₽' : 'не указан в каталоге' ?>
                                        </li>
                                    </ul>
                                    <a class="landing-btn landing-btn-primary" href="<?= $registerHref ?>">Оформить заявку</a>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
        </div>
    </div>
</section>
