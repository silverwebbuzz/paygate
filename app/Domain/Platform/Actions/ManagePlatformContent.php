<?php

namespace App\Domain\Platform\Actions;

use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Platform\Models\Page;
use App\Domain\Platform\Models\ReasonCode;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Admin-maintained content: content pages and the reason lists offered when
 * declining a deposit or failing a payout (G-48). Every change is audited.
 * A reason's code never changes once created (it is stored on
 * transactions); "other" always stays active.
 */
class ManagePlatformContent
{
    /**
     * @param  array{title: string, body: string, status: string, slug?: string|null}  $data
     */
    public function savePage(User $actor, ?Page $page, array $data): Page
    {
        $page ??= new Page(['slug' => Str::slug((string) ($data['slug'] ?? '') ?: $data['title'])]);

        if (! $page->exists && Page::query()->where('slug', $page->slug)->exists()) {
            throw ValidationException::withMessages(['slug' => __('A page with this address already exists.')]);
        }

        $old = $page->exists ? $page->only(['title', 'status']) : [];
        $page->fill(['title' => $data['title'], 'body' => $data['body'], 'status' => $data['status'], 'updated_by' => $actor->id])->save();

        AuditLog::record($old === [] ? 'page.created' : 'page.updated', $page, $old, $page->only(['slug', 'title', 'status']), $actor);

        return $page;
    }

    /**
     * @param  array{label: string, is_active: bool, sort: int}  $data
     */
    public function saveReason(User $actor, string $context, ?ReasonCode $reason, array $data, ?string $code = null): ReasonCode
    {
        if ($reason === null) {
            $code = Str::snake(trim((string) $code));

            if ($code === '' || ReasonCode::query()->where(['context' => $context, 'code' => $code])->exists()) {
                throw ValidationException::withMessages(['code' => __('Choose a new, unique code (letters and underscores).')]);
            }

            $reason = new ReasonCode(['context' => $context, 'code' => $code]);
        }

        if ($reason->code === 'other' && ! $data['is_active']) {
            throw ValidationException::withMessages(['is_active' => __('“Other” is always offered.')]);
        }

        $old = $reason->exists ? $reason->only(['label', 'is_active', 'sort']) : [];
        $reason->fill($data)->save();

        AuditLog::record('reason_code.saved', null, $old, ['context' => $context, 'code' => $reason->code, ...$reason->only(['label', 'is_active', 'sort'])], $actor);

        return $reason;
    }
}
