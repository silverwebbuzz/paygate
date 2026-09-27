<?php

namespace App\Domain\Core\Organisation\Enums;

/**
 * Lifecycle of partners and branches (Requirements §7.10). An organisation is
 * created as a draft, goes live when Admin activates it, and can be suspended
 * (temporarily) or offboarded (for good).
 * `pending_verification` and `rejected` exist in the schema for a future
 * self-service onboarding flow and are not used yet.
 */
enum OrganisationStatus: string
{
    case Draft = 'draft';
    case PendingVerification = 'pending_verification';
    case Active = 'active';
    case Suspended = 'suspended';
    case Offboarded = 'offboarded';
    case Rejected = 'rejected';

    /**
     * Statuses Admin may move a partner to from this one.
     *
     * @return list<self>
     */
    public function transitions(): array
    {
        return match ($this) {
            self::Draft, self::PendingVerification => [self::Active, self::Offboarded],
            self::Active => [self::Suspended, self::Offboarded],
            self::Suspended => [self::Active, self::Offboarded],
            self::Offboarded, self::Rejected => [],
        };
    }

    public function canMoveTo(self $status): bool
    {
        return in_array($status, $this->transitions(), true);
    }
}
