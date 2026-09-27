<?php

namespace App\Http\Admin\Partners;

use App\Domain\Partner\Actions\IssueApiKey;
use App\Domain\Partner\Actions\RevokeApiKey;
use App\Domain\Partner\Models\Partner;
use App\Domain\Partner\Models\PartnerApiKey;
use App\Http\Controller;
use App\Http\Shared\Credentials\ApiKeyRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Admin generates / rotates / revokes a partner's API key (drawer, "API" tab).
 */
class PartnerKeyController extends Controller
{
    public function store(ApiKeyRequest $request, Partner $partner, IssueApiKey $issue): RedirectResponse
    {
        $result = $issue->handle($request->actor(), $partner);

        Inertia::flash('credentials', [
            'partner' => $partner->name,
            'key_id' => $result['key']->key_id,
            'secret' => $result['secret'],
        ]);

        return back();
    }

    public function destroy(ApiKeyRequest $request, Partner $partner, PartnerApiKey $key, RevokeApiKey $revoke): RedirectResponse
    {
        abort_unless($key->partner_id === $partner->id, 404);

        $revoke->handle($request->actor(), $key, $request->string('reason')->value());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Key :key revoked.', ['key' => $key->key_id])]);

        return back();
    }
}
