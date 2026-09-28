<?php

namespace App\Domain\Reporting;

use App\Domain\Allocation\UsageCounters;
use App\Domain\Branch\Models\Branch;
use App\Domain\Commission\Enums\Direction;
use App\Domain\Core\Organisation\Enums\OrganisationStatus;
use App\Domain\Ledger\Ledger;
use App\Domain\Partner\Models\Partner;
use App\Domain\PaymentAccount\Models\PaymentAccount;
use App\Domain\Payout\PartnerBalance;
use App\Domain\Reconciliation\Models\ReconciliationCase;
use App\Domain\Reconciliation\Models\StatementEntry;
use App\Domain\Settlement\Models\Adjustment;
use App\Domain\Settlement\Models\Settlement;
use App\Domain\Settlement\Models\SettlementPayment;
use App\Domain\Transaction\Models\Transaction;
use App\Domain\Webhook\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * The figures of the three dashboards (design: KPIs, pay-in vs payout
 * chart, outcome, "needs attention", a table), for one scope and period.
 *
 * Definitions (decided 2026-09-28, G-49):
 * - volume / commission: transactions that succeeded in the period;
 * - outcome: transactions created in the period — success, failed
 *   (declined, expired, cancelled, failed) or still pending;
 * - unsettled: what parties and PayGate owe each other now (the ledger
 *   positions), not the reconciliation state.
 *
 * Live queries, cached for 30 seconds per scope and period.
 */
class DashboardMetrics
{
    private const WAITING_PAYIN = ['payment_submitted', 'payment_detected', 'under_review'];

    private const WAITING_PAYOUT = ['assigned', 'processing'];

    public function __construct(private Ledger $ledger, private PartnerBalance $balances, private UsageCounters $usage) {}

    /**
     * @return array<string, mixed>
     */
    public function for(Scope $scope, Period $period): array
    {
        return Cache::remember(
            'dashboard:'.$scope->cacheKey().':'.$period->from->timestamp.':'.$period->to->timestamp,
            30,
            fn () => $this->build($scope, $period),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function build(Scope $scope, Period $period): array
    {
        $outcome = $this->outcome($scope, $period);

        return [
            'kpis' => match (true) {
                $scope->isAdmin() => $this->adminKpis($scope, $period, $outcome),
                $scope->partnerId !== null => $this->partnerKpis($scope, $period, $outcome),
                default => $this->branchKpis($scope, $period),
            },
            'chart' => $this->chart($scope, $period),
            'outcome' => $outcome,
            'attention' => $this->attention($scope),
            'table' => match (true) {
                $scope->isAdmin() => $this->branchPerformance($period),
                $scope->partnerId !== null => $this->recentPayins($scope),
                default => $this->accountUsage($scope),
            },
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return Builder<Transaction>
     */
    private function transactions(Scope $scope): Builder
    {
        return $scope->apply(Transaction::query());
    }

    /**
     * @return Builder<Transaction>
     */
    private function succeeded(Scope $scope, Period $period, ?string $direction = null): Builder
    {
        return $this->transactions($scope)
            ->where('status', 'success')
            ->where('succeeded_at', '>=', $period->from)
            ->where('succeeded_at', '<', $period->to)
            ->when($direction !== null, fn (Builder $query) => $query->where('direction', $direction));
    }

    /**
     * @return array{success: int, pending: int, failed: int, total: int, rate: float|null, avg_approval_seconds: int|null, avg_ticket: int|null}
     */
    private function outcome(Scope $scope, Period $period): array
    {
        $counts = $this->transactions($scope)
            ->where('created_at', '>=', $period->from)
            ->where('created_at', '<', $period->to)
            ->toBase()
            ->selectRaw("COUNT(*) FILTER (WHERE status = 'success') AS success, COUNT(*) FILTER (WHERE status IN ('rejected', 'expired', 'cancelled', 'failed')) AS failed, COUNT(*) AS total")
            ->first();

        $success = (int) ($counts->success ?? 0);
        $failed = (int) ($counts->failed ?? 0);
        $total = (int) ($counts->total ?? 0);

        $timing = $this->succeeded($scope, $period)
            ->toBase()
            ->selectRaw("AVG(amount) AS ticket, AVG(EXTRACT(EPOCH FROM decided_at - submitted_at)) FILTER (WHERE direction = 'payin' AND submitted_at IS NOT NULL) AS approval")
            ->first();

        return [
            'success' => $success,
            'pending' => $total - $success - $failed,
            'failed' => $failed,
            'total' => $total,
            'rate' => $success + $failed === 0 ? null : round($success / ($success + $failed) * 100, 1),
            'avg_approval_seconds' => $timing?->approval === null ? null : (int) round((float) $timing->approval),
            'avg_ticket' => $timing?->ticket === null ? null : (int) round((float) $timing->ticket),
        ];
    }

    /**
     * @param  array{success: int, pending: int, failed: int, total: int, rate: float|null}  $outcome
     * @return list<array<string, mixed>>
     */
    private function adminKpis(Scope $scope, Period $period, array $outcome): array
    {
        $volume = (int) $this->succeeded($scope, $period)->sum('amount');
        $previous = (int) $this->succeeded($scope, $period->previous())->sum('amount');
        $count = $outcome['total'];
        $previousCount = $this->transactions($scope)->where('created_at', '>=', $period->previous()->from)->where('created_at', '<', $period->from)->count();
        $waiting = $this->waiting($scope);
        $unsettled = $this->ledger->unsettled();
        $commission = $this->succeeded($scope, $period)->toBase()->selectRaw('COALESCE(SUM(partner_commission), 0) AS partner, COALESCE(SUM(branch_commission), 0) AS branch, COALESCE(SUM(platform_margin), 0) AS margin')->first();

        return [
            $this->kpi('volume', 'Total volume', $volume, 'money', $this->delta($volume, $previous)),
            $this->kpi('transactions', 'Transactions', $count, 'count', $this->delta($count, $previousCount)),
            $this->kpi('successful', 'Successful', $outcome['success'], 'count', $outcome['rate'] === null ? null : ['text' => $outcome['rate'].'% success rate', 'tone' => 'up']),
            $this->kpi('pending', 'Pending now', $waiting['count'], 'count', ['text' => $waiting['stale'].' older than 30 min', 'tone' => $waiting['stale'] > 0 ? 'warn' : 'neutral']),
            $this->kpi('failed', 'Failed', $outcome['failed'], 'count', ['text' => 'Declined, expired or not paid', 'tone' => 'neutral']),
            $this->kpi('partners', 'Partners', Partner::query()->count(), 'count', ['text' => Partner::query()->where('status', OrganisationStatus::Active->value)->count().' active', 'tone' => 'neutral']),
            $this->kpi('branches', 'Branches', Branch::query()->count(), 'count', ['text' => Branch::query()->where('status', OrganisationStatus::Active->value)->count().' active', 'tone' => 'neutral']),
            $this->kpi('to_receive', 'Unsettled · to receive', $unsettled['to_receive'], 'money', ['text' => 'Owed to PayGate now', 'tone' => 'neutral']),
            $this->kpi('to_pay', 'Unsettled · to pay', $unsettled['to_pay'], 'money', ['text' => 'Owed by PayGate now', 'tone' => 'neutral']),
            $this->kpi('settled', 'Settled', $this->settledIn($period), 'money', ['text' => $this->money($this->openSettlements($scope)).' still open', 'tone' => 'neutral']),
            $this->kpi('commission', 'Platform margin', (int) ($commission->margin ?? 0), 'money', ['text' => 'Partners paid '.$this->money((int) ($commission->partner ?? 0)).' · branches earned '.$this->money((int) ($commission->branch ?? 0)), 'tone' => 'neutral']),
        ];
    }

    /**
     * @param  array{success: int, pending: int, failed: int, total: int, rate: float|null, avg_approval_seconds: int|null}  $outcome
     * @return list<array<string, mixed>>
     */
    private function partnerKpis(Scope $scope, Period $period, array $outcome): array
    {
        $volume = (int) $this->succeeded($scope, $period)->sum('amount');
        $previous = (int) $this->succeeded($scope, $period->previous())->sum('amount');
        $partner = Partner::query()->findOrFail($scope->partnerId);
        $balance = $this->balances->summary($partner);
        $waiting = $this->waiting($scope);

        return [
            $this->kpi('volume', 'Total volume', $volume, 'money', $this->delta($volume, $previous)),
            $this->kpi('successful', 'Successful', $outcome['success'], 'count', $outcome['rate'] === null ? null : ['text' => $outcome['rate'].'% success rate', 'tone' => 'up']),
            $this->kpi('pending', 'Pending now', $waiting['count'], 'count', ['text' => 'Waiting for the branch to confirm', 'tone' => $waiting['count'] > 0 ? 'warn' : 'neutral']),
            $this->kpi('failed', 'Failed', $outcome['failed'], 'count', ['text' => 'Declined, expired or not paid', 'tone' => 'neutral']),
            $this->kpi('balance', 'Current balance', (int) $balance['available'], 'money', ['text' => 'Available for payouts · largest single '.$this->money((int) $balance['max_payout']), 'tone' => 'neutral']),
            $this->kpi('settlement', 'Unsettled', (int) $balance['balance'], 'money', ['text' => (int) $balance['balance'] >= 0 ? 'PayGate owes you now' : 'You owe PayGate now', 'tone' => 'neutral']),
            $this->kpi('commission', 'Commission paid', (int) $this->succeeded($scope, $period)->sum('partner_commission'), 'money', ['text' => 'Deposit + withdrawal fees', 'tone' => 'neutral']),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function branchKpis(Scope $scope, Period $period): array
    {
        $deposits = (int) $this->succeeded($scope, $period, 'payin')->sum('amount');
        $previous = (int) $this->succeeded($scope, $period->previous(), 'payin')->sum('amount');
        $payouts = $this->succeeded($scope, $period, 'payout');
        $pending = $this->transactions($scope)->where('direction', 'payin')->whereIn('status', self::WAITING_PAYIN);
        $oldest = (clone $pending)->min('submitted_at');
        $cases = ReconciliationCase::query()->where('branch_id', $scope->branchId)->where('status', '!=', 'resolved');
        $accounts = PaymentAccount::query()->where('branch_id', $scope->branchId);
        $position = $this->ledger->unsettled(branchId: $scope->branchId);
        $settlement = Settlement::query()->current()->where(['party_type' => 'branch', 'branch_id' => $scope->branchId])->whereIn('status', ['calculated', 'partially_settled'])->first();

        return [
            $this->kpi('deposits', 'Deposits', $deposits, 'money', $this->delta($deposits, $previous)),
            $this->kpi('withdrawals', 'Withdrawals', (int) (clone $payouts)->sum('amount'), 'money', ['text' => (clone $payouts)->count().' payouts', 'tone' => 'neutral']),
            $this->kpi('pending_deposits', 'Pending manual deposits', (clone $pending)->count(), 'count', ['text' => $oldest === null ? 'Nothing waiting' : 'Oldest since '.CarbonImmutable::parse((string) $oldest)->setTimezone((string) config('app.business_timezone'))->format('H:i'), 'tone' => $oldest === null ? 'neutral' : 'warn']),
            $this->kpi('unsettled_utr', 'Unsettled UTR', (clone $cases)->count(), 'count', ['text' => $this->money((int) StatementEntry::query()->whereIn('id', (clone $cases)->select('statement_entry_id'))->sum('amount')), 'tone' => (clone $cases)->exists() ? 'warn' : 'neutral']),
            $this->kpi('accounts', 'Bank accounts active', (clone $accounts)->where('status', 'active')->count(), 'count', ['text' => 'of '.(clone $accounts)->count().' accounts', 'tone' => 'neutral']),
            $this->kpi('balance', 'Current position', $position['to_pay'] - $position['to_receive'], 'signed', ['text' => $position['to_receive'] > $position['to_pay'] ? 'You owe PayGate' : 'PayGate owes you', 'tone' => 'neutral']),
            $this->kpi('commission', 'Commission earned', (int) $this->succeeded($scope, $period)->sum('branch_commission'), 'money', ['text' => 'Deposit + withdrawal', 'tone' => 'neutral']),
            $this->kpi('settlement', 'Settlement due', $settlement?->remaining() ?? 0, 'money', ['text' => $settlement === null ? 'Nothing open' : ($settlement->direction === 'party_to_platform' ? 'You pay PayGate · '.$settlement->reference : 'PayGate pays you · '.$settlement->reference), 'tone' => 'neutral']),
        ];
    }

    /**
     * Pay-in vs payout amounts per hour or day (business time).
     *
     * @return array{bucket: string, points: list<array{at: string, payin: int, payout: int}>, payin_total: int, payout_total: int}
     */
    private function chart(Scope $scope, Period $period): array
    {
        $zone = (string) config('app.business_timezone');
        $unit = $period->bucket();

        $rows = $this->succeeded($scope, $period)
            ->toBase()
            ->selectRaw($unit === 'hour'
                ? "to_char(date_trunc('hour', succeeded_at AT TIME ZONE ?), 'YYYY-MM-DD\"T\"HH24:MI') AS slot, direction, SUM(amount) AS total"
                : "to_char(date_trunc('day', succeeded_at AT TIME ZONE ?), 'YYYY-MM-DD\"T\"HH24:MI') AS slot, direction, SUM(amount) AS total", [$zone])
            ->groupBy('slot', 'direction')
            ->get();

        $values = [];

        foreach ($rows as $row) {
            $values[(string) $row->slot][(string) $row->direction] = (int) $row->total;
        }

        $points = [];
        $cursor = $period->from->setTimezone($zone);
        $cursor = $unit === 'hour' ? $cursor->startOfHour() : $cursor->startOfDay();
        $end = $period->to->setTimezone($zone);

        while ($cursor->lessThan($end) && count($points) < 400) {
            $slot = $cursor->format('Y-m-d\TH:i');
            $points[] = ['at' => $slot, 'payin' => $values[$slot]['payin'] ?? 0, 'payout' => $values[$slot]['payout'] ?? 0];
            $cursor = $unit === 'hour' ? $cursor->addHour() : $cursor->addDay();
        }

        return [
            'bucket' => $unit,
            'points' => $points,
            'payin_total' => array_sum(array_column($points, 'payin')),
            'payout_total' => array_sum(array_column($points, 'payout')),
        ];
    }

    /**
     * The operational queues that need someone (design: "Needs attention").
     * `target` names the screen; the page turns it into a link.
     *
     * @return list<array{key: string, label: string, sub: string, value: string, tone: string, target: string|null}>
     */
    private function attention(Scope $scope): array
    {
        $items = [];
        $waitingPayins = $this->transactions($scope)->where('direction', 'payin')->whereIn('status', self::WAITING_PAYIN);
        $cases = $scope->apply(ReconciliationCase::query());

        $oldest = (clone $waitingPayins)->min('submitted_at');
        $items[] = $this->item('deposits', $scope->partnerId !== null ? 'Deposits being confirmed' : 'Pending manual deposits', $oldest === null ? 'Nothing waiting' : 'Oldest waiting '.CarbonImmutable::parse((string) $oldest)->diffForHumans(now(), CarbonInterface::DIFF_ABSOLUTE), (string) (clone $waitingPayins)->count(), 'pending', $scope->partnerId !== null ? 'payins' : 'deposits');

        if ($scope->partnerId === null) {
            $open = (clone $cases)->where('status', '!=', 'resolved');
            $amount = (int) StatementEntry::query()->whereIn('id', (clone $open)->select('statement_entry_id'))->sum('amount');
            $items[] = $this->item('cases', $scope->isAdmin() ? 'Unsettled UTR' : 'Unsettled statement lines', 'Bank lines without a matching transaction', (clone $open)->count().' · '.$this->money($amount), 'unsettled', 'cases');
        }

        $payouts = $this->transactions($scope)->where('direction', 'payout')->whereIn('status', self::WAITING_PAYOUT)->count();
        $items[] = $this->item('payouts', $scope->branchId !== null ? 'Payouts to pay' : 'Payouts in progress', 'Waiting for the branch to pay', (string) $payouts, 'processing', $scope->partnerId !== null ? 'payout_history' : 'payouts');

        // Webhooks go to partners: Admin and the partner see failures.
        if ($scope->branchId === null) {
            $failed = $scope->apply(WebhookEvent::query())->where('status', 'failed')->count();
            $items[] = $this->item('webhooks', 'Failed webhooks', 'Deliveries that gave up after all retries', (string) $failed, 'failed', $scope->isAdmin() ? null : 'developers');
        }

        // Accounts belong to branches: Admin and the branch see them.
        if ($scope->partnerId === null) {
            $pendingAccounts = $scope->apply(PaymentAccount::query())->where('status', 'verification_pending')->count();
            $items[] = $this->item('accounts', 'Bank accounts awaiting verification', 'Added or changed, not yet approved', (string) $pendingAccounts, 'pending_verification', 'accounts');
        }

        if ($scope->isAdmin()) {
            $items[] = $this->item('adjustments', 'Adjustments awaiting approval', 'A second admin must approve', (string) Adjustment::query()->where('status', 'pending')->count(), 'pending', 'adjustments');
        }

        return $items;
    }

    /**
     * @return array{title: string, columns: list<string>, rows: list<array<string, mixed>>}
     */
    private function branchPerformance(Period $period): array
    {
        $rows = Transaction::query()
            ->whereNotNull('branch_id')
            ->where('created_at', '>=', $period->from)
            ->where('created_at', '<', $period->to)
            ->groupBy('branch_id')
            ->toBase()
            ->selectRaw("branch_id, SUM(amount) FILTER (WHERE direction = 'payin' AND status = 'success') AS deposits, SUM(amount) FILTER (WHERE direction = 'payout' AND status = 'success') AS withdrawals, COUNT(*) FILTER (WHERE status = 'success') AS success, COUNT(*) FILTER (WHERE status IN ('rejected', 'expired', 'cancelled', 'failed')) AS failed")
            ->orderByRaw('deposits DESC NULLS LAST')
            ->limit(8)
            ->get();

        $branches = Branch::query()->whereIn('id', $rows->pluck('branch_id'))->withCount('mappings')->get()->keyBy('id');
        $positions = $this->ledger->partyTotals('branch');

        return [
            'title' => 'Branch performance',
            'columns' => ['Branch', 'Deposits', 'Withdrawals', 'Success rate', 'Position'],
            'rows' => array_values($rows->map(function (object $row) use ($branches, $positions) {
                $branch = $branches->get((string) $row->branch_id);
                $decided = (int) $row->success + (int) $row->failed;

                return [
                    'name' => $branch === null ? '—' : "{$branch->code} · {$branch->name}",
                    'sub' => ($branch->mappings_count ?? 0).' partners',
                    'a' => (int) $row->deposits,
                    'b' => (int) $row->withdrawals,
                    'rate' => $decided === 0 ? null : round((int) $row->success / $decided * 100, 1),
                    'value' => $positions[(string) $row->branch_id] ?? 0,
                ];
            })->all()),
        ];
    }

    /**
     * @return array{title: string, columns: list<string>, rows: list<array<string, mixed>>}
     */
    private function accountUsage(Scope $scope): array
    {
        $accounts = PaymentAccount::query()->where('branch_id', $scope->branchId)->whereIn('status', ['active', 'paused'])->orderBy('label')->limit(8)->get();
        $used = $this->usage->today('account', $accounts->pluck('id')->all(), Direction::Deposit);

        return [
            'title' => 'Account usage · today',
            'columns' => ['Account', 'Used today', 'Daily limit', 'Utilisation', 'Remaining'],
            'rows' => array_values($accounts->map(function (PaymentAccount $account) use ($used) {
                $today = $used[$account->id]['amount'] ?? 0;
                $limit = $account->daily_amount_limit;

                return [
                    'name' => $account->label,
                    'sub' => trim(($account->bank_name ?? 'UPI').' · '.($account->maskedAccountNumber() ?? $account->maskedUpiId() ?? '')),
                    'a' => $today,
                    'b' => $limit,
                    'rate' => $limit === null || $limit === 0 ? null : round(min($today / $limit * 100, 100), 1),
                    'value' => $limit === null ? null : max($limit - $today, 0),
                ];
            })->all()),
        ];
    }

    /**
     * @return array{title: string, columns: list<string>, rows: list<array<string, mixed>>}
     */
    private function recentPayins(Scope $scope): array
    {
        return [
            'title' => 'Recent pay-ins',
            'columns' => ['Order', 'Amount', 'Method', 'Status'],
            'rows' => array_values($this->transactions($scope)->where('direction', 'payin')->with('customer')->latest('created_at')->limit(6)->get()->map(fn (Transaction $txn) => [
                'name' => $txn->partner_transaction_id,
                'sub' => $txn->customer->name ?? $txn->customer->external_id ?? '',
                'a' => $txn->amount,
                'method' => $txn->method,
                'status' => $txn->status,
                'reference' => $txn->reference,
            ])->all()),
        ];
    }

    /**
     * @return array{count: int, stale: int}
     */
    private function waiting(Scope $scope): array
    {
        $query = fn () => $this->transactions($scope)->where(fn (Builder $query) => $query
            ->where(fn (Builder $query) => $query->where('direction', 'payin')->whereIn('status', self::WAITING_PAYIN))
            ->orWhere(fn (Builder $query) => $query->where('direction', 'payout')->whereIn('status', self::WAITING_PAYOUT)));

        return [
            'count' => $query()->count(),
            'stale' => $query()->where('updated_at', '<', now()->subMinutes(30))->count(),
        ];
    }

    private function settledIn(Period $period): int
    {
        return (int) SettlementPayment::query()->where('created_at', '>=', $period->from)->where('created_at', '<', $period->to)->sum('amount');
    }

    private function openSettlements(Scope $scope): int
    {
        return (int) $scope->apply(Settlement::query())->current()->whereIn('status', ['calculated', 'partially_settled'])->get()->sum(fn (Settlement $settlement) => $settlement->remaining());
    }

    /**
     * @param  array{text: string, tone: string}|null  $sub
     * @return array<string, mixed>
     */
    private function kpi(string $key, string $label, ?int $value, string $format, ?array $sub): array
    {
        return ['key' => $key, 'label' => $label, 'value' => $value, 'format' => $format, 'sub' => $sub];
    }

    /**
     * @return array{key: string, label: string, sub: string, value: string, tone: string, target: string|null}
     */
    private function item(string $key, string $label, string $sub, string $value, string $tone, ?string $target): array
    {
        return compact('key', 'label', 'sub', 'value', 'tone', 'target');
    }

    /**
     * @return array{text: string, tone: string}|null
     */
    private function delta(int $now, int $before): ?array
    {
        if ($before === 0) {
            return $now === 0 ? null : ['text' => 'None in the previous period', 'tone' => 'neutral'];
        }

        $change = round(($now - $before) / $before * 100, 1);

        return ['text' => ($change >= 0 ? '▲ ' : '▼ ').abs($change).'% vs previous period', 'tone' => $change >= 0 ? 'up' : 'down'];
    }

    private function money(int $paise): string
    {
        return '₹'.number_format($paise / 100, 2);
    }
}
