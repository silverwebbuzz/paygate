<?php

namespace Tests\Feature\Platform;

use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\SystemRoles;
use App\Domain\Platform\SectionRollout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SectionRolloutTest extends TestCase
{
    use RefreshDatabase;

    private User $super;

    private User $ops;

    protected function setUp(): void
    {
        parent::setUp();

        $this->super = User::factory()->admin()->withTwoFactor()->create();
        $this->ops = User::factory()->admin(SystemRoles::ADMIN_OPS)->withTwoFactor()->create();
    }

    /**
     * @param  array<string, list<string>>  $open
     */
    private function rollout(bool $active, array $open)
    {
        return $this->actingAs($this->super)->put(route('admin.section-rollout.update'), [
            'active' => $active,
            'open' => ['admin' => [], 'branch' => [], 'partner' => [], ...$open],
        ]);
    }

    public function test_only_super_admins_open_the_rollout_page_and_the_qa_checklist()
    {
        $this->actingAs($this->super)->get(route('admin.section-rollout.index'))
            ->assertInertia(fn ($page) => $page->component('admin/section-rollout')
                ->where('portals.admin.0.items.0.key', 'transactions')
                ->where('state.active', false)
                ->where('superAdmin', true));

        $this->actingAs($this->ops)->get(route('admin.section-rollout.index'))->assertForbidden();
        $this->actingAs($this->ops)->put(route('admin.section-rollout.update'), ['active' => true, 'open' => []])->assertForbidden();
        $this->actingAs($this->ops)->get(route('admin.qa-checklist.index'))->assertForbidden();
        $this->actingAs($this->ops)->get(route('admin.dashboard'))
            ->assertInertia(fn ($page) => $page->where('superAdmin', false)->where('qaChecklist', false));
    }

    public function test_while_rollout_is_on_other_users_see_and_open_only_the_open_sections()
    {
        $this->rollout(true, ['admin' => ['partners', 'no-such-item'], 'branch' => ['imports']])->assertSessionHasNoErrors();
        $this->assertSame(['admin' => ['partners'], 'branch' => ['imports'], 'partner' => []], app(SectionRollout::class)->state()['open']);
        $this->assertTrue(AuditLog::where('action', 'setting.updated')->exists());

        // The ops admin: Partners open, Branches closed (hidden from the menu, page refused).
        $this->actingAs($this->ops)->get(route('admin.dashboard'))
            ->assertInertia(fn ($page) => $page->where('rolloutLimited', true)->where('rolloutHidden', fn ($hidden) => collect($hidden)->contains('/admin/branches') && ! collect($hidden)->contains('/admin/partners')));
        $this->actingAs($this->ops)->get(route('admin.partners.index'))->assertOk();
        $this->actingAs($this->ops)->get(route('admin.branches.index'))->assertRedirect(route('admin.dashboard'));
        $this->actingAs($this->ops)->post(route('admin.branches.store'), [])->assertForbidden();

        // Dashboards and settings are always open.
        $this->actingAs($this->ops)->get(route('admin.dashboard'))->assertOk();
        $this->actingAs($this->ops)->get(route('profile.edit'))->assertOk();

        // Branch: Statement History open while A/C Statement Entry (its parent path) is closed.
        $owner = User::factory()->branch(SystemRoles::BRANCH_OWNER)->withTwoFactor()->create();
        $this->actingAs($owner)->get(route('branch.statement-imports.index'))->assertOk();
        $this->actingAs($owner)->get(route('branch.statements.index'))->assertRedirect(route('branch.dashboard'));

        // Partners: nothing open yet.
        $partner = User::factory()->partner()->create();
        $this->actingAs($partner)->get(route('partner.payins.index'))->assertRedirect(route('partner.dashboard'));

        // Super admins always see everything.
        $this->actingAs($this->super)->get(route('admin.branches.index'))->assertOk();
        $this->actingAs($this->super)->get(route('admin.dashboard'))->assertInertia(fn ($page) => $page->where('rolloutHidden', []));

        // Rollout off: everyone sees everything again.
        $this->rollout(false, ['admin' => ['partners']]);
        $this->actingAs($this->ops)->get(route('admin.branches.index'))->assertOk();
    }

    public function test_every_section_link_is_a_real_page()
    {
        foreach (SectionRollout::SECTIONS as $portal => $groups) {
            foreach ($groups as $items) {
                foreach ($items as $key => $item) {
                    $path = ltrim($item['paths'][0], '/');
                    $found = collect(Route::getRoutes()->getRoutes())->contains(fn ($route) => $route->uri() === $path && in_array('GET', $route->methods(), true));
                    $this->assertTrue($found, "{$portal}.{$key}: no page at /{$path}");
                }
            }
        }
    }
}
