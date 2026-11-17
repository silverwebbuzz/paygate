<?php

namespace Tests\Feature\Platform;

use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\SystemRoles;
use App\Domain\Platform\Settings;
use App\Domain\SecurityReport\Jobs\RunSecurityScan;
use App\Domain\SecurityReport\Models\SecurityReportEntry;
use App\Domain\SecurityReport\Models\SecurityScan;
use App\Domain\SecurityReport\SecurityScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SecurityReportTest extends TestCase
{
    use RefreshDatabase;

    private User $super;

    protected function setUp(): void
    {
        parent::setUp();

        $this->super = User::factory()->admin()->withTwoFactor()->create();
    }

    public function test_only_super_admins_open_the_report()
    {
        $this->actingAs($this->super)->get(route('admin.security-report.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('admin/security-report')
                ->where('findings.0.id', 'F-1')
                ->where('groups.0.key', 'access')
                ->where('details.round', '1')
                ->where('latest', null));

        $ops = User::factory()->admin(SystemRoles::ADMIN_OPS)->withTwoFactor()->create();

        foreach (['index', 'preview', 'download'] as $route) {
            $this->actingAs($ops)->get(route('admin.security-report.'.$route))->assertForbidden();
        }

        $this->actingAs($ops)->post(route('admin.security-report.scan'))->assertForbidden();
        $this->actingAs($ops)->put(route('admin.security-report.mark', 'test.access.partner_ids'), ['status' => 'pass'])->assertForbidden();
    }

    public function test_statuses_and_notes_are_saved_per_kind()
    {
        $this->actingAs($this->super)
            ->put(route('admin.security-report.mark', 'test.access.partner_ids'), ['status' => 'pass', 'note' => ' Tried 3 IDs '])
            ->assertRedirect();

        $entry = SecurityReportEntry::query()->findOrFail('test.access.partner_ids');
        $this->assertSame('pass', $entry->status);
        $this->assertSame('Tried 3 IDs', $entry->note);
        $this->assertSame($this->super->id, $entry->updated_by);

        $this->actingAs($this->super)->put(route('admin.security-report.mark', 'finding.headers'), ['status' => 'verified'])->assertRedirect();
        $this->actingAs($this->super)->put(route('admin.security-report.mark', 'decision.partner_2fa'), ['status' => 'agreed'])->assertRedirect();

        // A test can't take a finding's status, and unknown keys don't exist.
        $this->actingAs($this->super)->put(route('admin.security-report.mark', 'test.access.partner_ids'), ['status' => 'verified'])->assertSessionHasErrors('status');
        $this->actingAs($this->super)->put(route('admin.security-report.mark', 'test.nope'), ['status' => 'pass'])->assertNotFound();
    }

    public function test_report_details_are_saved_as_a_setting()
    {
        $this->actingAs($this->super)->put(route('admin.security-report.details'), [
            'client' => 'Acme Payments', 'round' => '2', 'environment' => 'Live', 'prepared_by' => '', 'note' => 'Re-test after fixes.',
        ])->assertRedirect();

        $this->assertEquals(
            ['client' => 'Acme Payments', 'round' => '2', 'environment' => 'Live', 'prepared_by' => null, 'note' => 'Re-test after fixes.'],
            app(Settings::class)->get(Settings::SECURITY_REPORT),
        );
    }

    public function test_a_scan_is_queued_once_at_a_time()
    {
        Queue::fake();

        $this->actingAs($this->super)->post(route('admin.security-report.scan'))->assertRedirect();
        $this->actingAs($this->super)->post(route('admin.security-report.scan'))->assertRedirect();

        $this->assertSame(1, SecurityScan::query()->count());
        Queue::assertPushed(RunSecurityScan::class, 1);
        Queue::assertPushedOn('reports', RunSecurityScan::class);
    }

    public function test_the_scan_stores_results_and_performance()
    {
        Http::preventStrayRequests();
        Http::fake(fn ($request) => str_contains($request->url(), '/v1/payins')
            ? Http::response(['error' => ['code' => 'rate_limited']], 429)
            : Http::response('<html>ok</html>', 200, ['X-Frame-Options' => 'SAMEORIGIN']));
        config(['paygate.security_scan.load_requests' => 3, 'paygate.security_scan.load_concurrency' => 2]);

        $scan = SecurityScan::query()->create([
            'status' => 'queued', 'target' => [], 'requested_by' => $this->super->id, 'requested_at' => now(),
        ]);

        (new RunSecurityScan($scan->id))->handle(app(SecurityScanner::class));

        $scan->refresh();
        $this->assertSame('done', $scan->status);
        $this->assertSame(count($scan->results ?? []), $scan->passed + $scan->failed + $scan->warnings);
        $this->assertGreaterThan(40, count($scan->results ?? []));
        $this->assertSame(['Configuration', 'TLS', 'Headers', 'Cookies', 'Exposure', 'Access', 'Errors', 'API', 'Payment page', 'Login', 'Performance'], array_values(array_unique(array_column($scan->results ?? [], 'area'))));
        $this->assertSame(3, $scan->performance[0]['requests'] ?? null);
        $this->assertSame(0, $scan->performance[0]['failed'] ?? null);

        // The fake answers 200 to /.env: the scan must call that out.
        $env = collect($scan->results ?? [])->firstWhere('check', '/.env not public');
        $this->assertSame(['fail', 'HTTP 200: publicly readable'], [$env['status'] ?? null, $env['detail'] ?? null]);
    }

    public function test_preview_and_pdf_contain_the_report()
    {
        SecurityReportEntry::query()->create(['entry_key' => 'test.money.double_approve', 'status' => 'fail', 'note' => 'Second tab also succeeded', 'updated_by' => $this->super->id]);
        app(Settings::class)->set(Settings::SECURITY_REPORT, ['client' => 'Acme Payments', 'round' => '2'], $this->super);

        $this->actingAs($this->super)->get(route('admin.security-report.preview'))
            ->assertOk()
            ->assertSee('Security &amp; Performance Assessment', false)
            ->assertSee('Prepared for Acme Payments')
            ->assertSee('Second tab also succeeded')
            ->assertSee('No automated scan has been run yet.')
            ->assertSee('Fix the 1 failed manual test(s)');

        $pdf = $this->actingAs($this->super)->get(route('admin.security-report.download'))->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringContainsString('round-2', (string) $pdf->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', (string) $pdf->getContent());
    }
}
