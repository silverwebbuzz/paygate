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
use Illuminate\Database\Eloquent\Collection;
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
 * - the partner's total balance over all branches (minus what open payouts
 *   hold) must cover it, checked under a lock on the partner row; the hold
 *   is placed on the chosen pair, whether or not that branch has the money;
 * - the branch, pair and partner daily withdrawal limits are reserved too.
 *
 * No branch fits → null. Call inside a DB transaction.
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
    public function assign(Partner $partner, int $amount, array $excludeBranchIds = [], ?string $onlyBranchId = null, bool $checkBalance = true): ?array
    {
        $available = $checkBalance ? $this->available($partner) : null;

        foreach ($this->candidates($partner, $amount, $excludeBranchIds, $onlyBranchId) as $mapping) {
            $fee = $this->fee($mapping, $amount);

            if ($fee === null || ($available !== null && $available < $amount + $fee)) {
                continue;
            }

            $reservation = $this->tryReserve($partner, $mapping, $amount, $amount + $fee, $fee);

            if ($reservation !== null) {
                return $reservation;
            }
        }

        return null;
    }

    /**
     * @return 'insufficient_balance'|'no_branch_available'
     */
    public function refusal(Partner $partner, int $amount): string
    {
        $fees = $this->candidates($partner, $amount)
            ->map(fn (PartnerBranchMapping $mapping) => $this->fee($mapping, $amount))
            ->filter(fn (?int $fee) => $fee !== null);

        if ($fees->isEmpty()) {
            return 'no_branch_available';
        }

        $available = $this->available($partner);

        return $fees->contains(fn (int $fee) => $available >= $amount + $fee) ? 'no_branch_available' : 'insufficient_balance';
    }

    public function available(Partner $partner): int
    {
        Partner::query()->whereKey($partner->id)->lockForUpdate()->value('id');

        $positions = $this->ledger->partnerPositions($partner->id);

        return array_sum(array_column($positions, 'balance')) - array_sum(array_column($positions, 'reserved'));
    }

    /**
     * @param  list<string>  $excludeBranchIds
     * @return Collection<int, PartnerBranchMapping>
     */
    private function candidates(Partner $partner, int $amount, array $excludeBranchIds = [], ?string $onlyBranchId = null): Collection
    {
        return PartnerBranchMapping::query()
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
    }

    private function fee(PartnerBranchMapping $mapping, int $amount): ?int
    {
        try {
            return $this->commissions->calculate($mapping, Direction::Withdrawal, $amount)['partner_commission'];
        } catch (ValidationException) {
            return null;
        }
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
                $this->ledger->hold($accountId, $needed);

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
