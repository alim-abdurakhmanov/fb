<?php
/**
 * Однократный пересчёт статусов всех заявок по логике updateApplicationStatus() из config.php.
 *
 * Запуск из корня проекта (или с указанием полного пути к скрипту):
 *   php scripts/recalculate_application_statuses.php
 *
 * Только CLI — не открывайте файл в браузере.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Запуск только из командной строки:\nphp scripts/recalculate_application_statuses.php\n");
}

require_once dirname(__DIR__) . '/config.php';

$pdo = getPDO();
$ids = $pdo->query('SELECT id FROM applications ORDER BY id ASC')->fetchAll(PDO::FETCH_COLUMN);

if ($ids === false) {
    fwrite(STDERR, "Не удалось получить список заявок.\n");
    exit(1);
}

$n = count($ids);
echo "Найдено заявок: {$n}\n";

$ok = 0;
$errors = [];

foreach ($ids as $id) {
    $id = (int) $id;
    try {
        $newStatus = updateApplicationStatus($id, false, false);
        echo "#{$id} -> " . ($newStatus ?? 'null') . "\n";
        $ok++;
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        $errors[] = "#{$id}: {$msg}";
        fwrite(STDERR, "Ошибка #{$id}: {$msg}\n");
    }
}

echo "\nГотово: успешно {$ok} из {$n}.\n";

if ($errors !== []) {
    echo "\nОшибки:\n" . implode("\n", $errors) . "\n";
    exit(1);
}

exit(0);
