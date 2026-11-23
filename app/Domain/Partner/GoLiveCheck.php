<?php

namespace App\Domain\Partner;

use App\Domain\Partner\Models\Partner;
use App\Support\ApiEnvironment;
use App\Support\Hosts;

/**
 * Go-live check (client request 2026-11-23): the URLs a partner has set,
 * each marked OK, missing or suspicious. On the live server a URL that looks
 * like a test site (localhost, a private or .local / .test host, a host
 * named staging / sandbox / test / dev / demo / uat / qa, one of our other
 * servers (PAYGATE_OTHER_HOSTS) or plain http) is flagged: a partner moving
 * to live often forgets one of them. On sandbox / testbox test URLs are
 * expected, so only missing ones are flagged.
 */
class GoLiveCheck
{
    /** Host words that mark a test site. */
    private const TEST_WORDS = ['staging', 'stage', 'stg', 'sandbox', 'testbox', 'test', 'testing', 'dev', 'demo', 'uat', 'qa', 'preprod', 'beta'];

    /**
     * @return array{environment: string, base_url: string, ready: bool, items: list<array{field: string, label: string, url: string|null, state: string, reason: string|null}>}
     */
    public function check(Partner $partner): array
    {
        $fields = [
            'return_url' => ['Return page (after payment)', true],
            'callback_url' => ['Pay-in callback', true],
            'payin_webhook_url' => ['Pay-in webhook', true],
            'payout_webhook_url' => ['Payout webhook', (bool) $partner->is_payout_enabled],
            'website_url' => ['Website', false],
        ];

        $items = [];

        foreach ($fields as $field => [$label, $required]) {
            $url = $partner->{$field};
            $url = is_string($url) && trim($url) !== '' ? trim($url) : null;
            $reason = $url === null ? null : self::problem($url);

            $items[] = [
                'field' => $field,
                'label' => $label,
                'url' => $url,
                'state' => match (true) {
                    $url === null => $required ? 'missing' : 'unused',
                    $reason !== null => 'warning',
                    default => 'ok',
                },
                'reason' => $url === null ? ($required ? 'Not set.' : null) : $reason,
            ];
        }

        return [
            'environment' => ApiEnvironment::current(),
            'base_url' => Hosts::url('api', '/v1'),
            'ready' => collect($items)->every(fn (array $item) => in_array($item['state'], ['ok', 'unused'], true)),
            'items' => $items,
        ];
    }

    /**
     * Why a partner URL looks like a test site, on the live server; null
     * when it looks fine (always null on sandbox / testbox).
     */
    public static function problem(string $url): ?string
    {
        if (! ApiEnvironment::isLive()) {
            return null;
        }

        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if ($host === '') {
            return 'Not a full address.';
        }

        foreach (ApiEnvironment::otherHosts() as $other) {
            if ($host === $other || str_ends_with($host, '.'.$other)) {
                return "On {$other}, one of our test servers' addresses.";
            }
        }

        if ($host === 'localhost' || preg_match('/\.(local|localhost|test|example|invalid|internal)$/', $host) === 1) {
            return 'A local / test address, not reachable on the internet.';
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
                ? 'A private network address.'
                : 'An IP address instead of the site\'s name: check it is the live site.';
        }

        $words = preg_split('/[.\-]/', $host) ?: [];

        foreach ($words as $word) {
            if (in_array($word, self::TEST_WORDS, true)) {
                return "The address contains \"{$word}\": looks like a test site.";
            }
        }

        if ($scheme !== 'https') {
            return 'Not https.';
        }

        return null;
    }
}
