<?php

namespace Database\Seeders;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\Models\Role;
use App\Domain\Core\Rbac\SystemRoles;
use App\Domain\Partner\Models\Partner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Local development data: one demo partner, one demo branch mapped to it,
 * and one user per built-in role. Password for all users: "password".
 * Never runs outside APP_ENV=local.
 */
class LocalDemoUserSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->isLocal()) {
            throw new RuntimeException('Demo data may only be seeded locally.');
        }

        $partner = Partner::updateOrCreate(['code' => 'DEMO-P'], [
            'name' => 'Demo Partner',
            'email' => 'partner@paygate.local',
            'website_url' => 'https://demo-merchant.local',
            'status' => 'active',
            'is_payin_enabled' => true,
            'is_payout_enabled' => true,
        ]);

        $branch = Branch::updateOrCreate(['code' => 'DEMO-B'], [
            'name' => 'Demo Branch',
            'status' => 'active',
            'is_deposit_enabled' => true,
            'is_withdrawal_enabled' => true,
        ]);

        if (! DB::table('partner_branch_mappings')->where(['partner_id' => $partner->id, 'branch_id' => $branch->id])->exists()) {
            DB::table('partner_branch_mappings')->insert([
                'id' => (string) Str::uuid7(),
                'partner_id' => $partner->id,
                'branch_id' => $branch->id,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $users = [
            'admin@paygate.local' => SystemRoles::ADMIN_SUPER,
            'ops@paygate.local' => SystemRoles::ADMIN_OPS,
            'finance@paygate.local' => SystemRoles::ADMIN_FINANCE,
            'partner@paygate.local' => SystemRoles::PARTNER_OWNER,
            'developer@paygate.local' => SystemRoles::PARTNER_DEVELOPER,
            'branch@paygate.local' => SystemRoles::BRANCH_OWNER,
            'operator@paygate.local' => SystemRoles::BRANCH_OPERATOR,
        ];

        foreach ($users as $email => $slug) {
            $role = Role::bySlug($slug);

            User::updateOrCreate(['email' => $email], [
                'name' => $role->user_type->label().' '.$role->name,
                'type' => $role->user_type,
                'role_id' => $role->id,
                'partner_id' => $role->user_type->value === 'partner' ? $partner->id : null,
                'branch_id' => $role->user_type->value === 'branch' ? $branch->id : null,
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]);
        }
    }
}
