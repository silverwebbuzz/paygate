<?php

namespace App\Domain\Payout;

use App\Domain\Commission\CommissionCalculator;
use App\Domain\Commission\Enums\Direction;
use App\Domain\Commission\RatePercent;
use App\Domain\Ledger\Ledger;
use App\Domain\Network\Models\PartnerBranchMapping;
use App\Domain\Partner\Models\Partner;
use Illuminate\Validation\ValidationException;

/**
 * A partner's money with us, as the partner may see it (no branch detail):
 * total position, what open payouts hold, what's available, and the largest
 * single payout possible right now (a payout is paid by one branch, so it
 * must fit one pair's available balance including the fee — Req G-15).
 */
class PartnerBalance
{
    public function __construct(private Ledger $ledger, private CommissionCalculator $commissions) {}

    /**
     * @return array{balance: int, reserved: int, available: int, max_payout: int}
     */
    public function summary(Partner $partner): array
    {
        $positions = $this->ledger->partnerPositions($partner->id);
        $balance = array_sum(array_column($positions, 'balance'));
        $reserved = array_sum(array_column($positions, 'reserved'));

        $mappings = PartnerBranchMapping::query()
            ->where(['partner_id' => $partner->id, 'status' => 'active', 'is_withdrawal_enabled' => true])
            ->whereHas('branch', fn ($query) => $query->where('status', 'active')->where('is_withdrawal_enabled', true))
            ->get();

        $max = 0;

        foreach ($mappings as $mapping) {
            $available = ($positions[$mapping->branch_id]['balance'] ?? 0) - ($positions[$mapping->branch_id]['reserved'] ?? 0);

            if ($available > 0) {
                $max = max($max, $this->largestPayout($mapping, $available));
            }
        }

        if ($partner->withdrawal_max_amount !== null) {
            $max = min($max, $partner->withdrawal_max_amount);
        }

        return ['balance' => $balance, 'reserved' => $reserved, 'available' => $balance - $reserved, 'max_payout' => $max];
    }

    /**
     * The largest amount X with X + fee(X) ≤ available at this pair.
     */
    private function largestPayout(PartnerBranchMapping $mapping, int $available): int
    {
        try {
            $rate = $this->commissions->calculate($mapping, Direction::Withdrawal, 1_000_000)['partner_rate_percent'];
        } catch (ValidationException) {
            return 0;
        }

        $units = RatePercent::units($rate);
        $amount = intdiv($available * 1_000_000, 1_000_000 + $units);

        while ($amount > 0 && $amount + CommissionCalculator::commission($amount, $rate) > $available) {
            $amount--;
        }

        return $amount;
    }
}
