<?php

namespace App\Domain\Partner\Models;

use App\Domain\Core\Identity\Models\User;
use Database\Factories\PartnerFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An external merchant website that integrates with the Gateway.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string $email
 * @property string $website_url
 * @property string $status
 */
#[UseFactory(PartnerFactory::class)]
class Partner extends Model
{
    /** @use HasFactory<PartnerFactory> */
    use HasFactory, HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_payin_enabled' => 'boolean',
            'is_payout_enabled' => 'boolean',
            'is_h2h_enabled' => 'boolean',
            'allow_upi' => 'boolean',
            'allow_qr' => 'boolean',
            'allow_bank_transfer' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
