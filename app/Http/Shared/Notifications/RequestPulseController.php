<?php

namespace App\Http\Shared\Notifications;

use App\Domain\Core\Identity\Models\User;
use App\Http\Controller;
use App\Http\Shared\Transactions\TransactionController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The request sound (client request 2026-11-25): every portal page asks this
 * every few seconds for the newest deposit and withdrawal request the person
 * can see, and beeps (a different sound for each) when either is newer than
 * the last answer. See resources/js/hooks/use-request-sound.ts.
 *
 * A deposit request is the customer submitting their payment (submitted_at);
 * a withdrawal request is the payout arriving (created_at). Same scope and
 * permissions as the Deposit / Withdrawal lists.
 */
class RequestPulseController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $latest = function (string $direction, string $column) use ($actor): ?string {
            $value = TransactionController::scoped($actor)->where('direction', $direction)->max($column);

            return $value === null ? null : Carbon::parse($value)->utc()->format('Y-m-d\TH:i:s.u\Z');
        };

        return response()->json([
            'deposit' => $actor->can('payins.view') ? $latest('payin', 'submitted_at') : null,
            'withdrawal' => $actor->can('payouts.view') ? $latest('payout', 'created_at') : null,
        ]);
    }
}
