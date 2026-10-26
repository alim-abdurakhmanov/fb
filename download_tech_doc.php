<?php
/**
 * Публичная выдача файлов технической документации (реестр ПО).
 * Доступ без авторизации. Только allowlist.
 */
declare(strict_types=1);

$catalog = [
    'functional' => [
        'path' => __DIR__ . '/docs/registry/opisanie-funkcionalnyh-harakteristik.docx',
        'download_name' => 'Описание функциональных характеристик ПО.docx',
    ],
    'install' => [
        'path' => __DIR__ . '/docs/registry/instrukciya-po-ustanovke.docx',
        'download_name' => 'Инструкция по установке.docx',
    ],
    'operation' => [
        'path' => __DIR__ . '/docs/registry/instrukciya-po-ekspluatacii.docx',
        'download_name' => 'Инструкция по эксплуатации ПО.docx',
    ],
];

$key = trim((string) ($_GET['file'] ?? ''));
if ($key === '' || !isset($catalog[$key])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Файл не найден';
    exit;
}

$item = $catalog[$key];
$path = $item['path'];
if (!is_file($path) || !is_readable($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Файл недоступен';
    exit;
}

$downloadName = (string) $item['download_name'];
$size = (int) filesize($path);
$asciiFallback = preg_replace('/[^\x20-\x7E]+/', '_', $downloadName) ?: 'document.docx';

header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Length: ' . $size);
header(
    "Content-Disposition: attachment; filename=\"{$asciiFallback}\"; filename*=UTF-8''" . rawurlencode($downloadName)
);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=3600');

readfile($path);
exit;
