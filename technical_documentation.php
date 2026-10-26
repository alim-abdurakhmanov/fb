<?php
/**
 * Публичная страница технической документации FinBuild.
 * Доступна без авторизации.
 * ЧПУ: /technical_documentation (см. корневой .htaccess).
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/public/landing_data.php';

$docs = [
    [
        'title' => 'Описание функциональных характеристик ПО.docx',
        'hint' => 'Назначение, возможности, роли и сценарии работы платформы',
        'file' => 'functional',
        'icon' => 'bi-file-earmark-text',
    ],
    [
        'title' => 'Инструкция по установке.docx',
        'hint' => 'Системные требования, запуск и начало работы',
        'file' => 'install',
        'icon' => 'bi-download',
    ],
    [
        'title' => 'Инструкция по эксплуатации ПО.docx',
        'hint' => 'Руководство пользователя: экраны и рекомендации',
        'file' => 'operation',
        'icon' => 'bi-book',
    ],
];
?><!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Техническая документация — FinBuild</title>
    <meta name="description" content="Техническая документация ПО FinBuild: описание функциональных характеристик, инструкция по установке и инструкция по эксплуатации.">
    <meta name="robots" content="index,follow">
    <link rel="canonical" href="/technical_documentation">
    <link rel="icon" href="assets/favicons/favicon.ico" sizes="any">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Montserrat:wght@600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/landing.css?v=<?= (int) (@filemtime(__DIR__ . '/assets/css/landing.css') ?: time()) ?>">
    <style>
        html {
            height: 100%;
        }
        body.landing-body.techdocs-page {
            min-height: 100%;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .techdocs-nav-inner {
            display: flex;
            justify-content: center;
            align-items: center;
            max-width: 1140px;
            margin: 0 auto;
            padding: 0.75rem 1.25rem;
        }
        .techdocs-nav-home {
            color: var(--landing-muted);
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 600;
            padding: 0.45rem 1rem;
            border-radius: 10px;
            border: 1px solid rgba(15, 23, 42, 0.12);
            background: #fff;
            transition: color 0.15s, background 0.15s, border-color 0.15s;
        }
        .techdocs-nav-home:hover {
            color: var(--landing-accent);
            background: var(--landing-accent-soft);
            border-color: rgba(37, 99, 235, 0.25);
        }
        .techdocs-main {
            flex: 1 0 auto;
            width: 100%;
            max-width: 820px;
            margin: 0 auto;
            padding: 2.5rem 1.25rem 4rem;
        }
        .techdocs-eyebrow {
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: var(--landing-accent);
            margin-bottom: 0.65rem;
        }
        .techdocs-title {
            font-family: var(--landing-brand);
            font-weight: 800;
            font-size: clamp(1.75rem, 3vw, 2.35rem);
            letter-spacing: -0.02em;
            margin: 0 0 0.75rem;
        }
        .techdocs-lead {
            color: var(--landing-muted);
            font-size: 1.05rem;
            line-height: 1.5;
            margin: 0 0 2rem;
            max-width: 42rem;
        }
        .techdocs-list {
            display: flex;
            flex-direction: column;
            gap: 0.85rem;
        }
        .techdocs-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            background: #fff;
            border: 1px solid rgba(15, 23, 42, 0.08);
            border-radius: 14px;
            padding: 1rem 1.1rem;
            box-shadow: var(--landing-shadow);
        }
        .techdocs-item__icon {
            flex: 0 0 auto;
            width: 2.6rem;
            height: 2.6rem;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--landing-accent-soft);
            color: var(--landing-accent);
            font-size: 1.2rem;
        }
        .techdocs-item__body {
            flex: 1 1 auto;
            min-width: 0;
        }
        .techdocs-item__title {
            font-weight: 650;
            margin: 0 0 0.2rem;
            font-size: 0.98rem;
            word-break: break-word;
        }
        .techdocs-item__hint {
            margin: 0;
            color: var(--landing-muted);
            font-size: 0.86rem;
            line-height: 1.35;
        }
        .techdocs-item__actions {
            flex: 0 0 auto;
        }
        @media (max-width: 575.98px) {
            .techdocs-item {
                flex-wrap: wrap;
            }
            .techdocs-item__actions {
                width: 100%;
            }
            .techdocs-item__actions .landing-btn {
                width: 100%;
                justify-content: center;
            }
        }
        .techdocs-note {
            margin-top: 1.75rem;
            color: var(--landing-muted);
            font-size: 0.9rem;
        }
        .techdocs-page .landing-footer {
            flex-shrink: 0;
            margin-top: auto;
        }
    </style>
</head>
<body class="landing-body techdocs-page">

<nav class="landing-nav" aria-label="Навигация">
    <div class="techdocs-nav-inner">
        <a class="landing-brand" href="/">
            <span class="landing-brand__wordmark">FINBUILD</span>
        </a>
    </div>
</nav>

<main class="techdocs-main">
    <p class="techdocs-eyebrow">Техническая документация</p>
    <h1 class="techdocs-title">Документы ПО «FinBuild»</h1>
    <p class="techdocs-lead">
        Публичные материалы для ознакомления и регистрации программного обеспечения.
        Файлы доступны для скачивания без авторизации.
    </p>

    <div class="techdocs-list" role="list">
        <?php foreach ($docs as $doc): ?>
            <div class="techdocs-item" role="listitem">
                <div class="techdocs-item__icon" aria-hidden="true">
                    <i class="bi <?= htmlspecialchars($doc['icon'], ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>"></i>
                </div>
                <div class="techdocs-item__body">
                    <p class="techdocs-item__title"><?= htmlspecialchars($doc['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></p>
                    <p class="techdocs-item__hint"><?= htmlspecialchars($doc['hint'], ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></p>
                </div>
                <div class="techdocs-item__actions">
                    <a class="landing-btn landing-btn-primary"
                       href="/download_tech_doc?file=<?= rawurlencode($doc['file']) ?>">
                        <i class="bi bi-download me-1" aria-hidden="true"></i>Скачать
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <p class="techdocs-note">
        Формат файлов: DOCX. При вопросах пишите на
        <a href="mailto:support@finbuild.ru">support@finbuild.ru</a>.
    </p>
</main>

<footer class="landing-footer">
    <div class="section-landing landing-footer-inner">
        <div>
            <strong style="font-family:Montserrat,sans-serif;font-size:1.05rem;">FINBUILD</strong>
            <p class="text-muted small mb-0 mt-1">Платформа банковских гарантий</p>
        </div>
        <div class="d-flex gap-2 flex-wrap justify-content-center">
            <a class="landing-btn landing-btn-outline" href="/">На главную</a>
        </div>
    </div>
</footer>

</body>
</html>
