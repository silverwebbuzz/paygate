<?php

namespace App\Domain\SecurityReport\Models;

use App\Domain\Core\Identity\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The super admin's status and note for one manual test, finding or client
 * decision of the security report (keys from Catalogue).
 *
 * @property string $entry_key
 * @property string|null $status
 * @property string|null $note
 * @property string|null $updated_by
 * @property CarbonInterface|null $updated_at
 * @property-read User|null $editor
 */
class SecurityReportEntry extends Model
{
    public const CREATED_AT = null;

    protected $primaryKey = 'entry_key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['updated_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
