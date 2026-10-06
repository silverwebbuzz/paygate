<?php

namespace App\Http\Checkout;

use App\Domain\PaymentAccount\Models\PaymentAccount;
use App\Domain\Transaction\Models\Transaction;
use BaconQrCode\Renderer\Color\Rgb;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\Fill;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * UPI payment link (NPCI "upi://pay") for the checkout QR. The amount and
 * our reference are written into the code when the customer reaches checkout,
 * so a scan opens that exact price.
 */
final class UpiLinks
{
    /**
     * @return array<string, string>
     */
    private static function params(PaymentAccount $account, Transaction $payin): array
    {
        return array_filter([
            'pa' => (string) $account->upi_id_encrypted,
            'pn' => $account->upi_display_name ?? $account->account_holder_name,
            'am' => number_format($payin->amount / 100, 2, '.', ''),
            'cu' => 'INR',
            'tn' => $payin->reference,
            'tr' => $payin->reference,
        ], fn ($value) => $value !== '');
    }

    public static function link(PaymentAccount $account, Transaction $payin): string
    {
        return 'upi://pay?'.http_build_query(self::params($account, $payin), '', '&', PHP_QUERY_RFC3986);
    }

    public static function qrSvg(PaymentAccount $account, Transaction $payin): string
    {
        $svg = (new Writer(new ImageRenderer(
            new RendererStyle(220, 1, null, null, Fill::uniformColor(new Rgb(255, 255, 255), new Rgb(15, 23, 42))),
            new SvgImageBackEnd,
        )))->writeString(self::link($account, $payin));

        return trim(substr($svg, (int) strpos($svg, "\n") + 1));
    }
}
