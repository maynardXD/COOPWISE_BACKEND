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
assertSameValue('staff', $paymentMethodParser->invoke(null, 'CW-STAFF-PAY-test'), 'Staff-recorded payments should be distinguishable from provider checkout');
assertSameValue('xendit', $paymentMethodParser->invoke(null, 'CW-PAY-test'), 'Provider payment references should be labeled as Xendit payments');
assertSameValue(3000, autoPayLoanAmount(3000, 10000, 12000), 'Auto-pay should apply available savings as a partial installment');
assertSameValue(8000, autoPayLoanAmount(12000, 8000, 10000), 'Auto-pay should not collect more than the remaining installment');
assertSameValue(0, autoPayLoanAmount(0, 8000, 10000), 'Auto-pay should skip an installment when savings are empty');
$overdueSchedule = [
    'due_date' => '2026-01-01',
    'principal_due' => '1000.00',
    'principal_paid' => '0.00',
    'overdue_interest_accrued' => '0.00',
    'overdue_interest_paid' => '0.00',
    'overdue_interest_remainder_units' => 0,
    'overdue_interest_calculated_at' => null
];
$dueDateAccrual = cooperativeAccrueLoanScheduleOverdueInterest($overdueSchedule, 600, '2026-01-01');
assertSameValue('0.00', $dueDateAccrual['overdue_interest_accrued'], 'Overdue interest should not accrue on the installment due date');
$dailyAccrual = cooperativeAccrueLoanScheduleOverdueInterest($overdueSchedule, 600, '2026-01-02');
assertSameValue('0.16', $dailyAccrual['overdue_interest_accrued'], 'Overdue interest should accrue at 6% per year on the unpaid principal');
$overdueSchedule = array_merge($overdueSchedule, $dailyAccrual);
$secondDailyAccrual = cooperativeAccrueLoanScheduleOverdueInterest($overdueSchedule, 600, '2026-01-03');
assertSameValue('0.33', $secondDailyAccrual['overdue_interest_accrued'], 'Sub-cent daily interest should carry forward without rounding drift');
$overdueSchedule = array_merge($overdueSchedule, $secondDailyAccrual);
$thirdDailyAccrual = cooperativeAccrueLoanScheduleOverdueInterest($overdueSchedule, 600, '2026-01-04');
assertSameValue('0.49', $thirdDailyAccrual['overdue_interest_accrued'], 'Repeated daily accrual should match cumulative simple interest');
$partialAccrualSchedule = array_merge($overdueSchedule, $thirdDailyAccrual, ['principal_paid' => '500.00']);
$afterPartialPrincipalPayment = cooperativeAccrueLoanScheduleOverdueInterest($partialAccrualSchedule, 600, '2026-01-05');
assertSameValue('0.58', $afterPartialPrincipalPayment['overdue_interest_accrued'], 'Future overdue interest should use only the remaining installment principal');
$zeroRateAccrual = cooperativeAccrueLoanScheduleOverdueInterest($overdueSchedule, 0, '2026-01-05');
assertSameValue('0.33', $zeroRateAccrual['overdue_interest_accrued'], 'A zero overdue rate should not add interest');
$cappedAccrual = cooperativeAccrueLoanScheduleOverdueInterest([
    'due_date' => '2024-01-01',
    'principal_due' => '100.00',
    'principal_paid' => '0.00',
    'overdue_interest_accrued' => '99.99',
    'overdue_interest_paid' => '0.00',
    'overdue_interest_remainder_units' => 0,
    'overdue_interest_calculated_at' => '2026-01-01'
], 600, '2026-01-02');
assertSameValue('100.00', $cappedAccrual['overdue_interest_accrued'], 'Overdue interest should not exceed the installment principal');
$eligibility = cooperativeLoanEligibility(500000, 0, 50000, 45, false, true, 500000, 0, 30, 1.0, 300000);
assertSameValue(true, $eligibility['eligible'], 'A qualified member should be able to apply within the product cap');
assertSameValue(300000, $eligibility['max_eligible_amount_cents'], 'The eligible amount should respect the product cap and existing exposure');
assertSameValue(0, cooperativeLoanCapitalCapacity(5000, 0, 5000), 'A fully lent share-capital balance should have no remaining loan capacity');
assertSameValue(2000, cooperativeLoanCapitalCapacity(5000, 0, 3000), 'Principal repayments should restore available loan capacity');
assertSameValue(6000, cooperativeLoanCapitalCapacity(5000, 1000, 0), 'Collected loan interest should increase cooperative loan capacity');
assertSameValue(0, cooperativeLoanCapitalCapacity(5000, 0, 0, 5000), 'Pending applications should reserve available loan capacity');
assertSameValue(7500, cooperativeAvailableRepayment(10000, 2500), 'A pending checkout should reserve part of the loan balance');
assertSameValue(0, cooperativeAvailableRepayment(10000, 12000), 'Reserved payments should not make available repayment negative');
$simulatedMembershipDays = new ReflectionFunction('cooperativeSimulatedMembershipDays');
assertSameValue(0, $simulatedMembershipDays->invoke(5), 'A simulated membership day should not complete before six seconds');
assertSameValue(1, $simulatedMembershipDays->invoke(6), 'One simulated membership day should complete after six seconds');
assertSameValue(30, $simulatedMembershipDays->invoke(180), 'The default 30-day membership requirement should take three minutes in the demo');
assertSameValue(30, $simulatedMembershipDays->invoke(189), 'The 31st accelerated membership day should not finish before 3:10');
assertSameValue(31, $simulatedMembershipDays->invoke(190), 'The 31-day membership requirement should finish after 3 minutes and 10 seconds');
assertSameValue(180, cooperativeMembershipRequiredSeconds(30), 'The default 30-day membership requirement should take three minutes');
assertSameValue(190, cooperativeMembershipRequiredSeconds(31), 'A 31-day membership policy should display a 3:10 waiting time');
assertSameValue(200, cooperativeMembershipRequiredSeconds(32), 'Each membership day after day 30 should add ten seconds');
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
$lateRepayment = allocateLoanRepayment(600, [[
    'id' => 3,
    'interest_due' => '5.00',
    'principal_due' => '10.00',
    'interest_paid' => '0.00',
    'principal_paid' => '0.00',
    'overdue_interest_accrued' => '1.00',
    'overdue_interest_paid' => '0.00',
    'overdue_interest_remainder_units' => 0,
    'total_due' => '15.00',
    'paid_amount' => '0.00',
    'status' => 'overdue'
]]);
assertSameValue(100, $lateRepayment['overdue_interest_paid'], 'Repayments should cover overdue interest before regular installment amounts');
assertSameValue(500, $lateRepayment['interest_paid'], 'Partial repayments should then cover scheduled interest');
assertSameValue('5.00', $lateRepayment['schedules'][0]['paid_amount'], 'Overdue interest should not be included in the contractual installment paid amount');
$lateOverpaymentRejected = false;
try {
    allocateLoanRepayment(1601, [[
        'id' => 4,
        'interest_due' => '5.00',
        'principal_due' => '10.00',
        'interest_paid' => '0.00',
        'principal_paid' => '0.00',
        'overdue_interest_accrued' => '1.00',
        'overdue_interest_paid' => '0.00',
        'overdue_interest_remainder_units' => 0,
        'total_due' => '15.00',
        'paid_amount' => '0.00',
        'status' => 'overdue'
    ]]);
} catch (InvalidArgumentException $error) {
    $lateOverpaymentRejected = true;
}
assertSameValue(true, $lateOverpaymentRejected, 'Repayments should reject amounts above all scheduled and overdue-interest balances');

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
