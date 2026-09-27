<?php

namespace App\Domain\Partner\Actions;

use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Partner\Models\Partner;
use App\Domain\Partner\Models\PartnerIpRule;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Replaces the partner's list of allowed server addresses. Accepts single
 * addresses ("52.66.45.184") and ranges ("52.66.45.0/24"), IPv4 or IPv6.
 * Enforcement on API calls comes with the Partner API (Phase 6).
 */
class SyncIpRules
{
    /**
     * @param  list<string>  $addresses
     */
    public function handle(User $actor, Partner $partner, array $addresses): void
    {
        $wanted = array_values(array_unique(array_map([self::class, 'toCidr'], $addresses)));

        DB::transaction(function () use ($actor, $partner, $wanted) {
            $existing = PartnerIpRule::query()->where('partner_id', $partner->id)->lockForUpdate()->get();
            $current = $existing->map(fn (PartnerIpRule $rule) => $rule->cidr)->all();

            $removed = array_values(array_diff($current, $wanted));
            $added = array_values(array_diff($wanted, $current));

            if ($removed === [] && $added === []) {
                return;
            }

            $existing->whereIn('cidr', $removed)->each(fn (PartnerIpRule $rule) => $rule->delete());

            foreach ($added as $cidr) {
                PartnerIpRule::create(['partner_id' => $partner->id, 'cidr' => $cidr, 'created_by' => $actor->id]);
            }

            AuditLog::record('partner.ip_rules_updated', $partner, ['ips' => $current], ['ips' => $wanted, 'added' => $added, 'removed' => $removed], $actor);
        });
    }

    /**
     * "1.2.3.4" → "1.2.3.4/32"; "1.2.3.0/24" stays; host bits are rejected
     * ("1.2.3.4/24" is ambiguous).
     */
    public static function toCidr(string $address): string
    {
        $address = trim($address);
        [$ip, $prefix] = array_pad(explode('/', $address, 2), 2, null);

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException("Not an IP address: {$address}");
        }

        $bits = str_contains((string) $ip, ':') ? 128 : 32;
        $prefix ??= (string) $bits;

        if (! ctype_digit($prefix) || (int) $prefix > $bits || (int) $prefix < ($bits === 32 ? 8 : 32)) {
            throw new InvalidArgumentException("Invalid range: {$address}");
        }

        $packed = (string) inet_pton((string) $ip);
        $network = '';

        for ($i = 0, $remaining = (int) $prefix; $i < strlen($packed); $i++, $remaining -= 8) {
            $mask = $remaining >= 8 ? 0xFF : ($remaining <= 0 ? 0 : (0xFF << (8 - $remaining)) & 0xFF);
            $network .= chr(ord($packed[$i]) & $mask);
        }

        if ($network !== $packed) {
            throw new InvalidArgumentException("Range has host bits set: {$address}");
        }

        return inet_ntop($packed)."/{$prefix}";
    }
}
