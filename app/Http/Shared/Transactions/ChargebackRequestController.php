<?php

namespace App\Http\Shared\Transactions;

use App\Domain\Core\Identity\Models\User;
use App\Domain\Transaction\Actions\ManageChargeback;
use App\Domain\Transaction\Models\Transaction;
use App\Http\Controller;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Raise a chargeback on a successful deposit (Branch › Pay-in history, or
 * Admin › Transactions): the bank's notice and the amount. Admin approves it on the Chargebacks screen.
 */
class ChargebackRequestController extends Controller
{
    public function store(Request $request, Transaction $transaction, ManageChargeback $chargebacks): RedirectResponse
    {
        Gate::authorize('reversals.create');
        Gate::authorize('view', $transaction);

        $data = $request->validate([
            'amount' => ['required', 'string', Money::RUPEES_RULE],
            'reason' => ['nullable', 'string', 'max:500'],
            'proof' => ['required', 'file', 'mimes:'.implode(',', (array) config('paygate.payin.proof_mimes')), 'max:'.config('paygate.payin.proof_max_kb')],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        /** @var UploadedFile $proof */
        $proof = $request->file('proof');
        $chargeback = $chargebacks->request($actor, $transaction, Money::toPaise((string) $data['amount']), $proof, $data['reason'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Chargeback :reference raised on :txn; Admin will review it.', ['reference' => $chargeback->reference, 'txn' => $transaction->reference])]);

        return back();
    }
}
