<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/scripts/lib/payment_reminders.php';

function assertReminderContains(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        throw new RuntimeException($message . '; missing ' . var_export($needle, true));
    }
}

$payload = paymentReminderBuildEmail(
    [
        'first_name' => '<Member>',
        'last_name' => 'One'
    ],
    [
        [
            'reminder_type' => 'loan_installment',
            'source_id' => 17,
            'due_date' => '2026-11-15',
            'reference' => 'LN-00017',
            'amount' => '250.00'
        ],
        [
            'reminder_type' => 'paluwagan_contribution',
            'source_id' => 4,
            'due_date' => '2026-11-16',
            'reference' => '<Cycle A>',
            'amount' => '50.00'
        ]
    ],
    '2026-11-17'
);

assertReminderContains('Loan installment', $payload['htmlContent'], 'The reminder should identify missed loan installments');
assertReminderContains('Daily Paluwagan contribution', $payload['htmlContent'], 'The reminder should identify missed daily contributions');
assertReminderContains('PHP 250.00', $payload['textContent'], 'The loan reminder should include the amount still due');
assertReminderContains('PHP 50.00', $payload['textContent'], 'The Paluwagan reminder should include the missed contribution amount');
assertReminderContains('&lt;Member&gt;', $payload['htmlContent'], 'Member names should be escaped in HTML');
assertReminderContains('&lt;Cycle A&gt;', $payload['htmlContent'], 'Cycle names should be escaped in HTML');

$emptyReminderRejected = false;
try {
    paymentReminderBuildEmail(['first_name' => 'Member', 'last_name' => 'One'], [], '2026-11-17');
} catch (InvalidArgumentException $error) {
    $emptyReminderRejected = true;
}
if (!$emptyReminderRejected) {
    throw new RuntimeException('An empty payment reminder should be rejected.');
}

echo "Payment reminder tests passed.\n";
