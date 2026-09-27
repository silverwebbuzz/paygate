<?php

namespace App\Domain\Customer\Models;

use App\Domain\Partner\Models\Partner;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A partner's customer, keyed by the partner's own customer id. Only the
 * minimum details are kept (Req G-43, decided 2026-09-27); how long they
 * are kept is still to be set with legal.
 *
 * @property string $id
 * @property string $partner_id
 * @property string $external_id
 * @property string|null $username
 * @property string|null $name
 * @property string|null $email
 * @property string|null $mobile
 * @property bool $is_blocked
 * @property CarbonInterface $first_seen_at
 * @property CarbonInterface $last_seen_at
 */
class PartnerCustomer extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_blocked' => 'boolean',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * Records (or refreshes) the customer; details only overwrite when given.
     *
     * @param  array{id: string, name?: string|null, email?: string|null, mobile?: string|null, username?: string|null}  $details
     */
    public static function remember(Partner $partner, array $details): self
    {
        $customer = self::query()->firstOrNew(['partner_id' => $partner->id, 'external_id' => $details['id']]);

        foreach (['name', 'email', 'mobile', 'username'] as $field) {
            if (isset($details[$field]) && $details[$field] !== '') {
                $customer->{$field} = $details[$field];
            }
        }

        $customer->last_seen_at = now();
        $customer->save();

        return $customer;
    }

    /**
     * @return BelongsTo<Partner, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }
}
