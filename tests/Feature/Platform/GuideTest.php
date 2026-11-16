<?php

namespace Tests\Feature\Platform;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\SystemRoles;
use App\Domain\Partner\Models\Partner;
use App\Domain\Platform\SectionRollout;
use App\Domain\Platform\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuideTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_admin_opens_the_guide_even_with_every_section_closed()
    {
        // Rollout on with nothing opened: the Guide is not a rollout section.
        $admin = User::factory()->admin()->withTwoFactor()->create();
        app(Settings::class)->set(SectionRollout::SETTING, ['active' => true, 'open' => ['admin' => [], 'branch' => [], 'partner' => []]], $admin);

        foreach ([SystemRoles::ADMIN_FULL, SystemRoles::ADMIN_OPS, SystemRoles::ADMIN_FINANCE, SystemRoles::ADMIN_VIEWER] as $role) {
            $this->actingAs(User::factory()->admin($role)->withTwoFactor()->create())
                ->get(route('admin.guide'))
                ->assertOk()
                ->assertInertia(fn ($page) => $page->component('admin/guide'));
        }
    }

    public function test_branches_and_partners_have_no_guide()
    {
        $branch = User::factory()->branch(SystemRoles::BRANCH_OWNER, Branch::factory()->create())->withTwoFactor()->create();
        $partner = User::factory()->partner(SystemRoles::PARTNER_OWNER, Partner::factory()->create())->withTwoFactor()->create();

        foreach ([$branch, $partner] as $user) {
            $this->assertNotSame(200, $this->actingAs($user)->get(route('admin.guide'))->status());
        }
    }
}
