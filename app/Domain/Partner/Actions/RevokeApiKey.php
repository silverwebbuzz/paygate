<?php

namespace App\Domain\Partner\Actions;

use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Partner\Models\PartnerApiKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stops a key immediately, e.g. when its secret may have leaked. The partner
 * can't call the API until a new key is generated.
 */
class RevokeApiKey
{
    public function handle(User $actor, PartnerApiKey $key, string $reason): PartnerApiKey
    {
        if ($key->status === 'revoked') {
            throw ValidationException::withMessages(['key' => __('This key is already revoked.')]);
        }

        DB::transaction(function () use ($actor, $key, $reason) {
            $old = $key->status;
            $key->update(['status' => 'revoked', 'revoked_at' => now()]);

            AuditLog::record('api_key.revoked', $key->partner, ['key_id' => $key->key_id, 'status' => $old], [
                'status' => 'revoked',
                'reason' => $reason,
            ], $actor);
        });

        return $key;
    }
}
