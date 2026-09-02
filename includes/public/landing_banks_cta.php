<section class="landing-section landing-section--banks-cta" aria-label="CTA после банков">
    <div class="section-landing">
        <div class="landing-cta-banner">
            <span class="d-inline-block landing-eyebrow landing-eyebrow--pill">Поможем на старте</span>
            <h2 class="fs-4">Поможем зарегистрироваться и получить гарантию</h2>
            <p>Оставьте заявку, и с Вами свяжется персональный менеджер</p>
            <div class="d-flex flex-column flex-sm-row gap-2 justify-content-center align-items-center">
                <button type="button" class="landing-btn" style="background:#fff;color:#1d4ed8;box-shadow:none;font-weight:700;"
                        data-bs-toggle="modal" data-bs-target="#landingLeadModal">
                    Оставить заявку
                </button>
            </div>
        </div>
    </div>
</section>

<div class="modal fade landing-modal" id="landingLeadModal" tabindex="-1" aria-labelledby="landingLeadModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content landing-modal__content">
            <div class="modal-header landing-modal__header">
                <div class="landing-modal__titlewrap">
                    <div>
                        <h2 class="modal-title landing-modal__title" id="landingLeadModalLabel">Оставить заявку</h2>
                        <div class="landing-modal__subtitle">С вами свяжется персональный менеджер в ближайшее время</div>
                    </div>
                </div>
                <button type="button" class="btn-close landing-modal__close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
            </div>
            <div class="modal-body landing-modal__body" id="landingLeadModalBody">
                <div class="alert alert-success mb-0 d-none" id="landingLeadSuccess" role="status">
                    <strong>Заявка отправлена.</strong> Скоро свяжемся.
                </div>
                <form method="post" action="index.php" id="landingLeadForm" class="landing-modal__form">
                    <input type="hidden" name="finbuild_lead" value="1">

                    <div class="mb-3">
                        <label class="form-label landing-modal__label" for="leadName">Как к Вам обращаться?</label>
                        <input class="form-control landing-modal__control" id="leadName" name="lead_name" type="text" autocomplete="name" required>
                    </div>
                    <div class="mb-0">
                        <label class="form-label landing-modal__label" for="leadPhone">Телефон</label>
                        <input class="form-control landing-modal__control" id="leadPhone" name="lead_phone" type="tel"
                               inputmode="tel" autocomplete="tel" placeholder="+7…" required>
                    </div>
                </form>
            </div>
            <div class="modal-footer landing-modal__footer" id="landingLeadModalFooter">
                <div class="landing-modal__footer-actions">
                    <button type="submit" form="landingLeadForm" class="landing-btn landing-btn-primary" id="landingLeadSubmitBtn">Оставить заявку</button>
                    <button type="button" class="landing-btn landing-btn-outline d-none" data-bs-dismiss="modal" id="landingLeadCloseBtn">Закрыть</button>
                </div>
            </div>
        </div>
    </div>
</div>

