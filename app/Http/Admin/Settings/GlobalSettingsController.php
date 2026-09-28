<?php

namespace App\Http\Admin\Settings;

use App\Domain\Core\Identity\Models\User;
use App\Domain\Platform\Actions\ManagePlatformContent;
use App\Domain\Platform\Models\ReasonCode;
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
 * Global Settings (Admin, decided list G-48): the settlement cut-off
 * (G-09), the deposit-waiting alert threshold (G-47), the support contact
 * on the customer payment page, and the decline / fail reasons. Content
 * pages have their own screen (PageController).
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
            'alert_settings' => ['deposit_wait_minutes' => $settings->depositWaitMinutes()],
            'checkout' => $settings->checkoutSupport(),
            'reasons' => ReasonCode::query()->orderBy('context')->orderBy('sort')->get(['id', 'context', 'code', 'label', 'is_active', 'sort']),
            'can' => ['update' => request()->user()?->can('settings.update') ?? false],
        ]);
    }

    public function updateAlerts(Request $request, Settings $settings): RedirectResponse
    {
        Gate::authorize('settings.update');

        $data = $request->validate(['deposit_wait_minutes' => ['required', 'integer', 'min:5', 'max:1440']]);
        $settings->set(Settings::DEPOSIT_WAIT_MINUTES, (int) $data['deposit_wait_minutes'], $this->actor($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Alert settings saved.')]);

        return back();
    }

    public function updateCheckout(Request $request, Settings $settings): RedirectResponse
    {
        Gate::authorize('settings.update');

        $data = $request->validate([
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\-\s()]{6,30}$/'],
        ]);
        $settings->set(Settings::CHECKOUT_SUPPORT, ['email' => $data['email'] ?? null, 'phone' => $data['phone'] ?? null], $this->actor($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Payment page support contact saved.')]);

        return back();
    }

    public function saveReason(Request $request, ManagePlatformContent $content, ?ReasonCode $reason = null): RedirectResponse
    {
        Gate::authorize('settings.update');

        $data = $request->validate([
            'context' => [$reason === null ? 'required' : 'prohibited', Rule::in(['payin_reject', 'payout_reject'])],
            'code' => [$reason === null ? 'required' : 'prohibited', 'string', 'max:50', 'regex:/^[a-z][a-z0-9_]*$/'],
            'label' => ['required', 'string', 'max:150'],
            'is_active' => ['boolean'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:999'],
        ]);

        $content->saveReason(
            $this->actor($request),
            $reason->context ?? (string) $data['context'],
            $reason,
            ['label' => $data['label'], 'is_active' => (bool) ($data['is_active'] ?? true), 'sort' => (int) ($data['sort'] ?? $reason->sort ?? 50)],
            $data['code'] ?? null,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Reason saved.')]);

        return back();
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }

    public function updateSettlement(Request $request, Settings $settings): RedirectResponse
    {
        Gate::authorize('settings.update');

        $data = $request->validate([
            'timezone' => ['required', Rule::in(DateTimeZone::listIdentifiers())],
            'time' => ['required', 'date_format:H:i'],
        ]);

        $settings->set(Settings::SETTLEMENT_CUTOFF, ['timezone' => $data['timezone'], 'time' => $data['time']], $this->actor($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Settlement day now ends at :time (:zone). It applies from the next cut-off.', ['time' => $data['time'], 'zone' => $data['timezone']])]);

        return back();
    }
}
