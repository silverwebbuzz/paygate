<?php

namespace Tests\Feature\Partners;

use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\SystemRoles;
use App\Domain\Partner\Actions\IssueApiKey;
use App\Domain\Partner\Models\Partner;
use App\Domain\Partner\Models\PartnerApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ApiCredentialsTest extends TestCase
{
    use RefreshDatabase;

    private function issue(Partner $partner): PartnerApiKey
    {
        $admin = User::factory()->admin()->create();

        return app(IssueApiKey::class)->handle($admin, $partner)['key'];
    }

    public function test_rotation_keeps_the_old_key_working_for_the_overlap_window()
    {
        $partner = Partner::factory()->create();
        $first = $this->issue($partner);
        $second = $this->issue($partner);

        $first->refresh();
        $this->assertSame('rotating', $first->status);
        $this->assertTrue($first->isUsable());
        $this->assertSame('active', $second->status);

        $this->travel(IssueApiKey::OVERLAP_HOURS)->hours();
        $this->travel(1)->minutes();
        $this->assertFalse($first->fresh()?->isUsable());

        // A third rotation retires the one still overlapping.
        $this->issue($partner);
        $this->assertSame('revoked', $first->fresh()?->status);
        $this->assertSame('rotating', $second->fresh()?->status);
        $this->assertSame(1, $partner->apiKeys()->where('status', 'active')->count());
    }

    public function test_generating_a_key_needs_the_persons_password()
    {
        $partner = Partner::factory()->create();
        $admin = User::factory()->admin()->withTwoFactor()->create();

        $this->actingAs($admin)->post(route('admin.partners.api-keys.store', $partner), ['password' => 'wrong'])
            ->assertSessionHasErrors('password');
        $this->assertSame(0, $partner->apiKeys()->count());

        $this->actingAs($admin)->post(route('admin.partners.api-keys.store', $partner), ['password' => 'password'])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('credentials.secret');
        $this->assertSame(1, $partner->apiKeys()->count());
    }

    public function test_revoking_needs_password_and_reason_and_is_audited()
    {
        $partner = Partner::factory()->create();
        $key = $this->issue($partner);
        $admin = User::factory()->admin()->withTwoFactor()->create();

        $this->actingAs($admin)->delete(route('admin.partners.api-keys.destroy', [$partner, $key]), ['password' => 'password'])
            ->assertSessionHasErrors('reason');

        $this->actingAs($admin)->delete(route('admin.partners.api-keys.destroy', [$partner, $key]), ['password' => 'password', 'reason' => 'Leaked in a support ticket'])
            ->assertSessionHasNoErrors();

        $this->assertSame('revoked', $key->fresh()?->status);
        $this->assertFalse($key->fresh()?->isUsable());
        $this->assertDatabaseHas('audit_logs', ['action' => 'api_key.revoked', 'subject_id' => $partner->id, 'actor_id' => $admin->id]);
    }

    public function test_rotating_a_key_is_audited()
    {
        $partner = Partner::factory()->create();
        $admin = User::factory()->admin()->withTwoFactor()->create();

        $this->actingAs($admin)->post(route('admin.partners.api-keys.store', $partner), ['password' => 'password'])
            ->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('admin.partners.api-keys.store', $partner), ['password' => 'password'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('audit_logs', ['action' => 'api_key.issued', 'subject_id' => $partner->id, 'actor_id' => $admin->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'api_key.rotated', 'subject_id' => $partner->id, 'actor_id' => $admin->id]);
        $this->assertSame(1, $partner->apiKeys()->where('status', 'active')->count());
        $this->assertSame(1, $partner->apiKeys()->where('status', 'rotating')->count());
    }

    public function test_a_key_of_another_partner_cannot_be_revoked_through_this_partner()
    {
        $partner = Partner::factory()->create();
        $other = $this->issue(Partner::factory()->create());
        $admin = User::factory()->admin()->withTwoFactor()->create();

        $this->actingAs($admin)->delete(route('admin.partners.api-keys.destroy', [$partner, $other]), ['password' => 'password', 'reason' => 'x'])
            ->assertNotFound();
    }

    public function test_partner_developers_manage_their_own_keys_and_never_see_secrets()
    {
        $partner = Partner::factory()->create();
        $key = $this->issue($partner);
        $developer = User::factory()->partner(SystemRoles::PARTNER_DEVELOPER, $partner)->create();

        $this->actingAs($developer)->get(route('partner.developers.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('partner/developers')
                ->where('keys.0.key_id', $key->key_id)
                ->missing('keys.0.secret')
                ->missing('keys.0.secret_encrypted'));

        $this->actingAs($developer)->post(route('partner.developers.api-keys.store'), ['password' => 'password'])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('credentials.secret');
        $this->assertSame('rotating', $key->fresh()?->status);
    }

    public function test_partners_cannot_touch_another_partners_keys()
    {
        $mine = Partner::factory()->create();
        $theirs = $this->issue(Partner::factory()->create());
        $owner = User::factory()->partner(SystemRoles::PARTNER_OWNER, $mine)->create();

        $this->actingAs($owner)->delete(route('partner.developers.api-keys.destroy', $theirs), ['password' => 'password', 'reason' => 'x'])
            ->assertNotFound();
        $this->assertSame('active', $theirs->fresh()?->status);
    }

    public function test_partner_viewers_cannot_open_the_developer_page()
    {
        $viewer = User::factory()->partner(SystemRoles::PARTNER_VIEWER)->create();

        $this->actingAs($viewer)->get(route('partner.developers.show'))->assertForbidden();
        $this->actingAs($viewer)->post(route('partner.developers.api-keys.store'), ['password' => 'password'])->assertForbidden();
    }

    public function test_partners_update_their_endpoints_and_ips()
    {
        $partner = Partner::factory()->create();
        $owner = User::factory()->partner(SystemRoles::PARTNER_OWNER, $partner)->create();

        $this->actingAs($owner)->put(route('partner.developers.endpoints'), [
            'payin_webhook_url' => 'https://merchant.example/hooks',
            'return_url' => '',
        ])->assertSessionHasNoErrors();
        $this->assertSame('https://merchant.example/hooks', $partner->fresh()?->payin_webhook_url);

        $this->actingAs($owner)->put(route('partner.developers.ip-rules'), ['ip_addresses' => "1.2.3.4\n2001:db8::1"])
            ->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing(['1.2.3.4/32', '2001:db8::1/128'], $partner->ipRules()->pluck('cidr')->all());
        $this->assertDatabaseHas('audit_logs', ['action' => 'partner.ip_rules_updated', 'subject_id' => $partner->id, 'actor_id' => $owner->id]);
    }

    public function test_business_profile_shows_own_rates_only_to_finance_roles()
    {
        $partner = Partner::factory()->create();

        $this->actingAs(User::factory()->partner(SystemRoles::PARTNER_OWNER, $partner)->create())
            ->get(route('partner.profile'))
            ->assertInertia(fn (Assert $page) => $page->component('partner/profile')->has('rates'));

        $this->actingAs(User::factory()->partner(SystemRoles::PARTNER_DEVELOPER, $partner)->create())
            ->get(route('partner.profile'))
            ->assertInertia(fn (Assert $page) => $page->where('rates', null));
    }
}
