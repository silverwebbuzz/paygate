<?php

namespace Tests\Feature\Reporting;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\SystemRoles;
use App\Domain\Reporting\Actions\ManageReportExports;
use App\Domain\Reporting\Jobs\GenerateReportExport;
use App\Domain\Reporting\Models\ReportExport;
use App\Domain\Reporting\Period;
use App\Domain\Transaction\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsPayinNetwork;
use Tests\TestCase;

class ReportingTest extends TestCase
{
    use BuildsPayinNetwork, RefreshDatabase;

    private User $admin;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Storage::fake('local');
        // Midday in India, so "today" holds everything created by the test.
        $this->travelTo(CarbonImmutable::parse('2026-09-28 12:00:00', 'Asia/Kolkata'));
        $this->buildNetwork();
        $this->activeAccount();
        $this->rate('partner', $this->partner->id, 'deposit', '6');
        $this->rate('branch', $this->branch->id, 'deposit', '4');
        $this->admin = User::factory()->admin()->withTwoFactor()->create();
        $this->operator = User::factory()->branch(SystemRoles::BRANCH_OPERATOR, $this->branch)->withTwoFactor()->create();

        foreach ([[1000000, '626812820491', 'approve'], [250000, '626812820492', 'approve'], [300000, '626812820493', 'decline']] as [$amount, $utr, $decision]) {
            $payin = $this->submittedPayin(['amount' => $amount], $utr);
            $this->actingAs($this->operator)->post(route("branch.deposits.{$decision}", $payin), $decision === 'approve' ? ['bank_utr' => $utr] : ['reason_code' => 'payment_not_found'])->assertSessionHasNoErrors();
        }

        $this->submittedPayin(['amount' => 120000], '626812820494'); // waiting for the branch
        // Periods end "now" (exclusive); the clock is frozen, so move on a minute.
        $this->travel(1)->minutes();
        Cache::flush();
    }

    private function kpis($page): array
    {
        return collect($page->toArray()['props']['metrics']['kpis'])->keyBy('key')->all();
    }

    public function test_the_admin_dashboard_shows_live_figures_for_the_period()
    {
        $this->actingAs($this->admin)->get(route('admin.dashboard'))
            ->assertInertia(function ($page) {
                $page->component('admin/dashboard')->where('period.range', 'today');
                $kpis = $this->kpis($page);
                $metrics = $page->toArray()['props']['metrics'];

                $this->assertSame(1250000, $kpis['volume']['value']);
                $this->assertSame(4, $kpis['transactions']['value']);
                $this->assertSame(2, $kpis['successful']['value']);
                $this->assertSame(1, $kpis['pending']['value']);
                $this->assertSame(1, $kpis['failed']['value']);
                // 6% vs 4%: the margin on ₹12,500 is ₹250.
                $this->assertSame(25000, $kpis['commission']['value']);
                // The branch owes ₹9,600 + ₹2,400; the platform owes the partner ₹9,400 + ₹2,350.
                $this->assertSame(1200000, $kpis['to_receive']['value']);
                $this->assertSame(1175000, $kpis['to_pay']['value']);

                $this->assertSame(['success' => 2, 'pending' => 1, 'failed' => 1], array_intersect_key($metrics['outcome'], array_flip(['success', 'pending', 'failed'])));
                $this->assertSame(66.7, $metrics['outcome']['rate']);
                $this->assertSame('hour', $metrics['chart']['bucket']);
                $this->assertSame(1250000, $metrics['chart']['payin_total']);
                $this->assertSame('1', collect($metrics['attention'])->firstWhere('key', 'deposits')['value']);
                $this->assertSame(1250000, $metrics['table']['rows'][0]['a']);
            });

        // Yesterday: nothing.
        $this->actingAs($this->admin)->get(route('admin.dashboard', ['range' => 'yesterday']))
            ->assertInertia(fn ($page) => $page->where('metrics.outcome.total', 0)->where('metrics.chart.payin_total', 0));
    }

    public function test_the_overview_counts_total_successful_and_failed_and_admins_can_filter()
    {
        $this->actingAs($this->admin)->get(route('admin.dashboard'))
            ->assertInertia(function ($page) {
                $overview = $page->toArray()['props']['overview'];

                $this->assertSame('payin', $overview['direction']);
                $this->assertSame([
                    'total_count' => 4, 'total_amount' => 1670000,
                    'success_count' => 2, 'success_amount' => 1250000,
                    'failed_count' => 1, 'failed_amount' => 300000,
                ], $overview['summary']);
                $this->assertSame(4, array_sum(array_column($overview['points'], 'total_count')));
                $this->assertSame(1250000, array_sum(array_column($overview['methods'], 'amount')));
                $this->assertCount(1, $page->toArray()['props']['options']['branches']);
            });

        // Another branch only: nothing. Payouts: nothing yet.
        $other = Branch::factory()->create();
        $this->actingAs($this->admin)->get(route('admin.dashboard', ['branches' => [$other->id]]))
            ->assertInertia(fn ($page) => $page->where('overview.summary.total_count', 0)->where('filters.branches', [$other->id]));
        $this->actingAs($this->admin)->get(route('admin.dashboard', ['partners' => [$this->partner->id], 'branches' => [$this->branch->id]]))
            ->assertInertia(fn ($page) => $page->where('overview.summary.total_count', 4));
        $this->actingAs($this->admin)->get(route('admin.dashboard', ['direction' => 'payout']))
            ->assertInertia(fn ($page) => $page->where('overview.direction', 'payout')->where('overview.summary.total_count', 0));

        // Partners see their own figures; filters and the lists are admin only.
        $partnerUser = User::factory()->partner(SystemRoles::PARTNER_OWNER, $this->partner)->withTwoFactor()->create();
        $this->actingAs($partnerUser)->get(route('partner.dashboard', ['branches' => [$other->id]]))
            ->assertInertia(fn ($page) => $page->where('overview.summary.total_count', 4)->where('filters.branches', [])->where('options', null));
    }

    public function test_partners_and_branches_see_their_own_side_only()
    {
        $partnerUser = User::factory()->partner(SystemRoles::PARTNER_OWNER, $this->partner)->withTwoFactor()->create();
        $this->actingAs($partnerUser)->get(route('partner.dashboard'))
            ->assertInertia(function ($page) {
                $kpis = $this->kpis($page);
                $this->assertSame(75000, $kpis['commission']['value']); // fees paid
                $this->assertSame(1175000, $kpis['balance']['value']);
                $this->assertArrayNotHasKey('to_receive', $kpis);
                $this->assertSame('Recent pay-ins', $page->toArray()['props']['metrics']['table']['title']);
            });

        $owner = User::factory()->branch(SystemRoles::BRANCH_OWNER, $this->branch)->withTwoFactor()->create();
        $this->actingAs($owner)->get(route('branch.dashboard'))
            ->assertInertia(function ($page) {
                $kpis = $this->kpis($page);
                $this->assertSame(1250000, $kpis['deposits']['value']);
                $this->assertSame(50000, $kpis['commission']['value']);
                $this->assertSame(-1200000, $kpis['balance']['value']);
                $this->assertSame('Account usage · today', $page->toArray()['props']['metrics']['table']['title']);
            });

        // No balances.view: the money-owed figures are left out.
        $this->actingAs($this->operator)->get(route('branch.dashboard'))
            ->assertInertia(fn ($page) => $this->assertArrayNotHasKey('balance', $this->kpis($page)));
    }

    public function test_periods_follow_business_days()
    {
        $now = CarbonImmutable::parse('2026-09-28 01:30:00', 'Asia/Kolkata');

        $yesterday = Period::resolve('yesterday', now: $now);
        $this->assertSame('2026-09-26T18:30:00+00:00', $yesterday->from->toIso8601String());
        $this->assertSame('2026-09-27T18:30:00+00:00', $yesterday->to->toIso8601String());
        $this->assertSame('hour', $yesterday->bucket());
        $this->assertSame('day', Period::resolve('30d', now: $now)->bucket());
        $this->assertSame('2026-08-31T18:30:00+00:00', Period::resolve('custom', '2026-09-01', '2026-09-30')->from->toIso8601String());
        // Date and time: from that minute up to the end of the "to" minute.
        $minutes = Period::resolve('custom', '2026-09-25T09:00', '2026-09-25T17:30');
        $this->assertSame('2026-09-25T03:30:00+00:00', $minutes->from->toIso8601String());
        $this->assertSame('2026-09-25T12:01:00+00:00', $minutes->to->toIso8601String());

        $this->expectException(ValidationException::class);
        Period::resolve('custom', '2026-09-30', '2026-09-01');
    }

    public function test_reports_show_each_portal_its_own_columns_and_rows()
    {
        $this->actingAs($this->admin)->get(route('admin.reports.index', ['report' => 'payins', 'range' => 'today']))
            ->assertInertia(fn ($page) => $page->component('reports/index')
                ->has('catalog', 8)
                ->has('rows', 4)
                ->where('totals._count', 4)
                ->where('totals.amount', 1670000)
                ->where('totals.platform_margin', 25000));

        $partnerUser = User::factory()->partner(SystemRoles::PARTNER_OWNER, $this->partner)->withTwoFactor()->create();
        $this->actingAs($partnerUser)->get(route('partner.reports.index', ['report' => 'payins', 'range' => 'today']))
            ->assertInertia(function ($page) {
                $columns = array_column($page->toArray()['props']['report']['columns'], 'key');
                $this->assertNotContains('branch', $columns);
                $this->assertNotContains('branch_commission', $columns);
                $this->assertNotContains('platform_margin', $columns);
                $this->assertContains('partner_commission', $columns);
                $this->assertSame(['payins', 'payouts', 'commission', 'balances', 'settlements'], array_column($page->toArray()['props']['catalog'], 'key'));
            });

        $owner = User::factory()->branch(SystemRoles::BRANCH_OWNER, $this->branch)->withTwoFactor()->create();
        $this->actingAs($owner)->get(route('branch.reports.index', ['report' => 'pairs', 'range' => 'today']))
            ->assertInertia(fn ($page) => $page->has('rows', 1)
                ->where('rows.0.partner', $this->partner->code)
                ->where('rows.0.branch_commission', 50000)
                ->where('rows.0.branch_position', -1200000)
                // The partner's side never reaches a branch.
                ->missing('rows.0.partner_position')
                ->missing('rows.0.partner_commission')
                ->missing('totals.partner_commission'));
        // Reports a branch doesn't have fall back to its first one.
        $this->actingAs($owner)->get(route('branch.reports.index', ['report' => 'partners']))->assertInertia(fn ($page) => $page->where('report.key', 'payins'));
    }

    public function test_exports_are_prepared_downloaded_by_their_owner_only_and_deleted_after_seven_days()
    {
        Transaction::query()->where('customer_utr_normalized', '626812820491')->update(['partner_transaction_id' => '=HYPERLINK("x")']);

        $this->actingAs($this->admin)->post(route('admin.reports.export'), ['report' => 'payins', 'format' => 'csv', 'range' => 'today'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('admin.reports.export'), ['report' => 'partners', 'format' => 'xlsx', 'range' => 'today'])->assertSessionHasNoErrors();

        // Queued on `reports`; run them as the worker would.
        Queue::assertPushedOn('reports', GenerateReportExport::class);
        foreach (ReportExport::pluck('id') as $id) {
            app()->call([new GenerateReportExport($id), 'handle']);
        }

        $csv = ReportExport::where('format', 'csv')->sole();
        $this->assertSame('ready', $csv->status, (string) $csv->error);
        $this->assertSame(4, $csv->rows);
        $content = Storage::disk('local')->get($csv->file->path);
        $this->assertStringContainsString('Transaction,"Order id",Created', $content);
        $this->assertStringContainsString("'=HYPERLINK", $content); // never a formula
        $this->assertStringContainsString('10000.00', $content);
        $this->assertSame('ready', ReportExport::where('format', 'xlsx')->sole()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'report.exported', 'actor_id' => $this->admin->id]);

        $this->actingAs($this->admin)->get(route('files.show', $csv->file_id))->assertOk()->assertHeader('Content-Disposition');
        $other = User::factory()->admin()->withTwoFactor()->create();
        $this->actingAs($other)->get(route('files.show', $csv->file_id))->assertNotFound();

        // Viewers may look but not export.
        $viewer = User::factory()->partner(SystemRoles::PARTNER_VIEWER, $this->partner)->withTwoFactor()->create();
        $this->actingAs($viewer)->post(route('partner.reports.export'), ['report' => 'payins', 'format' => 'csv'])->assertForbidden();

        $path = $csv->file->path;
        $this->travel(8)->days();
        $this->assertSame(2, app(ManageReportExports::class)->prune());
        $this->assertSame('expired', $csv->refresh()->status);
        $this->assertNull($csv->file_id);
        Storage::disk('local')->assertMissing($path);
    }
}
