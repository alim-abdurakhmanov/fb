<?php
/**
 * Отправка писем через SMTP (PHPMailer).
 * Параметры задаются в config.php; при отключённой почте или пустом хосте — тихий no-op.
 */

declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/Exception.php';
require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/SMTP.php';

/**
 * Отправляет одно HTML-письмо. Возвращает true при успехе.
 */
function finbuild_send_mail(string $to, string $subject, string $htmlBody, ?string $textBody = null): bool
{
    if (!defined('MAIL_ENABLED') || !MAIL_ENABLED) {
        return false;
    }
    if (!defined('MAIL_SMTP_HOST') || MAIL_SMTP_HOST === '') {
        return false;
    }
    // Без логина SMTP отправка не настраивается (Яндекс и др. требуют авторизацию)
    if (!defined('MAIL_SMTP_USER') || MAIL_SMTP_USER === '') {
        return false;
    }
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $mail = new PHPMailer(true);

    try {
        $mail->SMTPDebug = 0;
        $mail->isSMTP();
        $mail->Host = MAIL_SMTP_HOST;
        $mail->Port = defined('MAIL_SMTP_PORT') ? (int) MAIL_SMTP_PORT : 587;
        $hasUser = defined('MAIL_SMTP_USER') && MAIL_SMTP_USER !== '';
        $mail->SMTPAuth = $hasUser;
        if ($hasUser) {
            $mail->Username = MAIL_SMTP_USER;
            $mail->Password = defined('MAIL_SMTP_PASSWORD') ? (string) MAIL_SMTP_PASSWORD : '';
        }

        // tls / ssl / пусто — для порта 465 обычно ssl
        $secure = defined('MAIL_SMTP_SECURE') ? (string) MAIL_SMTP_SECURE : '';
        if ($secure === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($secure === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        }

        $mail->CharSet = 'UTF-8';
        // От кого: явный адрес или тот же ящик, что для SMTP (так требует большинство провайдеров)
        $fromAddr = (defined('MAIL_FROM_ADDRESS') && MAIL_FROM_ADDRESS !== '')
            ? MAIL_FROM_ADDRESS
            : MAIL_SMTP_USER;
        $fromName = defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'FinBuild';
        $mail->setFrom($fromAddr, $fromName);

        $mail->addAddress($to);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = $textBody ?? strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody));

        $mail->send();
        return true;
    } catch (PHPMailerException $e) {
        $msg = $e->getMessage();
        if (function_exists('error_log')) {
            error_log('finbuild mail: ' . $msg);
        }
        return false;
    }
}
