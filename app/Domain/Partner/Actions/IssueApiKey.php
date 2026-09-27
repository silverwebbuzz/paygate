<?php

namespace App\Domain\Partner\Actions;

use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Partner\Models\Partner;
use App\Domain\Partner\Models\PartnerApiKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Generates a partner's API key and secret. When the partner already has a
 * key, this is a rotation: the old key keeps working for OVERLAP_HOURS so the
 * partner can switch without downtime, then stops.
 *
 * The plain secret is returned once, to show to the person who generated it,
 * and is never shown again (only its last 4 characters).
 */
class IssueApiKey
{
    public const OVERLAP_HOURS = 24;

    /**
     * @return array{key: PartnerApiKey, secret: string}
     */
    public function handle(User $actor, Partner $partner): array
    {
        return DB::transaction(function () use ($actor, $partner) {
            $keys = PartnerApiKey::query()
                ->where('partner_id', $partner->id)
                ->whereIn('status', ['active', 'rotating'])
                ->lockForUpdate()
                ->get();

            // Only one old key overlaps at a time.
            foreach ($keys->where('status', 'rotating') as $old) {
                $old->update(['status' => 'revoked', 'revoked_at' => now()]);
            }

            $previous = $keys->firstWhere('status', 'active');
            $previous?->update(['status' => 'rotating', 'expires_at' => now()->addHours(self::OVERLAP_HOURS)]);

            $environment = app()->isProduction() ? 'live' : 'test';
            $secret = "sk_{$environment}_".Str::random(48);

            $key = PartnerApiKey::create([
                'partner_id' => $partner->id,
                'key_id' => "pk_{$environment}_".Str::lower(Str::random(24)),
                'secret_encrypted' => $secret,
                'secret_last4' => substr($secret, -4),
                'status' => 'active',
                'created_by' => $actor->id,
            ]);

            AuditLog::record($previous ? 'api_key.rotated' : 'api_key.issued', $partner, $previous ? [
                'key_id' => $previous->key_id,
            ] : [], [
                'key_id' => $key->key_id,
                'secret_last4' => $key->secret_last4,
                'previous_key_valid_until' => $previous?->expires_at?->toIso8601String(),
            ], $actor);

            return ['key' => $key, 'secret' => $secret];
        });
    }
}
