<?php
/**
 * GSB — Email sender using Brevo HTTP API
 *
 * Uses Brevo's transactional email API over HTTPS (port 443).
 * This works on Render free tier which blocks outbound SMTP ports.
 *
 * Usage:
 *   $result = gsb_send_email($toEmail, $toName, $subject, $htmlBody);
 *   if ($result !== true) { // error string returned }
 *
 * Legacy PHPMailer-style wrapper also available via gsb_mailer_send()
 */

/**
 * Send email via Brevo HTTP API
 * Returns true on success, error string on failure
 */
function gsb_send_email(string $toEmail, string $toName, string $subject, string $htmlBody): bool|string
{
    $apiKey  = defined('BREVO_API_KEY') ? BREVO_API_KEY : getenv('BREVO_API_KEY');
    $fromEmail = defined('SMTP_FROM') ? SMTP_FROM : (defined('SMTP_USER') ? SMTP_USER : '');
    $fromName  = 'GSB Airconditioning Services';

    if (empty($apiKey)) {
        error_log('GSB Mailer: BREVO_API_KEY is not set');
        return 'BREVO_API_KEY is not configured';
    }

    $payload = json_encode([
        'sender'      => ['name' => $fromName, 'email' => $fromEmail],
        'to'          => [['email' => $toEmail, 'name' => $toName]],
        'subject'     => $subject,
        'htmlContent' => $htmlBody,
    ]);

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'accept: application/json',
            'api-key: ' . $apiKey,
            'content-type: application/json',
        ],
        CURLOPT_TIMEOUT        => 15,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        error_log('GSB Mailer cURL error: ' . $curlError);
        return 'cURL error: ' . $curlError;
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        return true;
    }

    $decoded = json_decode($response, true);
    $errMsg  = $decoded['message'] ?? $response;
    error_log('GSB Mailer Brevo API error (' . $httpCode . '): ' . $errMsg);
    return 'Brevo API error: ' . $errMsg;
}
