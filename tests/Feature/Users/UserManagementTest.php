<?php

namespace Tests\Feature\Users;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Identity\Enums\UserStatus;
use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\Enums\Permission;
use App\Domain\Core\Rbac\Models\Role;
use App\Domain\Core\Rbac\SystemRoles;
use App\Domain\Partner\Models\Partner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    private const PASSWORD = ['password' => 'Str0ng-pass!word', 'password_confirmation' => 'Str0ng-pass!word'];

    private function superAdmin(): User
    {
        return User::factory()->admin()->withTwoFactor()->create();
    }

    public function test_admin_sees_all_users_and_owners_only_their_own_organisation()
    {
        $partner = Partner::factory()->create();
        $owner = User::factory()->partner(SystemRoles::PARTNER_OWNER, $partner)->create();
        $colleague = User::factory()->partner(SystemRoles::PARTNER_VIEWER, $partner)->create();
        $stranger = User::factory()->partner()->create();

        $this->actingAs($this->superAdmin())
            ->get(route('admin.users.index'))
            ->assertInertia(fn (Assert $page) => $page->component('users/index')->has('users.data', 4));

        $this->actingAs($owner)
            ->get(route('partner.users.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('users.data', fn ($rows) => collect($rows)->pluck('id')->sort()->values()->all() === collect([$owner->id, $colleague->id])->sort()->values()->all())
                ->where('organisation', null));

        $this->assertNotContains($stranger->id, [$owner->id, $colleague->id]);
    }

    public function test_users_without_the_permission_cannot_open_users()
    {
        $viewer = User::factory()->partner(SystemRoles::PARTNER_VIEWER)->create();
        $operator = User::factory()->branch(SystemRoles::BRANCH_OPERATOR)->withTwoFactor()->create();

        $this->actingAs($viewer)->get(route('partner.users.index'))->assertForbidden();
        $this->actingAs($operator)->get(route('branch.users.index'))->assertForbidden();
    }

    public function test_admin_adds_a_branch_user_on_the_branch_users_page_who_can_log_in_at_once()
    {
        $admin = $this->superAdmin();
        $branch = Branch::factory()->create();

        $this->actingAs($admin)->get(route('admin.branches.users.index', $branch))
            ->assertInertia(fn (Assert $page) => $page->component('users/index')->where('organisation.id', $branch->id));

        $this->actingAs($admin)->post(route('admin.branches.users.store', $branch), [
            'username' => 'ravi',
            'role_id' => Role::bySlug(SystemRoles::BRANCH_OPERATOR)->id,
            ...self::PASSWORD,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $user = User::where('username', 'ravi')->firstOrFail();
        $this->assertNull($user->email);
        $this->assertSame('ravi', $user->username);
        $this->assertSame(UserType::Branch, $user->type);
        $this->assertSame($branch->id, $user->branch_id);
        $this->assertTrue(Hash::check('Str0ng-pass!word', $user->password));
        $this->assertSame('active', $user->displayStatus());

        Notification::assertNothingSent();
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.created', 'subject_id' => $user->id, 'actor_id' => $admin->id]);
    }

    public function test_a_new_user_needs_a_confirmed_password()
    {
        $this->actingAs($this->superAdmin())->post(route('admin.users.store'), [
            'username' => 'nopass',
            'role_id' => Role::bySlug(SystemRoles::ADMIN_VIEWER)->id,
        ])->assertSessionHasErrors('password');

        $this->actingAs($this->superAdmin())->post(route('admin.users.store'), [
            'username' => 'mismatch',
            'role_id' => Role::bySlug(SystemRoles::ADMIN_VIEWER)->id,
            'password' => 'Str0ng-pass!word',
            'password_confirmation' => 'something-else',
        ])->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('users', ['username' => 'nopass']);
        $this->assertDatabaseMissing('users', ['username' => 'mismatch']);
    }

    public function test_a_new_user_needs_a_unique_valid_username()
    {
        User::factory()->create(['username' => 'taken']);
        $before = User::count();
        $payload = fn (string $username) => [
            'username' => $username,
            'role_id' => Role::bySlug(SystemRoles::ADMIN_VIEWER)->id,
            ...self::PASSWORD,
        ];

        foreach (['', 'ab', 'taken', 'Has Space', 'who@where', 'UPPER'] as $username) {
            $this->actingAs($this->superAdmin())->post(route('admin.users.store'), $payload($username))
                ->assertSessionHasErrors('username');
        }

        $this->assertSame($before, User::count());
    }

    public function test_admin_sets_another_users_password()
    {
        $admin = $this->superAdmin();
        $user = User::factory()->partner()->create();

        $this->actingAs($admin)->put(route('admin.users.password', $user), self::PASSWORD)->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('Str0ng-pass!word', $user->fresh()?->password));
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.password_set', 'subject_id' => $user->id, 'actor_id' => $admin->id]);

        // Not their own account, and not a partner owner for another organisation.
        $this->actingAs($admin)->put(route('admin.users.password', $admin), self::PASSWORD)->assertForbidden();
        $this->actingAs(User::factory()->partner(SystemRoles::PARTNER_OWNER)->create())
            ->put(route('partner.users.password', $user), self::PASSWORD)->assertForbidden();
    }

    public function test_the_admin_role_cannot_create_or_manage_super_admins()
    {
        $actor = User::factory()->admin(SystemRoles::ADMIN_FULL)->withTwoFactor()->create();
        $superAdmin = $this->superAdmin();

        $this->assertFalse($actor->isSuperAdmin());
        $this->assertEqualsCanonicalizing(Role::bySlug(SystemRoles::ADMIN_SUPER)->permissionValues(), $actor->permissionNames());

        $this->actingAs($actor)->post(route('admin.users.store'), [
            'username' => 'promoted',
            'role_id' => Role::bySlug(SystemRoles::ADMIN_SUPER)->id,
            ...self::PASSWORD,
        ])->assertSessionHasErrors('role_id');

        $this->actingAs($actor)->put(route('admin.users.password', $superAdmin), self::PASSWORD)->assertForbidden();
        $this->actingAs($actor)->put(route('admin.users.status', $superAdmin), ['status' => 'inactive', 'reason' => 'test'])->assertForbidden();

        // Other admins, including other Admin-role users, are fine.
        $this->actingAs($actor)->post(route('admin.users.store'), [
            'username' => 'second',
            'role_id' => Role::bySlug(SystemRoles::ADMIN_FULL)->id,
            ...self::PASSWORD,
        ])->assertSessionHasNoErrors();

        $this->actingAs($actor)->get(route('admin.users.index'))->assertInertia(fn (Assert $page) => $page
            ->where('roles', fn ($roles) => ! collect($roles)->contains('id', Role::bySlug(SystemRoles::ADMIN_SUPER)->id)));
    }

    public function test_super_admins_and_their_role_are_hidden_from_everyone_else()
    {
        $actor = User::factory()->admin(SystemRoles::ADMIN_FULL)->withTwoFactor()->create();
        $superAdmin = $this->superAdmin();
        $superRole = Role::bySlug(SystemRoles::ADMIN_SUPER);

        $this->actingAs($actor)->get(route('admin.users.index'))->assertInertia(fn (Assert $page) => $page
            ->where('users.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$actor->id])
            ->where('counts.all', 1)
            ->where('counts.admin', 1));

        $this->actingAs($actor)->get(route('admin.roles.index'))->assertInertia(fn (Assert $page) => $page
            ->where('roles', fn ($roles) => ! collect($roles)->contains('id', $superRole->id))
            ->where('counts.admin', 4));

        // Super admins still see everything.
        $this->actingAs($superAdmin)->get(route('admin.users.index'))->assertInertia(fn (Assert $page) => $page->where('counts.all', 2));
        $this->actingAs($superAdmin)->get(route('admin.roles.index'))->assertInertia(fn (Assert $page) => $page
            ->where('roles', fn ($roles) => collect($roles)->contains('id', $superRole->id)));
    }

    public function test_the_admin_role_does_not_see_the_super_admin_tools()
    {
        $actor = User::factory()->admin(SystemRoles::ADMIN_FULL)->withTwoFactor()->create();

        $this->actingAs($actor)->get(route('admin.section-rollout.index'))->assertForbidden();
        $this->actingAs($actor)->get(route('admin.qa-checklist.index'))->assertForbidden();
        $this->actingAs($actor)->get(route('admin.ui-kit'))->assertForbidden();
        $this->actingAs($actor)->get(route('admin.users.index'))->assertInertia(fn (Assert $page) => $page
            ->where('superAdmin', false)
            ->where('qaChecklist', false));

        $this->actingAs($this->superAdmin())->get(route('admin.ui-kit'))->assertOk();
    }

    public function test_the_admin_users_page_adds_admin_users_only()
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('admin.users.store'), [
            'username' => 'noorg',
            'role_id' => Role::bySlug(SystemRoles::PARTNER_VIEWER)->id,
            ...self::PASSWORD,
        ])->assertSessionHasErrors('role_id');

        $this->actingAs($admin)->post(route('admin.users.store'), [
            'username' => 'viewer',
            'role_id' => Role::bySlug(SystemRoles::ADMIN_VIEWER)->id,
            ...self::PASSWORD,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('users', ['username' => 'noorg']);
        $this->assertSame(UserType::Admin, User::where('username', 'viewer')->firstOrFail()->type);
    }

    public function test_a_partner_users_page_lists_and_adds_only_that_partners_users()
    {
        $admin = $this->superAdmin();
        $partner = Partner::factory()->create();
        $own = User::factory()->partner(SystemRoles::PARTNER_VIEWER, $partner)->create();
        User::factory()->partner()->create();

        $this->actingAs($admin)->get(route('admin.partners.users.index', $partner))
            ->assertInertia(fn (Assert $page) => $page
                ->where('users.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$own->id])
                ->where('organisation.type', 'partner'));

        // A branch role can't be added to a partner.
        $this->actingAs($admin)->post(route('admin.partners.users.store', $partner), [
            'username' => 'wrong',
            'role_id' => Role::bySlug(SystemRoles::BRANCH_OPERATOR)->id,
            ...self::PASSWORD,
        ])->assertSessionHasErrors('role_id');

        $this->actingAs($admin)->post(route('admin.partners.users.store', $partner), [
            'username' => 'dev',
            'role_id' => Role::bySlug(SystemRoles::PARTNER_DEVELOPER)->id,
            ...self::PASSWORD,
        ])->assertSessionHasNoErrors();

        $this->assertSame($partner->id, User::where('username', 'dev')->value('partner_id'));
        $this->assertDatabaseMissing('users', ['username' => 'wrong']);

        // Partner and branch users can't open another organisation's page.
        $this->actingAs(User::factory()->partner(SystemRoles::PARTNER_OWNER, $partner)->create())
            ->get(route('admin.partners.users.index', $partner))->assertForbidden();
    }

    public function test_owners_add_users_to_their_own_organisation_only()
    {
        $branch = Branch::factory()->create();
        $owner = User::factory()->branch(SystemRoles::BRANCH_OWNER, $branch)->withTwoFactor()->create();

        $this->actingAs($owner)->post(route('branch.users.store'), [
            'username' => 'operator2',
            'role_id' => Role::bySlug(SystemRoles::BRANCH_OPERATOR)->id,
            ...self::PASSWORD,
        ])->assertSessionHasNoErrors();

        $this->assertSame($branch->id, User::where('username', 'operator2')->value('branch_id'));

        // A branch owner can't create admins, and always adds to their own branch.
        $this->actingAs($owner)->post(route('branch.users.store'), [
            'username' => 'sneaky',
            'role_id' => Role::bySlug(SystemRoles::ADMIN_SUPER)->id,
            ...self::PASSWORD,
        ])->assertSessionHasErrors('role_id');

        $this->actingAs($owner)->post(route('branch.users.store'), [
            'username' => 'elsewhere',
            'role_id' => Role::bySlug(SystemRoles::BRANCH_OPERATOR)->id,
            'organisation_id' => Branch::factory()->create()->id,
            ...self::PASSWORD,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('users', ['username' => 'sneaky']);
        $this->assertSame($branch->id, User::where('username', 'elsewhere')->value('branch_id'));
    }

    public function test_owners_cannot_manage_users_of_another_organisation()
    {
        $owner = User::factory()->partner(SystemRoles::PARTNER_OWNER)->create();
        $other = User::factory()->partner(SystemRoles::PARTNER_VIEWER)->create();

        $this->actingAs($owner)->put(route('partner.users.status', $other), [
            'status' => 'inactive',
            'reason' => 'test',
        ])->assertForbidden();

        $this->assertSame(UserStatus::Active, $other->fresh()?->status);
    }

    public function test_admins_cannot_hand_out_or_manage_more_power_than_they_hold()
    {
        $roleAdmin = Role::create(['user_type' => UserType::Admin, 'name' => 'User admin', 'status' => 'active']);
        $roleAdmin->syncPermissions([
            Permission::UsersView,
            Permission::UsersCreate,
            Permission::UsersUpdate,
        ]);
        $actor = User::factory()->withTwoFactor()->create(['type' => UserType::Admin, 'role_id' => $roleAdmin->id, 'partner_id' => null]);
        $superAdmin = $this->superAdmin();

        $this->actingAs($actor)->post(route('admin.users.store'), [
            'username' => 'promoted',
            'role_id' => Role::bySlug(SystemRoles::ADMIN_SUPER)->id,
            ...self::PASSWORD,
        ])->assertSessionHasErrors('role_id');

        $this->actingAs($actor)->put(route('admin.users.status', $superAdmin), [
            'status' => 'inactive',
            'reason' => 'test',
        ])->assertForbidden();
    }

    public function test_deactivating_requires_a_reason_is_audited_and_can_be_undone()
    {
        $admin = $this->superAdmin();
        $user = User::factory()->partner()->create();

        $this->actingAs($admin)->put(route('admin.users.status', $user), ['status' => 'inactive'])
            ->assertSessionHasErrors('reason');

        $this->actingAs($admin)->put(route('admin.users.status', $user), ['status' => 'inactive', 'reason' => 'Left the company'])
            ->assertSessionHasNoErrors();
        $this->assertSame(UserStatus::Suspended, $user->fresh()?->status);
        $this->assertSame('inactive', $user->fresh()?->displayStatus());
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.suspended', 'subject_id' => $user->id]);

        $this->actingAs($admin)->put(route('admin.users.status', $user), ['status' => 'active', 'reason' => 'Back'])
            ->assertSessionHasNoErrors();
        $this->assertSame(UserStatus::Active, $user->fresh()?->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.reactivated', 'subject_id' => $user->id]);
    }

    public function test_users_cannot_deactivate_themselves_and_the_last_super_admin_stays()
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->put(route('admin.users.status', $admin), ['status' => 'inactive', 'reason' => 'x'])
            ->assertForbidden();

        $this->assertTrue($admin->isLastActiveSuperAdmin());
        $second = $this->superAdmin();
        $this->assertFalse($admin->fresh()?->isLastActiveSuperAdmin());

        $this->actingAs($second)->put(route('admin.users.status', $admin), ['status' => 'inactive', 'reason' => 'x'])
            ->assertSessionHasNoErrors();

        $this->assertTrue($second->fresh()?->isLastActiveSuperAdmin());
    }

    public function test_changing_username_and_role_is_audited()
    {
        $admin = $this->superAdmin();
        $user = User::factory()->branch(SystemRoles::BRANCH_OPERATOR)->create(['last_login_at' => now()]);
        $email = $user->email;

        $this->actingAs($admin)->put(route('admin.users.update', $user), [
            'username' => 'changed',
            'role_id' => Role::bySlug(SystemRoles::BRANCH_OWNER)->id,
        ])->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertSame('changed', $user->username);
        $this->assertSame($email, $user->email);
        $this->assertSame(SystemRoles::BRANCH_OWNER, $user->role->slug);
        $this->assertNotNull($user->email_verified_at);
        Notification::assertNothingSent();
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.updated', 'subject_id' => $user->id]);

        // The role must stay within the user's portal.
        $this->actingAs($admin)->put(route('admin.users.update', $user), [
            'username' => $user->username,
            'role_id' => Role::bySlug(SystemRoles::ADMIN_VIEWER)->id,
        ])->assertSessionHasErrors('role_id');
    }

    public function test_admin_can_reset_two_factor()
    {
        $admin = $this->superAdmin();
        $branchUser = User::factory()->branch()->withTwoFactor()->create();

        $this->actingAs($admin)->delete(route('admin.users.two-factor', $branchUser), ['reason' => 'Lost phone'])
            ->assertSessionHasNoErrors();
        $this->assertNull($branchUser->fresh()?->two_factor_confirmed_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.two_factor_reset', 'subject_id' => $branchUser->id]);
    }
}
