<?php

namespace App\Http\Admin\Accounts;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Models\User;
use App\Domain\PaymentAccount\Models\PaymentAccount;
use App\Http\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AccountLogController extends Controller
{
    public const EVENTS = [
        'created' => 'Added',
        'updated' => 'Edited',
        'verified' => 'Verified',
        'rejected' => 'Rejected',
        'activated' => 'Activated',
        'deactivated' => 'Inactive',
        'paused' => 'Paused',
        'disabled' => 'Disabled',
        'verification_changed' => 'Marked pending',
        'revealed' => 'Full number viewed',
    ];

    private const STATUS_EVENTS = ['activated' => 'active', 'deactivated' => 'inactive', 'paused' => 'paused', 'disabled' => 'disabled'];

    private const HIDDEN_FIELDS = ['status', 'verification', 'verified_at', 'verified_by', 'rejected_reason', 'updated_at', 'created_at'];

    public function index(Request $request): Response
    {
        Gate::authorize('accounts.view');

        $filters = $this->filters($request);

        $logs = $this->query($filters)
            ->paginate(50)
            ->withQueryString()
            ->through(fn (AuditLog $log) => $this->row($log));

        $account = $filters['account'] === null ? null : PaymentAccount::query()->find($filters['account']);

        return Inertia::render('admin/accounts/logs', [
            'logs' => $logs,
            'filters' => [
                'from' => $filters['from']->toDateString(),
                'to' => $filters['to']->toDateString(),
                'event' => $filters['event'],
                'branch' => $filters['branch'],
                'account' => $filters['account'],
                'search' => $filters['search'],
            ],
            'account' => $account === null ? null : ['id' => $account->id, 'label' => $account->label, 'holder' => $account->account_holder_name],
            'events' => collect(self::EVENTS)->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values(),
            'branches' => Branch::query()->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        Gate::authorize('accounts.view');

        $filters = $this->filters($request);
        $query = $this->query($filters);
        $zone = (string) config('app.business_timezone');

        return response()->streamDownload(function () use ($query, $zone) {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            $safe = fn (?string $value) => $value !== null && preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;

            fputcsv($out, ['When', 'Event', 'From status', 'To status', 'Changed fields', 'Account holder', 'Account label', 'Bank', 'Account number', 'UPI ID', 'Branch code', 'Branch', 'By', 'Username', 'Role', 'Reason', 'IP']);

            foreach ($query->lazy(500) as $log) {
                /** @var AuditLog $log */
                $row = $this->row($log);

                fputcsv($out, [
                    CarbonImmutable::parse($row['at'])->setTimezone($zone)->format('Y-m-d H:i:s'),
                    $row['event_label'],
                    $row['from'],
                    $row['to'],
                    $safe(implode(', ', $row['fields'])),
                    $safe($row['account']['holder'] ?? null),
                    $safe($row['account']['label'] ?? null),
                    $safe($row['account']['bank_name'] ?? null),
                    $row['account']['account_number'] ?? null,
                    $row['account']['upi_id'] ?? null,
                    $safe($row['branch']['code'] ?? null),
                    $safe($row['branch']['name'] ?? null),
                    $safe($row['who']['name']),
                    $safe($row['who']['username']),
                    $safe($row['who']['role']),
                    $safe($row['reason']),
                    $row['ip'],
                ]);
            }

            fclose($out);
        }, 'account-log-'.$filters['from']->toDateString().'-to-'.$filters['to']->toDateString().'.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * @return array{from: CarbonImmutable, to: CarbonImmutable, event: string|null, branch: string|null, account: string|null, search: string}
     */
    private function filters(Request $request): array
    {
        $zone = (string) config('app.business_timezone');
        $date = fn (string $key, CarbonImmutable $default) => is_string($request->query($key)) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query($key)) === 1
            ? CarbonImmutable::parse((string) $request->query($key), $zone)
            : $default;
        $uuid = fn (string $key) => is_string($request->query($key)) && preg_match('/^[0-9a-f-]{36}$/i', (string) $request->query($key)) === 1 ? (string) $request->query($key) : null;
        $event = $request->query('event');

        return [
            'from' => $date('from', CarbonImmutable::now($zone)->subDays(29))->startOfDay(),
            'to' => $date('to', CarbonImmutable::now($zone))->endOfDay(),
            'event' => is_string($event) && array_key_exists($event, self::EVENTS) ? $event : null,
            'branch' => $uuid('branch'),
            'account' => $uuid('account'),
            'search' => trim((string) $request->query('search')),
        ];
    }

    /**
     * @param  array{from: CarbonImmutable, to: CarbonImmutable, event: string|null, branch: string|null, account: string|null, search: string}  $filters
     * @return Builder<AuditLog>
     */
    private function query(array $filters): Builder
    {
        $event = $filters['event'];
        $search = $filters['search'];

        return AuditLog::query()
            ->with(['actor.role', 'subject' => fn ($morph) => $morph->morphWith([PaymentAccount::class => ['branch']])])
            ->where('subject_type', 'payment_account')
            ->where('action', 'like', 'payment_account.%')
            ->whereBetween('created_at', [$filters['from']->utc(), $filters['to']->utc()])
            ->when($event !== null && isset(self::STATUS_EVENTS[$event]), fn (Builder $query) => $query
                ->where('action', 'payment_account.status_changed')
                ->where('new_values->status', self::STATUS_EVENTS[(string) $event]))
            ->when($event !== null && ! isset(self::STATUS_EVENTS[$event]), fn (Builder $query) => $query->where('action', 'payment_account.'.$event))
            ->when($filters['account'] !== null, fn (Builder $query) => $query->where('subject_id', $filters['account']))
            ->when($filters['branch'] !== null, fn (Builder $query) => $query->whereIn('subject_id', PaymentAccount::query()->where('branch_id', $filters['branch'])->select('id')))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereIn('subject_id', PaymentAccount::query()->where(fn (Builder $query) => $query
                    ->whereLike('label', "%{$search}%")
                    ->orWhereLike('account_holder_name', "%{$search}%")
                    ->orWhereLike('bank_name', "%{$search}%")
                    ->orWhere('account_number_last4', $search)
                    ->orWhere('upi_id_last4', $search))->select('id'))
                ->orWhereIn('actor_id', User::query()->where(fn (Builder $query) => $query
                    ->whereLike('name', "%{$search}%")
                    ->orWhereLike('username', "%{$search}%")
                    ->orWhereLike('email', "%{$search}%"))->select('id'))
                ->orWhere('ip_address', $search)
                ->orWhereLike('new_values->reason', "%{$search}%")))
            ->latest('created_at')
            ->orderByDesc('id');
    }

    /**
     * @return array{id: string, at: string, event: string, event_label: string, from: string|null, to: string|null, fields: list<string>, changes: list<array{field: string, before: string|null, now: string}>, reason: string|null, ip: string|null, account: array<string, string|null>|null, branch: array{code: string, name: string}|null, who: array{name: string, username: string|null, role: string|null, portal: string|null}}
     */
    private function row(AuditLog $log): array
    {
        $old = $log->old_values ?? [];
        $new = $log->new_values ?? [];
        $event = substr($log->action, strlen('payment_account.'));

        if ($event === 'status_changed') {
            $event = array_search($new['status'] ?? null, self::STATUS_EVENTS, true) ?: 'status_changed';
        }

        $from = null;
        $to = null;

        if (isset($new['verification']) && is_string($new['verification']) && in_array($event, ['verified', 'rejected', 'verification_changed'], true)) {
            $from = isset($old['verification']) && is_string($old['verification']) ? $old['verification'] : null;
            $to = $new['verification'];
        } elseif (isset($new['status']) && is_string($new['status'])) {
            $from = isset($old['status']) && is_string($old['status']) ? $old['status'] : null;
            $to = $new['status'];
        }

        $fields = $event === 'updated' ? $log->changeLines(self::HIDDEN_FIELDS) : [];

        $account = $log->subject instanceof PaymentAccount ? $log->subject : null;
        $actor = $log->actor;

        return [
            'id' => $log->id,
            'at' => $log->created_at->toIso8601String(),
            'event' => $event,
            'event_label' => self::EVENTS[$event] ?? ucfirst(str_replace('_', ' ', $event)),
            'from' => $from,
            'to' => $to,
            'fields' => $fields,
            'changes' => $log->changePairs(['updated_at', 'created_at', 'rejected_reason']),
            'reason' => isset($new['reason']) && is_string($new['reason']) ? $new['reason'] : null,
            'ip' => $log->ip_address,
            'account' => $account === null ? null : [
                'id' => $account->id,
                'label' => $account->label,
                'holder' => $account->account_holder_name,
                'bank_name' => $account->bank_name,
                'account_number' => $account->maskedAccountNumber(),
                'upi_id' => $account->maskedUpiId(),
            ],
            'branch' => $account === null ? null : ['code' => $account->branch->code, 'name' => $account->branch->name],
            'who' => [
                'name' => $actor->name ?? 'System',
                'username' => $actor?->username,
                'role' => $actor?->role->name,
                'portal' => $actor?->type->value,
            ],
        ];
    }
}
