<?php
/**
 * Диагностика SMTP-отправки. Доступ только руководителям (role=director).
 * Использование: /mail_test.php?to=ваш_адрес@example.com
 * ВНИМАНИЕ: после отладки удалите этот файл с сервера.
 */
require_once 'config.php';

checkAuth();
$currentUser = getCurrentUser();

if (!finbuild_is_director($currentUser)) {
    http_response_code(403);
    exit('Доступ запрещён.');
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

require_once __DIR__ . '/vendor/phpmailer/phpmailer/src/Exception.php';
require_once __DIR__ . '/vendor/phpmailer/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/vendor/phpmailer/phpmailer/src/SMTP.php';

header('Content-Type: text/plain; charset=utf-8');

$to = isset($_GET['to']) ? trim((string) $_GET['to']) : '';

echo "=== Конфигурация ===\n";
echo 'MAIL_ENABLED:       ' . (defined('MAIL_ENABLED') ? var_export(MAIL_ENABLED, true) : '(не задано)') . "\n";
echo 'MAIL_SMTP_HOST:     ' . (defined('MAIL_SMTP_HOST') ? MAIL_SMTP_HOST : '(не задано)') . "\n";
echo 'MAIL_SMTP_PORT:     ' . (defined('MAIL_SMTP_PORT') ? MAIL_SMTP_PORT : '(не задано)') . "\n";
echo 'MAIL_SMTP_SECURE:   ' . (defined('MAIL_SMTP_SECURE') ? MAIL_SMTP_SECURE : '(не задано)') . "\n";
echo 'MAIL_SMTP_USER:     ' . (defined('MAIL_SMTP_USER') ? MAIL_SMTP_USER : '(не задано)') . "\n";
echo 'MAIL_SMTP_PASSWORD: ' . (defined('MAIL_SMTP_PASSWORD') && MAIL_SMTP_PASSWORD !== '' ? '(задан, длина ' . strlen((string) MAIL_SMTP_PASSWORD) . ')' : '(пусто)') . "\n";
echo 'MAIL_FROM_ADDRESS:  ' . (defined('MAIL_FROM_ADDRESS') ? MAIL_FROM_ADDRESS : '(не задано)') . "\n";
echo 'MAIL_FROM_NAME:     ' . (defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : '(не задано)') . "\n";
echo 'PHP OpenSSL:        ' . (extension_loaded('openssl') ? 'есть' : 'НЕТ (нужен для ssl/tls)') . "\n";
echo "\n";

if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    echo "Укажите корректный адрес: /mail_test.php?to=ваш_адрес@example.com\n";
    exit;
}

echo "=== Проверка доступности хоста (fsockopen) ===\n";
$host = defined('MAIL_SMTP_HOST') ? MAIL_SMTP_HOST : '';
$port = defined('MAIL_SMTP_PORT') ? (int) MAIL_SMTP_PORT : 587;
$errno = 0;
$errstr = '';
$start = microtime(true);
$fp = @fsockopen(($port === 465 ? 'ssl://' : '') . $host, $port, $errno, $errstr, 10);
if ($fp) {
    echo "TCP-соединение с {$host}:{$port} — OK (" . round((microtime(true) - $start) * 1000) . " мс)\n\n";
    fclose($fp);
} else {
    echo "НЕ удалось подключиться к {$host}:{$port} — [$errno] $errstr\n";
    echo "Вероятно, исходящий порт {$port} закрыт фаерволом хостинга.\n\n";
}

echo "=== Попытка отправки (SMTPDebug=3) ===\n";

$mail = new PHPMailer(true);
try {
    $mail->SMTPDebug = 3;
    $mail->Debugoutput = static function ($str, $level) {
        echo rtrim($str) . "\n";
    };
    $mail->isSMTP();
    $mail->Host = MAIL_SMTP_HOST;
    $mail->Port = $port;
    $mail->SMTPAuth = true;
    $mail->Username = MAIL_SMTP_USER;
    $mail->Password = defined('MAIL_SMTP_PASSWORD') ? (string) MAIL_SMTP_PASSWORD : '';

    $secure = defined('MAIL_SMTP_SECURE') ? (string) MAIL_SMTP_SECURE : '';
    if ($secure === 'tls') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    } elseif ($secure === 'ssl') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    }

    $mail->CharSet = 'UTF-8';
    $fromAddr = (defined('MAIL_FROM_ADDRESS') && MAIL_FROM_ADDRESS !== '') ? MAIL_FROM_ADDRESS : MAIL_SMTP_USER;
    $fromName = defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'FinBuild';
    $mail->setFrom($fromAddr, $fromName);
    $mail->addAddress($to);
    $mail->isHTML(true);
    $mail->Subject = 'FinBuild — тест почты ' . date('Y-m-d H:i:s');
    $mail->Body = '<p>Это тестовое письмо из FinBuild. Если вы его получили — SMTP работает.</p>';
    $mail->AltBody = 'Это тестовое письмо из FinBuild.';

    $mail->send();
    echo "\n=== РЕЗУЛЬТАТ: письмо отправлено на {$to} ===\n";
} catch (PHPMailerException $e) {
    echo "\n=== РЕЗУЛЬТАТ: ОШИБКА ===\n";
    echo $e->getMessage() . "\n";
    echo 'ErrorInfo: ' . $mail->ErrorInfo . "\n";
}
