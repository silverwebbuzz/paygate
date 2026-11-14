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
                ->where('portals.admin.0.items.0.key', 'accounts')
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

        // Branch: Statement History open while Manual A/C Statement (its parent path) is closed.
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

    public function test_partner_api_docs_stay_hidden_from_admins_until_a_super_admin_opens_them()
    {
        // Rollout off, nothing opened: hidden and refused for admins, open for super admins.
        $this->actingAs($this->ops)->get(route('admin.dashboard'))
            ->assertInertia(fn ($page) => $page->where('rolloutHidden', ['/admin/partner-api-docs']));
        $this->actingAs($this->ops)->get(route('admin.partner-api-docs.show'))->assertRedirect(route('admin.dashboard'));
        $this->actingAs($this->ops)->get(route('admin.partner-api-docs.download'))->assertRedirect(route('admin.dashboard'));
        $this->actingAs($this->super)->get(route('admin.partner-api-docs.show'))->assertOk();

        // Opened, rollout still off: the menu item shows and the guide opens and downloads.
        $this->rollout(false, ['admin' => ['partner_api_docs']])->assertSessionHasNoErrors();
        $this->actingAs($this->ops)->get(route('admin.dashboard'))
            ->assertInertia(fn ($page) => $page->where('rolloutHidden', []));
        $this->actingAs($this->ops)->get(route('admin.partner-api-docs.show'))
            ->assertInertia(fn ($page) => $page->component('admin/partner-api-docs'));
        $this->actingAs($this->ops)->get(route('admin.partner-api-docs.preview'))
            ->assertOk()->assertSee('Standard guide')->assertDontSee('No key yet');
        $this->actingAs($this->ops)->get(route('admin.partner-api-docs.download'))
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_every_section_link_is_a_real_page()
    {
        foreach (SectionRollout::SECTIONS as $portal => $groups) {
            foreach ($groups as $items) {
                foreach ($items as $key => $item) {
                    $path = ltrim($item['paths'][0], '/');
                    // Route parameters match one segment ({side?} may also be absent).
                    $pattern = fn (string $uri) => '#^'.preg_replace(['#/\\\{[^}]+\?\\\}#', '#\\\{[^}]+\\\}#'], ['(/[^/]+)?', '[^/]+'], preg_quote($uri, '#')).'$#';
                    $found = collect(Route::getRoutes()->getRoutes())->contains(fn ($route) => in_array('GET', $route->methods(), true) && preg_match($pattern($route->uri()), $path) === 1);
                    $this->assertTrue($found, "{$portal}.{$key}: no page at /{$path}");
                }
            }
        }
    }

    public function test_branch_and_partner_reports_balance_and_settlement_list_are_retired()
    {
        // Client request 2026-11-14: their Finance is the settlement dashboard only, no Reports.
        // Closed whatever rollout says (here: off).
        $branchUser = User::factory()->branch(SystemRoles::BRANCH_OWNER)->withTwoFactor()->create();
        $partnerUser = User::factory()->partner(SystemRoles::PARTNER_OWNER)->withTwoFactor()->create();

        foreach (['/branch/balance', '/branch/settlements', '/branch/reports/branch_deposits'] as $path) {
            $this->actingAs($branchUser)->get($path)->assertRedirect(route('branch.dashboard'));
        }
        $this->actingAs($branchUser)->post('/branch/reports/export', ['report' => 'branch_deposits', 'format' => 'csv'])->assertForbidden();
        $this->actingAs($branchUser)->get('/branch/settlement-dashboard')->assertOk();

        foreach (['/partner/balance', '/partner/settlements', '/partner/reports/partner_deposits'] as $path) {
            $this->actingAs($partnerUser)->get($path)->assertRedirect(route('partner.dashboard'));
        }
        $this->actingAs($partnerUser)->get('/partner/settlement-dashboard')->assertOk();
    }

    public function test_every_portal_page_belongs_to_a_section_or_is_always_open()
    {
        // Dashboards (exact) and the super-admin tools are never closed.
        $alwaysOpen = ['/admin', '/branch', '/partner', '/admin/qa-checklist', '/admin/section-rollout', '/admin/ui-kit'];
        $paths = [];

        foreach (SectionRollout::SECTIONS as $portal => $groups) {
            foreach ($groups as $items) {
                foreach ($items as $item) {
                    $paths[$portal] = [...$paths[$portal] ?? [], ...$item['paths']];
                }
            }
        }

        // Retired pages are owned too: closed to everyone.
        foreach (SectionRollout::RETIRED as $portal => $retired) {
            $paths[$portal] = [...$paths[$portal] ?? [], ...$retired];
        }

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uri = '/'.$route->uri();

            if (preg_match('#^/(admin|branch|partner)(/|$)#', $uri, $match) !== 1 || $this->underAny($uri, $alwaysOpen, exactOnly: ['/admin', '/branch', '/partner'])) {
                continue;
            }

            $owned = collect($paths[$match[1]])->contains(fn (string $prefix) => $uri === $prefix || str_starts_with($uri, $prefix.'/'));
            $this->assertTrue($owned, "{$uri} is in no Section rollout item: add it to SectionRollout::SECTIONS or to the always-open list here.");
        }
    }

    /**
     * @param  list<string>  $prefixes
     * @param  list<string>  $exactOnly  prefixes that match only themselves
     */
    private function underAny(string $uri, array $prefixes, array $exactOnly = []): bool
    {
        return collect($prefixes)->contains(fn (string $prefix) => $uri === $prefix || (! in_array($prefix, $exactOnly, true) && str_starts_with($uri, $prefix.'/')));
    }
}
