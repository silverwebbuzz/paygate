<?php

namespace App\Domain\Reporting\Models;

use App\Domain\Core\Identity\Models\User;
use App\Domain\Platform\Models\StoredFile;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One export of a report, prepared in the background. Only the person who
 * asked can download it; the file is deleted after 7 days (G-49).
 *
 * @property string $id
 * @property string $user_id
 * @property string $report
 * @property string $format csv / xlsx
 * @property array{from: string, to: string, range: string, filters: array<string, string|null>} $parameters
 * @property string $status queued / running / ready / failed / expired
 * @property int|null $rows
 * @property string|null $file_id
 * @property string|null $error
 * @property CarbonInterface $created_at
 * @property CarbonInterface|null $completed_at
 * @property CarbonInterface|null $expires_at
 * @property-read User $user
 * @property-read StoredFile|null $file
 */
class ReportExport extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    public const KEEP_DAYS = 7;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'rows' => 'integer',
            'created_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<StoredFile, $this>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class);
    }
}
