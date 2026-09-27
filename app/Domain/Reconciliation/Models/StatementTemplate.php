<?php

namespace App\Domain\Reconciliation\Models;

use App\Domain\Reconciliation\Import\ColumnMapping;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A bank's statement layout: which column holds the date, UTR, credit…
 * Saved on the first import of a layout and found again by the file's
 * header row, so the next upload of that bank's file is one click.
 *
 * @property string $id
 * @property string $name bank / layout name
 * @property string $header_signature
 * @property array<string, mixed> $mapping see ColumnMapping
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class StatementTemplate extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['mapping' => 'array'];
    }

    public function columnMapping(): ColumnMapping
    {
        return ColumnMapping::fromArray($this->mapping);
    }
}
