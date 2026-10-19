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
    case PayinNoAccount = 'payin_no_account';
    case PayoutNoBranch = 'payout_no_branch';
    case PayoutShortBranch = 'payout_short_branch';
    case PayinAmountDiffers = 'payin_amount_differs';
    case DepositMoved = 'deposit_moved';
    case DepositNotInBank = 'deposit_not_in_bank';

    public function label(): string
    {
        return match ($this) {
            self::PayinAmountDiffers => 'Deposit approved for a different amount than ordered',
            self::DepositMoved => 'Deposit moved to your branch',
            self::DepositNotInBank => 'Branch says a deposit isn’t in its bank',
            self::PayinNoAccount => 'Customers can’t pay: no bank account free',
            self::PayoutNoBranch => 'Payouts refused: no branch can take them',
            self::PayoutShortBranch => 'Payout sent to a branch holding less of the partner’s balance',
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
            self::DepositsWaiting, self::PayoutAssigned, self::AccountReviewed, self::DepositMoved => [UserType::Branch],
            self::UnsettledLines => [UserType::Admin, UserType::Branch],
            self::WebhookFailed => [UserType::Partner],
            self::AdjustmentRequested, self::AdjustmentDecided, self::AccountVerification,
            self::PayinNoAccount, self::PayoutNoBranch, self::PayoutShortBranch, self::PayinAmountDiffers, self::DepositNotInBank => [UserType::Admin],
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
