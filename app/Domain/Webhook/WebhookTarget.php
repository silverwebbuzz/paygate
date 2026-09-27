<?php

namespace App\Domain\Webhook;

use RuntimeException;

/**
 * Refuses webhook URLs that point into private networks (SSRF): every
 * address the host resolves to must be public. Allowed locally
 * (paygate.webhooks.allow_private_targets) so developers can test against
 * their own machine.
 */
final class WebhookTarget
{
    public static function ensureAllowed(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');

        if (! in_array($scheme, ['https', 'http'], true) || $host === '') {
            throw new RuntimeException('The webhook URL is not a valid http(s) URL.');
        }

        if (config('paygate.webhooks.allow_private_targets')) {
            return;
        }

        if ($scheme !== 'https') {
            throw new RuntimeException('Webhook URLs must use https.');
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : (gethostbynamel($host) ?: []);

        if ($addresses === []) {
            throw new RuntimeException("Could not resolve {$host}.");
        }

        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new RuntimeException("{$host} resolves to a private or reserved address ({$address}); refused.");
            }
        }
    }
}
