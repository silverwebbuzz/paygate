<?php

namespace Database\Seeders;

use App\Domain\Branch\Models\Branch;
use App\Domain\Commission\Models\CommissionRate;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\Models\Role;
use App\Domain\Core\Rbac\SystemRoles;
use App\Domain\Partner\Models\Partner;
use App\Domain\PaymentAccount\Actions\ChangeAccountStatus;
use App\Domain\PaymentAccount\Actions\ReviewPaymentAccount;
use App\Domain\PaymentAccount\Actions\SavePaymentAccount;
use App\Domain\PaymentAccount\Enums\AccountStatus;
use App\Domain\PaymentAccount\Models\PaymentAccount;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Local development data: one demo partner, one demo branch mapped to it,
 * one verified and active demo bank + UPI account (made-up numbers),
 * sample commission rates (partner pays 6% / 2.5%, branch earns 4% / 1.5%;
 * made-up values for trying the screens), and one user per built-in role. Password for all users: "password".
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

        $rates = [
            ['partner', $partner->id, 'deposit', '6'],
            ['partner', $partner->id, 'withdrawal', '2.5'],
            ['branch', $branch->id, 'deposit', '4'],
            ['branch', $branch->id, 'withdrawal', '1.5'],
        ];

        foreach ($rates as [$type, $id, $direction, $rate]) {
            CommissionRate::query()->firstOrCreate(
                ['subject_type' => $type, 'subject_id' => $id, 'side' => $type, 'direction' => $direction, 'effective_to' => null],
                ['fee_type' => 'percent', 'rate_percent' => $rate, 'effective_from' => now()],
            );
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
                'username' => strstr($email, '@', true),
                'type' => $role->user_type,
                'role_id' => $role->id,
                'partner_id' => $role->user_type->value === 'partner' ? $partner->id : null,
                'branch_id' => $role->user_type->value === 'branch' ? $branch->id : null,
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]);
        }

        $this->seedDemoAccount($branch);
    }

    private function seedDemoAccount(Branch $branch): void
    {
        if (PaymentAccount::query()->where('branch_id', $branch->id)->exists()) {
            return;
        }

        $admin = User::query()->where('email', 'admin@paygate.local')->firstOrFail();
        $account = app(SavePaymentAccount::class)->handle($admin, $branch, null, [
            'label' => 'Demo HDFC current',
            'account_holder_name' => 'Demo Branch Pvt Ltd',
            'is_bank_enabled' => true,
            'bank_name' => 'HDFC Bank',
            'ifsc' => 'HDFC0000001',
            'account_number' => '00000000000001',
            'is_upi_enabled' => true,
            'upi_id' => 'demo.branch@hdfcbank',
            'upi_display_name' => 'Demo Branch',
            'is_qr_enabled' => true,
            'daily_amount_limit' => 50000000,
            'max_open_sessions' => 5,
        ]);

        app(ReviewPaymentAccount::class)->approve($admin, $account);
        app(ChangeAccountStatus::class)->handle($admin, $account, AccountStatus::Active);
    }
}
