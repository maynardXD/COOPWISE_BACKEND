<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/financial_workflows.php';

$database = [
    'host' => getenv('DB_HOST') ?: '127.0.0.1',
    'port' => getenv('DB_PORT') ?: '3306',
    'name' => getenv('DB_DATABASE') ?: 'coopwise_db',
    'user' => getenv('DB_USERNAME') ?: 'root',
    'password' => getenv('DB_PASSWORD') ?: '',
    'ssl_ca' => getenv('DB_SSL_CA') ?: ''
];
$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    $database['host'],
    $database['port'],
    $database['name']
);
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false
];
if ($database['ssl_ca'] !== '' && defined('PDO::MYSQL_ATTR_SSL_CA')) {
    $options[PDO::MYSQL_ATTR_SSL_CA] = $database['ssl_ca'];
}

$db = new PDO($dsn, $database['user'], $database['password'], $options);
$today = date('Y-m-d');
$runId = bin2hex(random_bytes(16));
$startedAt = date('Y-m-d H:i:s');
$db->prepare(
    "INSERT INTO auto_pay_runs (run_id, status, started_at) VALUES (?, 'running', ?)"
)->execute([$runId, $startedAt]);
$processed = [
    'members_checked' => 0,
    'loan_installments' => 0,
    'paluwagan_contributions' => 0,
    'partial_loan_installments' => 0,
    'skipped_loan_installments' => 0,
    'skipped_paluwagan_contributions' => 0,
    'errors' => 0
];
try {
    $members = $db->query(
        "SELECT id, loan_autopay_enabled, paluwagan_autopay_enabled
         FROM members
         WHERE status = 'active'
           AND (loan_autopay_enabled = 1 OR paluwagan_autopay_enabled = 1)
         ORDER BY id ASC"
    )->fetchAll();
} catch (Throwable $error) {
    $details = json_encode(['error' => 'member_selection_failed'], JSON_UNESCAPED_SLASHES);
    $db->prepare(
        "UPDATE auto_pay_runs SET status = 'failed', completed_at = ?, error_count = 1, details = ? WHERE run_id = ?"
    )->execute([date('Y-m-d H:i:s'), $details, $runId]);
    error_log('CoopWise daily savings auto-pay could not select members; run=' . $runId . ': ' . $error->getMessage());
    exit(1);
}

foreach ($members as $member) {
    $memberId = (int) $member['id'];
    $processed['members_checked']++;
    $db->beginTransaction();
    try {
        $memberLock = $db->prepare(
            'SELECT loan_autopay_enabled, paluwagan_autopay_enabled
             FROM members WHERE id = ? AND status = ? FOR UPDATE'
        );
        $memberLock->execute([$memberId, 'active']);
        $memberSettings = $memberLock->fetch();
        if (!$memberSettings) {
            $db->commit();
            continue;
        }
        $balance = savingsBalance($db, $memberId);

        if ((int) $memberSettings['loan_autopay_enabled'] === 1) {
            $loanTotals = [];
            $schedules = $db->prepare(
                "SELECT s.*, l.member_id, l.current_balance, l.total_paid
                 FROM loan_schedules s
                 INNER JOIN loans l ON l.id = s.loan_id
                 WHERE l.member_id = ?
                   AND l.status IN ('approved', 'active')
                   AND s.due_date <= ?
                   AND s.status IN ('pending', 'overdue')
                 ORDER BY s.due_date ASC, s.id ASC
                 FOR UPDATE"
            );
            $schedules->execute([$memberId, $today]);
            foreach ($schedules->fetchAll() as $schedule) {
                $due = moneyToCents((string) $schedule['total_due']);
                $alreadyPaid = moneyToCents((string) $schedule['paid_amount']);
                $remainingDue = max(0, $due - $alreadyPaid);
                if ($remainingDue === 0) {
                    $db->prepare("UPDATE loan_schedules SET status = 'paid' WHERE id = ?")->execute([(int) $schedule['id']]);
                    continue;
                }
                $loanId = (int) $schedule['loan_id'];
                if (!isset($loanTotals[$loanId])) {
                    $loanTotals[$loanId] = [
                        'balance' => moneyToCents((string) $schedule['current_balance']),
                        'paid' => moneyToCents((string) $schedule['total_paid'])
                    ];
                }
                if ($balance < 1 || $loanTotals[$loanId]['balance'] < 1) {
                    if ($balance < 1) {
                        $processed['skipped_loan_installments']++;
                    }
                    continue;
                }

                $payment = autoPayLoanAmount($balance, $remainingDue, $loanTotals[$loanId]['balance']);
                if ($payment < 1) {
                    continue;
                }
                if ($payment < $remainingDue) {
                    $processed['partial_loan_installments']++;
                }

                $reference = 'CW-AUTO-LN-' . (int) $schedule['id'] . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(3));
                $db->prepare(
                    'INSERT INTO savings_transactions
                        (member_id, type, amount, balance_after, reference_no, description, created_by_user_id)
                     VALUES (?, ?, ?, ?, ?, ?, NULL)'
                )->execute([
                    $memberId,
                    'withdrawal',
                    formatCents($payment),
                    formatCents($balance - $payment),
                    $reference,
                    'Opt-in savings auto-payment for loan installment due ' . $schedule['due_date']
                ]);
                $db->prepare(
                    'INSERT INTO loan_payments
                        (loan_id, member_id, paid_by_user_id, amount_paid, payment_method, reference_no, payment_date)
                     VALUES (?, ?, NULL, ?, ?, ?, NOW())'
                )->execute([
                    (int) $schedule['loan_id'],
                    $memberId,
                    formatCents($payment),
                    'savings',
                    $reference
                ]);

                $paid = $alreadyPaid + $payment;
                $scheduleStatus = $paid >= $due ? 'paid' : ($schedule['due_date'] < $today ? 'overdue' : 'pending');
                $db->prepare('UPDATE loan_schedules SET paid_amount = ?, status = ? WHERE id = ?')
                    ->execute([formatCents($paid), $scheduleStatus, (int) $schedule['id']]);
                $loanBalance = max(0, $loanTotals[$loanId]['balance'] - $payment);
                $loanPaid = $loanTotals[$loanId]['paid'] + $payment;
                $db->prepare('UPDATE loans SET current_balance = ?, total_paid = ?, status = ? WHERE id = ?')
                    ->execute([
                        formatCents($loanBalance),
                        formatCents($loanPaid),
                        $loanBalance === 0 ? 'completed' : 'active',
                        $loanId
                    ]);
                $loanTotals[$loanId] = ['balance' => $loanBalance, 'paid' => $loanPaid];
                $balance -= $payment;
                $processed['loan_installments']++;
            }
        }

        if ((int) $memberSettings['paluwagan_autopay_enabled'] === 1) {
            $cycles = $db->prepare(
                "SELECT c.id, c.daily_contribution, cm.payout_order
                 FROM cooperative_cycles c
                 INNER JOIN cooperative_cycle_members cm ON cm.cycle_id = c.id
                 WHERE cm.member_id = ? AND c.status = 'active' AND c.start_date <= ?
                 ORDER BY c.id ASC
                 FOR UPDATE"
            );
            $cycles->execute([$memberId, $today]);
            foreach ($cycles->fetchAll() as $cycle) {
                $cycleId = (int) $cycle['id'];
                $readyPayout = $db->prepare("SELECT id FROM cooperative_payouts WHERE cycle_id = ? AND status = 'ready' LIMIT 1");
                $readyPayout->execute([$cycleId]);
                if ($readyPayout->fetch()) {
                    continue;
                }
                $existing = $db->prepare(
                    'SELECT id FROM cooperative_contributions WHERE cycle_id = ? AND member_id = ? AND contribution_date = ? LIMIT 1'
                );
                $existing->execute([$cycleId, $memberId, $today]);
                if ($existing->fetch()) {
                    continue;
                }

                $amount = moneyToCents((string) $cycle['daily_contribution']);
                if ($balance < $amount) {
                    $processed['skipped_paluwagan_contributions']++;
                    continue;
                }

                $reference = 'CW-AUTO-COOP-' . $cycleId . '-' . $memberId . '-' . $today;
                $db->prepare(
                    'INSERT INTO savings_transactions
                        (member_id, type, amount, balance_after, reference_no, description, created_by_user_id)
                     VALUES (?, ?, ?, ?, ?, ?, NULL)'
                )->execute([
                    $memberId,
                    'withdrawal',
                    formatCents($amount),
                    formatCents($balance - $amount),
                    $reference,
                    'Opt-in automatic daily Paluwagan contribution for ' . $today
                ]);
                $db->prepare(
                    'INSERT INTO cooperative_contributions
                        (cycle_id, member_id, contribution_date, amount, reference_no, recorded_by_user_id)
                     VALUES (?, ?, ?, ?, ?, NULL)'
                )->execute([$cycleId, $memberId, $today, formatCents($amount), $reference]);
                $balance -= $amount;
                $processed['paluwagan_contributions']++;

                createPayoutWhenRoundIsComplete($db, $cycleId, $today);
            }
        }
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $processed['errors']++;
        error_log('CoopWise daily savings auto-pay failed for member ' . $memberId . ': ' . $error->getMessage());
    }
}

try {
    $db->prepare(
        'UPDATE auto_pay_runs SET status = ?, completed_at = ?, members_checked = ?,
            loan_installments = ?, paluwagan_contributions = ?, partial_loan_installments = ?,
            skipped_loan_installments = ?, skipped_paluwagan_contributions = ?, error_count = ?, details = ?
         WHERE run_id = ?'
    )->execute([
        $processed['errors'] > 0 ? 'failed' : 'completed',
        date('Y-m-d H:i:s'),
        $processed['members_checked'],
        $processed['loan_installments'],
        $processed['paluwagan_contributions'],
        $processed['partial_loan_installments'],
        $processed['skipped_loan_installments'],
        $processed['skipped_paluwagan_contributions'],
        $processed['errors'],
        json_encode($processed, JSON_UNESCAPED_SLASHES),
        $runId
    ]);
} catch (Throwable $error) {
    error_log('CoopWise daily savings auto-pay run summary could not be saved; run=' . $runId . ': ' . $error->getMessage());
    exit(1);
}
$processed['run_id'] = $runId;
$processed['status'] = $processed['errors'] > 0 ? 'failed' : 'completed';
echo json_encode($processed, JSON_UNESCAPED_SLASHES) . PHP_EOL;
if ($processed['errors'] > 0) {
    exit(1);
}

function createPayoutWhenRoundIsComplete(PDO $db, int $cycleId, string $today): void
{
    $count = $db->prepare('SELECT COUNT(*) FROM cooperative_cycle_members WHERE cycle_id = ?');
    $count->execute([$cycleId]);
    $memberCount = (int) $count->fetchColumn();
    if ($memberCount < 1) {
        return;
    }

    $contributions = $db->prepare('SELECT COUNT(*) FROM cooperative_contributions WHERE cycle_id = ? AND contribution_date = ?');
    $contributions->execute([$cycleId, $today]);
    if ((int) $contributions->fetchColumn() !== $memberCount) {
        return;
    }

    $pending = $db->prepare("SELECT id FROM cooperative_payouts WHERE cycle_id = ? AND status = 'ready' LIMIT 1");
    $pending->execute([$cycleId]);
    if ($pending->fetch()) {
        return;
    }

    $recipientQuery = $db->prepare(
        'SELECT cm.member_id, cm.payout_order
         FROM cooperative_cycle_members cm
         LEFT JOIN cooperative_payouts cp ON cp.cycle_id = cm.cycle_id AND cp.member_id = cm.member_id
         WHERE cm.cycle_id = ? AND cp.id IS NULL
         ORDER BY cm.payout_order ASC LIMIT 1'
    );
    $recipientQuery->execute([$cycleId]);
    $recipient = $recipientQuery->fetch();
    if (!$recipient) {
        throw new RuntimeException('No unassigned member remains in the completed Paluwagan round.');
    }

    $cycleQuery = $db->prepare('SELECT daily_contribution FROM cooperative_cycles WHERE id = ?');
    $cycleQuery->execute([$cycleId]);
    $dailyContribution = $cycleQuery->fetchColumn();
    $reference = 'CW-PAYOUT-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
    $db->prepare(
        "INSERT INTO cooperative_payouts
            (cycle_id, member_id, payout_order, payout_date, amount, reference_no, status)
         VALUES (?, ?, ?, ?, ?, ?, 'ready')"
    )->execute([
        $cycleId,
        (int) $recipient['member_id'],
        (int) $recipient['payout_order'],
        $today,
        formatCents(moneyToCents((string) $dailyContribution) * $memberCount),
        $reference
    ]);
}

function savingsBalance(PDO $db, int $memberId): int
{
    $query = $db->prepare(
        "SELECT COALESCE(SUM(CASE WHEN type = 'deposit' THEN amount ELSE -amount END), 0)
         FROM savings_transactions WHERE member_id = ?"
    );
    $query->execute([$memberId]);
    return moneyToCents((string) $query->fetchColumn());
}
