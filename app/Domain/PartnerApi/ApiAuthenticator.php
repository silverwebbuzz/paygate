<?php

namespace App\Domain\PartnerApi;

use App\Domain\Core\Organisation\Enums\OrganisationStatus;
use App\Domain\Partner\Models\Partner;
use App\Domain\Partner\Models\PartnerApiKey;
use App\Domain\Partner\Models\PartnerIpRule;
use App\Domain\PartnerApi\Exceptions\ApiException;
use Illuminate\Support\Facades\Cache;

/**
 * Checks a signed Partner API request (Architecture §10):
 *
 *   X-Key-Id:    the partner's key id
 *   X-Timestamp: unix seconds, within ±300 s of our clock
 *   X-Nonce:     random, used once (kept 10 minutes)
 *   X-Signature: hex HMAC-SHA256(secret,
 *                  timestamp \n nonce \n METHOD \n path?query \n sha256hex(body))
 *
 * Then the caller's IP must be on the partner's allowed list (decided
 * 2026-09-27: an empty list blocks the API; not enforced locally) and the
 * partner must be active.
 */
class ApiAuthenticator
{
    public const MAX_CLOCK_SKEW = 300;

    public const NONCE_TTL = 600;

    /**
     * @return array{partner: Partner, key: PartnerApiKey}
     */
    public function authenticate(?string $keyId, ?string $timestamp, ?string $nonce, ?string $signature, string $method, string $pathWithQuery, string $body, ?string $ip): array
    {
        if ($keyId === null || $timestamp === null || $nonce === null || $signature === null) {
            throw new ApiException('unauthenticated', 'Send X-Key-Id, X-Timestamp, X-Nonce and X-Signature headers.', 401);
        }

        $key = PartnerApiKey::query()->with('partner')->where('key_id', $keyId)->first();

        if ($key === null || ! $key->isUsable()) {
            throw new ApiException('unauthenticated', 'Unknown or revoked API key.', 401);
        }

        if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::MAX_CLOCK_SKEW) {
            throw new ApiException('timestamp_out_of_range', 'X-Timestamp must be the current unix time (±5 minutes). Check your server clock.', 401);
        }

        if (preg_match('/^[A-Za-z0-9_-]{16,64}$/', $nonce) !== 1) {
            throw new ApiException('unauthenticated', 'X-Nonce must be 16–64 letters, digits, - or _.', 401);
        }

        $expected = hash_hmac('sha256', self::canonical($timestamp, $nonce, $method, $pathWithQuery, $body), $key->secret_encrypted);

        if (! hash_equals($expected, strtolower($signature))) {
            throw new ApiException('invalid_signature', 'The signature does not match. See the API documentation for how to sign requests.', 401);
        }

        // Only after the signature is valid, so strangers can't burn nonces.
        if (! Cache::add("api-nonce:{$key->id}:{$nonce}", 1, self::NONCE_TTL)) {
            throw new ApiException('nonce_reused', 'This X-Nonce was already used. Send a new random nonce with every request.', 401);
        }

        $partner = $key->partner;

        if (config('paygate.api.enforce_ip_allowlist') && ! $this->ipAllowed($partner, $ip)) {
            throw new ApiException('ip_not_allowed', "Requests from {$ip} are not allowed for this partner. Add the address under API & Webhooks › Allowed IPs.", 403);
        }

        if ($partner->status !== OrganisationStatus::Active) {
            throw new ApiException('partner_inactive', 'This partner account is not active.', 403);
        }

        $this->touch($key);

        return ['partner' => $partner, 'key' => $key];
    }

    public static function canonical(string $timestamp, string $nonce, string $method, string $pathWithQuery, string $body): string
    {
        return implode("\n", [$timestamp, $nonce, strtoupper($method), $pathWithQuery, hash('sha256', $body)]);
    }

    private function ipAllowed(Partner $partner, ?string $ip): bool
    {
        if ($ip === null || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return PartnerIpRule::query()
            ->where('partner_id', $partner->id)
            ->where('is_active', true)
            ->whereRaw('?::inet <<= cidr', [$ip])
            ->exists();
    }

    /**
     * Records when the key was last used, at most once a minute.
     */
    private function touch(PartnerApiKey $key): void
    {
        if ($key->last_used_at === null || $key->last_used_at->lt(now()->subMinute())) {
            $key->forceFill(['last_used_at' => now()])->saveQuietly();
        }
    }
}
