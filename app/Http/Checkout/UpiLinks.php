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
 * UPI payment links (NPCI "upi://pay" format) for the checkout page: the
 * amount and our reference are pre-filled so the customer can't mistype
 * them. The same link drives the QR code and the "open in app" buttons.
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

    /**
     * Links that open a specific app (Android). "Any UPI app" uses the plain link.
     *
     * @return list<array{app: string, label: string, url: string}>
     */
    public static function apps(PaymentAccount $account, Transaction $payin): array
    {
        $query = http_build_query(self::params($account, $payin), '', '&', PHP_QUERY_RFC3986);

        return [
            ['app' => 'gpay', 'label' => 'GPay', 'url' => 'tez://upi/pay?'.$query],
            ['app' => 'phonepe', 'label' => 'PhonePe', 'url' => 'phonepe://pay?'.$query],
            ['app' => 'paytm', 'label' => 'Paytm', 'url' => 'paytmmp://pay?'.$query],
            ['app' => 'any', 'label' => 'Any UPI app', 'url' => 'upi://pay?'.$query],
        ];
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
