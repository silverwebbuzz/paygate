<?php

namespace Tests\Feature\Partners;

use App\Domain\Branch\Models\Branch;
use App\Domain\Commission\Enums\Direction;
use App\Domain\Commission\Models\CommissionRate;
use App\Domain\Commission\RateBook;
use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Organisation\Enums\OrganisationStatus;
use App\Domain\Core\Rbac\SystemRoles;
use App\Domain\Partner\Models\Partner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PartnerManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $role = SystemRoles::ADMIN_SUPER): User
    {
        return User::factory()->admin($role)->withTwoFactor()->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'Atoz Gaming',
            'code' => 'ATOZ',
            'email' => 'Ops@Atoz.example',
            'website_url' => 'https://atoz.example',
            'return_url' => 'https://atoz.example/return',
            'callback_url' => 'https://atoz.example/callback',
            'payin_webhook_url' => 'https://atoz.example/hooks/payin',
            'ip_addresses' => "52.66.45.184, 52.66.164.33\n10.0.0.0/24",
            'is_payin_enabled' => true,
            'allow_qr' => true,
            'allow_upi' => true,
            'allow_bank_transfer' => false,
            'is_h2h_enabled' => false,
            'session_ttl_minutes' => 15,
            'deposit_min_amount' => '100',
            'deposit_max_amount' => '50000.50',
            'deposit_daily_limit' => '-1',
            'deposit_rate' => '6',
            'withdrawal_rate' => '2.5',
            'payout_limit_type' => 'daily_reset',
            'is_payout_enabled' => true,
            'withdrawal_min_amount' => '100',
            'withdrawal_max_amount' => '-1',
            'withdrawal_daily_limit' => '-1',
            'is_auto_withdrawal' => false,
            'is_partial_withdrawal' => false,
            'branch_ids' => [],
            ...$overrides,
        ];
    }

    public function test_partner_list_and_wizard_open_for_admins()
    {
        Partner::factory()->count(3)->create();
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.partners.index'))
            ->assertInertia(fn (Assert $page) => $page->component('admin/partners/index')->has('partners.data', 3));

        $this->actingAs($admin)->get(route('admin.partners.create'))
            ->assertInertia(fn (Assert $page) => $page->component('admin/partners/form')->where('partner', null));
    }

    public function test_partners_and_branches_cannot_open_partner_management()
    {
        $this->actingAs(User::factory()->partner()->create())->get(route('admin.partners.index'))->assertForbidden();
        $this->actingAs($this->admin(SystemRoles::ADMIN_FINANCE))->get(route('admin.partners.create'))->assertForbidden();
    }

    public function test_the_wizard_creates_a_draft_partner_with_everything_and_a_key_shown_once()
    {
        $admin = $this->admin();
        $branch = Branch::factory()->create();

        $response = $this->actingAs($admin)->post(route('admin.partners.store'), $this->payload(['branch_ids' => [$branch->id]]));

        $partner = Partner::where('code', 'ATOZ')->firstOrFail();
        $response->assertRedirect(route('admin.partners.index', ['partner' => $partner->id]));

        $this->assertSame(OrganisationStatus::Draft, $partner->status);
        $this->assertSame('ops@atoz.example', $partner->email);
        $this->assertSame(10000, $partner->deposit_min_amount);
        $this->assertSame(5000050, $partner->deposit_max_amount);
        $this->assertNull($partner->deposit_daily_limit);
        $this->assertNull($partner->withdrawal_max_amount);
        $this->assertFalse($partner->allow_bank_transfer);

        $this->assertEqualsCanonicalizing(['52.66.45.184/32', '52.66.164.33/32', '10.0.0.0/24'], $partner->ipRules()->pluck('cidr')->all());
        $this->assertTrue($partner->branches()->whereKey($branch->id)->exists());
        $this->assertSame('6.0000', app(RateBook::class)->partnerRate($partner, Direction::Deposit));
        $this->assertSame('2.5000', app(RateBook::class)->partnerRate($partner, Direction::Withdrawal));

        // The secret is flashed once, stored encrypted, and never in plain text.
        $response->assertInertiaFlash('credentials.key_id');
        $credentials = $response->getSession()->get('inertia.flash_data')['credentials'];
        $key = $partner->activeApiKey()->firstOrFail();
        $this->assertNotNull($credentials);
        $this->assertSame($key->key_id, $credentials['key_id']);
        $this->assertSame($credentials['secret'], $key->secret_encrypted);
        $raw = $key->getRawOriginal('secret_encrypted');
        $this->assertNotSame($credentials['secret'], $raw);
        $this->assertSame($credentials['secret'], Crypt::decryptString($raw));
        $this->assertSame(substr($credentials['secret'], -4), $key->secret_last4);

        $this->assertDatabaseHas('audit_logs', ['action' => 'partner.created', 'subject_id' => $partner->id, 'actor_id' => $admin->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'api_key.issued', 'subject_id' => $partner->id]);
    }

    public function test_invalid_input_is_rejected_per_field()
    {
        Partner::factory()->create(['code' => 'TAKEN']);

        $this->actingAs($this->admin())->post(route('admin.partners.store'), $this->payload([
            'code' => 'TAKEN',
            'ip_addresses' => '999.1.1.1',
            'deposit_min_amount' => '500',
            'deposit_max_amount' => '100',
            'withdrawal_rate' => '120',
            'return_url' => 'not a url',
        ]))->assertSessionHasErrors(['code', 'ip_addresses', 'deposit_max_amount', 'withdrawal_rate', 'return_url']);

        $this->assertSame(1, Partner::count());
    }

    public function test_endpoints_are_required_but_the_website_is_optional()
    {
        $admin = $this->admin();
        $missing = [
            'return_url' => '',
            'callback_url' => '',
            'payin_webhook_url' => '',
            'deposit_min_amount' => '',
        ];

        $this->actingAs($admin)->post(route('admin.partners.store'), $this->payload($missing))
            ->assertSessionHasErrors(array_keys($missing));

        $this->actingAs($admin)->post(route('admin.partners.store'), $this->payload(['website_url' => '']))
            ->assertSessionHasNoErrors();

        $partner = Partner::where('code', 'ATOZ')->firstOrFail();
        $this->assertNull($partner->website_url);

        $this->actingAs($admin)->put(route('admin.partners.update', $partner), $this->payload($missing))
            ->assertSessionHasErrors(array_keys($missing));
    }

    public function test_whole_internet_ranges_are_refused()
    {
        $this->actingAs($this->admin())->post(route('admin.partners.store'), $this->payload(['ip_addresses' => '0.0.0.0/0']))
            ->assertSessionHasErrors('ip_addresses');
    }

    public function test_create_and_activate_goes_live_in_one_step_or_not_at_all()
    {
        $admin = $this->admin();

        // Pay-in enabled but no deposit rate: nothing is created.
        $this->actingAs($admin)->post(route('admin.partners.store'), $this->payload(['deposit_rate' => '', 'activate' => true]))
            ->assertSessionHasErrors('status');
        $this->assertSame(0, Partner::count());

        $this->actingAs($admin)->post(route('admin.partners.store'), $this->payload(['activate' => true]))->assertSessionHasNoErrors();
        $partner = Partner::firstOrFail();
        $this->assertSame(OrganisationStatus::Active, $partner->status);
        $this->assertNotNull($partner->verified_at);
        $this->assertSame($admin->id, $partner->verified_by);
    }

    public function test_editing_changes_rates_from_now_and_keeps_history()
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.partners.store'), $this->payload());
        $partner = Partner::firstOrFail();

        $this->travel(1)->hours();
        $this->actingAs($admin)->put(route('admin.partners.update', $partner), $this->payload(['deposit_rate' => '5.75']))
            ->assertSessionHasNoErrors();

        $history = CommissionRate::query()->where(['subject_id' => $partner->id, 'direction' => 'deposit'])->orderBy('effective_from')->get();
        $this->assertCount(2, $history);
        $this->assertSame('6.0000', $history[0]->rate_percent);
        $this->assertNotNull($history[0]->effective_to);
        $this->assertSame('5.7500', $history[1]->rate_percent);
        $this->assertNull($history[1]->effective_to);
        // An unchanged rate isn't duplicated.
        $this->assertSame(1, CommissionRate::query()->where(['subject_id' => $partner->id, 'direction' => 'withdrawal'])->count());
    }

    public function test_a_rate_below_a_mapped_branch_is_allowed_but_flagged()
    {
        $admin = $this->admin();
        $branch = Branch::factory()->create(['code' => 'BR-1']);
        CommissionRate::create([
            'subject_type' => 'branch', 'subject_id' => $branch->id, 'side' => 'branch', 'direction' => 'deposit',
            'rate_percent' => '4', 'effective_from' => now()->subDay(),
        ]);

        $this->actingAs($admin)->post(route('admin.partners.store'), $this->payload(['deposit_rate' => '3', 'branch_ids' => [$branch->id]]))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.type', 'warning');

        $partner = Partner::firstOrFail();
        $audit = AuditLog::query()
            ->where(['action' => 'commission_rate.set', 'subject_id' => $partner->id])
            ->get()
            ->first(fn ($log) => ($log->new_values['direction'] ?? null) === 'deposit');

        $this->assertSame('BR-1', $audit->new_values['negative_margin_pairs'][0]['code']);
    }

    public function test_code_is_fixed_once_the_partner_is_live()
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.partners.store'), $this->payload(['activate' => true]));
        $partner = Partner::firstOrFail();

        $this->actingAs($admin)->put(route('admin.partners.update', $partner), $this->payload(['code' => 'NEWCODE']))
            ->assertSessionHasErrors('code');

        $this->assertSame('ATOZ', $partner->fresh()?->code);
    }

    public function test_admins_without_partner_update_permission_cannot_edit()
    {
        $partner = Partner::factory()->create(['status' => 'draft']);
        $ops = $this->admin(SystemRoles::ADMIN_OPS); // may map branches, but not edit partners

        $this->actingAs($ops)->put(route('admin.partners.update', $partner), $this->payload())->assertForbidden();
        $this->assertSame(0, CommissionRate::count());
    }

    public function test_payin_and_payout_toggles_from_the_list_are_audited()
    {
        $admin = $this->admin();
        $partner = Partner::factory()->create(['is_payin_enabled' => true, 'is_payout_enabled' => true]);

        $this->actingAs($admin)->put(route('admin.partners.direction', $partner), ['direction' => 'payin', 'enabled' => false])
            ->assertSessionHasErrors('reason');
        $this->assertTrue($partner->fresh()?->is_payin_enabled);

        $this->actingAs($admin)->put(route('admin.partners.direction', $partner), [
            'direction' => 'payin',
            'enabled' => false,
            'reason' => 'Paused for the weekend',
        ])->assertSessionHasNoErrors();
        $this->assertFalse($partner->fresh()?->is_payin_enabled);

        $this->actingAs($admin)->put(route('admin.partners.direction', $partner), [
            'direction' => 'payout',
            'enabled' => false,
            'reason' => 'Balance review',
        ])->assertSessionHasNoErrors();

        $log = AuditLog::query()->where(['action' => 'partner.direction_changed', 'subject_id' => $partner->id, 'actor_id' => $admin->id])->first();
        $this->assertNotNull($log);
        $this->assertSame('payin', $log->new_values['direction']);
        $this->assertFalse($log->new_values['enabled']);
        $this->assertSame('Paused for the weekend', $log->new_values['reason']);

        $this->actingAs($this->admin(SystemRoles::ADMIN_OPS))->put(route('admin.partners.direction', $partner), [
            'direction' => 'payin',
            'enabled' => true,
            'reason' => 'Back',
        ])->assertForbidden();
        $this->assertFalse($partner->fresh()?->is_payin_enabled);
    }

    public function test_status_changes_follow_the_lifecycle_and_need_a_reason()
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.partners.store'), $this->payload(['activate' => true]));
        $partner = Partner::firstOrFail();

        $this->actingAs($admin)->put(route('admin.partners.status', $partner), ['status' => 'suspended'])->assertSessionHasErrors('reason');
        $this->actingAs($admin)->put(route('admin.partners.status', $partner), ['status' => 'suspended', 'reason' => 'Chargebacks'])->assertSessionHasNoErrors();
        $this->assertSame(OrganisationStatus::Suspended, $partner->fresh()?->status);

        $this->actingAs($admin)->put(route('admin.partners.status', $partner), ['status' => 'offboarded', 'reason' => 'Contract ended']);
        $this->actingAs($admin)->put(route('admin.partners.status', $partner), ['status' => 'active', 'reason' => 'Oops'])->assertSessionHasErrors('status');
        $this->assertSame(OrganisationStatus::Offboarded, $partner->fresh()?->status);

        // Activated (wizard), suspended, offboarded.
        $this->assertSame(3, AuditLog::where(['action' => 'partner.status_changed', 'subject_id' => $partner->id])->count());
    }

    public function test_unmapping_a_branch_deactivates_rather_than_deletes()
    {
        $admin = $this->admin();
        [$a, $b] = Branch::factory()->count(2)->create();
        $this->actingAs($admin)->post(route('admin.partners.store'), $this->payload(['branch_ids' => [$a->id, $b->id]]));
        $partner = Partner::firstOrFail();

        $this->actingAs($admin)->put(route('admin.partners.update', $partner), $this->payload(['branch_ids' => [$a->id]]));

        $this->assertDatabaseHas('partner_branch_mappings', ['partner_id' => $partner->id, 'branch_id' => $b->id, 'status' => 'inactive']);
        $this->assertSame([$a->id], $partner->branches()->pluck('branches.id')->all());

        $this->actingAs($admin)->put(route('admin.partners.update', $partner), $this->payload(['branch_ids' => [$a->id, $b->id]]));
        $this->assertSame(2, $partner->mappings()->count());
    }

    public function test_the_drawer_detail_loads_on_demand()
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.partners.store'), $this->payload());
        $partner = Partner::firstOrFail();

        $this->actingAs($admin)
            ->get(route('admin.partners.index', ['partner' => $partner->id]), [
                'X-Inertia' => 'true',
                'X-Inertia-Partial-Component' => 'admin/partners/index',
                'X-Inertia-Partial-Data' => 'detail',
                'X-Inertia-Version' => Inertia::getVersion(),
            ])
            ->assertOk()
            ->assertJsonPath('props.detail.id', $partner->id)
            ->assertJsonCount(1, 'props.detail.keys')
            ->assertJsonMissingPath('props.detail.keys.0.secret');
    }
}
