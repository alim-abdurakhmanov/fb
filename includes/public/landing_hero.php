<section class="landing-hero" aria-labelledby="landing-hero-title">
    <div class="section-landing">
        <div class="landing-hero-grid">
            <div>
                <span class="landing-eyebrow">Все виды банковских гарантий</span>
                <h1 id="landing-hero-title">Платформа <span class="landing-br-desktop"><br></span>банковских гарантий</h1>
                <p class="landing-hero-lead">
                    Онлайн оформление банковских гарантий
                </p>
                <div class="landing-hero-btns">
                    <a class="landing-btn landing-btn-primary" href="<?= htmlspecialchars(finbuild_landing_register_url(['role' => 'partner'])) ?>">Зарегистрироваться</a>
                    <a class="landing-btn landing-btn-outline" href="login.php">Войти</a>
                </div>
                <div class="landing-trust-micro" aria-label="Ключевые преимущества">
                    <span><i class="bi bi-check-circle-fill text-primary"></i> Одна заявка - множество предложений банков</span>
                    <span><i class="bi bi-check-circle-fill text-primary"></i> Все регионы</span>
                </div>
            </div>
            <div class="landing-hero-panel" aria-hidden="true">
                <div class="landing-hero-visual__aurora"></div>
                <div class="landing-hero-panel-inner landing-hero-visual">
                    <svg class="landing-hero-visual__svg" viewBox="0 0 340 218" xmlns="http://www.w3.org/2000/svg" role="presentation" focusable="false">
                        <defs>
                            <linearGradient id="heroFlowLine" x1="0%" y1="0%" x2="100%" y2="0%">
                                <stop offset="0%" stop-color="#3b82f6"/>
                                <stop offset="55%" stop-color="#6366f1"/>
                                <stop offset="100%" stop-color="#a78bfa"/>
                            </linearGradient>
                            <filter id="heroFlowGlow" x="-40%" y="-40%" width="180%" height="180%">
                                <feGaussianBlur stdDeviation="2.5" result="b"/>
                                <feMerge><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge>
                            </filter>
                        </defs>
                        <!-- базовая траектория -->
                        <path id="heroFlowPath" fill="none" stroke="rgba(148,163,184,0.28)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M 44 132 L 120 72 L 186 174 L 272 74"/>
                        <!-- бегущий «импульс» по контуру -->
                        <path class="landing-hero-visual__dash" fill="none" stroke="url(#heroFlowLine)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" pathLength="100" stroke-dasharray="14 86" stroke-dashoffset="0" d="M 44 132 L 120 72 L 186 174 L 272 74" filter="url(#heroFlowGlow)"/>
                        <!-- узлы -->
                        <g class="landing-hero-visual__nodes">
                            <g class="landing-hero-visual__node landing-hero-visual__node--1">
                                <circle class="landing-hero-visual__node-ring" cx="44" cy="132" r="13" fill="none" stroke="rgba(96,165,250,0.45)" stroke-width="2"/>
                                <circle class="landing-hero-visual__node-core" cx="44" cy="132" r="6" fill="#f8fafc"/>
                                <text x="44" y="158" text-anchor="middle" class="landing-hero-visual__txt">Создание заявки</text>
                            </g>
                            <g class="landing-hero-visual__node landing-hero-visual__node--2">
                                <circle class="landing-hero-visual__node-ring" cx="120" cy="72" r="13" fill="none" stroke="rgba(96,165,250,0.45)" stroke-width="2"/>
                                <circle class="landing-hero-visual__node-core" cx="120" cy="72" r="6" fill="#f8fafc"/>
                                <text x="120" y="54" text-anchor="middle" class="landing-hero-visual__txt">Предложения банков</text>
                            </g>
                            <g class="landing-hero-visual__node landing-hero-visual__node--3">
                                <circle class="landing-hero-visual__node-ring" cx="186" cy="174" r="13" fill="none" stroke="rgba(96,165,250,0.45)" stroke-width="2"/>
                                <circle class="landing-hero-visual__node-core" cx="186" cy="174" r="6" fill="#f8fafc"/>
                                <text x="186" y="200" text-anchor="middle" class="landing-hero-visual__txt">Выбор банка</text>
                            </g>
                            <g class="landing-hero-visual__node landing-hero-visual__node--4">
                                <circle class="landing-hero-visual__node-ring" cx="272" cy="74" r="13" fill="none" stroke="rgba(96,165,250,0.45)" stroke-width="2"/>
                                <circle class="landing-hero-visual__node-core" cx="272" cy="74" r="6" fill="#f8fafc"/>
                                <text x="272" y="56" text-anchor="middle" class="landing-hero-visual__txt">Выпуск гарантии</text>
                            </g>
                        </g>
                        <!-- точки, движущиеся вдоль пути -->
                        <circle class="landing-hero-visual__particle landing-hero-visual__particle--a" cx="0" cy="0" r="4" fill="#93c5fd" opacity="0.95">
                            <animateMotion dur="14s" repeatCount="indefinite" rotate="auto" calcMode="linear" path="M 44 132 L 120 72 L 186 174 L 272 74"/>
                        </circle>
                        <circle class="landing-hero-visual__particle landing-hero-visual__particle--b" cx="0" cy="0" r="3.2" fill="#c4b5fd" opacity="0.85">
                            <animateMotion dur="14s" repeatCount="indefinite" begin="7s" rotate="auto" calcMode="linear" path="M 44 132 L 120 72 L 186 174 L 272 74"/>
                        </circle>
                    </svg>
                </div>
            </div>
        </div>
    </div>
</section>
