<?php

namespace App\Domain\Commission;

use App\Domain\Commission\Enums\Direction;
use App\Domain\Commission\Models\CommissionRate;
use App\Domain\Network\Models\PartnerBranchMapping;
use Illuminate\Validation\ValidationException;

/**
 * Commission of one successful transaction (Req §6, Database.md D-4):
 * the partner pays its rate, the branch earns its rate, the platform keeps
 * the difference (which may be negative, G-07). Rates in force at the moment
 * of success are used — a pair override first, else the partner's / branch's
 * own — and each commission is rounded half-up to the paisa.
 */
class CommissionCalculator
{
    public function __construct(private RateBook $rates) {}

    /**
     * @return array{partner_rate_percent: string, branch_rate_percent: string, partner_commission: int, branch_commission: int, platform_margin: int, partner_rate_id: string, branch_rate_id: string}
     */
    public function calculate(PartnerBranchMapping $mapping, Direction $direction, int $amount): array
    {
        $partnerRate = $this->rates->currentRow('mapping', $mapping->id, 'partner', $direction)
            ?? $this->rates->currentRow('partner', $mapping->partner_id, 'partner', $direction);
        $branchRate = $this->rates->currentRow('mapping', $mapping->id, 'branch', $direction)
            ?? $this->rates->currentRow('branch', $mapping->branch_id, 'branch', $direction);

        if (! $partnerRate instanceof CommissionRate || ! $branchRate instanceof CommissionRate) {
            throw ValidationException::withMessages(['commission' => __('No :direction commission is set for this :missing. Ask an administrator to set it before approving.', [
                'direction' => $direction->value,
                'missing' => $partnerRate === null ? 'partner' : 'branch',
            ])]);
        }

        $partnerCommission = self::commission($amount, $partnerRate->rate_percent);
        $branchCommission = self::commission($amount, $branchRate->rate_percent);

        return [
            'partner_rate_percent' => RatePercent::normalize($partnerRate->rate_percent),
            'branch_rate_percent' => RatePercent::normalize($branchRate->rate_percent),
            'partner_commission' => $partnerCommission,
            'branch_commission' => $branchCommission,
            'platform_margin' => $partnerCommission - $branchCommission,
            'partner_rate_id' => $partnerRate->id,
            'branch_rate_id' => $branchRate->id,
        ];
    }

    /**
     * amount × rate %, rounded half-up to the paisa, in integers only
     * (rate units are 0.0001 %, so 100 % = 1,000,000 units).
     */
    public static function commission(int $amount, string $ratePercent): int
    {
        return intdiv($amount * RatePercent::units($ratePercent) + 500_000, 1_000_000);
    }
}
