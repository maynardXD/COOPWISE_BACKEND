<?php

declare(strict_types=1);

/*
 * This file has shared money and cooperative finance calculations.
 * The API and automatic payment script use the same rules from here.
 */
function cooperativeMoneyToCents(string $amount): int
{
    if (!preg_match('/^(?:0|[1-9]\d{0,9})(?:\.\d{1,2})?$/', $amount)) {
        throw new InvalidArgumentException('A stored cooperative financial amount is invalid.');
    }
    [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
    return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0', STR_PAD_RIGHT);
}

// This function formats cents as a two-decimal money amount.
function cooperativeFormatCents(int $amount): string
{
    return intdiv($amount, 100) . '.' . str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
}

// This function calculates the loan amount that is not reserved by checkout.
function cooperativeAvailableRepayment(int $balance, int $reservedPayments): int
{
    return max(0, $balance - max(0, $reservedPayments));
}

// This calculates overdue interest for an installment without changing its original loan terms.
function cooperativeAccrueLoanScheduleOverdueInterest(array $schedule, int $annualRateBasisPoints, string $today): array
{
    $denominator = 10000 * 365;
    $timezone = new DateTimeZone('Asia/Manila');
    $asOfDate = DateTimeImmutable::createFromFormat('!Y-m-d', $today, $timezone);
    $dueDate = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $schedule['due_date'], $timezone);
    if (!$asOfDate || !$dueDate || $asOfDate->format('Y-m-d') !== $today
        || $dueDate->format('Y-m-d') !== (string) $schedule['due_date']) {
        throw new InvalidArgumentException('Loan dates must use the YYYY-MM-DD format.');
    }

    $principalDue = cooperativeMoneyToCents((string) $schedule['principal_due']);
    $principalPaid = cooperativeMoneyToCents((string) ($schedule['principal_paid'] ?? '0.00'));
    $accrued = cooperativeMoneyToCents((string) ($schedule['overdue_interest_accrued'] ?? '0.00'));
    $paid = cooperativeMoneyToCents((string) ($schedule['overdue_interest_paid'] ?? '0.00'));
    $remainderUnits = (int) ($schedule['overdue_interest_remainder_units'] ?? 0);
    if (abs($remainderUnits) > intdiv($denominator, 2)) {
        throw new InvalidArgumentException('The overdue-interest remainder is invalid.');
    }
    $lastCalculated = !empty($schedule['overdue_interest_calculated_at'])
        ? DateTimeImmutable::createFromFormat('!Y-m-d', (string) $schedule['overdue_interest_calculated_at'], $timezone)
        : $dueDate;
    if (!$lastCalculated) {
        throw new InvalidArgumentException('The overdue-interest calculation date is invalid.');
    }

    $newAccrued = $accrued;
    $newRemainderUnits = $remainderUnits;
    $calculatedAt = $schedule['overdue_interest_calculated_at'] ?? null;
    if ($annualRateBasisPoints > 0 && $asOfDate > $dueDate && $principalDue > $principalPaid) {
        if ($lastCalculated < $dueDate) {
            $lastCalculated = $dueDate;
        }
        $days = $lastCalculated < $asOfDate ? (int) $lastCalculated->diff($asOfDate)->days : 0;
        if ($days > 0) {
            $unpaidPrincipal = $principalDue - $principalPaid;
            $numerator = $unpaidPrincipal * $annualRateBasisPoints * $days + $remainderUnits;
            $increment = intdiv($numerator + intdiv($denominator, 2), $denominator);
            $newRemainderUnits = $numerator - ($increment * $denominator);
            $newAccrued = min($principalDue, $accrued + $increment);
            if ($newAccrued === $principalDue) {
                $newRemainderUnits = 0;
            }
        }
        $calculatedAt = $today;
    } elseif ($annualRateBasisPoints > 0 && $principalDue <= $principalPaid) {
        $newRemainderUnits = 0;
    } elseif ($annualRateBasisPoints <= 0) {
        $newRemainderUnits = 0;
    }

    return [
        'overdue_interest_accrued' => cooperativeFormatCents($newAccrued),
        'overdue_interest_paid' => cooperativeFormatCents($paid),
        'overdue_interest_calculated_at' => $calculatedAt,
        'overdue_interest_remainder_units' => $newRemainderUnits,
        'overdue_interest_balance_cents' => max(0, $newAccrued - $paid),
        'new_overdue_interest_cents' => $newAccrued - $accrued
    ];
}

// This calculates lending capacity after interest returns and existing loans are counted.
function cooperativeLoanCapitalCapacity(
    int $paidUpShareCapital,
    int $collectedInterest,
    int $outstandingPrincipal,
    int $pendingPrincipal = 0,
    int $distributedInterest = 0
): int {
    if ($distributedInterest < 0 || $distributedInterest > $collectedInterest) {
        throw new InvalidArgumentException('Distributed loan interest cannot exceed collected interest.');
    }

    $lendableInterest = $collectedInterest - $distributedInterest;
    return max(0, $paidUpShareCapital + $lendableInterest - $outstandingPrincipal - $pendingPrincipal);
}

// This reads the current capital and loan totals from the database.
function cooperativeAvailableLoanCapital($db, ?int $excludePendingLoanId = null): array
{
    $pendingLoanFilter = $excludePendingLoanId === null ? '' : ' AND id <> ?';
    $params = $excludePendingLoanId === null ? [] : [$excludePendingLoanId];
    $row = $db->raw(
        "SELECT
            (SELECT COALESCE(SUM(CASE WHEN transaction_type = 'payment' THEN amount ELSE -amount END), 0)
             FROM share_capital_transactions) AS paid_up_share_capital,
            (SELECT COALESCE(SUM(interest_paid), 0)
             FROM loan_payments WHERE breakdown_recorded = 1) AS collected_interest,
            (SELECT COALESCE(SUM(interest_amount), 0)
             FROM cooperative_loan_interest_distributions
             WHERE status = 'allocated') AS distributed_interest,
            (SELECT COALESCE(SUM(GREATEST(s.principal_due - s.principal_paid, 0)), 0)
             FROM loan_schedules s
             INNER JOIN loans l ON l.id = s.loan_id
             WHERE l.status IN ('approved', 'active')) AS outstanding_principal,
            (SELECT COALESCE(SUM(principal_amount), 0)
             FROM loans WHERE status = 'pending'{$pendingLoanFilter}) AS pending_principal",
        $params
    )->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('Cooperative lending capacity could not be calculated.');
    }

    $paidUp = cooperativeMoneyToCents((string) $row['paid_up_share_capital']);
    $interest = cooperativeMoneyToCents((string) $row['collected_interest']);
    $distributedInterest = cooperativeMoneyToCents((string) $row['distributed_interest']);
    if ($distributedInterest > $interest) {
        throw new RuntimeException('Distributed loan interest exceeds the recorded collected interest.');
    }
    $outstanding = cooperativeMoneyToCents((string) $row['outstanding_principal']);
    $pending = cooperativeMoneyToCents((string) $row['pending_principal']);

    return [
        'paid_up_share_capital' => $paidUp,
        'collected_interest' => $interest,
        'distributed_interest' => $distributedInterest,
        'lendable_interest' => max(0, $interest - $distributedInterest),
        'outstanding_principal' => $outstanding,
        'pending_principal' => $pending,
        'available' => cooperativeLoanCapitalCapacity(
            $paidUp,
            $interest,
            $outstanding,
            $pending,
            $distributedInterest
        )
    ];
}

// This splits interest equally and shares any leftover cents fairly across members.
function cooperativeEqualInterestAllocations(int $interestCents, array $memberIds, int $rotation = 0): array
{
    if ($interestCents < 0) {
        throw new InvalidArgumentException('Loan interest cannot be negative.');
    }

    $memberIds = array_values(array_unique(array_filter(
        array_map('intval', $memberIds),
        static function (int $memberId): bool {
            return $memberId > 0;
        }
    )));
    sort($memberIds, SORT_NUMERIC);
    if ($interestCents === 0 || !$memberIds) {
        return [];
    }

    $share = intdiv($interestCents, count($memberIds));
    $remainder = $interestCents % count($memberIds);
    $allocations = array_fill_keys($memberIds, $share);
    $start = (($rotation % count($memberIds)) + count($memberIds)) % count($memberIds);
    for ($index = 0; $index < $remainder; $index++) {
        $memberId = $memberIds[($start + $index) % count($memberIds)];
        $allocations[$memberId]++;
    }

    return $allocations;
}

// This function runs a database query using the available connection.
function cooperativeFinanceStatement($db, string $sql, array $params = [])
{
    if ($db instanceof PDO) {
        $statement = $db->prepare($sql);
        $statement->execute($params);
        return $statement;
    }

    return $db->raw($sql, $params);
}

// This saves a repayment's interest split once, so the same payment cannot be counted twice.
function cooperativeRecordLoanInterestDistribution($db, int $loanPaymentId, int $interestCents): ?int
{
    if ($loanPaymentId < 1 || $interestCents < 0) {
        throw new InvalidArgumentException('A valid loan payment and non-negative interest amount are required.');
    }
    if ($interestCents === 0) {
        return null;
    }

    $existing = cooperativeFinanceStatement(
        $db,
        'SELECT id, interest_amount FROM cooperative_loan_interest_distributions WHERE loan_payment_id = ? FOR UPDATE',
        [$loanPaymentId]
    )->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        if (cooperativeMoneyToCents((string) $existing['interest_amount']) !== $interestCents) {
            throw new RuntimeException('An existing loan-interest distribution does not match the recorded payment.');
        }
        return (int) $existing['id'];
    }

    $activeMembers = cooperativeFinanceStatement(
        $db,
        "SELECT id FROM members WHERE status = 'active' ORDER BY id"
    )->fetchAll(PDO::FETCH_COLUMN);
    $memberIds = array_map('intval', $activeMembers);
    $paidUpByMember = [];
    if ($memberIds) {
        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
        $paidUpRows = cooperativeFinanceStatement(
            $db,
            "SELECT member_id,
                    SUM(CASE WHEN transaction_type = 'payment' THEN amount ELSE -amount END) AS paid_up_amount
             FROM share_capital_transactions
             WHERE member_id IN ($placeholders)
             GROUP BY member_id
             HAVING SUM(CASE WHEN transaction_type = 'payment' THEN amount ELSE -amount END) > 0",
            $memberIds
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($paidUpRows as $row) {
            $paidUpByMember[(int) $row['member_id']] = true;
        }
    }
    $eligibleMemberIds = array_values(array_filter(
        $memberIds,
        static function (int $memberId) use ($paidUpByMember): bool {
            return isset($paidUpByMember[$memberId]);
        }
    ));
    $distributionId = cooperativeFinanceInsert($db, 'cooperative_loan_interest_distributions', [
        'loan_payment_id' => $loanPaymentId,
        'interest_amount' => cooperativeFormatCents($interestCents),
        'eligible_investor_count' => count($eligibleMemberIds),
        'status' => $eligibleMemberIds ? 'allocated' : 'retained'
    ]);
    if ($distributionId < 1) {
        throw new RuntimeException('The loan-interest distribution could not be recorded.');
    }

    $allocations = cooperativeEqualInterestAllocations(
        $interestCents,
        $eligibleMemberIds,
        $loanPaymentId
    );
    foreach ($allocations as $memberId => $amount) {
        $returnId = cooperativeFinanceInsert($db, 'cooperative_loan_interest_returns', [
            'distribution_id' => $distributionId,
            'member_id' => (int) $memberId,
            'amount' => cooperativeFormatCents($amount),
            'claim_status' => 'available'
        ]);
        if ($returnId < 1) {
            throw new RuntimeException('An investor loan-interest return could not be recorded.');
        }
    }

    return $distributionId;
}

// This function saves a finance record using the available connection.
function cooperativeFinanceInsert($db, string $table, array $values): int
{
    if ($db instanceof PDO) {
        $columns = array_keys($values);
        $quotedColumns = implode(', ', array_map(static function (string $column): string {
            return '`' . $column . '`';
        }, $columns));
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $statement = $db->prepare(
            'INSERT INTO `' . $table . '` (' . $quotedColumns . ') VALUES (' . $placeholders . ')'
        );
        $statement->execute(array_values($values));
        return (int) $db->lastInsertId();
    }

    return (int) $db->table($table)->insert($values);
}

// This function converts elapsed demo time into membership days.
function cooperativeSimulatedMembershipDays(int $elapsedSeconds, int $realSecondsPerDay = 6): int
{
    if ($elapsedSeconds < 0 || $realSecondsPerDay < 1) {
        throw new InvalidArgumentException('Simulated membership time must be non-negative.');
    }
    $firstPeriodSeconds = 30 * $realSecondsPerDay;
    if ($elapsedSeconds <= $firstPeriodSeconds) {
        return intdiv($elapsedSeconds, $realSecondsPerDay);
    }
    return 30 + intdiv($elapsedSeconds - $firstPeriodSeconds, 10);
}

// This function calculates how long the demo membership period takes.
function cooperativeMembershipRequiredSeconds(int $membershipDays, int $realSecondsPerDay = 6): int
{
    if ($membershipDays < 0 || $realSecondsPerDay < 1) {
        throw new InvalidArgumentException('The membership requirement must be non-negative.');
    }
    if ($membershipDays <= 30) {
        return $membershipDays * $realSecondsPerDay;
    }
    return (30 * $realSecondsPerDay) + (($membershipDays - 30) * 10);
}

// This function checks the member against the cooperative loan rules.
function cooperativeLoanEligibility(
    int $paidUpShareCapital,
    int $savingsBalance,
    int $activeLoanBalance,
    int $membershipDays,
    bool $hasPendingApplication,
    bool $documentsApproved,
    int $minimumPaidUpShareCapital,
    int $minimumSavingsBalance,
    int $minimumMembershipDays,
    float $loanToShareCapitalMultiplier,
    int $productLimit = PHP_INT_MAX
): array {
    $shareCapitalLimit = (int) floor($paidUpShareCapital * $loanToShareCapitalMultiplier + 0.000001);
    $availableLimit = max(0, min($productLimit, $shareCapitalLimit - $activeLoanBalance));
    $reasons = [];

    if ($paidUpShareCapital < $minimumPaidUpShareCapital) {
        $reasons[] = 'Your paid-up share capital has not reached the cooperative’s minimum.';
    }
    if ($savingsBalance < $minimumSavingsBalance) {
        $reasons[] = 'Your savings balance has not reached the configured minimum.';
    }
    if ($membershipDays < $minimumMembershipDays) {
        $reasons[] = 'You have not completed the required membership period.';
    }
    if (!$documentsApproved) {
        $reasons[] = 'Your loan documents must be approved before applying.';
    }
    if ($hasPendingApplication) {
        $reasons[] = 'You already have a loan application waiting for review.';
    }
    if ($activeLoanBalance > 0 && $availableLimit < 1) {
        $reasons[] = 'Your current loan balance uses your available loan limit.';
    }
    if ($availableLimit < 1 && !$reasons) {
        $reasons[] = 'Your current share capital does not support a loan under the configured limit.';
    }

    return [
        'eligible' => !$reasons && $availableLimit > 0,
        'max_eligible_amount_cents' => $availableLimit,
        'reasons' => $reasons
    ];
}

// This function splits the approved surplus between member returns.
function cooperativeSurplusAllocations(
    int $netSurplus,
    int $fiscalYear,
    ?string $registrationDate,
    float $reservePercent,
    float $educationPercent,
    float $communityPercent,
    float $optionalPercent,
    float $shareCapitalPoolPercent
): array {
    if ($netSurplus < 1) {
        throw new InvalidArgumentException('Net surplus must be greater than zero.');
    }
    if (!$registrationDate || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $registrationDate)) {
        throw new InvalidArgumentException('Set the cooperative registration date before preparing a surplus distribution.');
    }

    $registrationYear = (int) substr($registrationDate, 0, 4);
    if ($fiscalYear < $registrationYear || $fiscalYear >= (int) date('Y')) {
        throw new InvalidArgumentException('Choose a completed fiscal year between the cooperative registration year and last year.');
    }
    $firstFiveYears = $fiscalYear - $registrationYear < 5;
    if ($reservePercent < ($firstFiveYears ? 50 : 10)
        || $educationPercent < 0 || $educationPercent > 10
        || $communityPercent < 3
        || $optionalPercent < 0 || $optionalPercent > 7
        || $shareCapitalPoolPercent < 0 || $shareCapitalPoolPercent > 100) {
        throw new InvalidArgumentException('The proposed fund percentages do not meet the configured Cooperative Code allocation rules.');
    }
    $fundPercent = $reservePercent + $educationPercent + $communityPercent + $optionalPercent;
    if ($fundPercent > 100) {
        throw new InvalidArgumentException('Fund allocations cannot exceed the net surplus.');
    }

    $remainingForFunds = $netSurplus;
    $reserve = min($remainingForFunds, (int) round($netSurplus * $reservePercent / 100));
    $remainingForFunds -= $reserve;
    $education = min($remainingForFunds, (int) round($netSurplus * $educationPercent / 100));
    $remainingForFunds -= $education;
    $community = min($remainingForFunds, (int) round($netSurplus * $communityPercent / 100));
    $remainingForFunds -= $community;
    $optional = min($remainingForFunds, (int) round($netSurplus * $optionalPercent / 100));
    $distributable = $netSurplus - $reserve - $education - $community - $optional;
    $shareCapitalPool = (int) round($distributable * $shareCapitalPoolPercent / 100);

    return [
        'reserve_allocation' => $reserve,
        'education_allocation' => $education,
        'community_allocation' => $community,
        'optional_allocation' => $optional,
        'share_capital_pool' => $shareCapitalPool,
        'patronage_pool' => $distributable - $shareCapitalPool
    ];
}

// This function splits an amount according to each member's share.
function cooperativeProportionalAllocation(int $pool, array $weights): array
{
    $weights = array_filter($weights, static function ($weight): bool {
        return is_int($weight) && $weight > 0;
    });
    $totalWeight = array_sum($weights);
    if ($pool < 0 || ($pool > 0 && $totalWeight < 1)) {
        throw new InvalidArgumentException('A positive distribution pool requires eligible member activity.');
    }
    if ($pool === 0) {
        return array_fill_keys(array_keys($weights), 0);
    }

    $allocations = [];
    $remainders = [];
    $allocated = 0;
    foreach ($weights as $memberId => $weight) {
        $exact = $pool * (float) $weight / $totalWeight;
        $amount = (int) floor($exact);
        $allocations[$memberId] = $amount;
        $remainders[$memberId] = $exact - $amount;
        $allocated += $amount;
    }
    uksort($remainders, static function ($left, $right) use ($remainders): int {
        $difference = $remainders[$right] <=> $remainders[$left];
        return $difference !== 0 ? $difference : ((int) $left <=> (int) $right);
    });
    foreach (array_slice(array_keys($remainders), 0, $pool - $allocated) as $memberId) {
        $allocations[$memberId]++;
    }
    return $allocations;
}

// This function splits a repayment across the loan installments.
function allocateLoanRepayment(int $amount, array $schedules): array
{
    if ($amount < 1) {
        throw new InvalidArgumentException('Loan repayments must be positive.');
    }
    $remaining = $amount;
    $principalPaid = 0;
    $interestPaid = 0;
    $overdueInterestPaid = 0;
    $updatedSchedules = [];
    foreach ($schedules as $schedule) {
        if ($remaining < 1) {
            break;
        }
        $overdueInterestDue = cooperativeMoneyToCents((string) ($schedule['overdue_interest_accrued'] ?? '0.00'));
        $overdueInterestPaidBefore = cooperativeMoneyToCents((string) ($schedule['overdue_interest_paid'] ?? '0.00'));
        $remainderUnits = (int) ($schedule['overdue_interest_remainder_units'] ?? 0);
        if (abs($remainderUnits) > intdiv(10000 * 365, 2)) {
            throw new InvalidArgumentException('The overdue-interest remainder is invalid.');
        }
        $interestDue = cooperativeMoneyToCents((string) $schedule['interest_due']);
        $principalDue = cooperativeMoneyToCents((string) $schedule['principal_due']);
        $paidInterestBefore = cooperativeMoneyToCents((string) ($schedule['interest_paid'] ?? '0.00'));
        $paidPrincipalBefore = cooperativeMoneyToCents((string) ($schedule['principal_paid'] ?? '0.00'));
        $overdueInterestApplied = min($remaining, max(0, $overdueInterestDue - $overdueInterestPaidBefore));
        $remaining -= $overdueInterestApplied;
        $interestApplied = min($remaining, max(0, $interestDue - $paidInterestBefore));
        $remaining -= $interestApplied;
        $principalApplied = min($remaining, max(0, $principalDue - $paidPrincipalBefore));
        $remaining -= $principalApplied;
        $principalPaid += $principalApplied;
        $interestPaid += $interestApplied;
        $overdueInterestPaid += $overdueInterestApplied;
        $paidOverdueInterest = $overdueInterestPaidBefore + $overdueInterestApplied;
        $paidInterest = $paidInterestBefore + $interestApplied;
        $paidPrincipal = $paidPrincipalBefore + $principalApplied;
        if ($paidPrincipal >= $principalDue) {
            $remainderUnits = 0;
        }
        $remainingBase = max(0, cooperativeMoneyToCents((string) $schedule['total_due']) - $paidInterest - $paidPrincipal);
        $remainingOverdueInterest = max(0, $overdueInterestDue - $paidOverdueInterest);
        $updatedSchedules[] = [
            'id' => (int) $schedule['id'],
            'paid_amount' => cooperativeFormatCents($paidInterest + $paidPrincipal),
            'principal_paid' => cooperativeFormatCents($paidPrincipal),
            'interest_paid' => cooperativeFormatCents($paidInterest),
            'overdue_interest_paid' => cooperativeFormatCents($paidOverdueInterest),
            'overdue_interest_accrued' => cooperativeFormatCents($overdueInterestDue),
            'overdue_interest_remainder_units' => $remainderUnits,
            'status' => $remainingBase === 0 && $remainingOverdueInterest === 0 && $remainderUnits === 0
                ? 'paid'
                : ($remainingOverdueInterest > 0 && $remainingBase === 0
                    ? 'overdue'
                    : (string) $schedule['status'])
        ];
    }
    if ($remaining !== 0) {
        throw new InvalidArgumentException('The repayment exceeds the unpaid installment schedule.');
    }
    return [
        'principal_paid' => $principalPaid,
        'interest_paid' => $interestPaid,
        'overdue_interest_paid' => $overdueInterestPaid,
        'schedules' => $updatedSchedules
    ];
}
