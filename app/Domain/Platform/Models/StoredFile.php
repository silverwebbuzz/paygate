<?php

namespace App\Domain\Platform\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A private file (payment proof, statement, logo…). Stored on a non-public
 * disk; people see it only through a controller that checks access.
 *
 * @property string $id
 * @property string $disk
 * @property string $path
 * @property string|null $original_name
 * @property string $mime
 * @property int $size_bytes
 * @property string $sha256
 * @property string|null $attachable_type
 * @property string|null $attachable_id
 * @property string $purpose
 * @property string $uploaded_by_type customer / user / system
 * @property string|null $uploaded_by_id
 * @property Carbon $created_at
 */
class StoredFile extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'files';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Stores an upload privately under <purpose>/<yyyy>/<mm>/ with a random name.
     */
    public static function store(UploadedFile $upload, string $purpose, Model $attachable, string $uploadedByType, ?string $uploadedById = null): self
    {
        $disk = 'local';
        $path = $upload->store($purpose.'/'.now()->format('Y/m'), $disk);

        if ($path === false) {
            throw new RuntimeException('Could not store the uploaded file.');
        }

        return self::create([
            'disk' => $disk,
            'path' => $path,
            'original_name' => mb_substr($upload->getClientOriginalName(), 0, 255),
            'mime' => (string) $upload->getMimeType(),
            'size_bytes' => (int) $upload->getSize(),
            'sha256' => (string) hash_file('sha256', (string) $upload->getRealPath()),
            'attachable_type' => $attachable->getMorphClass(),
            'attachable_id' => $attachable->getKey(),
            'purpose' => $purpose,
            'uploaded_by_type' => $uploadedByType,
            'uploaded_by_id' => $uploadedById,
        ]);
    }

    /**
     * Keeps a file already on the private disk (e.g. an upload staged for a
     * second step), copied under <purpose>/<yyyy>/<mm>/.
     */
    public static function adopt(string $sourcePath, string $originalName, string $mime, string $purpose, Model $attachable, string $uploadedByType, ?string $uploadedById = null): self
    {
        $disk = 'local';
        $path = $purpose.'/'.now()->format('Y/m').'/'.Str::random(40).'.'.pathinfo($sourcePath, PATHINFO_EXTENSION);

        if (! Storage::disk($disk)->copy($sourcePath, $path)) {
            throw new RuntimeException('Could not store the file.');
        }

        return self::create([
            'disk' => $disk,
            'path' => $path,
            'original_name' => mb_substr($originalName, 0, 255),
            'mime' => $mime,
            'size_bytes' => (int) Storage::disk($disk)->size($path),
            'sha256' => (string) hash_file('sha256', Storage::disk($disk)->path($path)),
            'attachable_type' => $attachable->getMorphClass(),
            'attachable_id' => $attachable->getKey(),
            'purpose' => $purpose,
            'uploaded_by_type' => $uploadedByType,
            'uploaded_by_id' => $uploadedById,
        ]);
    }

    public function contents(): ?string
    {
        return Storage::disk($this->disk)->get($this->path);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }
}
