<?php
$kinds = finbuild_landing_bg_guarantee_kinds();
?>
<section id="bgtypes" class="landing-section landing-section--dark" aria-labelledby="bgtypes-title">
    <div class="section-landing">
        <div class="landing-section-head landing-section-head--light">
            <h2 class="section-title section-title--light" id="bgtypes-title">Виды банковских гарантий</h2>
            <p class="section-sub section-sub--light landing-max-w">
                Ставка от 1,5%
            </p>
        </div>

        <div class="landing-bg-kind-grid">
            <?php foreach ($kinds as $card): ?>
                <article class="landing-bg-kind-card">
                    <?php if (($card['tags'] ?? []) !== []): ?>
                        <div class="landing-bg-kind-card__tags">
                            <?php foreach ($card['tags'] as $tag): ?>
                                <span class="landing-bg-kind-tag"><?= htmlspecialchars((string) $tag, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <h3 class="landing-bg-kind-card__title"><?= htmlspecialchars($card['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></h3>
                    <p class="landing-bg-kind-card__text"><?= htmlspecialchars($card['text'], ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>
