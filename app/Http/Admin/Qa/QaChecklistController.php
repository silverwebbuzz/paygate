<?php

namespace App\Http\Admin\Qa;

use App\Domain\Core\Identity\Models\User;
use App\Domain\Partner\Models\Partner;
use App\Domain\Qa\Actions\ManageQaChecklist;
use App\Domain\Qa\Checklist;
use App\Domain\Qa\Models\QaResult;
use App\Domain\Qa\TestRunner;
use App\Http\Controller;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin › QA Checklist (super admins only; every environment, config paygate.qa.enabled):
 * every feature with where it is, how to test it and who to log in as; the
 * tester's Pass / Fail with a note; the latest automated result with a
 * Re-run button (not on production: TestRunner::available); and test
 * pay-ins / payouts for the manual checks.
 */
class QaChecklistController extends Controller
{
    public function index(TestRunner $runner): Response
    {
        $this->ensureEnabled();

        $sections = array_map(fn (array $section) => [
            ...$section,
            'items' => array_map(fn (array $item) => [...$item, 'address' => Checklist::address($item['url'])], $section['items']),
        ], Checklist::sections());

        return Inertia::render('admin/qa-checklist', [
            'sections' => $sections,
            'logins' => Checklist::LOGINS,
            'results' => $this->results(),
            'runner' => ['problem' => $runner->available()],
            'partners' => Partner::query()->where('status', 'active')->orderBy('code')->get(['id', 'code', 'name']),
            'created' => session('qa_created'),
        ]);
    }

    public function mark(Request $request, string $key, ManageQaChecklist $qa): RedirectResponse
    {
        $this->ensureEnabled();

        $data = $request->validate([
            'status' => ['nullable', Rule::in(['pass', 'fail'])],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $qa->mark($this->actor($request), $key, $data['status'] ?? null, $data['note'] ?? null);

        return back();
    }

    public function run(Request $request, ManageQaChecklist $qa, TestRunner $runner): RedirectResponse
    {
        $this->ensureEnabled();

        if (($problem = $runner->available()) !== null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Automated tests can’t run here: :problem', ['problem' => $problem])]);

            return back();
        }

        $data = $request->validate(['key' => ['nullable', 'string', 'max:80']]);
        $count = $qa->run($this->actor($request), $data['key'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => $count === 1 ? __('Tests queued.') : __(':count items queued: the whole suite runs once.', ['count' => $count])]);

        return back();
    }

    public function sample(Request $request, ManageQaChecklist $qa): RedirectResponse
    {
        $this->ensureEnabled();

        $data = $request->validate([
            'kind' => ['required', Rule::in(['payin', 'payout'])],
            'partner_id' => ['required', 'uuid', Rule::exists('partners', 'id')],
            'amount' => ['required', 'string', Money::RUPEES_RULE],
        ]);
        /** @var Partner $partner */
        $partner = Partner::query()->findOrFail((string) $data['partner_id']);
        $amount = Money::toPaise($data['amount']);

        $created = $data['kind'] === 'payin'
            ? ['kind' => 'payin', ...$qa->createPayin($partner, $amount)]
            : ['kind' => 'payout', ...$qa->createPayout($partner, $amount)];

        return back()->with('qa_created', $created);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function results(): array
    {
        $results = [];

        foreach (QaResult::query()->with('tester:id,name')->get() as $result) {
            $results[$result->check_key] = [
                'manual_status' => $result->manual_status,
                'manual_note' => $result->manual_note,
                'tested_by' => $result->tester?->name,
                'tested_at' => $result->tested_at?->toIso8601String(),
                // A run that never finished (worker stopped) shows as stuck so it can be started again.
                'auto_status' => in_array($result->auto_status, ['queued', 'running'], true) && ! $result->isRunning() ? 'stuck' : $result->auto_status,
                'auto_summary' => $result->auto_summary,
                'auto_tests' => $result->auto_tests,
                'auto_output' => $result->auto_output,
                'auto_requested_at' => $result->auto_requested_at?->toIso8601String(),
                'auto_finished_at' => $result->auto_finished_at?->toIso8601String(),
            ];
        }

        return $results;
    }

    private function ensureEnabled(): void
    {
        abort_unless((bool) config('paygate.qa.enabled'), 404);
        abort_unless(request()->user()?->isSuperAdmin() === true, 403);
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
