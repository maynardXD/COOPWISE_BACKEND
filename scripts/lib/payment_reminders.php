<?php

declare(strict_types=1);

require_once __DIR__ . '/brevo_email.php';

function sendDailyPaymentReminders(PDO $db): array
{
    $timezone = new DateTimeZone('Asia/Manila');
    $now = new DateTimeImmutable('now', $timezone);
    $today = $now->format('Y-m-d');
    $yesterday = $now->modify('-1 day')->format('Y-m-d');
    $members = [];

    $loanSchedules = $db->prepare(
        "SELECT m.id AS member_id, m.member_number, m.first_name, m.last_name, u.email,
                s.id AS source_id, s.due_date, l.loan_code AS reference,
                ROUND(s.total_due - s.paid_amount, 2) AS amount
         FROM loan_schedules s
         INNER JOIN loans l ON l.id = s.loan_id
         INNER JOIN members m ON m.id = l.member_id
         INNER JOIN users u ON u.id = m.user_id
         LEFT JOIN payment_reminder_log r
           ON r.member_id = m.id
          AND r.reminder_type = 'loan_installment'
          AND r.source_id = s.id
          AND r.due_date = s.due_date
          AND r.status = 'sent'
         WHERE m.status = 'active'
           AND u.status = 'active'
           AND u.email IS NOT NULL
           AND TRIM(u.email) <> ''
           AND l.status IN ('approved', 'active')
           AND s.due_date < ?
           AND s.status IN ('pending', 'overdue')
           AND s.paid_amount < s.total_due
           AND r.id IS NULL
         ORDER BY m.id ASC, s.due_date ASC, s.id ASC"
    );
    $loanSchedules->execute([$today]);
    foreach ($loanSchedules->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $memberId = (int) $row['member_id'];
        $members[$memberId] ??= [
            'member' => $row,
            'items' => []
        ];
        $members[$memberId]['items'][] = [
            'reminder_type' => 'loan_installment',
            'source_id' => (int) $row['source_id'],
            'due_date' => (string) $row['due_date'],
            'reference' => (string) $row['reference'],
            'amount' => (string) $row['amount']
        ];
    }

    $paluwaganContributions = $db->prepare(
        "SELECT m.id AS member_id, m.member_number, m.first_name, m.last_name, u.email,
                c.id AS source_id, c.name AS reference, c.daily_contribution AS amount
         FROM cooperative_cycles c
         INNER JOIN cooperative_cycle_members cm ON cm.cycle_id = c.id
         INNER JOIN members m ON m.id = cm.member_id
         INNER JOIN users u ON u.id = m.user_id
         LEFT JOIN payment_reminder_log r
           ON r.member_id = m.id
          AND r.reminder_type = 'paluwagan_contribution'
          AND r.source_id = c.id
          AND r.due_date = ?
          AND r.status = 'sent'
         WHERE c.status = 'active'
           AND c.start_date <= ?
           AND m.status = 'active'
           AND u.status = 'active'
           AND u.email IS NOT NULL
           AND TRIM(u.email) <> ''
           AND r.id IS NULL
           AND NOT EXISTS (
               SELECT 1
               FROM cooperative_contributions cc
               WHERE cc.cycle_id = c.id
                 AND cc.member_id = m.id
                 AND cc.contribution_date = ?
           )
           AND NOT EXISTS (
               SELECT 1
               FROM cooperative_payouts cp
               WHERE cp.cycle_id = c.id
                 AND cp.status = 'ready'
           )
         ORDER BY m.id ASC, c.id ASC"
    );
    $paluwaganContributions->execute([$yesterday, $yesterday, $yesterday]);
    foreach ($paluwaganContributions->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $memberId = (int) $row['member_id'];
        $members[$memberId] ??= [
            'member' => $row,
            'items' => []
        ];
        $members[$memberId]['items'][] = [
            'reminder_type' => 'paluwagan_contribution',
            'source_id' => (int) $row['source_id'],
            'due_date' => $yesterday,
            'reference' => (string) $row['reference'],
            'amount' => (string) $row['amount']
        ];
    }

    $result = [
        'payment_reminder_emails_sent' => 0,
        'payment_reminder_email_failures' => 0,
        'payment_reminder_items_sent' => 0,
        'payment_reminder_invalid_emails' => 0
    ];

    foreach ($members as $memberId => $reminder) {
        $email = strtolower(trim((string) $reminder['member']['email']));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $result['payment_reminder_invalid_emails']++;
            error_log('CoopWise payment reminder skipped a member with an invalid account email; member_id=' . $memberId);
            continue;
        }

        $payload = paymentReminderBuildEmail($reminder['member'], $reminder['items'], $today);
        $delivery = sendBrevoTransactionalEmail(
            $email,
            $payload['subject'],
            $payload['htmlContent'],
            $payload['textContent']
        );
        $attemptedAt = $now->format('Y-m-d H:i:s');

        $db->beginTransaction();
        try {
            foreach ($reminder['items'] as $item) {
                paymentReminderRecordAttempt(
                    $db,
                    $memberId,
                    $item,
                    $delivery['sent'],
                    $delivery['error_code'],
                    $attemptedAt
                );
            }
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }

        if ($delivery['sent']) {
            $result['payment_reminder_emails_sent']++;
            $result['payment_reminder_items_sent'] += count($reminder['items']);
        } else {
            $result['payment_reminder_email_failures']++;
            error_log(
                'CoopWise payment reminder email failed; member_id=' . $memberId .
                '; error=' . $delivery['error_code']
            );
        }
    }

    return $result;
}

function paymentReminderBuildEmail(array $member, array $items, string $asOfDate): array
{
    if ($items === []) {
        throw new InvalidArgumentException('A payment reminder email must contain at least one missed payment.');
    }

    $escape = static function ($value): string {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    };
    $memberName = trim((string) $member['first_name'] . ' ' . (string) $member['last_name']);
    $htmlRows = '';
    $textRows = [];
    foreach ($items as $item) {
        $isLoan = $item['reminder_type'] === 'loan_installment';
        $label = $isLoan ? 'Loan installment' : 'Daily Paluwagan contribution';
        $reference = (string) $item['reference'];
        $amount = number_format((float) $item['amount'], 2, '.', ',');
        $htmlRows .= '<tr><td>' . $escape($label) . '</td><td>' . $escape($reference) .
            '</td><td>' . $escape($item['due_date']) . '</td><td>PHP ' . $escape($amount) . '</td></tr>';
        $textRows[] = $label . ' — ' . $reference . ', due ' . $item['due_date'] .
            ', PHP ' . $amount;
    }

    $html = '<!doctype html><html><body><p>Hello ' . $escape($memberName) . ',</p>' .
        '<p>CoopWise records show the following payments have not been recorded as of ' .
        $escape($asOfDate) . ':</p>' .
        '<table border="1" cellpadding="6" cellspacing="0"><thead><tr>' .
        '<th>Payment</th><th>Loan or cycle</th><th>Due date</th><th>Amount</th>' .
        '</tr></thead><tbody>' . $htmlRows . '</tbody></table>' .
        '<p>If you have already paid, please disregard this message while the record is updated. ' .
        'If you believe a payment is missing from the record, contact cooperative staff.</p>' .
        '<p>CoopWise</p></body></html>';

    $text = "Hello {$memberName},\n\nCoopWise records show the following payments have not been " .
        "recorded as of {$asOfDate}:\n\n" . implode("\n", $textRows) .
        "\n\nIf you have already paid, please disregard this message while the record is updated. " .
        "If you believe a payment is missing from the record, contact cooperative staff.\n\nCoopWise";

    return [
        'subject' => 'CoopWise payment reminder',
        'htmlContent' => $html,
        'textContent' => $text
    ];
}

function paymentReminderRecordAttempt(
    PDO $db,
    int $memberId,
    array $item,
    bool $sent,
    ?string $errorCode,
    string $attemptedAt
): void {
    $status = $sent ? 'sent' : 'failed';
    $db->prepare(
        'INSERT INTO payment_reminder_log
            (member_id, reminder_type, source_id, due_date, status, attempt_count,
             last_attempt_at, sent_at, last_error_code)
         VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            status = VALUES(status),
            attempt_count = attempt_count + 1,
            last_attempt_at = VALUES(last_attempt_at),
            sent_at = VALUES(sent_at),
            last_error_code = VALUES(last_error_code)'
    )->execute([
        $memberId,
        $item['reminder_type'],
        (int) $item['source_id'],
        $item['due_date'],
        $status,
        $attemptedAt,
        $sent ? $attemptedAt : null,
        $errorCode
    ]);
}
