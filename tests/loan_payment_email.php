<?php
// This test checks the loan payment email content.


declare(strict_types=1);

define('PREVENT_DIRECT_ACCESS', true);

class Controller {}

require_once dirname(__DIR__) . '/app/controllers/ApiLoans.php';

// This function checks that the loan email includes the required details.
function assertLoanEmailContains(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        throw new RuntimeException($message . '; missing ' . var_export($needle, true));
    }
}

$buildEmail = new ReflectionMethod(ApiLoans::class, 'loan_payment_email_content');
$buildEmail->setAccessible(true);
$payload = $buildEmail->invoke(null, [
    'id' => 28,
    'loan_code' => 'CW-LN-000028',
    'member_number' => '000014',
    'first_name' => '<Member>',
    'last_name' => 'Sample',
    'loan_type_name' => 'Emergency loan',
    'status' => 'active',
    'created_at' => '2026-10-17 09:30:00',
    'principal_amount' => '1000.00',
    'approved_amount' => '950.00',
    'current_balance' => '475.00',
    'total_paid' => '475.00',
    'interest_rate' => '5.00',
    'overdue_interest_rate' => '6.00',
    'overdue_interest_balance' => '12.34',
    'term_months' => 2,
    'purpose' => '<Testing>'
], [
    [
        'due_date' => '2026-11-17',
        'principal_due' => '475.00',
        'interest_due' => '25.00',
        'total_due' => '500.00',
        'paid_amount' => '25.00',
        'overdue_interest_accrued' => '12.34',
        'overdue_interest_paid' => '0.00',
        'status' => 'overdue'
    ]
]);

assertLoanEmailContains('CW-LN-000028', $payload['textContent'], 'The email should include the loan ID');
assertLoanEmailContains('PHP 475.00', $payload['textContent'], 'The email should include the current loan balance');
assertLoanEmailContains('2026-11-17', $payload['textContent'], 'The email should include the due date');
assertLoanEmailContains('Emergency loan', $payload['textContent'], 'The email should identify the loan offer');
assertLoanEmailContains('Overdue interest balance: PHP 12.34', $payload['textContent'], 'The email should include the accrued overdue-interest balance');
assertLoanEmailContains('6.00% per year', $payload['textContent'], 'The email should disclose the agreed overdue-interest rate');
assertLoanEmailContains('Remaining PHP 487.34', $payload['textContent'], 'The schedule should include overdue interest in the remaining installment balance');
assertLoanEmailContains('&lt;Member&gt;', $payload['htmlContent'], 'The member name should be escaped in HTML');
assertLoanEmailContains('&lt;Testing&gt;', $payload['htmlContent'], 'The loan purpose should be escaped in HTML');

echo "Loan payment email tests passed.\n";
