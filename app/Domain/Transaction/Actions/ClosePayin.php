<?php

namespace App\Domain\Transaction\Actions;

use App\Domain\Allocation\Actions\ReleaseAllocation;
use App\Domain\Transaction\Enums\PayinStatus;
use App\Domain\Transaction\Models\Transaction;
use App\Domain\Transaction\Models\TransactionEvent;
use Illuminate\Support\Facades\DB;

/**
 * Ends a pay-in the customer never paid: `expired` (time ran out, run by
 * the scheduler every minute) or `cancelled` (by the partner via the API).
 * Only pay-ins still waiting for payment can be closed; the capacity they
 * reserved is given back.
 */
class ClosePayin
{
    public function __construct(private ReleaseAllocation $release) {}

    /**
     * @return bool false when the pay-in had already moved on
     */
    public function handle(Transaction $payin, PayinStatus $to, string $actorType, ?string $actorId = null, ?string $reason = null): bool
    {
        return DB::transaction(function () use ($payin, $to, $actorType, $actorId, $reason) {
            /** @var Transaction $locked */
            $locked = Transaction::query()->whereKey($payin->id)->lockForUpdate()->firstOrFail();

            if (! $locked->payinStatus()->isOpen()) {
                return false;
            }

            $this->release->handle($locked, $to->value);

            $from = $locked->status;
            $locked->forceFill(['status' => $to->value, 'status_reason_code' => $to->value, 'decided_at' => now()])->save();
            $locked->session()->update(['status' => 'expired']);

            TransactionEvent::record($locked, $to === PayinStatus::Expired ? 'expired' : 'cancelled', $from, $locked->status, $actorType, $actorId, $reason);

            $payin->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
    }

    /**
     * Expires every pay-in whose time ran out. Returns how many.
     */
    public function expireDue(int $limit = 500): int
    {
        $count = 0;

        Transaction::query()
            ->where('direction', 'payin')
            ->whereIn('status', [PayinStatus::Created->value, PayinStatus::AwaitingPayment->value])
            ->where('expires_at', '<=', now())
            ->orderBy('expires_at')
            ->limit($limit)
            ->get()
            ->each(function (Transaction $payin) use (&$count) {
                if ($this->handle($payin, PayinStatus::Expired, 'system', null, 'Payment time ran out.')) {
                    $count++;
                }
            });

        return $count;
    }
}
