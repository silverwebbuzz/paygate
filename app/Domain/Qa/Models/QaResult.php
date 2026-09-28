<?php

namespace App\Domain\Qa\Models;

use App\Domain\Core\Identity\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The results of one QA checklist item: what the tester marked by hand and
 * what the latest automated run found.
 *
 * @property string $check_key
 * @property string|null $manual_status pass / fail
 * @property string|null $manual_note
 * @property string|null $tested_by
 * @property CarbonInterface|null $tested_at
 * @property string|null $auto_status queued / running / passed / failed / missing / error
 * @property string|null $auto_summary
 * @property list<array{test: string, status: string, message: string|null}>|null $auto_tests
 * @property string|null $auto_output
 * @property string|null $auto_requested_by
 * @property CarbonInterface|null $auto_requested_at
 * @property CarbonInterface|null $auto_finished_at
 * @property-read User|null $tester
 */
class QaResult extends Model
{
    public const CREATED_AT = null;

    /** A run that hasn't finished after this long is treated as stuck. */
    public const STUCK_AFTER_MINUTES = 20;

    protected $primaryKey = 'check_key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'auto_tests' => 'array',
            'tested_at' => 'datetime',
            'auto_requested_at' => 'datetime',
            'auto_finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function tester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tested_by');
    }

    public function isRunning(): bool
    {
        return in_array($this->auto_status, ['queued', 'running'], true)
            && $this->auto_requested_at?->gt(now()->subMinutes(self::STUCK_AFTER_MINUTES)) === true;
    }
}
