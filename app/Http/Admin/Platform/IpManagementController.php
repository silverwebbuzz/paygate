<?php

namespace App\Http\Admin\Platform;

use App\Domain\Partner\Models\Partner;
use App\Domain\Partner\Models\PartnerIpRule;
use App\Domain\PartnerApi\Models\ApiRequestLog;
use App\Http\Controller;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * IP Management (Admin): every partner's API allow-list in one place, with
 * the addresses that actually called in the last 7 days and how many calls
 * were refused. Lists are edited on the partner (API & security).
 */
class IpManagementController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('ip_rules.view');

        $since = now()->subDays(7);
        $rules = [];

        foreach (PartnerIpRule::query()->get(['partner_id', 'cidr']) as $rule) {
            $rules[$rule->partner_id][] = $rule->cidr;
        }

        $calls = [];

        foreach (ApiRequestLog::query()
            ->where('created_at', '>=', $since)
            ->whereNotNull('partner_id')
            ->toBase()
            ->selectRaw('partner_id, ip, COUNT(*) AS calls, COUNT(*) FILTER (WHERE status_code = 403) AS refused, MAX(created_at) AS last_at')
            ->groupBy('partner_id', 'ip')
            ->orderByDesc('calls')
            ->get() as $row) {
            $calls[(string) $row->partner_id][] = ['ip' => $row->ip, 'calls' => (int) $row->calls, 'refused' => (int) $row->refused, 'last_at' => $row->last_at];
        }

        $partners = [];

        foreach (Partner::query()->orderBy('code')->get(['id', 'code', 'name', 'status']) as $partner) {
            $partners[] = [
                'id' => $partner->id,
                'code' => $partner->code,
                'name' => $partner->name,
                'status' => $partner->status->value,
                'rules' => $rules[$partner->id] ?? [],
                'calls' => $calls[$partner->id] ?? [],
            ];
        }

        return Inertia::render('admin/ip-management', [
            'enforced' => (bool) config('paygate.api.enforce_ip_allowlist', true),
            'partners' => $partners,
        ]);
    }
}
