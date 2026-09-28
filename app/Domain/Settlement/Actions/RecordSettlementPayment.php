<?php

namespace App\Domain\Settlement\Actions;

use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Ledger\Ledger;
use App\Domain\Settlement\Models\Settlement;
use App\Domain\Settlement\Models\SettlementLine;
use App\Domain\Settlement\Models\SettlementPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Admin's "tick as settled" (Requirements F15; decided 2026-09-28: one
 * admin, no second approval, G-10). Money moved outside PayGate; for each
 * pair line an amount up to what is still open is recorded (partial is
 * fine; the rest carries forward, G-11). Each amount posts a `settlement`
 * journal that moves the pair position toward zero against settlement
 * clearing, and the settlement becomes partially settled or settled.
 *
 * Only the party's latest settlement takes payments: older ones are
 * carried forward into it.
 */
class RecordSettlementPayment
{
    public function __construct(private Ledger $ledger) {}

    /**
     * @param  array<string, int>  $amounts  settlement line id => paise
     */
    public function handle(User $actor, Settlement $settlement, array $amounts, string $paidAt, ?string $reference = null, ?string $note = null): Settlement
    {
        $amounts = array_filter($amounts, fn (int $amount) => $amount > 0);

        if ($amounts === []) {
            throw ValidationException::withMessages(['amounts' => __('Enter the amount paid for at least one line.')]);
        }

        return DB::transaction(function () use ($actor, $settlement, $amounts, $paidAt, $reference, $note) {
            /** @var Settlement $locked */
            $locked = Settlement::query()->whereKey($settlement->id)->lockForUpdate()->firstOrFail();
            $this->ensureLatest($locked);

            if ($locked->status === 'settled') {
                throw ValidationException::withMessages(['amounts' => __('This settlement is already fully settled.')]);
            }

            $lines = $locked->lines()->lockForUpdate()->get()->keyBy('id');
            $owners = $this->ledger->owners(array_values(array_map('strval', $lines->pluck('ledger_account_id')->all())));

            foreach ($amounts as $lineId => $amount) {
                /** @var SettlementLine|null $line */
                $line = $lines->get($lineId);

                if ($line === null) {
                    throw ValidationException::withMessages(['amounts' => __('That line isn’t part of this settlement.')]);
                }

                if ($amount > $line->remaining()) {
                    throw ValidationException::withMessages(["amounts.{$lineId}" => __('At most ₹:max is still open on this line.', ['max' => number_format($line->remaining() / 100, 2)])]);
                }

                // Paying a partner out must leave what payouts in progress hold.
                if ($locked->party_type === 'partner' && $line->closing_balance > 0) {
                    $figures = $this->ledger->figures($line->ledger_account_id);
                    $free = max(0, $figures['balance'] - $figures['reserved']);

                    if ($amount > $free) {
                        throw ValidationException::withMessages(["amounts.{$lineId}" => __('Only ₹:free can be paid out now: the rest is held for payouts in progress or already used.', ['free' => number_format($free / 100, 2)])]);
                    }
                }

                $payment = SettlementPayment::create([
                    'settlement_id' => $locked->id,
                    'settlement_line_id' => $line->id,
                    'amount' => $amount,
                    'direction' => $line->direction(),
                    'method' => 'external',
                    'external_reference' => $reference === null ? null : mb_substr(trim($reference), 0, 100),
                    'paid_at' => $paidAt,
                    'recorded_by' => $actor->id,
                ]);

                // Toward zero: a position the platform owes goes down, one owed to it goes up.
                $delta = $line->closing_balance > 0 ? -$amount : $amount;

                $this->ledger->post('settlement', [
                    $line->ledger_account_id => $delta,
                    $this->ledger->account(Ledger::SETTLEMENT_CLEARING) => -$delta,
                ], null, "Settlement {$locked->reference}", $actor->id, ['settlement_payment_id' => $payment->id]);

                $line->forceFill(['settled_amount' => $line->settled_amount + $amount])->save();

                AuditLog::record('settlement.payment_recorded', $locked, [], [
                    'line' => $line->id,
                    'pair' => $owners[$line->ledger_account_id] ?? null,
                    'amount' => $amount,
                    'direction' => $line->direction(),
                    'reference' => $reference,
                    'paid_at' => $paidAt,
                ], $actor);
            }

            // Party level: the lines' payments in the settlement's direction, net.
            $signed = $lines->sum(fn (SettlementLine $line) => $line->direction() === $locked->direction ? $line->settled_amount : -$line->settled_amount);

            $locked->forceFill([
                'settled_amount' => max(0, min($locked->net_amount, (int) $signed)),
                'status' => $lines->every(fn (SettlementLine $line) => $line->remaining() === 0) ? 'settled' : 'partially_settled',
                'notes' => $note === null || trim($note) === '' ? $locked->notes : trim(($locked->notes ?? '')."\n".trim($note)),
            ])->save();

            $settlement->setRawAttributes($locked->getAttributes(), true);

            return $locked;
        });
    }

    private function ensureLatest(Settlement $settlement): void
    {
        $latest = Settlement::query()
            ->where(['party_type' => $settlement->party_type, $settlement->party_type.'_id' => $settlement->partyId()])
            ->where('status', '!=', 'cancelled')
            ->orderByDesc('period_end')
            ->first();

        if ($latest !== null && $latest->id !== $settlement->id) {
            throw ValidationException::withMessages(['amounts' => __('This balance was carried forward into :reference: record the payment there.', ['reference' => $latest->reference])]);
        }
    }
}
