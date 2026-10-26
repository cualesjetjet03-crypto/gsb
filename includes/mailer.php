<?php
/**
 * GSB — PHPMailer factory
 *
 * Returns a pre-configured PHPMailer instance ready to send.
 * Credentials come exclusively from config.php — never hardcoded here.
 *
 * Usage:
 *   $mail = gsb_mailer();
 *   $mail->addAddress($email, $name);
 *   $mail->Subject = '...';
 *   $mail->Body    = '...';
 *   $mail->send();
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

function gsb_mailer(): PHPMailer
{
    $mail = new PHPMailer(true); // true = throw exceptions

    $mail->isSMTP();
    $mail->Host       = defined('SMTP_HOST') ? SMTP_HOST : 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = defined('SMTP_USER') ? SMTP_USER : '';
    $mail->Password   = defined('SMTP_PASS') ? SMTP_PASS : '';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = defined('SMTP_PORT') ? SMTP_PORT : 587;
    $mail->CharSet    = 'UTF-8';

    $mail->setFrom(
        defined('SMTP_USER') ? SMTP_USER : '',
        'GSB Airconditioning Services'
    );

    $mail->isHTML(true);

    return $mail;
}
