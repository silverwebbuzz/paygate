<?php

namespace App\Domain\Payout\Enums;

/**
 * Payout lifecycle (Requirements §7.2, decided 2026-09-27):
 * assigned (balance reserved, in a branch's queue) → processing (the branch
 * is paying) → success (paid, UTR recorded) or failed (couldn't pay; the
 * partner sends a new request). Cancelled only while still assigned.
 * `created` / `validated` are passed within the create request; a payout
 * without enough balance is refused and never stored ("balance is low").
 */
enum PayoutStatus: string
{
    case Created = 'created';
    case Validated = 'validated';
    case Assigned = 'assigned';
    case Processing = 'processing';
    case Success = 'success';
    case Failed = 'failed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Returned = 'returned';

    public function isOpen(): bool
    {
        return in_array($this, [self::Assigned, self::Processing], true);
    }
}
