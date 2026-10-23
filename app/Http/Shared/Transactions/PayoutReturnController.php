<?php

namespace App\Http\Shared\Transactions;

use App\Domain\Core\Identity\Models\User;
use App\Domain\Transaction\Actions\ManagePayoutReturn;
use App\Domain\Transaction\Models\PayoutReturn;
use App\Domain\Transaction\Models\Transaction;
use App\Http\Controller;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Payout returns on the payout lists (decided 2026-10-22):
 * - raise: Partner › Pay-out (or Admin › Transactions for it): the customer
 *   says the money never arrived;
 * - record: Branch › Pay-out history, when the money is back in its bank;
 * - confirm / reject: the branch (or Admin) with the amount that came back,
 *   the credit's UTR and the bank's proof.
 */
class PayoutReturnController extends Controller
{
    public function store(Request $request, Transaction $transaction, ManagePayoutReturn $returns): RedirectResponse
    {
        Gate::authorize('raiseReturn', $transaction);

        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $return = $returns->request($this->user($request), $transaction, (string) $data['reason']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':reference raised on :txn; the branch will check its bank and confirm.', ['reference' => $return->reference, 'txn' => $transaction->reference])]);

        return back();
    }

    public function record(Request $request, Transaction $transaction, ManagePayoutReturn $returns): RedirectResponse
    {
        Gate::authorize('recordReturn', $transaction);

        $data = $this->confirmation($request);
        $return = $returns->record($this->user($request), $transaction, $data['payout_payment_id'] ?? null, Money::toPaise((string) $data['amount']), (string) $data['utr'], $this->returnedAt($data), $this->proof($request), $data['note'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':reference recorded: booked today, the partner is notified.', ['reference' => $return->reference])]);

        return back();
    }

    public function confirm(Request $request, PayoutReturn $payoutReturn, ManagePayoutReturn $returns): RedirectResponse
    {
        Gate::authorize('decideReturn', $payoutReturn->transaction);

        $data = $this->confirmation($request);
        $returns->confirm($this->user($request), $payoutReturn, $data['payout_payment_id'] ?? null, Money::toPaise((string) $data['amount']), (string) $data['utr'], $this->returnedAt($data), $this->proof($request), $data['note'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':reference confirmed: booked today, the partner is notified.', ['reference' => $payoutReturn->reference])]);

        return back();
    }

    public function reject(Request $request, PayoutReturn $payoutReturn, ManagePayoutReturn $returns): RedirectResponse
    {
        Gate::authorize('decideReturn', $payoutReturn->transaction);

        $note = (string) $request->validate(['note' => ['required', 'string', 'max:500']])['note'];
        $returns->reject($this->user($request), $payoutReturn, $note);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':reference rejected; the partner is told why.', ['reference' => $payoutReturn->reference])]);

        return back();
    }

    /**
     * @return array{payout_payment_id?: string|null, amount: string, utr: string, returned_at: string, note?: string|null}
     */
    private function confirmation(Request $request): array
    {
        /** @var array{payout_payment_id?: string|null, amount: string, utr: string, returned_at: string, note?: string|null} */
        return $request->validate([
            // Which transfer came back (optional when the payout was one transfer).
            'payout_payment_id' => ['nullable', 'uuid'],
            'amount' => ['required', 'string', Money::RUPEES_RULE],
            'utr' => ['required', 'string', 'max:50'],
            'returned_at' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
            'proof' => ['required', 'file', 'mimes:'.implode(',', (array) config('paygate.payin.proof_mimes')), 'max:'.config('paygate.payin.proof_max_kb')],
        ]);
    }

    /**
     * The date and time typed on the bank statement, in India time.
     *
     * @param  array{returned_at: string}  $data
     */
    private function returnedAt(array $data): CarbonImmutable
    {
        // Stored in UTC: Eloquent writes the wall-clock time as given.
        return CarbonImmutable::parse($data['returned_at'], (string) config('app.business_timezone'))->utc();
    }

    private function proof(Request $request): UploadedFile
    {
        /** @var UploadedFile */
        return $request->file('proof');
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
