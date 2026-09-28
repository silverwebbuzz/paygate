<?php

namespace Tests\Feature\Platform;

use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\SystemRoles;
use App\Domain\Platform\Models\ReasonCode;
use App\Domain\Platform\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsPayinNetwork;
use Tests\TestCase;

class PlatformAdministrationTest extends TestCase
{
    use BuildsPayinNetwork, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->buildNetwork();
        $this->activeAccount();
        $this->admin = User::factory()->admin()->withTwoFactor()->create();
    }

    public function test_global_settings_hold_alerts_the_payment_page_contact_and_reasons()
    {
        $this->actingAs($this->admin)->put(route('admin.settings.alerts'), ['deposit_wait_minutes' => 3])->assertSessionHasErrors('deposit_wait_minutes');
        $this->actingAs($this->admin)->put(route('admin.settings.alerts'), ['deposit_wait_minutes' => 45])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->put(route('admin.settings.checkout'), ['email' => 'help@paygate.example', 'phone' => '+91 98765 43210'])->assertSessionHasNoErrors();

        $settings = app(Settings::class);
        $this->assertSame(45, $settings->depositWaitMinutes());
        $this->assertSame(['email' => 'help@paygate.example', 'phone' => '+91 98765 43210'], $settings->checkoutSupport());

        // The customer payment page shows the contact.
        [, $token] = $this->createPayin();
        $this->get("http://pay.paygate.local/p/{$token}")->assertInertia(fn ($page) => $page->where('support.email', 'help@paygate.example'));

        // Reasons: add one, rename one; "other" can't be switched off.
        $this->actingAs($this->admin)->post(route('admin.settings.reasons.store'), ['context' => 'payin_reject', 'code' => 'customer_cancelled', 'label' => 'Customer cancelled', 'is_active' => true])->assertSessionHasNoErrors();
        $this->assertArrayHasKey('customer_cancelled', ReasonCode::options('payin_reject'));
        $other = ReasonCode::where(['context' => 'payin_reject', 'code' => 'other'])->sole();
        $this->actingAs($this->admin)->put(route('admin.settings.reasons.update', $other), ['label' => 'Other', 'is_active' => false])->assertSessionHasErrors('is_active');
        $invalid = ReasonCode::where(['context' => 'payin_reject', 'code' => 'invalid_utr'])->sole();
        $this->actingAs($this->admin)->put(route('admin.settings.reasons.update', $invalid), ['label' => 'UTR not valid', 'is_active' => false])->assertSessionHasNoErrors();
        $this->assertArrayNotHasKey('invalid_utr', ReasonCode::options('payin_reject'));

        $viewer = User::factory()->admin(SystemRoles::ADMIN_VIEWER)->withTwoFactor()->create();
        $this->actingAs($viewer)->put(route('admin.settings.alerts'), ['deposit_wait_minutes' => 60])->assertForbidden();
    }

    public function test_published_pages_are_public_and_never_run_html()
    {
        $this->actingAs($this->admin)->post(route('admin.pages.store'), [
            'title' => 'Terms of use', 'body' => "# Terms\n\nBe nice.<script>alert(1)</script>", 'status' => 'published',
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('admin.pages.store'), ['title' => 'Draft notes', 'body' => 'x', 'status' => 'draft'])->assertSessionHasNoErrors();

        auth()->logout();
        $this->get(route('legal.show', 'terms-of-use'))
            ->assertInertia(fn ($page) => $page->component('legal/show')->where('title', 'Terms of use')->where('html', fn ($html) => str_contains($html, '<h1>Terms</h1>') && ! str_contains($html, '<script>')));
        $this->get(route('legal.show', 'draft-notes'))->assertNotFound();
        $this->get('http://pay.paygate.local/legal/terms-of-use')->assertOk();
    }

    public function test_ip_management_lists_every_partners_allow_list()
    {
        $this->actingAs($this->admin)->get(route('admin.ip-management.index'))
            ->assertInertia(fn ($page) => $page->component('admin/ip-management')
                ->where('partners.0.code', $this->partner->code)
                ->where('partners.0.rules.0', '127.0.0.1/32'));
    }

    public function test_audit_logs_admin_sees_all_and_a_branch_only_its_users()
    {
        $owner = User::factory()->branch(SystemRoles::BRANCH_OWNER, $this->branch)->withTwoFactor()->create();
        AuditLog::record('branch.did.something', null, [], ['x' => 1], $owner);
        AuditLog::record('admin.did.something', null, [], ['y' => 2], $this->admin);

        $this->actingAs($this->admin)->get(route('admin.audit-logs.index', ['search' => 'did.something']))
            ->assertInertia(fn ($page) => $page->component('audit-logs/index')->has('items.data', 2));
        $this->actingAs($this->admin)->get(route('admin.audit-logs.index', ['tab' => 'security']))->assertInertia(fn ($page) => $page->where('tab', 'security'));

        $this->actingAs($owner)->get(route('branch.audit-logs.index', ['search' => 'did.something', 'tab' => 'security']))
            ->assertInertia(fn ($page) => $page->where('tab', 'activity')->has('items.data', 1)->where('items.data.0.action', 'branch.did.something'));
    }
}
