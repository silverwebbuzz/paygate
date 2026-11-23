<?php

namespace App\Support;

/**
 * Which PayGate server this is, for partners who use more than one (client
 * request 2026-11-23). Three servers, each with its own database:
 *
 *   testbox — our own internal testing server
 *   sandbox — staging, for partners to integrate (a27pay.tech)
 *   live    — real money (a27pay.com)
 *
 * Set by PAYGATE_ENVIRONMENT; when unset, a production install is live and
 * any other is testbox.
 *
 * Every Partner API answer and webhook carries it (field `environment`,
 * header X-PayGate-Environment). API keys carry their server in the prefix:
 * pk_live_ / sk_live_ (live), pk_sb_ / sk_sb_ (sandbox), pk_test_ / sk_test_
 * (testbox). A key of another server is refused before it is looked up
 * (ApiAuthenticator), so a URL left pointing at the wrong server fails at
 * once with a clear message instead of "unknown key".
 */
final class ApiEnvironment
{
    public const LIVE = 'live';

    public const SANDBOX = 'sandbox';

    public const TESTBOX = 'testbox';

    public const ALL = [self::LIVE, self::SANDBOX, self::TESTBOX];

    public const HEADER = 'X-PayGate-Environment';

    public static function current(): string
    {
        $configured = strtolower(trim((string) config('paygate.environment')));

        if (in_array($configured, self::ALL, true)) {
            return $configured;
        }

        return app()->isProduction() ? self::LIVE : self::TESTBOX;
    }

    public static function isLive(): bool
    {
        return self::current() === self::LIVE;
    }

    /** Key prefix word of each server: pk_<word>_ / sk_<word>_. */
    public const KEY_WORDS = [self::LIVE => 'live', self::SANDBOX => 'sb', self::TESTBOX => 'test'];

    /** The prefix word of the keys this server issues and accepts. */
    public static function keyPrefix(): string
    {
        return self::KEY_WORDS[self::current()];
    }

    /**
     * The server a key was issued on, from its prefix; null for a key
     * without a known prefix (never refused for it).
     */
    public static function ofKey(string $keyId): ?string
    {
        foreach (self::KEY_WORDS as $environment => $word) {
            if (str_starts_with($keyId, "pk_{$word}_")) {
                return $environment;
            }
        }

        return null;
    }

    /** A key this server would refuse as another server's. */
    public static function refuses(string $keyId): bool
    {
        $environment = self::ofKey($keyId);

        return $environment !== null && $environment !== self::current();
    }

    /** Human label, e.g. "sandbox (staging)". */
    public static function label(?string $environment = null): string
    {
        return match ($environment ?? self::current()) {
            self::LIVE => 'live',
            self::SANDBOX => 'sandbox (staging)',
            default => 'testbox (internal testing)',
        };
    }

    /**
     * A server's API base URL, if set (PAYGATE_<SERVER>_API_URL), named in
     * the wrong_environment error.
     */
    public static function apiUrl(string $environment): ?string
    {
        $url = trim((string) config("paygate.api.urls.{$environment}"));

        return $url === '' ? null : rtrim($url, '/');
    }

    /**
     * Hosts of our other servers (PAYGATE_OTHER_HOSTS, e.g. on live
     * "a27pay.tech"): a partner URL on one of them is a test site.
     *
     * @return list<string>
     */
    public static function otherHosts(): array
    {
        return array_values(array_filter(array_map(
            fn (string $host) => strtolower(trim($host)),
            explode(',', (string) config('paygate.other_hosts')),
        )));
    }
}
