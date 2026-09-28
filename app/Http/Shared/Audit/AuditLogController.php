<?php

namespace App\Http\Shared\Audit;

use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Audit\Models\SecurityLog;
use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Core\Identity\Models\User;
use App\Http\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Audit Logs: every recorded action (who, what, before → after, IP,
 * request id) and, for Admin, sign-in and security events. A branch sees
 * what its own users did. Read-only: the logs can't be changed.
 */
class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('audit_logs.view');

        /** @var User $actor */
        $actor = $request->user();
        $isAdmin = $actor->isType(UserType::Admin);
        $tab = $isAdmin && $request->query('tab') === 'security' ? 'security' : 'activity';
        $search = trim((string) $request->query('search'));
        $zone = (string) config('app.business_timezone');
        $date = fn (string $key, CarbonImmutable $default) => is_string($request->query($key)) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query($key)) === 1
            ? CarbonImmutable::parse((string) $request->query($key), $zone)
            : $default;
        $from = $date('from', CarbonImmutable::now($zone)->subDays(6))->startOfDay();
        $to = $date('to', CarbonImmutable::now($zone))->endOfDay();
        $ownUsers = fn (Builder $query, string $column) => $query->when(! $isAdmin, fn (Builder $query) => $query->whereIn($column, User::query()->where('branch_id', $actor->branch_id)->select('id')));

        if ($tab === 'security') {
            $items = SecurityLog::query()
                ->with('user')
                ->whereBetween('created_at', [$from->utc(), $to->utc()])
                ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query->where('email', 'ilike', "%{$search}%")->orWhere('ip_address', $search)->orWhere('event', $search)))
                ->latest('created_at')
                ->paginate(50)
                ->withQueryString()
                ->through(fn (SecurityLog $log) => [
                    'id' => $log->id,
                    'event' => $log->event->value,
                    'who' => $log->user->name ?? $log->email,
                    'ip' => $log->ip_address,
                    'request_id' => $log->request_id,
                    'context' => $log->context,
                    'at' => $log->created_at->toIso8601String(),
                ]);
        } else {
            $items = $ownUsers(AuditLog::query(), 'actor_id')
                ->with('actor')
                ->whereBetween('created_at', [$from->utc(), $to->utc()])
                ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                    ->where('action', 'ilike', "%{$search}%")
                    ->orWhere('request_id', $search)
                    ->orWhereHas('actor', fn (Builder $query) => $query->where('name', 'ilike', "%{$search}%")->orWhere('email', 'ilike', "%{$search}%"))))
                ->latest('created_at')
                ->paginate(50)
                ->withQueryString()
                ->through(fn ($log) => $log instanceof AuditLog ? [
                    'id' => $log->id,
                    'action' => $log->action,
                    'who' => $log->actor->name ?? 'System',
                    'subject' => $log->subject_type === null ? null : $log->subject_type.' '.substr((string) $log->subject_id, -8),
                    'old' => $log->old_values,
                    'new' => $log->new_values,
                    'ip' => $log->ip_address,
                    'request_id' => $log->request_id,
                    'at' => $log->created_at->toIso8601String(),
                ] : []);
        }

        return Inertia::render('audit-logs/index', [
            'portal' => $actor->type->value,
            'tab' => $tab,
            'items' => $items,
            'filters' => ['search' => $search, 'from' => $from->toDateString(), 'to' => $to->toDateString()],
        ]);
    }
}
