<?php

namespace App\Domain\PartnerApi\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One call to the Partner API: who, what, result, how long. Bodies are not
 * stored (they hold customer details).
 *
 * @property string $id
 * @property string|null $partner_id
 * @property string|null $api_key_id
 * @property string $method
 * @property string $path
 * @property int $status_code
 * @property int $duration_ms
 * @property string|null $ip
 * @property string|null $request_id
 * @property string|null $partner_transaction_id
 * @property Carbon $created_at
 */
class ApiRequestLog extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status_code' => 'integer',
            'duration_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
