<?php

namespace App\Domain\Partner;

use App\Domain\Partner\Actions\IssueApiKey;
use App\Domain\Partner\Models\Partner;
use App\Domain\PartnerApi\ApiAuthenticator;
use App\Support\Hosts;
use App\Support\Money;
use Dompdf\Dompdf;
use Dompdf\Options;
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

    public static function ready(Partner $partner): bool
    {
        $partner->loadMissing('activeApiKey');

        return $partner->activeApiKey !== null
            && filled($partner->return_url)
            && filled($partner->callback_url)
            && filled($partner->payin_webhook_url)
            && (! $partner->is_payout_enabled || filled($partner->payout_webhook_url));
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

        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsHtml5ParserEnabled(true);
        $options->setDefaultFont('DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->html($partner, $secret), 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();
        $dompdf->addInfo('Title', 'PayGate integration '.$code);
        $dompdf->addInfo('Author', 'PayGate');

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$code.'-paygate.pdf"',
        ]);
    }

    public function html(Partner $partner, ?string $secret): string
    {
        $partner->loadMissing('activeApiKey');
        $key = $partner->activeApiKey;
        $ips = $partner->ipRules()->orderBy('created_at')->get()->map(fn ($rule) => $rule->display())->implode(', ');

        return view('partners.integration-file', [
            'partnerName' => $partner->name,
            'partnerCode' => $partner->code,
            'generatedAt' => now()->timezone((string) config('app.business_timezone'))->format('j M Y, H:i'),
            'base' => Hosts::url('api', '/v1'),
            'keyId' => $key?->key_id,
            'secret' => $secret,
            'last4' => $key?->secret_last4,
            'ips' => $ips === '' ? null : $ips,
            'rateLimit' => (int) config('paygate.api.rate_limit'),
            'overlapHours' => IssueApiKey::OVERLAP_HOURS,
            'clockMinutes' => (int) (ApiAuthenticator::MAX_CLOCK_SKEW / 60),
            'returnUrl' => $this->shown($partner->return_url),
            'callbackUrl' => $this->shown($partner->callback_url),
            'payinWebhookUrl' => $this->shown($partner->payin_webhook_url),
            'payoutWebhookUrl' => $this->shown($partner->payout_webhook_url),
            'sampleReturn' => $partner->return_url ?: 'https://your-site/deposit/done',
            'depositMin' => $this->money($partner->deposit_min_amount),
            'depositMax' => $this->money($partner->deposit_max_amount),
            'sessionMinutes' => $partner->session_ttl_minutes,
        ])->render();
    }

    private function shown(?string $value): string
    {
        return ($value === null || $value === '') ? 'not set' : $value;
    }

    private function money(?int $paise): string
    {
        return $paise === null || $paise < 0 ? 'unlimited' : 'Rs '.Money::toRupees($paise);
    }
}
