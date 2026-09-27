<?php

namespace Tests\Feature\Rbac;

use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\Enums\Permission;
use App\Domain\Core\Rbac\Models\Role;
use App\Domain\Core\Rbac\SystemRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->admin()->withTwoFactor()->create();
    }

    public function test_roles_screen_lists_the_roles_and_grid_of_a_portal()
    {
        $this->actingAs($this->superAdmin())
            ->get(route('admin.roles.index', ['type' => 'branch']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/roles/index')
                ->where('type', 'branch')
                ->has('roles', 2)
                ->where('menus', fn ($menus) => collect($menus)->pluck('key')->doesntContain('partners')
                    && collect($menus)->pluck('key')->contains('accounts')));
    }

    public function test_only_admins_with_the_permission_can_open_roles()
    {
        $ops = User::factory()->admin(SystemRoles::ADMIN_OPS)->withTwoFactor()->create();
        $partner = User::factory()->partner()->create();

        $this->actingAs($ops)->get(route('admin.roles.index'))->assertForbidden();
        $this->actingAs($partner)->get(route('admin.roles.index'))->assertForbidden();
    }

    public function test_admin_creates_a_role_with_permissions_and_it_is_audited()
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('admin.roles.store'), [
            'user_type' => 'branch',
            'name' => 'Deposit desk',
            'description' => 'Approves deposits only',
            'permissions' => ['payins.view', 'payins.approve'],
        ])->assertRedirect();

        $role = Role::where('name', 'Deposit desk')->firstOrFail();
        $this->assertSame(UserType::Branch, $role->user_type);
        $this->assertEqualsCanonicalizing(['payins.view', 'payins.approve'], $role->permissionValues());
        $this->assertDatabaseHas('audit_logs', ['action' => 'role.created', 'subject_id' => $role->id, 'actor_id' => $admin->id]);
    }

    public function test_a_role_cannot_hold_permissions_of_another_portal()
    {
        $this->actingAs($this->superAdmin())->post(route('admin.roles.store'), [
            'user_type' => 'branch',
            'name' => 'Bad role',
            'permissions' => ['partners.update'],
        ])->assertSessionHasErrors('permissions');

        $this->assertDatabaseMissing('roles', ['name' => 'Bad role']);
    }

    public function test_updating_permissions_records_before_and_after()
    {
        $admin = $this->superAdmin();
        $role = Role::bySlug(SystemRoles::BRANCH_OPERATOR);

        $this->actingAs($admin)->put(route('admin.roles.update', $role), [
            'name' => $role->name,
            'description' => $role->description,
            'status' => 'active',
            'permissions' => ['payins.view'],
        ])->assertRedirect();

        $this->assertSame(['payins.view'], $role->fresh()?->permissionValues());

        $audit = AuditLog::where('action', 'role.updated')->firstOrFail();
        $this->assertContains('payins.approve', $audit->old_values['permissions'] ?? []);
        $this->assertSame(['payins.view'], $audit->new_values['permissions'] ?? null);
    }

    public function test_super_admin_role_is_locked_and_always_holds_everything()
    {
        $role = Role::bySlug(SystemRoles::ADMIN_SUPER);

        $this->actingAs($this->superAdmin())->put(route('admin.roles.update', $role), [
            'name' => 'Super admin',
            'status' => 'active',
            'permissions' => [],
        ])->assertForbidden();

        $this->assertEqualsCanonicalizing(
            array_map(fn (Permission $permission) => $permission->value, Permission::forType(UserType::Admin)),
            $role->permissionValues(),
        );
    }

    public function test_admins_cannot_grant_permissions_they_do_not_hold()
    {
        $roleAdmin = Role::create(['user_type' => UserType::Admin, 'name' => 'Role admin', 'status' => 'active']);
        $roleAdmin->syncPermissions([Permission::RolesView, Permission::RolesCreate, Permission::RolesUpdate, Permission::PayinsView]);
        $actor = User::factory()->withTwoFactor()->create(['type' => UserType::Admin, 'role_id' => $roleAdmin->id, 'partner_id' => null]);

        $this->actingAs($actor)->post(route('admin.roles.store'), [
            'user_type' => 'admin',
            'name' => 'Escalated',
            'permissions' => ['settlements.update'],
        ])->assertSessionHasErrors('permissions');

        // Nor edit a role that is more powerful than their own.
        $this->actingAs($actor)->put(route('admin.roles.update', Role::bySlug(SystemRoles::ADMIN_FINANCE)), [
            'name' => 'Finance',
            'status' => 'active',
            'permissions' => [],
        ])->assertForbidden();
    }

    public function test_only_unused_custom_roles_can_be_deleted()
    {
        $admin = $this->superAdmin();
        $custom = Role::create(['user_type' => UserType::Partner, 'name' => 'Temporary', 'status' => 'active']);

        $this->actingAs($admin)->delete(route('admin.roles.destroy', Role::bySlug(SystemRoles::PARTNER_VIEWER)))->assertForbidden();
        $this->actingAs($admin)->delete(route('admin.roles.destroy', $custom))->assertRedirect();

        $this->assertDatabaseMissing('roles', ['id' => $custom->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'role.deleted', 'subject_id' => $custom->id]);
    }

    public function test_an_inactive_role_grants_nothing()
    {
        $user = User::factory()->branch(SystemRoles::BRANCH_OPERATOR)->create();
        $this->assertTrue($user->can('payins.approve'));

        $user->role->update(['status' => 'inactive']);

        $this->assertFalse($user->fresh()?->can('payins.approve'));
    }
}
