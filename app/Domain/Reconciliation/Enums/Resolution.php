<?php

namespace App\Domain\Reconciliation\Enums;

/**
 * How a reconciliation case was closed (G-26, decided 2026-09-28).
 * `written_off` and `adjusted` need ledger adjustments and come with
 * Phase 10.
 */
enum Resolution: string
{
    // Linked to its transaction (by a person, or by the system when the
    // matching deposit / payout arrived later). Includes late approvals.
    case Linked = 'linked';
    // Not a customer payment (bank charges, interest, own transfers…).
    case Rejected = 'rejected';
    // The branch sent the money back to the customer outside PayGate.
    case Refunded = 'refunded';
    case WrittenOff = 'written_off';
    case Adjusted = 'adjusted';

    public function label(): string
    {
        return match ($this) {
            self::Linked => 'Linked to transaction',
            self::Rejected => 'Not a customer payment',
            self::Refunded => 'Returned to customer',
            self::WrittenOff => 'Written off',
            self::Adjusted => 'Adjusted',
        };
    }
}
