<?php

namespace App\Domain\Payout;

use App\Domain\Allocation\Exceptions\LimitReached;
use App\Domain\Allocation\UsageCounters;
use App\Domain\Branch\Models\Branch;
use App\Domain\Commission\CommissionCalculator;
use App\Domain\Commission\Enums\Direction;
use App\Domain\Ledger\Ledger;
use App\Domain\Network\Models\PartnerBranchMapping;
use App\Domain\Partner\Models\Partner;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Chooses the branch that pays a payout and holds the money for it
 * (Req G-15/G-17, Database.md §3.3 and §4 "Payout create"):
 *
 * - candidates: the partner's active mappings with withdrawals on, at active
 *   branches with withdrawals on whose per-payout range fits the amount,
 *   in round-robin order (the pair used longest ago first);
 * - needed = amount + the partner's withdrawal fee at that pair's rate;
 * - the pair balance (partner position minus what open payouts hold) must
 *   cover it: reserved with a conditional update that waits, never skips;
 * - the branch, pair and partner daily withdrawal limits are reserved too.
 *
 * No branch fits → null ("balance is low"). Call inside a DB transaction.
 */
class PayoutRouter
{
    public function __construct(
        private Ledger $ledger,
        private UsageCounters $usage,
        private CommissionCalculator $commissions,
    ) {}

    /**
     * @param  list<string>  $excludeBranchIds
     * @return array{branch_id: string, mapping_id: string, ledger_account_id: string, needed: int, fee: int, date: string, amount: int}|null
     */
    public function assign(Partner $partner, int $amount, array $excludeBranchIds = [], ?string $onlyBranchId = null): ?array
    {
        $mappings = PartnerBranchMapping::query()
            ->join('branches as b', 'b.id', '=', 'partner_branch_mappings.branch_id')
            ->where('partner_branch_mappings.partner_id', $partner->id)
            ->where('partner_branch_mappings.status', 'active')
            ->where('partner_branch_mappings.is_withdrawal_enabled', true)
            ->where('b.status', 'active')
            ->where('b.is_withdrawal_enabled', true)
            ->whereRaw('? BETWEEN COALESCE(b.withdrawal_min_amount, 0) AND COALESCE(b.withdrawal_max_amount, ?)', [$amount, $amount])
            ->when($excludeBranchIds !== [], fn ($query) => $query->whereNotIn('partner_branch_mappings.branch_id', $excludeBranchIds))
            ->when($onlyBranchId !== null, fn ($query) => $query->where('partner_branch_mappings.branch_id', $onlyBranchId))
            ->orderByRaw('partner_branch_mappings.last_payout_assigned_at NULLS FIRST')
            ->orderBy('partner_branch_mappings.id')
            ->select('partner_branch_mappings.*')
            ->get();

        foreach ($mappings as $mapping) {
            try {
                $fee = $this->commissions->calculate($mapping, Direction::Withdrawal, $amount)['partner_commission'];
            } catch (ValidationException) {
                continue; // no withdrawal rate for this pair: it can't take payouts
            }

            $reservation = $this->tryReserve($partner, $mapping, $amount, $amount + $fee, $fee);

            if ($reservation !== null) {
                return $reservation;
            }
        }

        return null;
    }

    /**
     * Gives back everything a payout holds.
     *
     * @param  array<string, mixed>  $reservation  as returned by assign()
     */
    public function release(array $reservation): void
    {
        $this->ledger->release((string) $reservation['ledger_account_id'], (int) $reservation['needed']);

        foreach ($this->scopes($reservation) as [$type, $id]) {
            $this->usage->release($type, $id, (string) $reservation['date'], Direction::Withdrawal, (int) $reservation['amount']);
        }
    }

    /**
     * The payout was paid: the balance hold ends (the ledger journal has
     * booked the real movement) and the day's usage becomes confirmed.
     *
     * @param  array<string, mixed>  $reservation
     */
    public function confirm(array $reservation): void
    {
        $this->ledger->release((string) $reservation['ledger_account_id'], (int) $reservation['needed']);

        foreach ($this->scopes($reservation) as [$type, $id]) {
            $this->usage->confirm($type, $id, (string) $reservation['date'], Direction::Withdrawal, (int) $reservation['amount']);
        }
    }

    /**
     * @return array{branch_id: string, mapping_id: string, ledger_account_id: string, needed: int, fee: int, date: string, amount: int}|null
     */
    private function tryReserve(Partner $partner, PartnerBranchMapping $mapping, int $amount, int $needed, int $fee): ?array
    {
        $accountId = $this->ledger->account(Ledger::PARTNER_POSITION, $partner->id, $mapping->branch_id);
        $date = UsageCounters::businessDate();

        try {
            return DB::transaction(function () use ($partner, $mapping, $amount, $needed, $fee, $accountId, $date) {
                if (! $this->ledger->reserve($accountId, $needed)) {
                    throw new LimitReached('balance');
                }

                /** @var Branch $branch */
                $branch = Branch::query()->findOrFail($mapping->branch_id);

                $limits = [
                    ['branch', $branch->id, $branch->withdrawal_daily_limit],
                    ['mapping', $mapping->id, $mapping->withdrawal_daily_limit],
                    ['partner', $partner->id, $partner->withdrawal_daily_limit],
                ];

                foreach ($limits as [$type, $id, $limit]) {
                    if (! $this->usage->reserve($type, $id, $date, Direction::Withdrawal, $amount, $limit)) {
                        throw new LimitReached($type);
                    }
                }

                $mapping->forceFill(['last_payout_assigned_at' => now()])->save();

                return [
                    'branch_id' => $mapping->branch_id,
                    'mapping_id' => $mapping->id,
                    'ledger_account_id' => $accountId,
                    'needed' => $needed,
                    'fee' => $fee,
                    'date' => $date,
                    'amount' => $amount,
                ];
            });
        } catch (LimitReached) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $reservation
     * @return list<array{0: string, 1: string}>
     */
    private function scopes(array $reservation): array
    {
        $mapping = PartnerBranchMapping::query()->find((string) $reservation['mapping_id']);

        return array_values(array_filter([
            ['branch', (string) $reservation['branch_id']],
            $mapping ? ['mapping', $mapping->id] : null,
            $mapping ? ['partner', $mapping->partner_id] : null,
        ]));
    }
}
