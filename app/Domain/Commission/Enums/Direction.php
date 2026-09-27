<?php

namespace App\Domain\Commission\Enums;

/**
 * Deposit = pay-in (customer pays a branch account); withdrawal = payout
 * (a branch pays the customer).
 */
enum Direction: string
{
    case Deposit = 'deposit';
    case Withdrawal = 'withdrawal';
}
