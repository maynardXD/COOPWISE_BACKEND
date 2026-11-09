<?php

declare(strict_types=1);

define('PREVENT_DIRECT_ACCESS', true);

class Controller {}

require_once dirname(__DIR__) . '/scheme/libraries/Api.php';
require_once dirname(__DIR__) . '/app/controllers/ApiCheckout.php';
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
assertSameValue(3000, autoPayLoanAmount(3000, 10000, 12000), 'Auto-pay should apply available savings as a partial installment');
assertSameValue(8000, autoPayLoanAmount(12000, 8000, 10000), 'Auto-pay should not collect more than the remaining installment');
assertSameValue(0, autoPayLoanAmount(0, 8000, 10000), 'Auto-pay should skip an installment when savings are empty');

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
