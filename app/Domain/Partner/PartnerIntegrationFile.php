<?php

namespace App\Domain\Partner;

use App\Domain\Partner\Actions\IssueApiKey;
use App\Domain\Partner\Models\Partner;
use App\Domain\PartnerApi\ApiAuthenticator;
use App\Support\Hosts;
use App\Support\Money;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class PartnerIntegrationFile
{
    private const SESSION = 'partner_integration_secret';

    public static function flashCredentials(Partner $partner, string $keyId, string $secret, string $issuedUrl): void
    {
        session([self::SESSION => ['partner_id' => $partner->id, 'secret' => $secret]]);

        Inertia::flash('credentials', [
            'partner' => $partner->name,
            'key_id' => $keyId,
            'secret' => $secret,
            'file_url' => $issuedUrl,
        ]);
    }

    public static function takeSecret(Partner $partner): ?string
    {
        $stored = session(self::SESSION);
        session()->forget(self::SESSION);

        if (! is_array($stored) || ($stored['partner_id'] ?? null) !== $partner->id || ! is_string($stored['secret'] ?? null)) {
            return null;
        }

        return $stored['secret'];
    }

    public function download(Partner $partner, ?string $secret): Response
    {
        $code = preg_replace('/[^A-Za-z0-9._-]/', '', $partner->code) ?: 'partner';

        return response($this->render($partner, $secret), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$code.'-paygate.txt"',
        ]);
    }

    public function render(Partner $partner, ?string $secret): string
    {
        $partner->loadMissing('activeApiKey');
        $key = $partner->activeApiKey;
        $base = Hosts::url('api', '/v1');
        $blank = 'not set';
        $line = fn (string $label, ?string $value) => $label.': '.(($value === null || $value === '') ? $blank : $value);
        $money = fn (?int $paise) => $paise === null || $paise < 0 ? 'unlimited' : '₹'.Money::toRupees($paise);

        $secretLine = $secret !== null
            ? $secret
            : ($key === null
                ? 'No key yet. Generate one, then download from that screen. That copy is the only file that contains the secret.'
                : 'Not included. The secret is shown only once, when the key is generated. This key’s secret ends with '.$key->secret_last4.'.');

        $ips = $partner->ipRules()->orderBy('created_at')->get()->map(fn ($rule) => $rule->display())->implode(', ');

        return implode("\n", [
            'PayGate partner integration',
            'Partner: '.$partner->name.' ('.$partner->code.')',
            'API version: v1',
            '',
            'Give this file to the partner’s developer. Keep the secret on their server only.',
            '',
            $line('Base URL', $base),
            $line('Key ID', $key?->key_id),
            'Secret: '.$secretLine,
            $line('Allowed IPs', $ips === '' ? null : $ips),
            'Calls are accepted only from those addresses. An empty list blocks every call.',
            'Rate limit: '.(int) config('paygate.api.rate_limit').' requests per minute.',
            'Rotating the secret keeps the previous key working for '.IssueApiKey::OVERLAP_HOURS.' hours.',
            '',
            'Your URLs',
            $line('Return URL', $partner->return_url).' — the customer’s browser is sent here after paying. Treat that visit as a hint and confirm the pay-in with the API before crediting them.',
            $line('Pay-in callback URL', $partner->callback_url),
            $line('Pay-in webhook URL', $partner->payin_webhook_url).' — PayGate POSTs signed pay-in updates here.',
            $line('Payout webhook URL', $partner->payout_webhook_url).' — PayGate POSTs signed payout updates here.',
            '',
            'Amounts are in paise (₹100 = 10000). Pay-in limits: '.$money($partner->deposit_min_amount).' to '.$money($partner->deposit_max_amount).'. The payment page stays open for '.$partner->session_ttl_minutes.' minutes.',
            '',
            '1. Sign every request',
            'Headers:',
            '  X-Key-Id: the key id',
            '  X-Timestamp: unix time in seconds, within ±'.(ApiAuthenticator::MAX_CLOCK_SKEW / 60).' minutes',
            '  X-Nonce: a new random string each request, 16–64 characters (letters, digits, - or _)',
            '  X-Signature: lower-case hex HMAC-SHA256 of the string below, keyed with the secret',
            'String to sign, five lines joined with a newline:',
            '  timestamp',
            '  nonce',
            '  METHOD',
            '  path with query, for example /v1/payins',
            '  sha256 hex of the exact body bytes (empty body: sha256 of "")',
            'Sign the exact bytes you send.',
            '',
            '2. Create a pay-in',
            'POST '.$base.'/payins',
            '{',
            '  "order_id": "ORD-1",',
            '  "amount": 10000,',
            '  "customer": { "id": "user-1", "name": "Ravi Kumar", "mobile": "9876543210", "email": "ravi@example.com" },',
            '  "return_url": "'.($partner->return_url ?: 'https://your-site/deposit/done').'",',
            '  "metadata": { "cart": "42" }',
            '}',
            'order_id is yours, unique per pay-in. customer.id is required. return_url is optional and must be on your website or return URL domain.',
            'Response 201 data includes id, order_id, status "created", amount, payment_url and expires_at. Send the customer to payment_url.',
            'Sending the same order_id again with the same amount, customer id and return_url returns the original pay-in. Different details return 409 duplicate_order_id.',
            '',
            '3. Pay-in status',
            'GET '.$base.'/payins/{id}',
            'GET '.$base.'/payins?order_id=ORD-1',
            'POST '.$base.'/payins/status with {"ids": ["…"], "order_ids": ["…"]} (up to 100). Unknown ones are listed in not_found.',
            'POST '.$base.'/payins/{id}/cancel before the customer pays.',
            'Credit the customer only when status is success. Statuses: created, awaiting_payment, payment_submitted, under_review, success, rejected, expired, cancelled, chargeback, refunded.',
            'A late payment can become success after expired or rejected, with "late": true. Credit it once.',
            '',
            '4. Create a payout',
            'POST '.$base.'/payouts',
            '{',
            '  "order_id": "WD-1",',
            '  "amount": 10000,',
            '  "customer": { "id": "user-1" },',
            '  "beneficiary": { "type": "bank", "name": "Ravi Kumar", "account_number": "50100482716640", "ifsc": "HDFC0001203", "bank_name": "HDFC Bank" }',
            '}',
            'For UPI use "type": "upi" and "upi_id". The withdrawal fee is charged on top of the amount and comes out of your balance.',
            'Statuses: assigned, processing, success (response includes the transfer UTR), failed (the held amount is back in your balance), cancelled, returned.',
            'GET '.$base.'/payouts/{id} or GET '.$base.'/payouts?order_id=WD-1 or POST '.$base.'/payouts/status.',
            'POST '.$base.'/payouts/{id}/cancel while it is still assigned.',
            'GET '.$base.'/balance returns balance, reserved, available and max_payout.',
            '',
            '5. Webhooks we send you',
            'Pay-in events go to the pay-in webhook URL: payin.submitted, payin.success, payin.rejected, payin.expired, payin.chargeback, payin.refunded.',
            'Payout events go to the payout webhook URL: payout.success, payout.failed, payout.returned.',
            'POST with headers X-PayGate-Event-Id, X-PayGate-Event and X-PayGate-Signature: t=<unix>,v1=<hex>.',
            'Body: { "id": "…", "type": "payin.success", "created_at": "…", "data": { the same object the API returns } }',
            'Check the signature before trusting it: HMAC-SHA256 of "<t>.<raw body>" with your secret must equal v1, and t must be within 5 minutes.',
            'Reply with any 2xx within 10 seconds. Otherwise we retry after 1 min, 5 min, 15 min, 1 h, 6 h and 24 h.',
            'After a secret is rotated, webhooks are signed with the new secret.',
            '',
            '6. Errors',
            '{ "error": { "code": "validation_failed", "message": "…", "request_id": "…", "fields": {} } }',
            'Use the code, not the message. Every response has an X-Request-Id header.',
            '',
        ]);
    }
}
