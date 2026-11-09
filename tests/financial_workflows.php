<?php

declare(strict_types=1);

define('PREVENT_DIRECT_ACCESS', true);

class Controller {}

require_once dirname(__DIR__) . '/scheme/libraries/Api.php';
require_once dirname(__DIR__) . '/app/controllers/ApiCheckout.php';
require_once dirname(__DIR__) . '/scripts/lib/cooperative_finance.php';
require_once dirname(__DIR__) . '/scripts/lib/financial_workflows.php';

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . '; expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }
}

class WorkflowTestStatement
{
    private $result;

    public function __construct($result)
    {
        $this->result = $result;
    }

    public function fetch($mode = null)
    {
        return $this->result;
    }
}

class WorkflowTestTable
{
    private $database;

    public function __construct(WorkflowTestDatabase $database)
    {
        $this->database = $database;
    }

    public function insert(array $row)
    {
        $this->database->transactions[] = $row;
        return count($this->database->transactions);
    }
}

class WorkflowTestDatabase
{
    public $transactions = [];
    public $opening_balance = 2500;

    public function raw(string $sql, array $params = [])
    {
        if (strpos($sql, 'SELECT amount FROM savings_transactions') !== false) {
            foreach ($this->transactions as $transaction) {
                if ($transaction['member_id'] === $params[0]
                    && $transaction['type'] === $params[1]
                    && $transaction['reference_no'] === $params[2]) {
                    return new WorkflowTestStatement(['amount' => $transaction['amount']]);
                }
            }
            return new WorkflowTestStatement(false);
        }

        if (strpos($sql, 'SELECT COALESCE(SUM') !== false) {
            $balance = $this->opening_balance;
            foreach ($this->transactions as $transaction) {
                $amount = (int) round((float) $transaction['amount'] * 100);
                $balance += $transaction['type'] === 'deposit' ? $amount : -$amount;
            }
            return new WorkflowTestStatement(['balance' => number_format($balance / 100, 2, '.', '')]);
        }

        return new WorkflowTestStatement(['id' => 7]);
    }

    public function table(string $name)
    {
        if ($name !== 'savings_transactions') {
            throw new RuntimeException('Unexpected table access in deposit test: ' . $name);
        }
        return new WorkflowTestTable($this);
    }
}

$controller = (new ReflectionClass(ApiCheckout::class))->newInstanceWithoutConstructor();
$database = new WorkflowTestDatabase();
$databaseProperty = new ReflectionProperty(ApiCheckout::class, 'db');
$databaseProperty->setAccessible(true);
$databaseProperty->setValue($controller, $database);
$applyDeposit = new ReflectionMethod(ApiCheckout::class, 'apply_savings_deposit');
$applyDeposit->setAccessible(true);
$payment = [
    'member_id' => 7,
    'amount' => '10.00',
    'reference_no' => 'CW-PAY-TEST-1'
];

$applyDeposit->invoke($controller, $payment);
assertSameValue(1, count($database->transactions), 'A confirmed deposit should create one ledger row');
assertSameValue('35.00', $database->transactions[0]['balance_after'], 'The ledger should record the post-deposit balance');
$applyDeposit->invoke($controller, $payment);
assertSameValue(1, count($database->transactions), 'A repeated provider confirmation must not credit twice');

$moneyParser = new ReflectionMethod(ApiCheckout::class, 'money_to_cents');
$moneyParser->setAccessible(true);
assertSameValue(12345, $moneyParser->invoke(null, '123.45'), 'Money values should use integer cents');
assertSameValue(false, $moneyParser->invoke(null, '12.345'), 'Amounts with excess precision must be rejected');
$paymentMethodParser = new ReflectionMethod(ApiCheckout::class, 'payment_method_for_reference');
$paymentMethodParser->setAccessible(true);
assertSameValue('savings', $paymentMethodParser->invoke(null, 'CW-SAV-PAY-test'), 'Savings references should be labeled as savings payments');
assertSameValue('xendit', $paymentMethodParser->invoke(null, 'CW-PAY-test'), 'Provider payment references should be labeled as Xendit payments');
assertSameValue(3000, autoPayLoanAmount(3000, 10000, 12000), 'Auto-pay should apply available savings as a partial installment');
assertSameValue(8000, autoPayLoanAmount(12000, 8000, 10000), 'Auto-pay should not collect more than the remaining installment');
assertSameValue(0, autoPayLoanAmount(0, 8000, 10000), 'Auto-pay should skip an installment when savings are empty');
$eligibility = cooperativeLoanEligibility(500000, 0, 50000, 45, false, true, 500000, 0, 30, 1.0, 300000);
assertSameValue(true, $eligibility['eligible'], 'A qualified member should be able to apply within the product cap');
assertSameValue(300000, $eligibility['max_eligible_amount_cents'], 'The eligible amount should respect the product cap and existing exposure');
$ineligible = cooperativeLoanEligibility(400000, 0, 0, 20, false, true, 500000, 0, 30, 1.0);
assertSameValue(false, $ineligible['eligible'], 'Members below configured share and membership thresholds should not be eligible');
assertSameValue(2, count($ineligible['reasons']), 'Eligibility should explain each unmet sample-policy requirement');
$allocations = cooperativeSurplusAllocations(1000000, 2025, '2020-06-01', 10, 5, 3, 0, 50);
assertSameValue(100000, $allocations['reserve_allocation'], 'The reserve allocation should use the configured share');
assertSameValue(50000, $allocations['education_allocation'], 'The education allocation should use the configured share');
assertSameValue(30000, $allocations['community_allocation'], 'The community allocation should use the configured share');
assertSameValue(410000, $allocations['share_capital_pool'], 'Half of the distributable remainder should go to share-capital interest');
assertSameValue(410000, $allocations['patronage_pool'], 'The remainder should go to patronage refunds');
$currentYearRejected = false;
try {
    cooperativeSurplusAllocations(100000, (int) date('Y'), '2020-01-01', 10, 5, 3, 0, 50);
} catch (InvalidArgumentException $error) {
    $currentYearRejected = true;
}
assertSameValue(true, $currentYearRejected, 'Surplus distributions should only use completed fiscal years');
$firstFiveYearRejected = false;
try {
    cooperativeSurplusAllocations(100000, 2025, '2022-01-01', 49, 5, 3, 0, 50);
} catch (InvalidArgumentException $error) {
    $firstFiveYearRejected = true;
}
assertSameValue(true, $firstFiveYearRejected, 'The first-five-years reserve floor should be enforced');
$proportional = cooperativeProportionalAllocation(100, [1 => 1, 2 => 2]);
assertSameValue(100, array_sum($proportional), 'Proportional allocation should distribute every cent');
assertSameValue(33, $proportional[1], 'Rounding remainders should be distributed deterministically');
assertSameValue(67, $proportional[2], 'The larger patronage basis should receive the larger share');
$repayment = allocateLoanRepayment(2000, [
    ['id' => 1, 'interest_due' => '5.00', 'principal_due' => '10.00', 'interest_paid' => '0.00', 'principal_paid' => '0.00', 'total_due' => '15.00', 'status' => 'pending'],
    ['id' => 2, 'interest_due' => '2.00', 'principal_due' => '10.00', 'interest_paid' => '0.00', 'principal_paid' => '0.00', 'total_due' => '12.00', 'status' => 'pending'],
]);
assertSameValue(1300, $repayment['principal_paid'], 'Repayments should restore principal after interest is covered');
assertSameValue(700, $repayment['interest_paid'], 'Repayments should track interest separately from principal');
assertSameValue('3.00', $repayment['schedules'][1]['principal_paid'], 'Partial repayment should be applied to the next schedule in order');

$api = (new ReflectionClass(Api::class))->newInstanceWithoutConstructor();
$previousQuery = $_GET;
$_GET = ['page' => '2', 'per_page' => '500'];
assertSameValue(
    ['page' => 2, 'per_page' => 100, 'offset' => 100],
    $api->pagination_params(),
    'Pagination should enforce its maximum page size'
);
$_GET = ['page' => 'invalid', 'per_page' => '-1'];
assertSameValue(
    ['page' => 1, 'per_page' => 1, 'offset' => 0],
    $api->pagination_params(),
    'Invalid pagination inputs should safely fall back and clamp'
);
$_GET = $previousQuery;

echo "Financial workflow unit tests passed.\n";
