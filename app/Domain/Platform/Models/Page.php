<?php

namespace App\Domain\Platform\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A content page Admin writes (G-48: terms, privacy, payment help…), in
 * Markdown. Published pages are linked from the customer payment page and
 * the login screen and shown at /legal/{slug}.
 *
 * @property string $id
 * @property string $slug
 * @property string $title
 * @property string $body Markdown
 * @property string $status draft / published
 * @property string|null $updated_by
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class Page extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    /**
     * The body as safe HTML (raw HTML in the Markdown is stripped).
     */
    public function html(): string
    {
        return Str::markdown($this->body, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
    }
}
