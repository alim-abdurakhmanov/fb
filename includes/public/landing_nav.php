<?php // Общая навигация лендинга (можно включать без подготовки переменных) ?>
<nav class="landing-nav" aria-label="Главное меню">
    <div class="landing-nav-inner">
        <a class="landing-brand" href="index.php">
            <span class="landing-brand__wordmark">FINBUILD</span>
        </a>
        <div class="landing-nav-links">
            <a href="#audience">Для кого</a>
            <a href="#bgtypes">Виды гарантий</a>
            <a href="#selection">Подбор банков</a>
            <a href="#features">Возможности</a>
            <a href="#steps">Этапы</a>
            <a href="#faq">FAQ</a>
        </div>
        <div class="landing-nav-actions">
            <a class="landing-btn landing-btn-outline d-none d-sm-inline-flex" href="login.php">Войти</a>
            <a class="landing-btn landing-btn-primary landing-btn--nav-register" href="<?= htmlspecialchars(finbuild_landing_register_url(['role' => 'partner'])) ?>">Зарегистрироваться</a>
            <button class="btn btn-light border landing-mobile-toggle d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#landingNavCanvas" aria-controls="landingNavCanvas" aria-label="Открыть меню">
                <i class="bi bi-list fs-5"></i>
            </button>
        </div>
    </div>
</nav>

<div class="offcanvas offcanvas-end" tabindex="-1" id="landingNavCanvas" aria-labelledby="landingNavCanvasLabel">
    <div class="offcanvas-header">
        <a class="landing-brand" href="index.php" id="landingNavCanvasLabel" aria-label="FINBUILD">
            <span class="landing-brand__wordmark">FINBUILD</span>
        </a>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Закрыть"></button>
    </div>
    <div class="offcanvas-body landing-offcanvas-links">
        <a href="#audience" data-bs-dismiss="offcanvas">Для кого</a>
        <a href="#bgtypes" data-bs-dismiss="offcanvas">Виды гарантий</a>
        <a href="#selection" data-bs-dismiss="offcanvas">Подбор банков</a>
        <a href="#features" data-bs-dismiss="offcanvas">Возможности</a>
        <a href="#steps" data-bs-dismiss="offcanvas">Этапы</a>
        <a href="#faq" data-bs-dismiss="offcanvas">FAQ</a>
        <hr>
        <div class="d-grid gap-2">
            <a class="landing-btn landing-btn-outline" href="login.php">Войти</a>
            <a class="landing-btn landing-btn-primary" href="<?= htmlspecialchars(finbuild_landing_register_url(['role' => 'partner'])) ?>">Зарегистрироваться</a>
        </div>
    </div>
</div>
