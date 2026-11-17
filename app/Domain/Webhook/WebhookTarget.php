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
    /**
     * Checks the URL and returns curl options that pin the connection to the
     * public address just checked, so a DNS answer that changes between the
     * check and the request (DNS rebinding) can't reach a private address.
     * Empty when private targets are allowed (local) or the host is an IP.
     *
     * @return array<int, mixed>
     */
    public static function pinnedCurlOptions(string $url): array
    {
        $addresses = self::ensureAllowed($url);
        $parts = parse_url($url);
        $host = (string) ($parts['host'] ?? '');

        if ($addresses === [] || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [];
        }

        $port = (int) ($parts['port'] ?? (strtolower((string) ($parts['scheme'] ?? '')) === 'http' ? 80 : 443));

        return [CURLOPT_RESOLVE => ["{$host}:{$port}:{$addresses[0]}"]];
    }

    /**
     * @return list<string> the checked public addresses (empty when private targets are allowed)
     */
    public static function ensureAllowed(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');

        if (! in_array($scheme, ['https', 'http'], true) || $host === '') {
            throw new RuntimeException('The webhook URL is not a valid http(s) URL.');
        }

        if (config('paygate.webhooks.allow_private_targets')) {
            return [];
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

        return $addresses;
    }
}
