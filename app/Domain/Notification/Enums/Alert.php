<?php

namespace App\Domain\Notification\Enums;

use App\Domain\Core\Identity\Enums\UserType;

/**
 * The alerts people get (decided 2026-09-28, G-47): always in the portal's
 * bell, and by email unless the person turns that off for the event.
 */
enum Alert: string
{
    case DepositsWaiting = 'deposits_waiting';
    case UnsettledLines = 'unsettled_lines';
    case PayoutAssigned = 'payout_assigned';
    case WebhookFailed = 'webhook_failed';
    case AdjustmentRequested = 'adjustment_requested';
    case AdjustmentDecided = 'adjustment_decided';
    case SettlementCalculated = 'settlement_calculated';
    case AccountVerification = 'account_verification';
    case AccountReviewed = 'account_reviewed';
    case Reversal = 'reversal';

    public function label(): string
    {
        return match ($this) {
            self::DepositsWaiting => 'Deposits waiting too long',
            self::UnsettledLines => 'New unsettled bank lines',
            self::PayoutAssigned => 'Payout to pay',
            self::WebhookFailed => 'Webhook delivery failed',
            self::AdjustmentRequested => 'Adjustment awaiting approval',
            self::AdjustmentDecided => 'Your adjustment was approved or rejected',
            self::SettlementCalculated => 'Settlement calculated',
            self::AccountVerification => 'Bank account awaiting verification',
            self::AccountReviewed => 'Bank account verified or rejected',
            self::Reversal => 'Chargeback, refund or returned payout',
        };
    }

    /**
     * Portals whose users can receive it (for the preferences screen).
     *
     * @return list<UserType>
     */
    public function userTypes(): array
    {
        return match ($this) {
            self::DepositsWaiting, self::PayoutAssigned, self::AccountReviewed => [UserType::Branch],
            self::UnsettledLines => [UserType::Admin, UserType::Branch],
            self::WebhookFailed => [UserType::Partner],
            self::AdjustmentRequested, self::AdjustmentDecided, self::AccountVerification => [UserType::Admin],
            self::SettlementCalculated, self::Reversal => [UserType::Partner, UserType::Branch],
        };
    }

    /**
     * @return list<self>
     */
    public static function for(UserType $type): array
    {
        return array_values(array_filter(self::cases(), fn (self $alert) => in_array($type, $alert->userTypes(), true)));
    }
}
