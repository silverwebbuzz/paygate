<?php

namespace Tests\Unit;

use App\Auth\SystemRoles;
use App\Enums\Permission;
use App\Enums\UserType;
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
        foreach ([Permission::PartnerManage, Permission::CommissionManage, Permission::SettlementManage, Permission::RoleManage, Permission::HorizonView] as $permission) {
            $this->assertSame([UserType::Admin], $permission->userTypes(), $permission->value);
        }
    }

    public function test_separation_of_duties_between_admin_roles()
    {
        $roles = SystemRoles::definitions();

        $this->assertNotContains(Permission::SettlementManage, $roles[SystemRoles::ADMIN_OPS]['permissions']);
        $this->assertNotContains(Permission::AccountVerify, $roles[SystemRoles::ADMIN_FINANCE]['permissions']);
        $this->assertNotContains(Permission::TransactionDecide, $roles[SystemRoles::ADMIN_VIEWER]['permissions']);
    }

    public function test_branch_operator_can_approve_deposits_but_not_edit_bank_accounts()
    {
        $operator = SystemRoles::definitions()[SystemRoles::BRANCH_OPERATOR]['permissions'];

        $this->assertContains(Permission::TransactionDecide, $operator);
        $this->assertNotContains(Permission::AccountManage, $operator);
    }

    public function test_super_admin_and_owners_get_everything_their_portal_allows()
    {
        $roles = SystemRoles::definitions();

        $this->assertEqualsCanonicalizing(Permission::forType(UserType::Admin), $roles[SystemRoles::ADMIN_SUPER]['permissions']);
        $this->assertEqualsCanonicalizing(Permission::forType(UserType::Partner), $roles[SystemRoles::PARTNER_OWNER]['permissions']);
        $this->assertEqualsCanonicalizing(Permission::forType(UserType::Branch), $roles[SystemRoles::BRANCH_OWNER]['permissions']);
    }
}
