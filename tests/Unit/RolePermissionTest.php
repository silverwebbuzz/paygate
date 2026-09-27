<?php

namespace Tests\Unit;

use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Core\Rbac\Enums\Permission;
use App\Domain\Core\Rbac\SystemRoles;
use PHPUnit\Framework\TestCase;

class RolePermissionTest extends TestCase
{
    public function test_built_in_roles_only_hold_permissions_allowed_for_their_portal()
    {
        foreach (SystemRoles::definitions() as $slug => $definition) {
            $this->assertStringStartsWith($definition['type']->value.'.', $slug);

            foreach ($definition['permissions'] as $permission) {
                $this->assertTrue($permission->allowedFor($definition['type']), "{$slug} must not have {$permission->value}");
            }
        }
    }

    public function test_admin_only_permissions_are_never_available_to_partners_or_branches()
    {
        foreach ([Permission::PartnersUpdate, Permission::CommissionsUpdate, Permission::SettlementsUpdate, Permission::RolesUpdate, Permission::AccountsVerify, Permission::HorizonView] as $permission) {
            $this->assertSame([UserType::Admin], $permission->userTypes(), $permission->value);
        }
    }

    public function test_separation_of_duties_between_admin_roles()
    {
        $roles = SystemRoles::definitions();

        $this->assertNotContains(Permission::SettlementsUpdate, $roles[SystemRoles::ADMIN_OPS]['permissions']);
        $this->assertNotContains(Permission::AccountsVerify, $roles[SystemRoles::ADMIN_FINANCE]['permissions']);
        $this->assertNotContains(Permission::PayinsApprove, $roles[SystemRoles::ADMIN_VIEWER]['permissions']);
    }

    public function test_branch_operator_can_approve_deposits_but_not_edit_bank_accounts()
    {
        $operator = SystemRoles::definitions()[SystemRoles::BRANCH_OPERATOR]['permissions'];

        $this->assertContains(Permission::PayinsApprove, $operator);
        $this->assertNotContains(Permission::AccountsUpdate, $operator);
    }

    public function test_super_admin_and_owners_get_everything_their_portal_allows()
    {
        $roles = SystemRoles::definitions();

        $this->assertEqualsCanonicalizing(Permission::forType(UserType::Admin), $roles[SystemRoles::ADMIN_SUPER]['permissions']);
        $this->assertEqualsCanonicalizing(Permission::forType(UserType::Partner), $roles[SystemRoles::PARTNER_OWNER]['permissions']);
        $this->assertEqualsCanonicalizing(Permission::forType(UserType::Branch), $roles[SystemRoles::BRANCH_OWNER]['permissions']);
    }

    public function test_every_permission_belongs_to_a_menu_available_to_its_portals()
    {
        foreach (Permission::cases() as $permission) {
            $this->assertNotEmpty($permission->userTypes(), $permission->value);

            foreach ($permission->userTypes() as $type) {
                $this->assertContains($type, $permission->menu()->userTypes(), $permission->value);
            }
        }
    }

    public function test_partners_create_payments_and_branches_approve_them()
    {
        $this->assertSame([UserType::Admin, UserType::Partner], Permission::PayinsCreate->userTypes());
        $this->assertSame([UserType::Admin, UserType::Branch], Permission::PayinsApprove->userTypes());
        $this->assertSame([UserType::Admin, UserType::Branch], Permission::PayoutsProcess->userTypes());
        $this->assertFalse(Permission::AccountsVerify->allowedFor(UserType::Branch));
    }
}
