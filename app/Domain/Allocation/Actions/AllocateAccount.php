<?php

namespace App\Domain\Allocation\Actions;

use App\Domain\Allocation\Exceptions\LimitReached;
use App\Domain\Allocation\Exceptions\NoAccountAvailable;
use App\Domain\Allocation\UsageCounters;
use App\Domain\Branch\Models\Branch;
use App\Domain\Commission\Enums\Direction;
use App\Domain\Network\Models\PartnerBranchMapping;
use App\Domain\Partner\Models\Partner;
use App\Domain\PaymentAccount\Models\PaymentAccount;
use App\Domain\PaymentSession\Models\PaymentSession;
use App\Domain\Transaction\Enums\PayinStatus;
use App\Domain\Transaction\Models\Transaction;
use App\Domain\Transaction\Models\TransactionEvent;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Picks the account a customer pays into, when they choose a payment method
 * (Req G-29, G-30; Database.md §4):
 *
 * 1. Eligible accounts: active account of an active branch that is mapped
 *    (active, deposits on) to the partner, supports the method, and whose
 *    per-payment and branch limits fit the amount.
 * 2. Round robin: the account used longest ago first. Accounts another
 *    customer is being allocated right now are skipped (SKIP LOCKED); if
 *    that leaves nothing, look once more and wait instead of skipping, so a
 *    burst doesn't wrongly report "unavailable".
 * 3. Reserve today's capacity on the account, branch, pair and partner with
 *    conditional updates; if any limit is full, try the next account.
 */
class AllocateAccount
{
    public const METHODS = ['upi', 'qr', 'bank_transfer'];

    private const MAX_CANDIDATES = 25;

    public function __construct(private UsageCounters $usage, private ReleaseAllocation $release) {}

    public function handle(Transaction $payin, string $method): PaymentAccount
    {
        if (! in_array($method, self::METHODS, true)) {
            throw new RuntimeException("Unknown method {$method}.");
        }

        return DB::transaction(function () use ($payin, $method) {
            /** @var Transaction $locked */
            $locked = Transaction::query()->whereKey($payin->id)->lockForUpdate()->firstOrFail();

            if (! $locked->payinStatus()->isOpen()) {
                throw new NoAccountAvailable('closed');
            }

            $partner = Partner::query()->findOrFail($locked->partner_id);

            if (! $this->partnerAllows($partner, $method)) {
                throw new NoAccountAvailable('method_not_offered');
            }

            // Same account can take the new method: just switch.
            if ($locked->payment_account_id !== null) {
                $current = PaymentAccount::query()->find($locked->payment_account_id);

                if ($current !== null && self::supports($current, $method)) {
                    $this->switchMethod($locked, $method);

                    return $current;
                }

                $this->release->handle($locked, 'method_changed');
            }

            $account = $this->pick($locked, $partner, $method, skipLocked: true)
                ?? $this->pick($locked, $partner, $method, skipLocked: false);

            if ($account === null) {
                throw new NoAccountAvailable('no_account');
            }

            return $account;
        });
    }

    public static function supports(PaymentAccount $account, string $method): bool
    {
        return match ($method) {
            'upi' => $account->is_upi_enabled,
            'qr' => $account->is_upi_enabled && $account->is_qr_enabled,
            'bank_transfer' => $account->is_bank_enabled,
            default => false,
        };
    }

    public function partnerAllows(Partner $partner, string $method): bool
    {
        return match ($method) {
            'upi' => $partner->allow_upi,
            'qr' => $partner->allow_qr,
            'bank_transfer' => $partner->allow_bank_transfer,
            default => false,
        };
    }

    private function pick(Transaction $payin, Partner $partner, string $method, bool $skipLocked): ?PaymentAccount
    {
        $tried = [];

        for ($attempt = 0; $attempt < self::MAX_CANDIDATES; $attempt++) {
            $candidate = $this->candidate($payin, $method, $tried, $skipLocked);

            if ($candidate === null) {
                return null;
            }

            $tried[] = $candidate['account_id'];

            $reserved = $this->tryReserve($payin, $partner, $candidate, $method);

            if ($reserved !== null) {
                return $reserved;
            }
        }

        return null;
    }

    /**
     * The next eligible account in round-robin order, locked.
     *
     * @param  list<string>  $exclude
     * @return array{account_id: string, mapping_id: string, branch_id: string}|null
     */
    private function candidate(Transaction $payin, string $method, array $exclude, bool $skipLocked): ?array
    {
        $methodCondition = match ($method) {
            'upi' => 'a.is_upi_enabled',
            'qr' => 'a.is_upi_enabled AND a.is_qr_enabled',
            'bank_transfer' => 'a.is_bank_enabled',
            default => throw new RuntimeException("Unknown method {$method}."),
        };

        $query = DB::table('payment_accounts as a')
            ->join('partner_branch_mappings as m', 'm.branch_id', '=', 'a.branch_id')
            ->join('branches as b', 'b.id', '=', 'a.branch_id')
            ->where('m.partner_id', $payin->partner_id)
            ->where('m.status', 'active')
            ->where('m.is_deposit_enabled', true)
            ->whereRaw('(m.effective_from IS NULL OR m.effective_from <= now())')
            ->whereRaw('(m.effective_to IS NULL OR m.effective_to > now())')
            ->where('b.status', 'active')
            ->where('b.is_deposit_enabled', true)
            ->where('a.status', 'active')
            ->whereRaw($methodCondition)
            ->whereRaw('? BETWEEN COALESCE(a.min_amount, 0) AND COALESCE(a.max_amount, ?)', [$payin->amount, $payin->amount])
            ->whereRaw('? BETWEEN COALESCE(b.deposit_min_amount, 0) AND COALESCE(b.deposit_max_amount, ?)', [$payin->amount, $payin->amount])
            ->when($exclude !== [], fn ($query) => $query->whereNotIn('a.id', $exclude))
            ->orderByRaw('a.last_allocated_at NULLS FIRST')
            ->orderBy('a.id')
            ->select(['a.id as account_id', 'm.id as mapping_id', 'a.branch_id'])
            ->limit(1);

        $sql = $query->toSql().($skipLocked ? ' FOR UPDATE OF a SKIP LOCKED' : ' FOR UPDATE OF a');

        $rows = DB::select($sql, $query->getBindings());

        if (! isset($rows[0])) {
            return null;
        }

        return [
            'account_id' => (string) $rows[0]->account_id,
            'mapping_id' => (string) $rows[0]->mapping_id,
            'branch_id' => (string) $rows[0]->branch_id,
        ];
    }

    /**
     * Reserves every limit for this account, or nothing (savepoint).
     */
    /**
     * @param  array{account_id: string, mapping_id: string, branch_id: string}  $candidate
     */
    private function tryReserve(Transaction $payin, Partner $partner, array $candidate, string $method): ?PaymentAccount
    {
        try {
            return DB::transaction(function () use ($payin, $partner, $candidate, $method) {
                /** @var PaymentAccount $account */
                $account = PaymentAccount::query()->findOrFail($candidate['account_id']);
                /** @var Branch $branch */
                $branch = Branch::query()->findOrFail($candidate['branch_id']);
                /** @var PartnerBranchMapping $mapping */
                $mapping = PartnerBranchMapping::query()->findOrFail($candidate['mapping_id']);
                $date = UsageCounters::businessDate();
                $amount = $payin->amount;
                $in = Direction::Deposit;

                // A top-up branch may only take what is left of its allowance;
                // otherwise its daily cap applies.
                $branchLimit = $branch->usesTopup() ? $branch->deposit_topup_balance : $branch->deposit_daily_limit;

                $scopes = [
                    ['account', $account->id, $account->daily_amount_limit, $account->daily_count_limit, $account->max_open_sessions],
                    ['branch', $branch->id, $branchLimit, null, null],
                    ['mapping', $mapping->id, $mapping->deposit_daily_limit, null, null],
                    ['partner', $partner->id, $partner->deposit_daily_limit, null, null],
                ];

                foreach ($scopes as [$type, $id, $amountLimit, $countLimit, $sessionLimit]) {
                    if (! $this->usage->reserve($type, $id, $date, $in, $amount, $amountLimit, $countLimit, $sessionLimit)) {
                        throw new LimitReached($type);
                    }
                }

                $account->forceFill(['last_allocated_at' => now()])->save();

                $from = $payin->status;
                $payin->forceFill([
                    'branch_id' => $branch->id,
                    'payment_account_id' => $account->id,
                    'method' => $method,
                    'status' => PayinStatus::AwaitingPayment->value,
                ])->save();

                TransactionEvent::record($payin, 'allocated', $from, $payin->status, 'customer', null, null, [
                    'account_id' => $account->id,
                    'branch_id' => $branch->id,
                    'method' => $payin->method,
                    // What to give back on expiry / cancellation (ReleaseAllocation).
                    'reservation' => [
                        'date' => $date,
                        'amount' => $amount,
                        'scopes' => [['account', $account->id, true], ['branch', $branch->id, false], ['mapping', $mapping->id, false], ['partner', $partner->id, false]],
                    ],
                ]);

                PaymentSession::query()->where('transaction_id', $payin->id)->update(['method_selected_at' => now()]);

                return $account;
            });
        } catch (LimitReached) {
            return null;
        }
    }

    private function switchMethod(Transaction $payin, string $method): void
    {
        $old = $payin->method;
        $payin->forceFill(['method' => $method])->save();

        TransactionEvent::record($payin, 'method_changed', $payin->status, $payin->status, 'customer', null, null, ['from' => $old, 'to' => $method]);
    }
}
