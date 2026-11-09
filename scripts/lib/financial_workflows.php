<?php

declare(strict_types=1);

function autoPayLoanAmount(int $availableSavings, int $remainingInstallment, int $loanBalance): int
{
    return max(0, min($availableSavings, $remainingInstallment, $loanBalance));
}

function moneyToCents(string $amount): int
{
    if (!preg_match('/^(?:0|[1-9]\d{0,9})(?:\.\d{1,2})?$/', $amount)) {
        throw new InvalidArgumentException('A stored financial amount is invalid.');
    }
    [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
    return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0', STR_PAD_RIGHT);
}

function formatCents(int $amount): string
{
    return intdiv($amount, 100) . '.' . str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
}
