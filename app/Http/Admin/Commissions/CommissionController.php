<?php

namespace App\Http\Admin\Commissions;

use App\Domain\Branch\Models\Branch;
use App\Domain\Partner\Models\Partner;
use App\Domain\Transaction\Models\Transaction;
use App\Http\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Commissions (Admin): what partners paid and branches earned on the
 * transactions that succeeded in a period, and the platform margin between
 * them. The rates snapshotted on each transaction are used, never today's.
 */
class CommissionController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('commissions.view');

        $zone = config('app.business_timezone');
        $today = CarbonImmutable::now($zone);
        $date = fn (string $key, CarbonImmutable $default) => is_string($request->query($key)) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query($key)) === 1
            ? CarbonImmutable::parse((string) $request->query($key), $zone)
            : $default;
        $from = $date('from', $today->startOfMonth())->startOfDay();
        $to = $date('to', $today)->endOfDay();
        $by = $request->query('by') === 'branch' ? 'branch' : 'partner';

        $rows = Transaction::query()
            ->where('status', 'success')
            ->whereBetween('succeeded_at', [$from->utc(), $to->utc()])
            ->groupBy($by.'_id', 'direction')
            ->selectRaw("{$by}_id AS party_id, direction, COUNT(*) AS count, SUM(amount) AS amount, SUM(partner_commission) AS partner_commission, SUM(branch_commission) AS branch_commission, SUM(platform_margin) AS margin")
            ->toBase()
            ->get();

        $names = ($by === 'partner' ? Partner::query() : Branch::query())->whereIn('id', $rows->pluck('party_id'))->get(['id', 'code', 'name'])->keyBy('id');
        /** @var array<string, array{id: string, code: string, name: string, payin: array{count: int, amount: int, commission: int}, payout: array{count: int, amount: int, commission: int}, margin: int}> $parties */
        $parties = [];

        foreach ($rows as $row) {
            $id = (string) $row->party_id;
            $parties[$id] ??= [
                'id' => $id,
                'code' => $names->get($id)->code ?? '—',
                'name' => $names->get($id)->name ?? '—',
                'payin' => ['count' => 0, 'amount' => 0, 'commission' => 0],
                'payout' => ['count' => 0, 'amount' => 0, 'commission' => 0],
                'margin' => 0,
            ];
            $figures = [
                'count' => (int) $row->count,
                'amount' => (int) $row->amount,
                'commission' => (int) ($by === 'partner' ? $row->partner_commission : $row->branch_commission),
            ];

            if ($row->direction === 'payin') {
                $parties[$id]['payin'] = $figures;
            } else {
                $parties[$id]['payout'] = $figures;
            }

            $parties[$id]['margin'] += (int) $row->margin;
        }

        $parties = array_values($parties);
        usort($parties, fn (array $a, array $b) => ($b['payin']['commission'] + $b['payout']['commission']) <=> ($a['payin']['commission'] + $a['payout']['commission']));

        return Inertia::render('admin/commissions', [
            'by' => $by,
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'rows' => $parties,
            'totals' => [
                'partner_commission' => (int) $rows->sum('partner_commission'),
                'branch_commission' => (int) $rows->sum('branch_commission'),
                'margin' => (int) $rows->sum('margin'),
                'volume' => (int) $rows->sum('amount'),
            ],
        ]);
    }
}
