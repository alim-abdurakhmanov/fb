<?php
$landingSteps = finbuild_landing_pipeline_steps();
?>
<section id="steps" class="landing-section landing-section--dark" aria-labelledby="steps-title">
    <div class="section-landing">
        <div class="landing-section-head landing-section-head--light">
            <h2 class="section-title section-title--light" id="steps-title">Этапы получения банковской гарантии</h2>
        </div>
        <ul class="landing-timeline mt-4" role="list">
            <?php foreach ($landingSteps as $i => $row): ?>
                <li class="landing-timeline-item">
                    <p class="landing-step-title">
                        <span class="landing-step-num" aria-hidden="true"><?= (int) ($i + 1) ?></span>
                        <span class="landing-step-name"><?= htmlspecialchars($row['step'], ENT_QUOTES, 'UTF-8') ?></span>
                    </p>
                    <?php if (trim((string) ($row['detail'] ?? '')) !== ''): ?>
                        <p class="landing-step-detail"><?= htmlspecialchars($row['detail'], ENT_QUOTES, 'UTF-8') ?></p>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</section>
