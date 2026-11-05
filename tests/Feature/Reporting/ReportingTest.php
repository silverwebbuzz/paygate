<?php

namespace Tests\Feature\Reporting;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\SystemRoles;
use App\Domain\Reporting\Actions\ManageReportExports;
use App\Domain\Reporting\Jobs\GenerateReportExport;
use App\Domain\Reporting\Models\ReportExport;
use App\Domain\Reporting\Period;
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

    public function test_each_portal_gets_only_its_own_deposit_and_withdrawal_reports()
    {
        // /reports opens the first report; old ?report= links still land on theirs.
        $this->actingAs($this->admin)->get(route('admin.reports.index', ['range' => 'today']))
            ->assertRedirect(route('admin.reports.show', ['report' => 'branch_deposits', 'range' => 'today']));
        $this->actingAs($this->admin)->get(route('admin.reports.index', ['report' => 'partner_withdrawals']))
            ->assertRedirect(route('admin.reports.show', ['report' => 'partner_withdrawals']));
        foreach (['branch_deposits', 'partner_deposits', 'branch_withdrawals', 'partner_withdrawals'] as $key) {
            $this->actingAs($this->admin)->get(route('admin.reports.show', ['report' => $key]))->assertOk();
        }
        $this->actingAs($this->admin)->get(route('admin.reports.show', ['report' => 'branch_deposits', 'range' => 'today']))
            ->assertInertia(fn ($page) => $page->component('reports/index')
                ->where('report.key', 'branch_deposits')
                ->where('report.filters', ['branch' => 'Branch', 'search' => 'Search']));
        $this->actingAs($this->admin)->get(route('admin.reports.show', ['report' => 'payins']))->assertNotFound();

        $partnerUser = User::factory()->partner(SystemRoles::PARTNER_OWNER, $this->partner)->withTwoFactor()->create();
        $this->actingAs($partnerUser)->get(route('partner.reports.index'))
            ->assertRedirect(route('partner.reports.show', ['report' => 'partner_deposits']));
        $this->actingAs($partnerUser)->get(route('partner.reports.show', ['report' => 'partner_withdrawals']))->assertOk();
        $this->actingAs($partnerUser)->get(route('partner.reports.show', ['report' => 'partner_deposits', 'range' => 'today']))
            ->assertInertia(fn ($page) => $page
                ->where('report.key', 'partner_deposits')
                ->where('report.filters', [])
                ->where('rows', [[
                    'sr' => 1,
                    'name' => $this->partner->name,
                    'amount' => 1250000,
                    'rate' => 6,
                    'commission' => 75000,
                ]]));
        // A partner never gets the branch reports (they show branch commission).
        $this->actingAs($partnerUser)->get(route('partner.reports.show', ['report' => 'branch_deposits']))->assertNotFound();
        $this->actingAs($partnerUser)->post(route('partner.reports.export'), ['report' => 'branch_deposits', 'format' => 'csv'])
            ->assertSessionHasErrors('report');

        $owner = User::factory()->branch(SystemRoles::BRANCH_OWNER, $this->branch)->withTwoFactor()->create();
        $this->actingAs($owner)->get(route('branch.reports.index'))
            ->assertRedirect(route('branch.reports.show', ['report' => 'branch_deposits']));
        $this->actingAs($owner)->get(route('branch.reports.show', ['report' => 'branch_withdrawals']))->assertOk();
        $this->actingAs($owner)->get(route('branch.reports.show', ['report' => 'branch_deposits', 'range' => 'today']))
            ->assertInertia(fn ($page) => $page
                ->where('rows.0.name', $this->branch->name)
                ->where('rows.0.commission', 50000)
                ->has('rows', 1));
        // Nor a branch the partner ones (they show partner fees).
        $this->actingAs($owner)->get(route('branch.reports.show', ['report' => 'partner_deposits']))->assertNotFound();

        // Another branch's figures never reach this one.
        $other = User::factory()->branch(SystemRoles::BRANCH_OWNER, Branch::factory()->create())->withTwoFactor()->create();
        $this->actingAs($other)->get(route('branch.reports.show', ['report' => 'branch_deposits', 'range' => 'today']))
            ->assertInertia(fn ($page) => $page->where('rows', []));
    }

    public function test_exports_are_prepared_downloaded_by_their_owner_only_and_deleted_after_seven_days()
    {
        $this->branch->forceFill(['name' => '=HYPERLINK("x")'])->save();

        $this->actingAs($this->admin)->post(route('admin.reports.export'), ['report' => 'branch_deposits', 'format' => 'csv', 'range' => 'today'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('admin.reports.export'), ['report' => 'partner_deposits', 'format' => 'xlsx', 'range' => 'today'])->assertSessionHasNoErrors();

        // Queued on `reports`; run them as the worker would.
        Queue::assertPushedOn('reports', GenerateReportExport::class);
        foreach (ReportExport::pluck('id') as $id) {
            app()->call([new GenerateReportExport($id), 'handle']);
        }

        $csv = ReportExport::where('format', 'csv')->sole();
        $this->assertSame('ready', $csv->status, (string) $csv->error);
        $this->assertSame(1, $csv->rows);
        $content = Storage::disk('local')->get($csv->file->path);
        $this->assertStringContainsString('Sr,"Branch Name","Total Amount",Commission,"Total Commission"', $content);
        $this->assertStringContainsString("'=HYPERLINK", $content); // never a formula
        $this->assertStringContainsString('12500.00', $content);
        $this->assertSame('ready', ReportExport::where('format', 'xlsx')->sole()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'report.exported', 'actor_id' => $this->admin->id]);

        $this->actingAs($this->admin)->get(route('files.show', $csv->file_id))->assertOk()->assertHeader('Content-Disposition');
        $other = User::factory()->admin()->withTwoFactor()->create();
        $this->actingAs($other)->get(route('files.show', $csv->file_id))->assertNotFound();

        // Viewers may look but not export.
        $viewer = User::factory()->partner(SystemRoles::PARTNER_VIEWER, $this->partner)->withTwoFactor()->create();
        $this->actingAs($viewer)->post(route('partner.reports.export'), ['report' => 'partner_deposits', 'format' => 'csv'])->assertForbidden();

        $path = $csv->file->path;
        $this->travel(8)->days();
        $this->assertSame(2, app(ManageReportExports::class)->prune());
        $this->assertSame('expired', $csv->refresh()->status);
        $this->assertNull($csv->file_id);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_the_deposit_and_withdrawal_reports_list_each_branch_and_partner_with_its_rate()
    {
        $this->actingAs($this->admin)->get(route('admin.reports.show', ['report' => 'branch_deposits']))
            ->assertInertia(fn ($page) => $page
                ->where('period.range', 'today')
                ->where('rows', [[
                    'sr' => 1,
                    'name' => $this->branch->name,
                    'amount' => 1250000,
                    'rate' => 4,
                    'commission' => 50000,
                ]])
                ->where('totals._count', 1)
                ->where('totals.amount', 1250000)
                ->missing('totals.sr'));

        $this->actingAs($this->admin)->get(route('admin.reports.show', ['report' => 'partner_deposits']))
            ->assertInertia(fn ($page) => $page->where('rows.0.rate', 6)->where('rows.0.commission', 75000));

        // Search by name, the branch filter, and no withdrawals in this period.
        // Rows show names only, but the code still finds a branch.
        $this->actingAs($this->admin)->get(route('admin.reports.show', ['report' => 'branch_deposits', 'search' => $this->branch->code]))
            ->assertInertia(fn ($page) => $page->has('rows', 1));
        $this->actingAs($this->admin)->get(route('admin.reports.show', ['report' => 'branch_deposits', 'search' => 'no such branch']))
            ->assertInertia(fn ($page) => $page->where('rows', []));
        $this->actingAs($this->admin)->get(route('admin.reports.show', ['report' => 'branch_deposits', 'branch' => Branch::factory()->create()->id]))
            ->assertInertia(fn ($page) => $page->where('rows', []));
        $this->actingAs($this->admin)->get(route('admin.reports.show', ['report' => 'branch_withdrawals']))
            ->assertInertia(fn ($page) => $page->where('rows', []));
        $this->actingAs($this->admin)->get(route('admin.reports.show', ['report' => 'partner_deposits', 'range' => 'yesterday']))
            ->assertInertia(fn ($page) => $page->where('rows', []));
    }
}
