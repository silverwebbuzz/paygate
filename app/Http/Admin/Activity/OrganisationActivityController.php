<?php

namespace App\Http\Admin\Activity;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Network\Models\PartnerBranchMapping;
use App\Domain\Partner\Models\Partner;
use App\Http\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class OrganisationActivityController extends Controller
{
    /** @var array<string, string> */
    private const PARTNER_EVENTS = [
        'partner.created' => 'Added',
        'partner.updated' => 'Updated',
        'partner.status_changed' => 'Status',
        'partner.direction_changed' => 'Pay-in / pay-out',
        'commission_rate.set' => 'Commission rate',
        'partner.branches_updated' => 'Mapped branches',
        'mapping.updated' => 'Branch mapping',
        'api_key.issued' => 'API key',
    ];

    /** @var array<string, string> */
    private const BRANCH_EVENTS = [
        'branch.created' => 'Added',
        'branch.updated' => 'Updated',
        'branch.status_changed' => 'Status',
        'branch.limit_topped_up' => 'Deposit allowance',
        'commission_rate.set' => 'Commission rate',
        'branch.partners_updated' => 'Mapped partners',
        'mapping.updated' => 'Branch mapping',
    ];

    public function partners(Request $request): Response
    {
        Gate::authorize('partners.view');

        return $this->page($request, 'partner');
    }

    public function branches(Request $request): Response
    {
        Gate::authorize('branches.view');

        return $this->page($request, 'branch');
    }

    private function page(Request $request, string $kind): Response
    {
        $events = $kind === 'partner' ? self::PARTNER_EVENTS : self::BRANCH_EVENTS;
        $filters = $this->filters($request, array_keys($events));
        $logs = $this->query($kind, $filters)
            ->with(['actor', 'subject' => fn ($morph) => $morph->morphWith([
                PartnerBranchMapping::class => ['partner', 'branch'],
            ])])
            ->latest('created_at')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (AuditLog $log) => $this->row($log, $kind));

        $parties = ($kind === 'partner' ? Partner::query() : Branch::query())
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        return Inertia::render('admin/activity/index', [
            'kind' => $kind,
            'logs' => $logs,
            'filters' => [
                'from' => $filters['from']->toDateString(),
                'to' => $filters['to']->toDateString(),
                'event' => $filters['event'],
                'party' => $filters['party'],
                'search' => $filters['search'],
            ],
            'events' => collect($events)->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values(),
            'parties' => $parties,
        ]);
    }

    /**
     * @param  list<string>  $events
     * @return array{from: CarbonImmutable, to: CarbonImmutable, event: string|null, party: string|null, search: string}
     */
    private function filters(Request $request, array $events): array
    {
        $zone = (string) config('app.business_timezone');
        $date = fn (string $key, CarbonImmutable $default) => is_string($request->query($key)) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query($key)) === 1
            ? CarbonImmutable::parse((string) $request->query($key), $zone)
            : $default;
        $event = $request->query('event');
        $party = $request->query('party');

        return [
            'from' => $date('from', CarbonImmutable::now($zone)->subDays(29))->startOfDay(),
            'to' => $date('to', CarbonImmutable::now($zone))->endOfDay(),
            'event' => is_string($event) && in_array($event, $events, true) ? $event : null,
            'party' => is_string($party) && preg_match('/^[0-9a-f-]{36}$/i', $party) === 1 ? $party : null,
            'search' => trim((string) $request->query('search')),
        ];
    }

    /**
     * @param  array{from: CarbonImmutable, to: CarbonImmutable, event: string|null, party: string|null, search: string}  $filters
     * @return Builder<AuditLog>
     */
    private function query(string $kind, array $filters): Builder
    {
        $search = $filters['search'];
        $party = $filters['party'];

        return AuditLog::query()
            ->whereBetween('created_at', [$filters['from']->utc(), $filters['to']->utc()])
            ->where(fn (Builder $query) => $this->scope($query, $kind, $party))
            ->when($filters['event'] === 'api_key.issued', fn (Builder $query) => $query->where('action', 'like', 'api_key.%'))
            ->when($filters['event'] !== null && $filters['event'] !== 'api_key.issued', fn (Builder $query) => $query->where('action', $filters['event']))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereIn('subject_id', $this->matchingParties($kind, $search))
                ->orWhereHas('actor', fn (Builder $query) => $query->whereLike('name', "%{$search}%")->orWhereLike('username', "%{$search}%"))));
    }

    private function scope(Builder $query, string $kind, ?string $party): void
    {
        $mappingIds = PartnerBranchMapping::query()->when($party !== null, fn (Builder $query) => $query->where($kind.'_id', $party))->select('id');

        $query->where(fn (Builder $query) => $query->where('subject_type', $kind)->when($party !== null, fn (Builder $query) => $query->where('subject_id', $party)))
            ->orWhere(fn (Builder $query) => $query->where('subject_type', 'mapping')->whereIn('subject_id', $mappingIds));
    }

    /**
     * @return Builder<Model>
     */
    private function matchingParties(string $kind, string $search): Builder
    {
        $model = $kind === 'partner' ? Partner::query() : Branch::query();

        return $model->where(fn (Builder $query) => $query->whereLike('name', "%{$search}%")->orWhereLike('code', "%{$search}%"))->select('id');
    }

    /**
     * @return array{id: string, at: string, summary: string, who: string, party: array{code: string, name: string}|null, changes: list<array{field: string, before: string|null, now: string}>, reason: string|null}
     */
    private function row(AuditLog $log, string $kind): array
    {
        $actor = $log->actor;
        $subject = $log->subject;
        $party = null;

        if ($subject instanceof Partner || $subject instanceof Branch) {
            $party = ['code' => $subject->code, 'name' => $subject->name];
        } elseif ($subject instanceof PartnerBranchMapping) {
            $side = $kind === 'partner' ? $subject->partner : $subject->branch;
            $party = ['code' => $side->code, 'name' => $side->name];
        }

        return [
            'id' => $log->id,
            'at' => $log->created_at->toIso8601String(),
            'summary' => $log->summary(),
            'who' => $actor === null ? 'System' : $actor->name.($actor->username ? ' · '.$actor->username : ''),
            'party' => $party,
            'changes' => $log->changePairs(),
            'reason' => is_string($log->new_values['reason'] ?? null) ? $log->new_values['reason'] : null,
        ];
    }
}
