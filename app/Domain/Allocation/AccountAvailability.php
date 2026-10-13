<?php

namespace App\Domain\Allocation;

use App\Domain\Allocation\Actions\AllocateAccount;
use App\Domain\Commission\Enums\Direction;
use App\Domain\Partner\Models\Partner;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use stdClass;

class AccountAvailability
{
    private const NOT_MAPPED = 'branch is not mapped to this partner';

    public function __construct(private UsageCounters $usage) {}

    /**
     * @return list<string>
     */
    public function methods(Partner $partner, int $amount): array
    {
        $free = collect($this->accounts($partner, $amount))
            ->filter(fn (array $row) => $row['blockers'] === [])
            ->flatMap(fn (array $row) => $row['methods'])
            ->all();

        return array_values(array_filter(AllocateAccount::METHODS, fn (string $method) => in_array($method, $free, true)));
    }

    /**
     * @return list<string>
     */
    public function reasons(Partner $partner, int $amount, int $limit = 5): array
    {
        $mapped = collect($this->accounts($partner, $amount))
            ->reject(fn (array $row) => in_array(self::NOT_MAPPED, $row['blockers'], true));

        if ($mapped->isEmpty()) {
            return ['No bank account is set up at any branch mapped to this partner.'];
        }

        $blocked = $mapped
            ->filter(fn (array $row) => $row['blockers'] !== [])
            ->map(fn (array $row) => "{$row['branch']} · {$row['account']}: ".implode('; ', $row['blockers']));

        if ($blocked->isEmpty()) {
            return ['Every account was taken by other customers at that moment.'];
        }

        return [
            ...$blocked->take($limit)->values()->all(),
            ...($blocked->count() > $limit ? ['+'.($blocked->count() - $limit).' more'] : []),
        ];
    }

    /**
     * @return list<array{account_id: string, branch: string, account: string, methods: list<string>, blockers: list<string>}>
     */
    public function accounts(Partner $partner, int $amount): array
    {
        $rows = DB::table('payment_accounts as a')
            ->join('branches as b', 'b.id', '=', 'a.branch_id')
            ->leftJoin('partner_branch_mappings as m', fn (JoinClause $join) => $join->on('m.branch_id', '=', 'a.branch_id')->where('m.partner_id', '=', $partner->id))
            ->orderBy('b.code')
            ->orderByRaw('a.last_allocated_at NULLS FIRST')
            ->orderBy('a.id')
            ->select([
                'a.id', 'a.label', 'a.status', 'a.is_bank_enabled', 'a.is_upi_enabled', 'a.is_qr_enabled',
                'a.min_amount', 'a.max_amount', 'a.daily_amount_limit', 'a.daily_count_limit', 'a.max_open_sessions',
                'b.id as branch_id', 'b.code as branch_code', 'b.status as branch_status', 'b.is_deposit_enabled as branch_deposits',
                'b.deposit_min_amount', 'b.deposit_max_amount', 'b.deposit_limit_type', 'b.deposit_daily_limit', 'b.deposit_topup_balance',
                'm.id as mapping_id', 'm.status as mapping_status', 'm.is_deposit_enabled as mapping_deposits', 'm.deposit_daily_limit as mapping_daily_limit',
            ])
            ->selectRaw('(m.effective_from IS NULL OR m.effective_from <= now()) AS mapping_started')
            ->selectRaw('(m.effective_to IS NULL OR m.effective_to > now()) AS mapping_not_ended')
            ->get();

        $accountUse = $this->usage->today('account', $rows->pluck('id')->all(), Direction::Deposit);
        $branchUse = $this->usage->today('branch', $rows->pluck('branch_id')->unique()->values()->all(), Direction::Deposit);
        $mappingUse = $this->usage->today('mapping', $rows->pluck('mapping_id')->filter()->unique()->values()->all(), Direction::Deposit);
        $partnerUse = $this->usage->today('partner', [$partner->id], Direction::Deposit)[$partner->id] ?? null;

        $result = [];

        foreach ($rows as $row) {
            $methods = array_values(array_filter([
                $row->is_upi_enabled ? 'upi' : null,
                $row->is_upi_enabled && $row->is_qr_enabled ? 'qr' : null,
                $row->is_bank_enabled ? 'bank_transfer' : null,
            ]));

            $result[] = [
                'account_id' => (string) $row->id,
                'branch' => (string) $row->branch_code,
                'account' => (string) $row->label,
                'methods' => $methods,
                'blockers' => $this->blockers($row, $amount, $methods, $accountUse[$row->id] ?? null, $branchUse[$row->branch_id] ?? null, $row->mapping_id === null ? null : ($mappingUse[$row->mapping_id] ?? null), $partner, $partnerUse),
            ];
        }

        return $result;
    }

    /**
     * @param  list<string>  $methods
     * @param  array{amount: int, count: int, reserved: int, sessions: int}|null  $account
     * @param  array{amount: int, count: int, reserved: int, sessions: int}|null  $branch
     * @param  array{amount: int, count: int, reserved: int, sessions: int}|null  $mapping
     * @param  array{amount: int, count: int, reserved: int, sessions: int}|null  $partnerUse
     * @return list<string>
     */
    private function blockers(stdClass $row, int $amount, array $methods, ?array $account, ?array $branch, ?array $mapping, Partner $partner, ?array $partnerUse): array
    {
        $blockers = [];

        if ($row->mapping_id === null) {
            $blockers[] = self::NOT_MAPPED;
        } else {
            if ($row->mapping_status !== 'active') {
                $blockers[] = "partner mapping is {$row->mapping_status}";
            }

            if (! $row->mapping_deposits) {
                $blockers[] = 'deposits are off in the partner mapping';
            }

            if (! $row->mapping_started) {
                $blockers[] = 'partner mapping has not started yet';
            }

            if (! $row->mapping_not_ended) {
                $blockers[] = 'partner mapping has ended';
            }
        }

        if ($row->branch_status !== 'active') {
            $blockers[] = "branch is {$row->branch_status}";
        }

        if (! $row->branch_deposits) {
            $blockers[] = 'branch deposits are off';
        }

        if ($row->status !== 'active') {
            $blockers[] = "account is {$row->status}";
        }

        if ($methods === []) {
            $blockers[] = 'account has no bank, UPI or QR turned on';
        }

        if (! $this->inRange($amount, $row->min_amount, $row->max_amount)) {
            $blockers[] = 'amount is outside the account range '.$this->range($row->min_amount, $row->max_amount);
        }

        if (! $this->inRange($amount, $row->deposit_min_amount, $row->deposit_max_amount)) {
            $blockers[] = 'amount is outside the branch range '.$this->range($row->deposit_min_amount, $row->deposit_max_amount);
        }

        if ($row->daily_amount_limit !== null && ($account['amount'] ?? 0) + $amount > $row->daily_amount_limit) {
            $blockers[] = 'account daily amount limit reached ('.$this->rupees($account['amount'] ?? 0).' of '.$this->rupees((int) $row->daily_amount_limit).' used)';
        }

        if ($row->daily_count_limit !== null && ($account['count'] ?? 0) + 1 > $row->daily_count_limit) {
            $blockers[] = "account daily payment count reached ({$row->daily_count_limit})";
        }

        if (($account['sessions'] ?? 0) + 1 > $row->max_open_sessions) {
            $blockers[] = "all {$row->max_open_sessions} open-payment slots of the account are in use";
        }

        if ($row->deposit_limit_type === 'topup') {
            if (($branch['reserved'] ?? 0) + $amount > $row->deposit_topup_balance) {
                $blockers[] = 'branch top-up allowance too low ('.$this->rupees(max(0, (int) $row->deposit_topup_balance - ($branch['reserved'] ?? 0))).' left)';
            }
        } elseif ($row->deposit_daily_limit !== null && ($branch['amount'] ?? 0) + $amount > $row->deposit_daily_limit) {
            $blockers[] = 'branch daily deposit limit reached ('.$this->rupees($branch['amount'] ?? 0).' of '.$this->rupees((int) $row->deposit_daily_limit).' used)';
        }

        if ($row->mapping_daily_limit !== null && ($mapping['amount'] ?? 0) + $amount > $row->mapping_daily_limit) {
            $blockers[] = 'partner mapping daily deposit limit reached ('.$this->rupees($mapping['amount'] ?? 0).' of '.$this->rupees((int) $row->mapping_daily_limit).' used)';
        }

        if ($partner->deposit_daily_limit !== null && ($partnerUse['amount'] ?? 0) + $amount > $partner->deposit_daily_limit) {
            $blockers[] = 'partner daily deposit limit reached ('.$this->rupees($partnerUse['amount'] ?? 0).' of '.$this->rupees($partner->deposit_daily_limit).' used)';
        }

        return $blockers;
    }

    private function inRange(int $amount, mixed $min, mixed $max): bool
    {
        return $amount >= (int) ($min ?? 0) && ($max === null || $amount <= (int) $max);
    }

    private function range(mixed $min, mixed $max): string
    {
        return ($min === null ? '₹0' : $this->rupees((int) $min)).'–'.($max === null ? 'no max' : $this->rupees((int) $max));
    }

    private function rupees(int $paise): string
    {
        return '₹'.number_format($paise / 100, 2);
    }
}
