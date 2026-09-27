<?php

namespace App\Domain\Transaction\Enums;

/**
 * Pay-in lifecycle (Requirements §7.1). SUCCESS is the branch operator's
 * approval (Phase 7); this phase covers created → awaiting payment →
 * submitted, and expiry / cancellation.
 */
enum PayinStatus: string
{
    case Created = 'created';
    case AwaitingPayment = 'awaiting_payment';
    case PaymentSubmitted = 'payment_submitted';
    case PaymentDetected = 'payment_detected';
    case UnderReview = 'under_review';
    case Success = 'success';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    case Chargeback = 'chargeback';
    case Refunded = 'refunded';

    /**
     * The customer can still pay (and the session can expire).
     */
    public function isOpen(): bool
    {
        return in_array($this, [self::Created, self::AwaitingPayment], true);
    }

    /**
     * The customer has told us they paid; the branch now checks.
     */
    public function isWaitingForBranch(): bool
    {
        return in_array($this, [self::PaymentSubmitted, self::PaymentDetected, self::UnderReview], true);
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Success, self::Rejected, self::Expired, self::Cancelled, self::Chargeback, self::Refunded], true);
    }
}
