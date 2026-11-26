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

    public function test_every_branch_user_opens_the_branch_guide_even_with_every_section_closed()
    {
        $admin = User::factory()->admin()->withTwoFactor()->create();
        app(Settings::class)->set(SectionRollout::SETTING, ['active' => true, 'open' => ['admin' => [], 'branch' => [], 'partner' => []]], $admin);
        $branch = Branch::factory()->create();

        foreach ([SystemRoles::BRANCH_OWNER, SystemRoles::BRANCH_OPERATOR] as $role) {
            $this->actingAs(User::factory()->branch($role, $branch)->withTwoFactor()->create())
                ->get(route('branch.guide'))
                ->assertOk()
                ->assertInertia(fn ($page) => $page->component('branch/guide'));
        }

        // Partners and admins don't get the branch portal.
        $partner = User::factory()->partner(SystemRoles::PARTNER_OWNER, Partner::factory()->create())->withTwoFactor()->create();
        $this->assertNotSame(200, $this->actingAs($partner)->get(route('branch.guide'))->status());
    }

    public function test_every_branch_guide_picture_exists()
    {
        preg_match_all('/shot\(\s*\'([a-z0-9-]+)\'/', (string) file_get_contents(resource_path('js/lib/branch-guide-content.ts')), $matches);

        $this->assertNotEmpty($matches[1]);
        foreach ($matches[1] as $file) {
            $this->assertFileExists(public_path("guide/branch/{$file}.jpg"));
        }
    }

    public function test_branches_and_partners_have_no_admin_guide()
    {
        $branch = User::factory()->branch(SystemRoles::BRANCH_OWNER, Branch::factory()->create())->withTwoFactor()->create();
        $partner = User::factory()->partner(SystemRoles::PARTNER_OWNER, Partner::factory()->create())->withTwoFactor()->create();

        foreach ([$branch, $partner] as $user) {
            $this->assertNotSame(200, $this->actingAs($user)->get(route('admin.guide'))->status());
        }
    }
}
