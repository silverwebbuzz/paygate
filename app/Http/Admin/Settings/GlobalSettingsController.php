<?php

namespace App\Http\Admin\Settings;

use App\Domain\Core\Identity\Models\User;
use App\Domain\Platform\Settings;
use App\Http\Controller;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Global Settings (Admin). Phase 10 adds the settlement cut-off (G-09:
 * the time and timezone at which the daily settlement day ends); the rest
 * of the page comes with Phase 12.
 */
class GlobalSettingsController extends Controller
{
    public function index(Settings $settings): Response
    {
        Gate::authorize('settings.view');

        return Inertia::render('admin/settings', [
            'settlement' => [
                ...$settings->settlementCutoff(),
                'last_cutoff' => $settings->lastCutoff()->toIso8601String(),
            ],
            'timezones' => DateTimeZone::listIdentifiers(),
        ]);
    }

    public function updateSettlement(Request $request, Settings $settings): RedirectResponse
    {
        Gate::authorize('settings.update');

        $data = $request->validate([
            'timezone' => ['required', Rule::in(DateTimeZone::listIdentifiers())],
            'time' => ['required', 'date_format:H:i'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $settings->set(Settings::SETTLEMENT_CUTOFF, ['timezone' => $data['timezone'], 'time' => $data['time']], $actor);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Settlement day now ends at :time (:zone). It applies from the next cut-off.', ['time' => $data['time'], 'zone' => $data['timezone']])]);

        return back();
    }
}
