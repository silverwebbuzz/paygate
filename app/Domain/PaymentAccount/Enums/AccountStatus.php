<?php

namespace App\Domain\PaymentAccount\Enums;

/**
 * Payment account lifecycle (Requirements §7.5):
 * NEW → VERIFICATION_PENDING → VERIFIED → ACTIVE ⇄ PAUSED → DISABLED;
 * VERIFICATION_PENDING → REJECTED. Changing the account details sends it
 * back to VERIFICATION_PENDING. Only ACTIVE (which implies verified) accounts
 * are shown to customers.
 *
 * Accounts are submitted for verification as soon as they are added, so
 * `new` is not used yet (kept for a future "save as draft").
 */
enum AccountStatus: string
{
    case New = 'new';
    case VerificationPending = 'verification_pending';
    case Verified = 'verified';
    case Active = 'active';
    case Paused = 'paused';
    case Disabled = 'disabled';
    case Rejected = 'rejected';

    /**
     * Statuses reachable by switching (verify / reject are separate Admin
     * decisions, see VerifyPaymentAccount).
     *
     * @return list<self>
     */
    public function switchableTo(): array
    {
        return match ($this) {
            self::Verified => [self::Active, self::Disabled],
            self::Active => [self::Paused, self::Disabled],
            self::Paused => [self::Active, self::Disabled],
            self::New, self::VerificationPending, self::Rejected => [self::Disabled],
            self::Disabled => [],
        };
    }

    public function isVerified(): bool
    {
        return in_array($this, [self::Verified, self::Active, self::Paused], true);
    }
}
