<?php
// This file sends email through the Brevo service.


declare(strict_types=1);

// This function sends brevo transactional email.
function sendBrevoTransactionalEmail(
    string $recipientEmail,
    string $subject,
    string $htmlContent,
    string $textContent
): array {
    $apiKey = trim((string) getenv('BREVO_API_KEY'));
    $senderEmail = strtolower(trim((string) getenv('BREVO_SENDER_EMAIL')));
    $senderName = trim((string) (getenv('BREVO_SENDER_NAME') ?: 'CoopWise'));
    if ($apiKey === '' || !filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
        return ['sent' => false, 'error_code' => 'configuration_missing'];
    }
    if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        return ['sent' => false, 'error_code' => 'recipient_invalid'];
    }

    $body = json_encode([
        'sender' => ['name' => $senderName, 'email' => $senderEmail],
        'to' => [['email' => $recipientEmail]],
        'subject' => $subject,
        'htmlContent' => $htmlContent,
        'textContent' => $textContent
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($body)) {
        return ['sent' => false, 'error_code' => 'content_encoding_failed'];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "api-key: {$apiKey}\r\nAccept: application/json\r\nContent-Type: application/json",
            'content' => $body,
            'timeout' => 20,
            'ignore_errors' => true
        ]
    ]);
    $http_response_header = [];
    $response = @file_get_contents('https://api.brevo.com/v3/smtp/email', false, $context);
    $status = 0;
    foreach ($http_response_header as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches)) {
            $status = (int) $matches[1];
        }
    }

    if ($response === false) {
        return ['sent' => false, 'error_code' => 'transport_error'];
    }
    if ($status < 200 || $status >= 300) {
        return ['sent' => false, 'error_code' => $status > 0 ? 'http_' . $status : 'http_status_unknown'];
    }

    return ['sent' => true, 'error_code' => null];
}
