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
 * header X-PayGate-Environment). API keys carry their kind in the prefix:
 * pk_live_ / sk_live_ on live, pk_test_ / sk_test_ on sandbox and testbox.
 * A live key on a test server, or a test key on live, is refused before it
 * is looked up (ApiAuthenticator), so a URL left pointing at the wrong
 * server fails at once with a clear message instead of "unknown key".
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

    /** The kind of key this server issues and accepts: "live" or "test". */
    public static function keyKind(): string
    {
        return self::isLive() ? 'live' : 'test';
    }

    /** Kept for IssueApiKey: the word in new keys' prefix (pk_live_ / pk_test_). */
    public static function keyPrefix(): string
    {
        return self::keyKind();
    }

    /**
     * The kind of a key from its prefix ("live" / "test"); null for a key
     * without one (never refused for it).
     */
    public static function kindOfKey(string $keyId): ?string
    {
        return match (true) {
            str_starts_with($keyId, 'pk_live_') => 'live',
            str_starts_with($keyId, 'pk_test_') => 'test',
            default => null,
        };
    }

    /** A key this server would refuse as the other kind's. */
    public static function refuses(string $keyId): bool
    {
        $kind = self::kindOfKey($keyId);

        return $kind !== null && $kind !== self::keyKind();
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

    /** The other servers' API base URL to name in errors, if set. */
    public static function otherApiUrl(): ?string
    {
        $url = trim((string) config('paygate.api.other_environment_url'));

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
