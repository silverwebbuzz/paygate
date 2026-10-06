<?php

namespace App\Domain\Partner\Actions;

use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Partner\Models\Partner;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SetPartnerDirection
{
    public function handle(User $actor, Partner $partner, string $direction, bool $enabled, string $reason): Partner
    {
        if (! in_array($direction, ['payin', 'payout'], true)) {
            throw ValidationException::withMessages(['direction' => __('Choose pay-in or pay-out.')]);
        }

        $current = $direction === 'payin' ? $partner->is_payin_enabled : $partner->is_payout_enabled;

        if ($current === $enabled) {
            throw ValidationException::withMessages(['enabled' => $enabled ? __('This is already enabled.') : __('This is already disabled.')]);
        }

        DB::transaction(function () use ($actor, $partner, $direction, $enabled, $reason, $current) {
            if ($direction === 'payin') {
                $partner->is_payin_enabled = $enabled;
            } else {
                $partner->is_payout_enabled = $enabled;
            }

            $partner->save();

            AuditLog::record('partner.direction_changed', $partner, [
                'direction' => $direction,
                'enabled' => $current,
            ], [
                'direction' => $direction,
                'enabled' => $enabled,
                'reason' => $reason,
            ], $actor);
        });

        return $partner;
    }
}
