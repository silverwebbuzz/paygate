<?php

namespace App\Domain\SecurityReport\Models;

use App\Domain\Core\Identity\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One automated scan the server ran against its own public addresses
 * (SecurityScanner), for Admin › Testing › Security Report.
 *
 * @property string $id
 * @property string $status queued / running / done / error
 * @property array{portal: string, api: string, pay: string} $target
 * @property int $passed
 * @property int $failed
 * @property int $warnings
 * @property list<array{status: string, area: string, check: string, detail: string}>|null $results
 * @property list<array<string, mixed>>|null $performance
 * @property string|null $error
 * @property string $requested_by
 * @property CarbonInterface $requested_at
 * @property CarbonInterface|null $finished_at
 * @property-read User $requester
 */
class SecurityScan extends Model
{
    use HasUuids;

    public $timestamps = false;

    /** A scan that hasn't finished after this long is shown as stuck and may be started again. */
    public const STUCK_AFTER_MINUTES = 15;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'target' => 'array',
            'results' => 'array',
            'performance' => 'array',
            'requested_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['queued', 'running'], true)
            && $this->requested_at->gt(now()->subMinutes(self::STUCK_AFTER_MINUTES));
    }

    public function shownStatus(): string
    {
        return in_array($this->status, ['queued', 'running'], true) && ! $this->isActive() ? 'stuck' : $this->status;
    }
}
