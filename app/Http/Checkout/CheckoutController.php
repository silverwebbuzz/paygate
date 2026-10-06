<?php

namespace App\Http\Checkout;

use App\Domain\Allocation\Actions\AllocateAccount;
use App\Domain\Allocation\Exceptions\NoAccountAvailable;
use App\Domain\Partner\Models\Partner;
use App\Domain\PaymentSession\Models\PaymentSession;
use App\Domain\Platform\Models\Page;
use App\Domain\Platform\Settings;
use App\Domain\Transaction\Actions\ClosePayin;
use App\Domain\Transaction\Actions\SubmitPayinProof;
use App\Domain\Transaction\Enums\PayinStatus;
use App\Domain\Transaction\Models\Transaction;
use App\Http\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The customer's payment page (pay.paygate.local/p/{token}). No login: the
 * unguessable token is the key. The customer sees the partner's name and
 * the one account allocated to them — never branch or commission details.
 */
class CheckoutController extends Controller
{
    public function show(Request $request, string $token, ClosePayin $close): Response
    {
        $session = $this->session($token);
        $payin = $session->transaction;

        // Don't show payment details a second past the deadline, even if the
        // expiry job hasn't run yet.
        if ($payin->payinStatus()->isOpen() && $payin->expires_at?->isPast()) {
            $close->handle($payin, PayinStatus::Expired, 'system', null, 'Payment time ran out.');
            $payin->refresh();
        }

        if ($session->opened_at === null) {
            $session->forceFill([
                'status' => $session->status === 'issued' ? 'opened' : $session->status,
                'opened_at' => now(),
                'client_ip' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
            ])->save();
        }

        $partner = $payin->partner;
        $account = $payin->paymentAccount;
        $showAccount = $account !== null && in_array($payin->payinStatus(), [PayinStatus::AwaitingPayment, PayinStatus::PaymentSubmitted], true);

        return Inertia::render('checkout/show', [
            'token' => $token,
            'partner' => ['name' => $partner->name, 'initials' => $this->initials($partner->name)],
            // Global Settings: support contact and published content pages (G-48).
            'support' => app(Settings::class)->checkoutSupport(),
            'pages' => Page::query()->where('status', 'published')->orderBy('title')->get(['slug', 'title'])->map(fn (Page $page) => ['title' => $page->title, 'url' => route('pay.legal.show', $page->slug)]),
            'payin' => [
                'reference' => $payin->reference,
                'order_id' => $payin->partner_transaction_id,
                'amount' => $payin->amount,
                'status' => $payin->status,
                'method' => $payin->method,
                'utr' => $payin->customer_utr_normalized,
                'created_at' => $payin->created_at?->toIso8601String(),
                'expires_at' => $payin->expires_at?->toIso8601String(),
                'submitted_at' => $payin->submitted_at?->toIso8601String(),
                'completed_at' => ($payin->succeeded_at ?? $payin->decided_at)?->toIso8601String(),
            ],
            'server_time' => now()->toIso8601String(),
            'methods' => array_values(array_filter(
                AllocateAccount::METHODS,
                fn (string $method) => app(AllocateAccount::class)->partnerAllows($partner, $method),
            )),
            'account' => $showAccount ? [
                'holder' => $account->account_holder_name,
                'bank_name' => $account->is_bank_enabled ? $account->bank_name : null,
                'account_number' => $account->is_bank_enabled ? $account->account_number_encrypted : null,
                'ifsc' => $account->is_bank_enabled ? $account->ifsc : null,
                'upi_id' => $account->is_upi_enabled ? $account->upi_id_encrypted : null,
                'upi_name' => $account->upi_display_name ?? $account->account_holder_name,
                'supports' => array_values(array_filter(AllocateAccount::METHODS, fn (string $method) => AllocateAccount::supports($account, $method))),
                'qr_svg' => $account->is_upi_enabled && $account->is_qr_enabled ? UpiLinks::qrSvg($account, $payin) : null,
            ] : null,
            'return_url' => $this->returnUrl($payin, $partner),
            'proof' => [
                'max_kb' => (int) config('paygate.payin.proof_max_kb'),
                'types' => config('paygate.payin.proof_mimes'),
            ],
        ]);
    }

    /**
     * The customer picks how to pay: allocate an account for that method.
     */
    public function method(Request $request, string $token, AllocateAccount $allocate): RedirectResponse
    {
        $session = $this->session($token);
        $data = $request->validate(['method' => ['required', Rule::in(AllocateAccount::METHODS)]]);

        try {
            $allocate->handle($session->transaction, $data['method']);
        } catch (NoAccountAvailable $exception) {
            throw ValidationException::withMessages(['method' => match ($exception->reason) {
                'method_not_offered' => __('This payment method isn’t available. Please choose another.'),
                'closed' => __('This payment is no longer open.'),
                default => __('This payment method is busy right now. Please try another method or try again in a few minutes.'),
            }]);
        }

        return to_route('pay.checkout.show', ['token' => $token]);
    }

    /**
     * "I've made the payment": UTR and/or screenshot.
     */
    public function proof(Request $request, string $token, SubmitPayinProof $submit): RedirectResponse
    {
        $session = $this->session($token);

        $request->validate([
            'utr' => ['nullable', 'string', 'max:50'],
            'photo' => ['nullable', 'file', 'mimes:'.implode(',', (array) config('paygate.payin.proof_mimes')), 'max:'.config('paygate.payin.proof_max_kb')],
        ]);

        $submit->handle($session->transaction, $request->input('utr'), $request->file('photo'));

        return to_route('pay.checkout.show', ['token' => $token]);
    }

    private function session(string $token): PaymentSession
    {
        $session = strlen($token) <= 64 ? PaymentSession::findByToken($token) : null;

        abort_if($session === null, 404);

        $session->load('transaction.partner', 'transaction.paymentAccount');

        return $session;
    }

    private function initials(string $name): string
    {
        $words = preg_split('/\s+/', trim($name)) ?: [];

        return mb_strtoupper(implode('', array_map(fn (string $word) => mb_substr($word, 0, 1), array_slice($words, 0, 2))));
    }

    /**
     * Where "Return to <partner>" goes: the per-request URL (already checked
     * to be on the partner's domain) or the partner's saved one, with the
     * order id and status. The partner must confirm the status via the API.
     */
    private function returnUrl(Transaction $payin, Partner $partner): ?string
    {
        $base = $payin->return_url ?? $partner->return_url;

        if ($base === null) {
            return null;
        }

        $query = http_build_query(['order_id' => $payin->partner_transaction_id, 'status' => $payin->status]);

        return $base.(str_contains($base, '?') ? '&' : '?').$query;
    }
}
