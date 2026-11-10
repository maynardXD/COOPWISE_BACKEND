<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/scripts/lib/brevo_sms_reminders.php';

function assertSmsValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . '; expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }
}

assertSmsValue('+639171234567', coopwiseNormalizePhilippineSmsPhone('09171234567'), 'local phone normalization');
assertSmsValue('+639171234567', coopwiseNormalizePhilippineSmsPhone('+639171234567'), 'international phone normalization');
assertSmsValue('+639171234567', coopwiseNormalizePhilippineSmsPhone('639171234567'), 'country-code phone normalization');
assertSmsValue(false, coopwiseNormalizePhilippineSmsPhone('0917123456'), 'reject short phone number');
assertSmsValue(false, coopwiseNormalizePhilippineSmsPhone('09171234567x'), 'reject invalid phone characters');

$previousEnabled = getenv('COOPWISE_SMS_REMINDERS_ENABLED');
$previousKey = getenv('BREVO_API_KEY');
$previousSender = getenv('BREVO_SMS_SENDER');
putenv('COOPWISE_SMS_REMINDERS_ENABLED=false');
putenv('BREVO_API_KEY=test-key');
putenv('BREVO_SMS_SENDER=COOPWISE');
assertSmsValue(false, coopwiseBrevoSmsConfigured(), 'SMS remains disabled unless explicitly enabled');
putenv('COOPWISE_SMS_REMINDERS_ENABLED=true');
assertSmsValue(true, coopwiseBrevoSmsConfigured(), 'SMS requires explicit enablement and valid configuration');
putenv('BREVO_SMS_SENDER=invalid sender name');
assertSmsValue(false, coopwiseBrevoSmsConfigured(), 'reject invalid SMS sender name');

foreach ([
    'COOPWISE_SMS_REMINDERS_ENABLED' => $previousEnabled,
    'BREVO_API_KEY' => $previousKey,
    'BREVO_SMS_SENDER' => $previousSender
] as $name => $value) {
    if ($value === false) {
        putenv($name);
    } else {
        putenv($name . '=' . $value);
    }
}

echo "SMS reminder tests passed.\n";
