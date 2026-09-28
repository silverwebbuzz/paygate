<?php

namespace App\Domain\Settlement\Actions;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Ledger\Ledger;
use App\Domain\Network\Models\PartnerBranchMapping;
use App\Domain\Partner\Models\Partner;
use App\Domain\Reconciliation\Enums\Resolution;
use App\Domain\Reconciliation\Models\ReconciliationCase;
use App\Domain\Reconciliation\Models\StatementEntry;
use App\Domain\Reconciliation\StatementMatcher;
use App\Domain\Settlement\Models\Adjustment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adjustments with maker–checker (decided 2026-09-28, G-44): one admin
 * requests (with a reason), a different admin approves or rejects. Only an
 * approved adjustment is booked, as one `adjustment` journal:
 *
 * - topup (G-16): the partner paid the platform to fund payouts at that
 *   branch: partner position +amount, settlement clearing −amount.
 * - correction / goodwill: the position ±amount, platform adjustments ∓.
 *
 * An adjustment may resolve an unsettled case: approving it closes the
 * case as "adjusted".
 */
class ManageAdjustment
{
    public function __construct(private Ledger $ledger, private StatementMatcher $matcher) {}

    public function request(User $actor, string $type, Partner $partner, Branch $branch, string $side, int $amount, string $reason, ?ReconciliationCase $case = null): Adjustment
    {
        $problem = match (true) {
            ! in_array($type, Adjustment::TYPES, true) => __('Choose the type of adjustment.'),
            ! in_array($side, ['partner', 'branch'], true) => __('Choose whose position changes.'),
            $amount === 0 => __('Enter an amount.'),
            $type === 'topup' && ($side !== 'partner' || $amount < 0) => __('A top-up adds to the partner’s balance at a branch.'),
            $type === 'goodwill' && $amount < 0 => __('Goodwill can only be in the party’s favour.'),
            ! PartnerBranchMapping::query()->where(['partner_id' => $partner->id, 'branch_id' => $branch->id])->exists() => __(':partner and :branch aren’t mapped to each other.', ['partner' => $partner->code, 'branch' => $branch->code]),
            $case !== null && (! $case->isOpen() || $case->branch_id !== $branch->id) => __('The case must be open and belong to this branch.'),
            default => null,
        };

        if ($problem !== null) {
            throw ValidationException::withMessages(['amount' => $problem]);
        }

        $adjustment = Adjustment::create([
            'reference' => Adjustment::newReference(),
            'type' => $type,
            'partner_id' => $partner->id,
            'branch_id' => $branch->id,
            'side' => $side,
            'amount' => $amount,
            'case_id' => $case?->id,
            'reason' => $reason,
            'status' => 'pending',
            'requested_by' => $actor->id,
        ]);

        AuditLog::record('adjustment.requested', $adjustment, [], $adjustment->only(['reference', 'type', 'side', 'amount', 'reason']), $actor);

        return $adjustment;
    }

    public function approve(User $actor, Adjustment $adjustment, ?string $note = null): Adjustment
    {
        return DB::transaction(function () use ($actor, $adjustment, $note) {
            $locked = $this->lockPending($actor, $adjustment);

            $this->ledger->post('adjustment', [
                $this->ledger->account($locked->side === 'partner' ? Ledger::PARTNER_POSITION : Ledger::BRANCH_POSITION, $locked->partner_id, $locked->branch_id) => $locked->amount,
                $this->ledger->account($locked->type === 'topup' ? Ledger::SETTLEMENT_CLEARING : Ledger::PLATFORM_ADJUSTMENTS) => -$locked->amount,
            ], $locked->transaction_id, "Adjustment {$locked->reference}: {$locked->reason}", $actor->id, ['adjustment_id' => $locked->id]);

            $locked->forceFill(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now(), 'decision_note' => $note])->save();

            $case = $locked->case_id === null ? null : ReconciliationCase::query()->whereKey($locked->case_id)->lockForUpdate()->first();

            if ($case !== null && $case->isOpen()) {
                StatementEntry::query()->whereKey($case->statement_entry_id)->update(['status' => 'ignored', 'transaction_id' => null]);
                $this->matcher->resolve($case, Resolution::Adjusted, $actor, __('Adjustment :reference approved.', ['reference' => $locked->reference]));
            }

            AuditLog::record('adjustment.approved', $locked, ['status' => 'pending'], ['status' => 'approved', 'note' => $note], $actor);
            $adjustment->setRawAttributes($locked->getAttributes(), true);

            return $locked;
        });
    }

    public function reject(User $actor, Adjustment $adjustment, string $note): Adjustment
    {
        return DB::transaction(function () use ($actor, $adjustment, $note) {
            $locked = $this->lockPending($actor, $adjustment);

            $locked->forceFill(['status' => 'rejected', 'approved_by' => $actor->id, 'approved_at' => now(), 'decision_note' => $note])->save();

            AuditLog::record('adjustment.rejected', $locked, ['status' => 'pending'], ['status' => 'rejected', 'note' => $note], $actor);
            $adjustment->setRawAttributes($locked->getAttributes(), true);

            return $locked;
        });
    }

    private function lockPending(User $actor, Adjustment $adjustment): Adjustment
    {
        /** @var Adjustment $locked */
        $locked = Adjustment::query()->whereKey($adjustment->id)->lockForUpdate()->firstOrFail();

        if ($locked->status !== 'pending') {
            throw ValidationException::withMessages(['adjustment' => __('This adjustment was already :status.', ['status' => $locked->status])]);
        }

        if ($locked->requested_by === $actor->id) {
            throw ValidationException::withMessages(['adjustment' => __('You requested this adjustment: another admin must approve or reject it.')]);
        }

        return $locked;
    }
}
