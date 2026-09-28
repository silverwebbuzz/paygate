<?php

namespace App\Http\Shared\Settlements;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Ledger\Ledger;
use App\Domain\Partner\Models\Partner;
use App\Domain\Settlement\Models\Settlement;
use App\Domain\Settlement\Models\SettlementLine;
use App\Domain\Settlement\Models\SettlementPayment;

/**
 * Settlements as each portal may see them: partners never see branches,
 * branch commission or margin (so no pair lines); branches never see the
 * partner's commission or the margin; Admin sees everything.
 */
class SettlementPresenter
{
    public function __construct(private Ledger $ledger) {}

    /**
     * @param  bool  $current  the party's newest settlement (the one that takes payments)
     * @return array<string, mixed>
     */
    public function row(Settlement $settlement, User $viewer, bool $current): array
    {
        $type = $viewer->type;

        return [
            'id' => $settlement->id,
            'reference' => $settlement->reference,
            'party_type' => $settlement->party_type,
            'party' => $settlement->party_type === 'partner'
                ? ['id' => $settlement->partner_id, 'code' => $settlement->partner->code ?? '—', 'name' => $settlement->partner->name ?? '—']
                : ['id' => $settlement->branch_id, 'code' => $settlement->branch->code ?? '—', 'name' => $settlement->branch->name ?? '—'],
            'run_type' => $settlement->run_type,
            'period_start' => $settlement->period_start->toIso8601String(),
            'period_end' => $settlement->period_end->toIso8601String(),
            'figures' => $this->figures($settlement, $type),
            'net_amount' => $settlement->net_amount,
            'direction' => $settlement->direction,
            'settled_amount' => $settlement->settled_amount,
            'status' => $settlement->status,
            // Unpaid remainder moved into a newer settlement.
            'carried_forward' => $settlement->status !== 'settled' && ! $current,
            'can_pay' => $current && $settlement->status !== 'settled' && $viewer->isType(UserType::Admin) && $viewer->can('settlements.update'),
            'calculated_at' => $settlement->calculated_at->toIso8601String(),
            'calculated_by' => $settlement->calculator->name ?? 'Daily run',
        ];
    }

    /**
     * Lines and payments, for the drawer.
     *
     * @return array<string, mixed>
     */
    public function detail(Settlement $settlement, User $viewer): array
    {
        $type = $viewer->type;
        $owners = $this->ledger->owners(array_values(array_map('strval', $settlement->lines->pluck('ledger_account_id')->all())));
        $partners = Partner::query()->whereIn('id', array_column($owners, 'partner_id'))->get(['id', 'code', 'name'])->keyBy('id');
        $branches = Branch::query()->whereIn('id', array_column($owners, 'branch_id'))->get(['id', 'code', 'name'])->keyBy('id');

        return [
            'id' => $settlement->id,
            'notes' => $settlement->notes,
            // Partners see totals only (lines would name branches).
            'lines' => $type === UserType::Partner ? [] : $settlement->lines->map(function (SettlementLine $line) use ($settlement, $owners, $partners, $branches, $type) {
                $owner = $owners[$line->ledger_account_id] ?? ['partner_id' => null, 'branch_id' => null];
                $counterpart = $settlement->party_type === 'partner' ? $branches->get($owner['branch_id']) : $partners->get($owner['partner_id']);

                return [
                    'id' => $line->id,
                    'counterpart' => ['code' => $counterpart->code ?? '—', 'name' => $counterpart->name ?? '—'],
                    'figures' => $this->figures($line, $type),
                    'net_amount' => $line->net_amount,
                    'direction' => $line->direction(),
                    'settled_amount' => $line->settled_amount,
                    'remaining' => $line->remaining(),
                ];
            })->values()->all(),
            'payments' => $settlement->payments->map(fn (SettlementPayment $payment) => [
                'id' => $payment->id,
                'line_id' => $type === UserType::Partner ? null : $payment->settlement_line_id,
                'amount' => $payment->amount,
                'direction' => $payment->direction,
                'reference' => $payment->external_reference,
                'paid_at' => $payment->paid_at->toDateString(),
                'recorded_by' => $type === UserType::Admin ? $payment->recorder->name : null,
                'recorded_at' => $payment->created_at->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, int|null>
     */
    private function figures(Settlement|SettlementLine $record, UserType $viewer): array
    {
        return [
            'opening' => $record->opening_balance,
            'gross_payin' => $record->gross_payin,
            'gross_payout' => $record->gross_payout,
            'partner_commission' => $viewer === UserType::Branch ? null : $record->partner_commission,
            'branch_commission' => $viewer === UserType::Partner ? null : $record->branch_commission,
            'platform_margin' => $viewer === UserType::Admin ? $record->platform_margin : null,
            'adjustments' => $record->adjustments_total,
            'settlements' => $record->settlements_total,
            'closing' => $record->closing_balance,
        ];
    }
}
