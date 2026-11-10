<?php

declare(strict_types=1);

function coopwiseNormalizePhilippineSmsPhone(string $phone)
{
    $phone = preg_replace('/[\s().-]+/', '', trim($phone));
    if (preg_match('/^09\d{9}$/', $phone)) {
        return '+63' . substr($phone, 1);
    }
    if (preg_match('/^(?:\+?63)9\d{9}$/', $phone)) {
        return '+' . ltrim($phone, '+');
    }
    return false;
}

function coopwiseBrevoSmsConfigured(): bool
{
    return in_array(strtolower(trim((string) getenv('COOPWISE_SMS_REMINDERS_ENABLED'))), ['1', 'true'], true)
        && trim((string) getenv('BREVO_API_KEY')) !== ''
        && preg_match('/^[A-Za-z0-9]{3,11}$/', trim((string) getenv('BREVO_SMS_SENDER'))) === 1;
}

function coopwiseSendBrevoSms(string $phone, string $content): array
{
    $payload = json_encode([
        'sender' => trim((string) getenv('BREVO_SMS_SENDER')),
        'recipient' => $phone,
        'content' => $content,
        'type' => 'transactional',
        'tag' => 'coopwise-payment-reminder'
    ], JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        throw new RuntimeException('sms_payload_error');
    }

    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "api-key: " . trim((string) getenv('BREVO_API_KEY'))
            . "\r\nAccept: application/json\r\nContent-Type: application/json",
        'content' => $payload,
        'timeout' => 15,
        'ignore_errors' => true
    ]]);
    $response = @file_get_contents('https://api.brevo.com/v3/transactionalSMS/sms', false, $context);
    $statusLine = $http_response_header[0] ?? '';
    preg_match('/\s(\d{3})\s/', $statusLine, $matches);
    $status = (int) ($matches[1] ?? 0);
    if ($response === false || $status < 200 || $status >= 300) {
        throw new RuntimeException($status > 0 ? 'http_' . $status : 'network_error');
    }

    $result = json_decode($response, true);
    return [
        'status' => $status,
        'message_id' => is_array($result) && isset($result['messageId'])
            ? substr((string) $result['messageId'], 0, 120)
            : null
    ];
}

function coopwiseProcessPaymentSmsReminders(PDO $db): array
{
    $stats = ['status' => 'disabled', 'attempted' => 0, 'sent' => 0, 'failed' => 0, 'duplicate' => 0, 'skipped' => 0];
    if (!coopwiseBrevoSmsConfigured()) {
        return $stats;
    }
    $timezoneName = trim((string) (getenv('COOPWISE_BUSINESS_TIMEZONE') ?: 'Asia/Manila'));
    $timezone = new DateTimeZone($timezoneName);
    $today = new DateTimeImmutable('today', $timezone);
    $todayDate = $today->format('Y-m-d');
    $missedDate = $today->modify('-1 day')->format('Y-m-d');
    $db->exec(
        "UPDATE sms_payment_reminder_logs
         SET status = 'failed', error_code = 'delivery_unknown'
         WHERE status = 'pending' AND attempted_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)"
    );
    $stats['status'] = 'completed';

    $loanQuery = $db->prepare(
        "SELECT m.id AS member_id, s.id AS reference_id, s.due_date
         FROM loan_schedules s
         INNER JOIN loans l ON l.id = s.loan_id
         INNER JOIN members m ON m.id = l.member_id
         WHERE m.status = 'active' AND m.sms_payment_reminders_enabled = 1
           AND l.status IN ('approved', 'active')
           AND s.due_date < ? AND s.status IN ('pending', 'overdue')
           AND s.paid_amount < s.total_due
         ORDER BY m.id ASC, s.due_date ASC, s.id ASC"
    );
    $loanQuery->execute([$todayDate]);
    foreach ($loanQuery->fetchAll() as $row) {
        coopwiseAttemptPaymentReminder(
            $db,
            $stats,
            (int) $row['member_id'],
            'loan_installment',
            (int) $row['reference_id'],
            (string) $row['due_date'],
            'CoopWise: A loan installment due ' . $row['due_date']
                . ' is overdue. Sign in to review it, or contact the cooperative if already paid.'
        );
    }

    $paluwaganQuery = $db->prepare(
        "SELECT m.id AS member_id, c.id AS reference_id
         FROM cooperative_cycles c
         INNER JOIN cooperative_cycle_members cm ON cm.cycle_id = c.id
         INNER JOIN members m ON m.id = cm.member_id
         WHERE c.status = 'active' AND c.start_date <= ?
           AND m.status = 'active' AND m.sms_payment_reminders_enabled = 1
           AND NOT EXISTS (
               SELECT 1 FROM cooperative_contributions cc
               WHERE cc.cycle_id = c.id AND cc.member_id = m.id AND cc.contribution_date = ?
           )
           AND NOT EXISTS (
               SELECT 1 FROM cooperative_payouts cp
               WHERE cp.cycle_id = c.id AND cp.status = 'ready'
           )
         ORDER BY m.id ASC, c.id ASC"
    );
    $paluwaganQuery->execute([$missedDate, $missedDate]);
    foreach ($paluwaganQuery->fetchAll() as $row) {
        coopwiseAttemptPaymentReminder(
            $db,
            $stats,
            (int) $row['member_id'],
            'paluwagan_contribution',
            (int) $row['reference_id'],
            $missedDate,
            'CoopWise: Your Paluwagan contribution for ' . $missedDate
                . ' is not recorded. Sign in to check it, or contact the cooperative.'
        );
    }

    if ($stats['failed'] > 0) {
        $stats['status'] = 'failed';
    }
    return $stats;
}

function coopwiseAttemptPaymentReminder(
    PDO $db,
    array &$stats,
    int $memberId,
    string $reminderType,
    int $referenceId,
    string $referenceDate,
    string $content
): void {
    $memberQuery = $db->prepare(
        "SELECT contact_number FROM members
         WHERE id = ? AND status = 'active' AND sms_payment_reminders_enabled = 1"
    );
    $memberQuery->execute([$memberId]);
    $currentPhone = $memberQuery->fetchColumn();
    if (!is_string($currentPhone) || trim($currentPhone) === '') {
        $stats['skipped']++;
        return;
    }

    try {
        $db->prepare(
            "INSERT INTO sms_payment_reminder_logs
                (member_id, reminder_type, reference_id, reference_date, status)
             VALUES (?, ?, ?, ?, 'pending')"
        )->execute([$memberId, $reminderType, $referenceId, $referenceDate]);
    } catch (PDOException $error) {
        if ((int) ($error->errorInfo[1] ?? 0) === 1062) {
            $stats['duplicate']++;
            return;
        }
        throw $error;
    }

    $logId = (int) $db->lastInsertId();
    $stats['attempted']++;
    $recipient = coopwiseNormalizePhilippineSmsPhone($currentPhone);
    try {
        if ($recipient === false) {
            throw new RuntimeException('invalid_phone');
        }
        $result = coopwiseSendBrevoSms($recipient, $content);
        $db->prepare(
            "UPDATE sms_payment_reminder_logs
             SET status = 'sent', provider_message_id = ?, provider_status = ?, sent_at = NOW()
             WHERE id = ? AND status = 'pending'"
        )->execute([$result['message_id'], $result['status'], $logId]);
        $stats['sent']++;
    } catch (Throwable $error) {
        $errorCode = preg_match('/^(?:http_[0-9]{3}|network_error|invalid_phone|sms_payload_error)$/', $error->getMessage())
            ? $error->getMessage()
            : 'delivery_error';
        $providerStatus = preg_match('/^http_([0-9]{3})$/', $errorCode, $matches) ? (int) $matches[1] : null;
        $db->prepare(
            "UPDATE sms_payment_reminder_logs
             SET status = 'failed', error_code = ?, provider_status = ?
             WHERE id = ? AND status = 'pending'"
        )->execute([$errorCode, $providerStatus, $logId]);
        $stats['failed']++;
        error_log(
            'CoopWise Brevo SMS reminder failed; log_id=' . $logId
            . '; type=' . $reminderType . '; error=' . $errorCode
        );
    }
}
