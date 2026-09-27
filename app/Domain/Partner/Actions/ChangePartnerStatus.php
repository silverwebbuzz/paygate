<?php

namespace App\Domain\Partner\Actions;

use App\Domain\Commission\Enums\Direction;
use App\Domain\Commission\RateBook;
use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Organisation\Enums\OrganisationStatus;
use App\Domain\Partner\Models\Partner;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Activates, suspends or offboards a partner. Activation checks the partner
 * can actually trade: commission can only be calculated with a rate for each
 * enabled direction, and the API needs a key.
 */
class ChangePartnerStatus
{
    public function __construct(private RateBook $rates) {}

    public function handle(User $actor, Partner $partner, OrganisationStatus $status, string $reason): Partner
    {
        if (! $partner->status->canMoveTo($status)) {
            throw ValidationException::withMessages(['status' => __('A :from partner can’t become :to.', [
                'from' => str_replace('_', ' ', $partner->status->value),
                'to' => str_replace('_', ' ', $status->value),
            ])]);
        }

        if ($status === OrganisationStatus::Active) {
            $missing = $this->activationBlockers($partner);

            if ($missing !== []) {
                throw ValidationException::withMessages(['status' => __('Not ready to go live: :missing.', ['missing' => implode('; ', $missing)])]);
            }
        }

        DB::transaction(function () use ($actor, $partner, $status, $reason) {
            $old = $partner->status;

            $partner->status = $status;

            if ($status === OrganisationStatus::Active && $partner->verified_at === null) {
                $partner->verified_at = now();
                $partner->verified_by = $actor->id;
            }

            $partner->save();

            AuditLog::record('partner.status_changed', $partner, ['status' => $old->value], ['status' => $status->value, 'reason' => $reason], $actor);
        });

        return $partner;
    }

    /**
     * What stops the partner going live (empty when ready).
     *
     * @return list<string>
     */
    public function activationBlockers(Partner $partner): array
    {
        $missing = [];

        if (! $partner->is_payin_enabled && ! $partner->is_payout_enabled) {
            $missing[] = __('enable pay-in or pay-out');
        }

        if ($partner->is_payin_enabled && $this->rates->partnerRate($partner, Direction::Deposit) === null) {
            $missing[] = __('set the deposit commission');
        }

        if ($partner->is_payout_enabled && $this->rates->partnerRate($partner, Direction::Withdrawal) === null) {
            $missing[] = __('set the withdrawal commission');
        }

        if (! $partner->activeApiKey()->exists()) {
            $missing[] = __('generate an API key');
        }

        return $missing;
    }
}
