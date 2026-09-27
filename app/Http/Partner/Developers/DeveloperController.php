<?php

namespace App\Http\Partner\Developers;

use App\Domain\Core\Identity\Models\User;
use App\Domain\Partner\Actions\ConfigurePartner;
use App\Domain\Partner\Actions\IssueApiKey;
use App\Domain\Partner\Actions\RevokeApiKey;
use App\Domain\Partner\Actions\SyncIpRules;
use App\Domain\Partner\Models\Partner;
use App\Domain\Partner\Models\PartnerApiKey;
use App\Domain\Partner\Models\PartnerIpRule;
use App\Http\Controller;
use App\Http\Partner\Developers\Requests\UpdateEndpointsRequest;
use App\Http\Partner\Developers\Requests\UpdateIpRulesRequest;
use App\Http\Shared\Credentials\ApiKeyRequest;
use App\Http\Shared\Rules\IpAddressList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Partner portal: API & Webhooks (design: credentials, endpoints, allowed
 * IPs; webhook delivery logs arrive with Phase 7).
 */
class DeveloperController extends Controller
{
    public function show(Request $request): Response
    {
        $actor = $this->actor($request);
        abort_unless($actor->can('api_keys.view') || $actor->can('webhooks.view') || $actor->can('ip_rules.view'), 403);

        $partner = $this->partner($actor);

        return Inertia::render('partner/developers', [
            'partner' => ['name' => $partner->name, 'code' => $partner->code, 'api_version' => $partner->api_version, 'status' => $partner->status->value],
            'keys' => $actor->can('api_keys.view') ? $partner->apiKeys()
                ->whereIn('status', ['active', 'rotating'])
                ->orderByDesc('created_at')
                ->get()
                ->filter(fn (PartnerApiKey $key) => $key->isUsable())
                ->values()
                ->map(fn (PartnerApiKey $key) => [
                    'id' => $key->id,
                    'key_id' => $key->key_id,
                    'last4' => $key->secret_last4,
                    'status' => $key->status,
                    'expires_at' => $key->expires_at?->toIso8601String(),
                    'created_at' => $key->created_at->toIso8601String(),
                    'last_used_at' => $key->last_used_at?->toIso8601String(),
                ]) : null,
            'endpoints' => $actor->can('webhooks.view') ? $partner->only(['return_url', 'callback_url', 'payin_webhook_url', 'payout_webhook_url']) : null,
            'ips' => $actor->can('ip_rules.view') ? $partner->ipRules()->orderBy('created_at')->get()->map(fn (PartnerIpRule $rule) => $rule->display()) : null,
            'can' => [
                'issue_keys' => $actor->can('api_keys.create'),
                'revoke_keys' => $actor->can('api_keys.delete'),
                'update_endpoints' => $actor->can('webhooks.update'),
                'update_ips' => $actor->can('ip_rules.create') && $actor->can('ip_rules.delete'),
            ],
            'overlap_hours' => IssueApiKey::OVERLAP_HOURS,
        ]);
    }

    public function issueKey(ApiKeyRequest $request, IssueApiKey $issue): RedirectResponse
    {
        $partner = $this->partner($request->actor());
        $result = $issue->handle($request->actor(), $partner);

        Inertia::flash('credentials', [
            'partner' => $partner->name,
            'key_id' => $result['key']->key_id,
            'secret' => $result['secret'],
        ]);

        return back();
    }

    public function revokeKey(ApiKeyRequest $request, PartnerApiKey $key, RevokeApiKey $revoke): RedirectResponse
    {
        abort_unless($key->partner_id === $request->actor()->partner_id, 404);

        $revoke->handle($request->actor(), $key, $request->string('reason')->value());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Key :key revoked. API calls signed with it are refused from now on.', ['key' => $key->key_id])]);

        return back();
    }

    public function updateEndpoints(UpdateEndpointsRequest $request, ConfigurePartner $configure): RedirectResponse
    {
        $configure->handle($request->actor(), $this->partner($request->actor()), $request->validated(), null, null, null);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Endpoints saved.')]);

        return back();
    }

    public function updateIps(UpdateIpRulesRequest $request, SyncIpRules $sync): RedirectResponse
    {
        $sync->handle($request->actor(), $this->partner($request->actor()), IpAddressList::split($request->input('ip_addresses')));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Allowed IPs saved.')]);

        return back();
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }

    private function partner(User $actor): Partner
    {
        return Partner::query()->findOrFail($actor->partner_id);
    }
}
