<?php
$landingFeatures = finbuild_landing_features();
$leadFeature = $landingFeatures[0] ?? null;
$restFeatures = array_slice($landingFeatures, 1);
?>
<section id="features" class="landing-section landing-section--band" aria-labelledby="features-title">
    <div class="section-landing">
        <div class="landing-section-head">
            <h2 class="section-title" id="features-title">Возможности платформы</h2>
            <p class="section-sub landing-max-w">
                Finbuild активно развивается и регулярно выпускаются обновления, которые улучшают функционал и удобство
            </p>
        </div>

        <?php if ($leadFeature !== null): ?>
            <article class="landing-feature-card landing-feature-card--lead">
                <div class="landing-feature-card__icon landing-feature-card__icon--lead" aria-hidden="true">
                    <i class="bi <?= htmlspecialchars($leadFeature['icon'], ENT_QUOTES, 'UTF-8') ?>"></i>
                </div>
                <div class="landing-feature-card__content">
                    <p class="landing-feature-card__label">Основа процесса</p>
                    <h3 class="landing-feature-card__title"><?= htmlspecialchars($leadFeature['title'], ENT_QUOTES, 'UTF-8') ?></h3>
                    <p class="landing-feature-card__text"><?= htmlspecialchars($leadFeature['text'], ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            </article>
        <?php endif; ?>

        <div class="landing-features-grid" role="list">
            <?php foreach ($restFeatures as $f): ?>
                <article class="landing-feature-card" role="listitem">
                    <div class="landing-feature-card__icon" aria-hidden="true">
                        <i class="bi <?= htmlspecialchars($f['icon'], ENT_QUOTES, 'UTF-8') ?>"></i>
                    </div>
                    <div class="landing-feature-card__content">
                        <h4 class="landing-feature-card__title landing-feature-card__title--sm"><?= htmlspecialchars($f['title'], ENT_QUOTES, 'UTF-8') ?></h4>
                        <p class="landing-feature-card__text"><?= htmlspecialchars($f['text'], ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>
