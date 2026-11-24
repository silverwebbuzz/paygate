<?php

namespace App\Http\Shared\Transactions;

use App\Domain\Reporting\Period;
use App\Domain\Transaction\Models\Chargeback;
use App\Domain\Transaction\Models\PayoutReturn;
use App\Domain\Transaction\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The date filter of the Chargebacks and Payout returns pages (client
 * request 2026-11-24): which date (?date_by=) and the period (?range=, the
 * same buttons as the other lists plus "All", the default: these pages are
 * work queues).
 *
 * - transaction: when the deposit / payout was created
 * - bank: when the bank took it back (chargeback, from its notice) or the
 *   money came back (payout return, entered on confirmation)
 * - entered: when it was raised in PayGate
 * - decided: when Admin approved it / the branch confirmed it (the day it
 *   is booked in settlements)
 *
 * A case without that date (a chargeback raised before 2026-11-24 has no
 * bank date; an open one has no decision) is left out while a period is set.
 */
final class ReversalDates
{
    public const BASES = ['transaction', 'bank', 'entered', 'decided'];

    private function __construct(
        public readonly string $by,
        public readonly ?Period $period,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $by = $request->query('date_by');
        $range = $request->query('range');
        $period = is_string($range) && $range !== '' && $range !== 'all' ? Period::fromRequest($request) : null;

        return new self(in_array($by, self::BASES, true) ? $by : 'entered', $period);
    }

    /**
     * A query on chargebacks or payout returns.
     *
     * @template TModel of Chargeback|PayoutReturn
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function applyToCases(Builder $query): Builder
    {
        $period = $this->period;

        if ($period === null) {
            return $query;
        }

        if ($this->by === 'transaction') {
            return $query->whereHas('transaction', fn (Builder $query) => $period->apply($query, 'transactions.created_at'));
        }

        $table = $query->getModel()->getTable();

        return $query->where(fn (Builder $query) => $period->apply($query, $table.'.'.$this->column($table)));
    }

    /**
     * A query on deposits (chargeback) or payouts (return): those with a
     * case whose date falls in the period. $cases narrows the cases looked
     * at (partners: approved chargebacks only).
     *
     * @param  Builder<Transaction>  $query
     * @param  'chargeback'|'return'  $kind
     * @param  (callable(Builder<Chargeback>|Builder<PayoutReturn>): mixed)|null  $cases
     * @return Builder<Transaction>
     */
    public function applyToTransactions(Builder $query, string $kind, ?callable $cases = null): Builder
    {
        $period = $this->period;

        if ($period === null) {
            return $query;
        }

        if ($this->by === 'transaction') {
            return $query->where(fn (Builder $query) => $period->apply($query, 'transactions.created_at'));
        }

        $table = $kind === 'chargeback' ? 'chargebacks' : 'payout_returns';

        return $query->whereHas($kind === 'chargeback' ? 'chargebacks' : 'payoutReturns', function (Builder $query) use ($period, $table, $cases) {
            if ($cases !== null) {
                $cases($query);
            }

            $period->apply($query, $table.'.'.$this->column($table));
        });
    }

    /**
     * @return array{by: string, period: array{range: string, from: string, to: string, label: string}|null}
     */
    public function toArray(): array
    {
        return ['by' => $this->by, 'period' => $this->period?->toArray()];
    }

    private function column(string $table): string
    {
        return match ($this->by) {
            'bank' => $table === 'chargebacks' ? 'debited_at' : 'returned_at',
            'decided' => 'decided_at',
            default => 'created_at',
        };
    }
}
