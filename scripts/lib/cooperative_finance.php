<?php

declare(strict_types=1);

function cooperativeMoneyToCents(string $amount): int
{
    if (!preg_match('/^(?:0|[1-9]\d{0,9})(?:\.\d{1,2})?$/', $amount)) {
        throw new InvalidArgumentException('A stored cooperative financial amount is invalid.');
    }
    [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
    return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0', STR_PAD_RIGHT);
}

function cooperativeFormatCents(int $amount): string
{
    return intdiv($amount, 100) . '.' . str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
}

function cooperativeSimulatedMembershipDays(int $elapsedSeconds, int $realSecondsPerDay = 6): int
{
    if ($elapsedSeconds < 0 || $realSecondsPerDay < 1) {
        throw new InvalidArgumentException('Simulated membership time must be non-negative.');
    }
    return intdiv($elapsedSeconds, $realSecondsPerDay);
}

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

function allocateLoanRepayment(int $amount, array $schedules): array
{
    if ($amount < 1) {
        throw new InvalidArgumentException('Loan repayments must be positive.');
    }
    $remaining = $amount;
    $principalPaid = 0;
    $interestPaid = 0;
    $updatedSchedules = [];
    foreach ($schedules as $schedule) {
        if ($remaining < 1) {
            break;
        }
        $interestDue = cooperativeMoneyToCents((string) $schedule['interest_due']);
        $principalDue = cooperativeMoneyToCents((string) $schedule['principal_due']);
        $paidInterestBefore = cooperativeMoneyToCents((string) ($schedule['interest_paid'] ?? '0.00'));
        $paidPrincipalBefore = cooperativeMoneyToCents((string) ($schedule['principal_paid'] ?? '0.00'));
        $interestApplied = min($remaining, max(0, $interestDue - $paidInterestBefore));
        $remaining -= $interestApplied;
        $principalApplied = min($remaining, max(0, $principalDue - $paidPrincipalBefore));
        $remaining -= $principalApplied;
        $principalPaid += $principalApplied;
        $interestPaid += $interestApplied;
        $paidInterest = $paidInterestBefore + $interestApplied;
        $paidPrincipal = $paidPrincipalBefore + $principalApplied;
        $updatedSchedules[] = [
            'id' => (int) $schedule['id'],
            'paid_amount' => cooperativeFormatCents($paidInterest + $paidPrincipal),
            'principal_paid' => cooperativeFormatCents($paidPrincipal),
            'interest_paid' => cooperativeFormatCents($paidInterest),
            'status' => $paidInterest + $paidPrincipal >= cooperativeMoneyToCents((string) $schedule['total_due'])
                ? 'paid'
                : (string) $schedule['status']
        ];
    }
    if ($remaining !== 0) {
        throw new InvalidArgumentException('The repayment exceeds the unpaid installment schedule.');
    }
    return [
        'principal_paid' => $principalPaid,
        'interest_paid' => $interestPaid,
        'schedules' => $updatedSchedules
    ];
}
