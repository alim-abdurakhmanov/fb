<section id="faq" class="landing-section landing-section--faq">
    <div class="section-landing">
        <div class="landing-section-head">
            <h2 class="section-title">Вопросы перед регистрацией</h2>
        </div>
        <?php foreach (finbuild_landing_faq() as $item): ?>
            <div class="landing-faq-item">
                <p class="fw-bold mb-2 small"><?= htmlspecialchars($item['q'], ENT_QUOTES, 'UTF-8') ?></p>
                <p class="text-muted small mb-0"><?= htmlspecialchars($item['a'], ENT_QUOTES, 'UTF-8') ?></p>
            </div>
        <?php endforeach; ?>

        <div class="landing-cta-banner mt-5">
            <span class="d-inline-block landing-eyebrow landing-eyebrow--pill">Начать работу в FinBuild</span>
            <h2 class="fs-4">Регистрация занимает минуту — и вы можете создавать заявки</h2>
            <p>Войдите в кабинет как партнёр и заведите первую клиентскую заявку, либо выберите клиентский сценарий, если нужна только ваша организация.</p>
            <div class="d-flex flex-column flex-sm-row gap-2 justify-content-center align-items-center">
                <a class="landing-btn" style="background:#fff;color:#1d4ed8;box-shadow:none;font-weight:700;" href="<?= htmlspecialchars(finbuild_landing_register_url(['role' => 'partner']), ENT_QUOTES, 'UTF-8') ?>">Зарегистрироваться</a>
                <a class="landing-btn landing-btn-outline" style="border-color:rgba(255,255,255,0.75);color:#fff;" href="login.php">Уже есть доступ</a>
            </div>
        </div>

    </div>
</section>

<section class="landing-section landing-section--band" aria-label="Контакты">
    <div class="section-landing">
        <div class="landing-section-head">
            <h2 class="section-title">Контакты</h2>
            <p class="section-sub landing-max-w">По всем вопросам пишите в поддержку — ответим и поможем</p>
        </div>

        <div class="landing-support-card" role="note" aria-label="Поддержка">
            <div class="landing-support-card__icon" aria-hidden="true"><i class="bi bi-envelope"></i></div>
            <div class="landing-support-card__body">
                <div class="landing-support-card__label">Поддержка FinBuild</div>
                <a class="landing-support-card__email" href="mailto:support@finbuild.ru">support@finbuild.ru</a>
            </div>
            <div class="landing-support-card__actions">
                <button type="button" class="landing-btn landing-btn-primary"
                        data-bs-toggle="modal" data-bs-target="#landingLeadModal">
                    Связаться с персональным менеджером
                </button>
            </div>
        </div>
    </div>
</section>

<footer class="landing-footer">
    <div class="section-landing landing-footer-inner">
        <div>
            <strong style="font-family:Montserrat,sans-serif;font-size:1.05rem;">FINBUILD</strong>
            <p class="text-muted small mb-0 mt-1">Платформа банковских гарантий</p>
        </div>
        <div class="d-flex gap-2 flex-wrap justify-content-center">
            <a class="landing-btn landing-btn-outline" href="login.php">Личный кабинет</a>
        </div>
    </div>
</footer>
