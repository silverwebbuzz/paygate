<?php

namespace App\Http\Shared\Accounts;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Partner\Models\Partner;
use App\Domain\PaymentAccount\Models\PaymentAccount;
use App\Domain\Reporting\Period;
use App\Http\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Every change to Bank & UPI accounts. Admin sees all branches; a branch
 * sees only its own accounts (and not PayGate staff's IP addresses).
 *
 * A partner (client request 2026-11-19) sees a short log of the accounts of
 * its mapped branches: only when an account turned active or inactive and
 * changes to its bank or UPI details, with the time. Not who, why, IP,
 * limits, verification or branch.
 */
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

    /** What a partner can filter by. */
    public const PARTNER_EVENTS = [
        'activated' => 'Active',
        'deactivated' => 'Inactive',
        'updated' => 'Bank / UPI changed',
    ];

    /** Bank and UPI details a partner sees changes of. */
    private const DETAIL_FIELDS = [
        'is_bank_enabled', 'account_holder_name', 'bank_name', 'ifsc', 'account_number', 'account_number_last4',
        'is_upi_enabled', 'upi_display_name', 'upi_id', 'upi_id_last4', 'is_qr_enabled',
    ];

    private const NOT_ACTIVE = ['inactive', 'paused', 'disabled'];

    /** Matches no branch: a partner with no mapped branch sees nothing. */
    private const NONE = '00000000-0000-0000-0000-000000000000';

    private const STATUS_EVENTS = ['activated' => 'active', 'deactivated' => 'inactive', 'paused' => 'paused', 'disabled' => 'disabled'];

    private const HIDDEN_FIELDS = ['status', 'verification', 'verified_at', 'verified_by', 'rejected_reason', 'updated_at', 'created_at'];

    public function index(Request $request): Response
    {
        Gate::authorize('accounts.view');

        $actor = $this->actor($request);
        $filters = $this->filters($request);

        $partner = $actor->isType(UserType::Partner);
        $logs = $this->query($filters, $partner)
            ->paginate(50)
            ->withQueryString()
            ->through(fn (AuditLog $log) => $partner ? $this->partnerRow($log) : $this->row($log, $actor));

        $account = $filters['account'] === null ? null : PaymentAccount::query()
            ->when($filters['branches'] !== [], fn (Builder $query) => $query->whereIn('branch_id', $filters['branches']))
            ->find($filters['account']);

        return Inertia::render('accounts/logs', [
            'portal' => $actor->type->value,
            'logs' => $logs,
            'period' => $filters['period']->toArray(),
            'filters' => [
                'event' => $filters['event'],
                'branches' => $actor->isType(UserType::Admin) ? $filters['branches'] : [],
                'account' => $filters['account'],
                'search' => $filters['search'],
            ],
            'account' => $account === null ? null : ['id' => $account->id, 'label' => $account->label],
            'events' => collect($partner ? self::PARTNER_EVENTS : self::EVENTS)->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values(),
            'branches' => $actor->isType(UserType::Admin) ? Branch::query()->orderBy('name')->get(['id', 'code', 'name']) : [],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        Gate::authorize('accounts.view');

        $actor = $this->actor($request);
        $filters = $this->filters($request);
        $partner = $actor->isType(UserType::Partner);
        $query = $this->query($filters, $partner);
        $zone = (string) config('app.business_timezone');

        return response()->streamDownload(function () use ($query, $zone, $actor, $partner) {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            $safe = fn (?string $value) => $value !== null && preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;

            if ($partner) {
                fputcsv($out, ['When', 'Change', 'Status', 'Changed details', 'Account label', 'Bank account holder', 'Bank', 'Account number', 'UPI ID', 'UPI name']);

                foreach ($query->lazy(500) as $log) {
                    /** @var AuditLog $log */
                    $row = $this->partnerRow($log);

                    fputcsv($out, [
                        CarbonImmutable::parse($row['at'])->setTimezone($zone)->format('Y-m-d H:i:s'),
                        $row['event_label'],
                        $row['to'],
                        $safe(implode(', ', $row['fields'])),
                        $safe($row['account']['label'] ?? null),
                        $safe($row['account']['holder'] ?? null),
                        $safe($row['account']['bank_name'] ?? null),
                        $row['account']['account_number'] ?? null,
                        $row['account']['upi_id'] ?? null,
                        $safe($row['account']['upi_name'] ?? null),
                    ]);
                }

                fclose($out);

                return;
            }

            fputcsv($out, ['When', 'Event', 'From status', 'To status', 'Changed fields', 'Bank account holder', 'Account label', 'Bank', 'Account number', 'UPI ID', 'UPI name', 'Branch', 'By', 'Username', 'Role', 'Reason', 'IP']);

            foreach ($query->lazy(500) as $log) {
                /** @var AuditLog $log */
                $row = $this->row($log, $actor);

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
                    $safe($row['account']['upi_name'] ?? null),
                    $safe($row['branch']['name'] ?? null),
                    $safe($row['who']['name']),
                    $safe($row['who']['username']),
                    $safe($row['who']['role']),
                    $safe($row['reason']),
                    $row['ip'],
                ]);
            }

            fclose($out);
        }, 'account-log-'.implode('-to-', $filters['period']->dates()).'.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * @return array{period: Period, event: string|null, branches: list<string>, account: string|null, search: string}
     */
    private function filters(Request $request): array
    {
        $uuid = fn (string $key) => is_string($request->query($key)) && preg_match('/^[0-9a-f-]{36}$/i', (string) $request->query($key)) === 1 ? (string) $request->query($key) : null;
        $event = $request->query('event');
        $actor = $this->actor($request);
        $events = $actor->isType(UserType::Partner) ? self::PARTNER_EVENTS : self::EVENTS;

        return [
            // Today by default; one account's log (its "Log" link) opens on 30 days.
            'period' => Period::fromRequest($request, $uuid('account') === null ? 'today' : '30d'),
            'event' => is_string($event) && array_key_exists($event, $events) ? $event : null,
            // Any number of branches (none = all; the old ?branch= still
            // works). A branch user only ever sees its own branch; a partner
            // the branches mapped to it.
            'branches' => match (true) {
                $actor->isType(UserType::Admin) => array_values(array_unique(array_filter(
                    [...(array) $request->query('branches', []), $request->query('branch')],
                    fn ($id) => is_string($id) && preg_match('/^[0-9a-f-]{36}$/i', $id) === 1,
                ))),
                $actor->isType(UserType::Partner) => array_values([
                    self::NONE,
                    ...Partner::query()->findOrFail($actor->partner_id)->branches()->pluck('branches.id')->map(fn ($id) => (string) $id)->all(),
                ]),
                default => [(string) $actor->branch_id],
            },
            'account' => $uuid('account'),
            'search' => trim((string) $request->query('search')),
        ];
    }

    /**
     * @param  array{period: Period, event: string|null, branches: list<string>, account: string|null, search: string}  $filters
     * @return Builder<AuditLog>
     */
    private function query(array $filters, bool $partner = false): Builder
    {
        $event = $filters['event'];
        $search = $filters['search'];

        if ($partner) {
            return $this->partnerQuery($filters);
        }

        return AuditLog::query()
            ->with(['actor.role', 'subject' => fn ($morph) => $morph->morphWith([PaymentAccount::class => ['branch']])])
            ->where('subject_type', 'payment_account')
            ->where('action', 'like', 'payment_account.%')
            ->where(fn (Builder $query) => $filters['period']->apply($query, 'created_at'))
            ->when($event !== null && isset(self::STATUS_EVENTS[$event]), fn (Builder $query) => $query
                ->where('action', 'payment_account.status_changed')
                ->where('new_values->status', self::STATUS_EVENTS[(string) $event]))
            ->when($event !== null && ! isset(self::STATUS_EVENTS[$event]), fn (Builder $query) => $query->where('action', 'payment_account.'.$event))
            ->when($filters['account'] !== null, fn (Builder $query) => $query->where('subject_id', $filters['account']))
            ->when($filters['branches'] !== [], fn (Builder $query) => $query->whereIn('subject_id', PaymentAccount::query()->whereIn('branch_id', $filters['branches'])->select('id')))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereIn('subject_id', PaymentAccount::query()->where(fn (Builder $query) => $query
                    ->whereLike('label', "%{$search}%")
                    ->orWhereLike('account_holder_name', "%{$search}%")
                    ->orWhereLike('upi_display_name', "%{$search}%")
                    ->orWhereLike('bank_name', "%{$search}%")
                    ->orWhere('account_number_last4', $search)
                    ->orWhere('upi_id_last4', $search))->select('id'))
                ->orWhereIn('actor_id', User::query()->where(fn (Builder $query) => $query
                    ->whereLike('name', "%{$search}%")
                    ->orWhereLike('username', "%{$search}%"))->select('id'))
                ->orWhere('ip_address', $search)
                ->orWhereLike('new_values->reason', "%{$search}%")))
            ->latest('created_at')
            ->orderByDesc('id');
    }

    /**
     * A partner's log: status changes, and edits of bank / UPI details.
     *
     * @param  array{period: Period, event: string|null, branches: list<string>, account: string|null, search: string}  $filters
     * @return Builder<AuditLog>
     */
    private function partnerQuery(array $filters): Builder
    {
        $event = $filters['event'];
        $search = $filters['search'];
        $details = function (Builder $query) {
            $query->where('action', 'payment_account.updated')->where(function (Builder $query) {
                foreach (self::DETAIL_FIELDS as $field) {
                    $query->orWhereNotNull("new_values->{$field}");
                }
            });
        };
        // Only a real change of status (verifying records it unchanged).
        $status = fn (Builder $query) => $query->where('action', '!=', 'payment_account.created')
            ->whereNotNull('new_values->status')
            ->whereRaw("(new_values->>'status') IS DISTINCT FROM (old_values->>'status')");

        return AuditLog::query()
            ->with(['subject'])
            ->where('subject_type', 'payment_account')
            ->where('action', 'like', 'payment_account.%')
            ->whereIn('subject_id', PaymentAccount::query()->whereIn('branch_id', $filters['branches'])->select('id'))
            ->where(fn (Builder $query) => $filters['period']->apply($query, 'created_at'))
            ->where(fn (Builder $query) => match ($event) {
                'activated' => $status($query->where('new_values->status', 'active')),
                'deactivated' => $status($query->whereIn('new_values->status', self::NOT_ACTIVE)),
                'updated' => $details($query),
                default => $query->where(fn (Builder $query) => $status($query))->orWhere(fn (Builder $query) => $details($query)),
            })
            ->when($filters['account'] !== null, fn (Builder $query) => $query->where('subject_id', $filters['account']))
            ->when($search !== '', fn (Builder $query) => $query->whereIn('subject_id', PaymentAccount::query()->where(fn (Builder $query) => $query
                ->whereLike('label', "%{$search}%")
                ->orWhereLike('account_holder_name', "%{$search}%")
                ->orWhereLike('upi_display_name', "%{$search}%")
                ->orWhereLike('bank_name', "%{$search}%")
                ->orWhere('account_number_last4', $search)
                ->orWhere('upi_id_last4', $search))->select('id')))
            ->latest('created_at')
            ->orderByDesc('id');
    }

    /**
     * One line of a partner's log: Active / Inactive, or which bank / UPI
     * details changed (before and now). Nothing about who or why.
     *
     * @return array{id: string, at: string, event: string, event_label: string, from: string|null, to: string|null, fields: list<string>, changes: list<array{field: string, before: string|null, now: string}>, reason: null, ip: null, account: array<string, string|null>|null, branch: null, who: null}
     */
    private function partnerRow(AuditLog $log): array
    {
        $old = $log->old_values ?? [];
        $new = $log->new_values ?? [];
        $simple = fn (mixed $status) => is_string($status) ? (in_array($status, self::NOT_ACTIVE, true) ? 'inactive' : $status) : null;
        $from = $simple($old['status'] ?? null);
        $to = $simple($new['status'] ?? null);

        $hidden = array_values(array_diff(array_keys($new), self::DETAIL_FIELDS));
        $changes = $log->action === 'payment_account.updated' ? $log->changePairs($hidden) : [];
        $event = $changes !== [] ? 'updated' : ($to === 'active' ? 'activated' : 'deactivated');
        $account = $log->subject instanceof PaymentAccount ? $log->subject : null;

        return [
            'id' => $log->id,
            'at' => $log->created_at->toIso8601String(),
            'event' => $event,
            'event_label' => self::PARTNER_EVENTS[$event],
            'from' => $from === $to ? null : $from,
            'to' => $from === $to ? null : $to,
            'fields' => array_map(fn (array $pair) => $pair['field'], $changes),
            'changes' => $changes,
            'reason' => null,
            'ip' => null,
            'account' => $account === null ? null : [
                'id' => $account->id,
                'label' => $account->label,
                'holder' => $account->is_bank_enabled ? $account->account_holder_name : null,
                'bank_name' => $account->is_bank_enabled ? $account->bank_name : null,
                'account_number' => $account->is_bank_enabled ? $account->maskedAccountNumber() : null,
                'upi_id' => $account->is_upi_enabled ? $account->maskedUpiId() : null,
                'upi_name' => $account->is_upi_enabled ? $account->upi_display_name : null,
            ],
            'branch' => null,
            'who' => null,
        ];
    }

    /**
     * @return array{id: string, at: string, event: string, event_label: string, from: string|null, to: string|null, fields: list<string>, changes: list<array{field: string, before: string|null, now: string}>, reason: string|null, ip: string|null, account: array<string, string|null>|null, branch: array{code: string, name: string}|null, who: array{name: string, username: string|null, role: string|null, portal: string|null}}
     */
    private function row(AuditLog $log, User $viewer): array
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
            'ip' => $viewer->isType(UserType::Admin) || $actor?->type === $viewer->type ? $log->ip_address : null,
            'account' => $account === null ? null : [
                'id' => $account->id,
                'label' => $account->label,
                'holder' => $account->is_bank_enabled ? $account->account_holder_name : null,
                'bank_name' => $account->is_bank_enabled ? $account->bank_name : null,
                'account_number' => $account->is_bank_enabled ? $account->maskedAccountNumber() : null,
                'upi_id' => $account->is_upi_enabled ? $account->maskedUpiId() : null,
                'upi_name' => $account->is_upi_enabled ? $account->upi_display_name : null,
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

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
