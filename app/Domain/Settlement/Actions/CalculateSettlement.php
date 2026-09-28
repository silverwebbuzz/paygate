<?php

namespace App\Domain\Settlement\Actions;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Ledger\Ledger;
use App\Domain\Notification\AlertDispatcher;
use App\Domain\Partner\Models\Partner;
use App\Domain\Settlement\Models\Settlement;
use App\Domain\Settlement\Models\SettlementLine;
use App\Domain\Transaction\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Settlement calculation (Requirements F14): for one party, from the end of
 * its previous settlement up to `$periodEnd`, per partner↔branch pair:
 *
 *   opening position + pay-ins + payouts + adjustments + payments recorded
 *   = closing position (all from the ledger, by journal posting time)
 *
 * The closing position is what is owed now, including anything left unpaid
 * from earlier settlements (carry-forward, decided 2026-09-28, G-11).
 * Gross amounts and commissions come from the transactions booked in the
 * period. Nothing is posted: this only records the figures.
 *
 * Daily runs (after the cut-off set in Global Settings, G-09) skip parties
 * with no postings in the period; on-demand runs always produce a record.
 */
class CalculateSettlement
{
    public function __construct(private Ledger $ledger, private AlertDispatcher $alerts) {}

    public function handle(string $partyType, string $partyId, CarbonImmutable $periodEnd, string $runType, ?User $actor = null): ?Settlement
    {
        // Times are stored to the second: boundaries are whole seconds, so
        // every journal falls into exactly one period.
        $periodEnd = $periodEnd->startOfSecond();

        return DB::transaction(function () use ($partyType, $partyId, $periodEnd, $runType, $actor) {
            // One calculation per party at a time.
            ($partyType === 'partner' ? Partner::query() : Branch::query())->whereKey($partyId)->lockForUpdate()->firstOrFail();

            $accounts = $this->ledger->partyAccounts($partyType, $partyId);
            $ids = array_column($accounts, 'id');
            $previous = Settlement::query()
                ->where(['party_type' => $partyType, $partyType.'_id' => $partyId])
                ->where('status', '!=', 'cancelled')
                ->orderByDesc('period_end')
                ->first();
            $start = $previous !== null ? CarbonImmutable::parse($previous->period_end) : $this->ledger->firstPostingAt($ids);

            if ($start === null || $start->greaterThanOrEqualTo($periodEnd)) {
                return $runType === 'daily' ? null : throw ValidationException::withMessages(['party' => $start === null
                    ? __('Nothing has been booked for this party yet.')
                    : __('The previous settlement already covers up to :time.', ['time' => $start->timezone(config('app.business_timezone'))->format('d M Y, H:i')])]);
            }

            $statement = $this->ledger->statement($ids, $start, $periodEnd);
            $moved = array_filter($statement, fn (array $figures) => $figures['by_type'] !== []);

            if ($runType === 'daily' && $moved === []) {
                return null;
            }

            $transactionIds = [];

            foreach ($statement as $figures) {
                array_push($transactionIds, ...$figures['transactions']);
            }

            $transactions = Transaction::query()
                ->whereIn('id', array_unique($transactionIds))
                ->get(['id', 'direction', 'amount', 'partner_commission', 'branch_commission', 'platform_margin'])
                ->keyBy('id');

            $lines = [];

            foreach ($statement as $accountId => $figures) {
                $booked = $transactions->only($figures['transactions']);
                $lines[] = [
                    'ledger_account_id' => $accountId,
                    'opening_balance' => $figures['opening'],
                    'gross_payin' => (int) $booked->where('direction', 'payin')->sum('amount'),
                    'gross_payout' => (int) $booked->where('direction', 'payout')->sum('amount'),
                    'partner_commission' => (int) $booked->sum('partner_commission'),
                    'branch_commission' => (int) $booked->sum('branch_commission'),
                    'platform_margin' => (int) $booked->sum('platform_margin'),
                    'adjustments_total' => ($figures['by_type']['adjustment'] ?? 0) + ($figures['by_type']['reversal'] ?? 0),
                    'settlements_total' => $figures['by_type']['settlement'] ?? 0,
                    'closing_balance' => $figures['closing'],
                    'net_amount' => abs($figures['closing']),
                    'settled_amount' => 0,
                ];
            }

            $sum = fn (string $column) => array_sum(array_column($lines, $column));
            $closing = (int) $sum('closing_balance');

            $settlement = Settlement::create([
                'reference' => Settlement::newReference(),
                'party_type' => $partyType,
                'partner_id' => $partyType === 'partner' ? $partyId : null,
                'branch_id' => $partyType === 'branch' ? $partyId : null,
                'run_type' => $runType,
                'period_start' => $start,
                'period_end' => $periodEnd,
                ...array_combine(
                    ['opening_balance', 'gross_payin', 'gross_payout', 'partner_commission', 'branch_commission', 'platform_margin', 'adjustments_total', 'settlements_total'],
                    array_map($sum, ['opening_balance', 'gross_payin', 'gross_payout', 'partner_commission', 'branch_commission', 'platform_margin', 'adjustments_total', 'settlements_total']),
                ),
                'closing_balance' => $closing,
                'net_amount' => abs($closing),
                'direction' => Settlement::directionOf($closing),
                // Nothing to pay on any pair: settled as it stands.
                'status' => $sum('net_amount') === 0 ? 'settled' : 'calculated',
                'calculated_by' => $actor?->id,
                'calculated_at' => now(),
            ]);

            foreach ($lines as $line) {
                SettlementLine::create(['settlement_id' => $settlement->id, ...$line]);
            }

            AuditLog::record('settlement.calculated', $settlement, [], [
                'reference' => $settlement->reference,
                'run' => $runType,
                'period_end' => $periodEnd->toIso8601String(),
                'closing' => $closing,
            ], $actor);

            $this->alerts->settlementCalculated($settlement);

            return $settlement;
        });
    }

    /**
     * The scheduled daily run: every party with pair positions, up to the
     * most recent cut-off. Safe to run again (nothing new → nothing made).
     *
     * @return int settlements created
     */
    public function daily(CarbonImmutable $cutoff): int
    {
        $created = 0;

        foreach (['partner' => Partner::query()->pluck('id'), 'branch' => Branch::query()->pluck('id')] as $type => $ids) {
            foreach ($ids as $id) {
                if ($this->handle($type, (string) $id, $cutoff, 'daily') !== null) {
                    $created++;
                }
            }
        }

        return $created;
    }
}
