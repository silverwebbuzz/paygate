<?php

namespace App\Http\Shared\Transactions;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Core\Identity\Models\User;
use App\Http\Shared\Reconciliation\ReconciliationScope;
use App\Http\Shared\Reconciliation\StatementPresenter;

/**
 * Payments › Deposit and Payments › Withdrawal (client request 2026-11-09):
 * one menu item each, the existing pages shown as tabs of it so a person
 * works a deposit or a withdrawal from one place.
 *
 * Deposit: Manual Deposit · A/C Statement · Unsettled UTR · UTR
 * Reconciliation · All deposits. Withdrawal: Manual Payout · UTR
 * Reconciliation · All withdrawals. Partners have the list only.
 *
 * Each tab keeps its own controller, URL, permission and rules; this only
 * adds the `hub` prop they share: which page it is (the menu item to mark),
 * the counts on the tabs, and the bank entry bar's accounts. The tabs
 * themselves are listed in resources/js/lib/payment-hub.ts.
 */
final class PaymentHub
{
    public const DEPOSIT = 'deposit';

    public const WITHDRAWAL = 'withdrawal';

    /**
     * @return array<string, mixed>
     */
    public static function props(User $actor, string $kind): array
    {
        $kind = $kind === self::WITHDRAWAL ? self::WITHDRAWAL : self::DEPOSIT;

        if ($actor->isType(UserType::Partner)) {
            return ['kind' => $kind, 'counts' => [], 'entry' => null];
        }

        return $kind === self::DEPOSIT
            ? ['kind' => $kind, 'counts' => self::depositCounts($actor), 'entry' => self::entry($actor)]
            : ['kind' => $kind, 'counts' => self::withdrawalCounts($actor), 'entry' => null];
    }

    /**
     * Deposits waiting for a decision (Pending + Payment hold) and open
     * unsettled bank lines.
     *
     * @return array<string, int>
     */
    private static function depositCounts(User $actor): array
    {
        $counts = [];

        if ($actor->can('payins.view')) {
            $counts['queue'] = TransactionController::scoped($actor)
                ->where('direction', 'payin')
                ->whereIn('status', [...DepositQueueController::TABS['pending'], ...DepositQueueController::TABS['hold']])
                ->count();
        }

        if ($actor->can('reconciliation.view')) {
            $counts['unsettled'] = ReconciliationScope::cases($actor)->where('status', '!=', 'resolved')->count();
        }

        return $counts;
    }

    /**
     * Payouts waiting for the branch to pay (To pay).
     *
     * @return array<string, int>
     */
    private static function withdrawalCounts(User $actor): array
    {
        if (! $actor->can('payouts.view')) {
            return [];
        }

        return ['queue' => TransactionController::scoped($actor)
            ->where('direction', 'payout')
            ->whereIn('status', PayoutQueueController::TABS['to_pay'])
            ->count()];
    }

    /**
     * The bank entry bar on every Deposit tab (the Manual A/C Statement's
     * "New statement line" and import): a line whose UTR and amount match a
     * waiting deposit approves it (AutoApproveDeposit), in place.
     *
     * @return array{accounts: mixed, branches: mixed}|null
     */
    private static function entry(User $actor): ?array
    {
        if (! $actor->can('statements.create')) {
            return null;
        }

        $presenter = app(StatementPresenter::class);

        return [
            'accounts' => ReconciliationScope::accounts($actor)->with('branch')->orderBy('label')->get()
                ->map(fn ($account) => [...$presenter->account($account), 'branch_id' => $account->branch_id, 'branch' => $account->branch->name])
                ->values(),
            'branches' => $actor->isType(UserType::Admin) ? Branch::query()->orderBy('name')->get(['id', 'code', 'name']) : [],
        ];
    }
}
