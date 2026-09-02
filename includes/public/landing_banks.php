<?php
$landingBankLogos = finbuild_landing_bank_logo_assets();
?>
<section id="banks" class="landing-section landing-section--banks-strip" aria-labelledby="banks-title">
    <div class="section-landing">
        <div class="landing-section-head">
            <h2 class="section-title" id="banks-title">Банки, которые <br class="landing-br-mobile">работают с Finbuild</h2>
        </div>

        <?php if ($landingBankLogos !== []): ?>
            <div class="landing-banks-marquee" aria-label="Логотипы банков — бегущая строка">
                <div class="landing-banks-marquee__viewport">
                    <div class="landing-banks-marquee__track">
                        <?php foreach ([false, true] as $isDuplicateRow): ?>
                            <div class="landing-banks-marquee__row"<?= $isDuplicateRow ? ' aria-hidden="true"' : '' ?>>
                                <?php foreach ($landingBankLogos as $logoUrl):
                                    $extraSlot = finbuild_landing_bank_logo_extra_slot_class($logoUrl);
                                    ?>
                                    <div class="landing-bank-logo-slot<?= $extraSlot !== '' ? ' ' . htmlspecialchars($extraSlot, ENT_QUOTES | ENT_HTML5, 'UTF-8') : '' ?>">
                                        <img src="<?= htmlspecialchars($logoUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>"
                                             alt=""
                                             loading="<?= $isDuplicateRow ? 'lazy' : 'eager' ?>"
                                             decoding="async"
                                             width="200"
                                             height="80">
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <p class="landing-muted-note mb-0">
                Добавьте файлы логотипов (<code class="small">png</code>, <code class="small">jpg</code>, <code class="small">svg</code>, <code class="small">webp</code>) в папку <code class="small">assets/banks/</code> — они появятся в блоке автоматически после обновления страницы.
            </p>
        <?php endif; ?>
    </div>
</section>
