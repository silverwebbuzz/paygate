<?php

namespace App\Domain\Platform\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * One global setting (Admin › Global Settings). Read and write through
 * App\Domain\Platform\Settings, never directly.
 *
 * @property string $key
 * @property mixed $value
 * @property string|null $updated_by
 * @property CarbonInterface|null $updated_at
 */
class Setting extends Model
{
    public const CREATED_AT = null;

    public $incrementing = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['value' => 'json', 'updated_at' => 'datetime'];
    }
}
