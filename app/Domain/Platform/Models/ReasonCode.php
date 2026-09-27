<?php

namespace App\Domain\Platform\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Standard reasons offered when declining (context payin_reject /
 * payout_reject), seeded by the platform migration.
 *
 * @property string $id
 * @property string $context
 * @property string $code
 * @property string $label
 * @property bool $is_active
 * @property int $sort
 */
class ReasonCode extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * @return array<string, string> code => label
     */
    public static function options(string $context): array
    {
        return self::query()->where(['context' => $context, 'is_active' => true])->orderBy('sort')->pluck('label', 'code')->all();
    }
}
