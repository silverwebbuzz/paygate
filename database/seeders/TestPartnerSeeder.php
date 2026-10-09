<?php

namespace Database\Seeders;

use App\Domain\Branch\Models\Branch;
use App\Domain\Commission\Enums\Direction;
use App\Domain\Commission\Models\CommissionRate;
use App\Domain\Commission\RatePercent;
use App\Domain\Core\Identity\Enums\UserStatus;
use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\Models\Role;
use App\Domain\Core\Rbac\SystemRoles;
use App\Domain\Network\Models\PartnerBranchMapping;
use App\Domain\Partner\Models\Partner;
use App\Domain\Partner\Models\PartnerApiKey;
use App\Domain\PaymentAccount\Enums\AccountStatus;
use App\Domain\PaymentAccount\Enums\AccountVerification;
use App\Domain\PaymentAccount\Models\PaymentAccount;
use App\Support\Crypto\BlindIndex;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TestPartnerSeeder extends Seeder
{
    private const PARTNERS = [
        [
            'code' => 'ECLINIC',
            'name' => 'Eclinic Pro',
            'email' => 'pay@eclinicpro.com',
            'username' => 'partner1',
            'website_url' => 'https://eclinicpro.com',
            'return_url' => 'https://eclinicpro.com/partner1/return.php',
            'callback_url' => 'https://eclinicpro.com/partner1/webhook.php',
            'payin_webhook_url' => 'https://eclinicpro.com/partner1/webhook.php',
            'payout_webhook_url' => 'https://eclinicpro.com/partner1/webhook.php',
            'key_id' => 'pk_test_partner1',
            'secret' => 'sk_test_partner1',
        ],
        [
            'code' => 'TRILOK',
            'name' => 'Trilok Export',
            'email' => 'pay@trilokexport.com',
            'username' => 'partner2',
            'website_url' => 'https://trilokexport.com',
            'return_url' => 'https://trilokexport.com/partner2/return.php',
            'callback_url' => 'https://trilokexport.com/partner2/webhook.php',
            'payin_webhook_url' => 'https://trilokexport.com/partner2/webhook.php',
            'payout_webhook_url' => 'https://trilokexport.com/partner2/webhook.php',
            'key_id' => 'pk_test_partner2',
            'secret' => 'sk_test_partner2',
        ],
        [
            'code' => 'MENET',
            'name' => 'Menet Zero',
            'email' => 'pay@menetzero.com',
            'username' => 'partner3',
            'website_url' => 'https://menetzero.com',
            'return_url' => 'https://menetzero.com/partner3/return.php',
            'callback_url' => 'https://menetzero.com/partner3/webhook.php',
            'payin_webhook_url' => 'https://menetzero.com/partner3/webhook.php',
            'payout_webhook_url' => 'https://menetzero.com/partner3/webhook.php',
            'key_id' => 'pk_test_partner3',
            'secret' => 'sk_test_partner3',
        ],
        [
            'code' => 'SWBUZZIN',
            'name' => 'Silver Web Buzz IN',
            'email' => 'pay@silverwebbuzz.in',
            'username' => 'partner4',
            'website_url' => 'https://silverwebbuzz.in',
            'return_url' => 'https://silverwebbuzz.in/partner4/return.php',
            'callback_url' => 'https://silverwebbuzz.in/partner4/webhook.php',
            'payin_webhook_url' => 'https://silverwebbuzz.in/partner4/webhook.php',
            'payout_webhook_url' => 'https://silverwebbuzz.in/partner4/webhook.php',
            'key_id' => 'pk_test_partner4',
            'secret' => 'sk_test_partner4',
        ],
        [
            'code' => 'SWBUZZ',
            'name' => 'Silver Web Buzz',
            'email' => 'pay@silverwebbuzz.com',
            'username' => 'partner5',
            'website_url' => 'https://www.silverwebbuzz.com',
            'return_url' => 'https://www.silverwebbuzz.com/partner5/return.php',
            'callback_url' => 'https://www.silverwebbuzz.com/partner5/webhook.php',
            'payin_webhook_url' => 'https://www.silverwebbuzz.com/partner5/webhook.php',
            'payout_webhook_url' => 'https://www.silverwebbuzz.com/partner5/webhook.php',
            'key_id' => 'pk_test_partner5',
            'secret' => 'sk_test_partner5',
        ],
    ];

    public function run(): void
    {
        $partnerRole = Role::bySlug(SystemRoles::PARTNER_OWNER);
        $branchRole = Role::bySlug(SystemRoles::BRANCH_OWNER);

        DB::transaction(function () use ($partnerRole, $branchRole): void {
            $this->testBranches($branchRole);
            $branches = Branch::query()->orderBy('code')->get();

            foreach (self::PARTNERS as $entry) {
                $partner = $this->partner($entry);
                $this->rate('partner', $partner->id, 'partner', Direction::Deposit, '5');
                $this->rate('partner', $partner->id, 'partner', Direction::Withdrawal, '4');
                $this->branches($partner, $branches);
                $this->login($partner, $partnerRole, $entry['username'], UserType::Partner);
                $this->key($partner, $entry['key_id'], $entry['secret']);
            }
        });

        $this->command?->info('Seeded 5 test partners (deposit 5%, withdrawal 4%) and 5 test branches.');
        $this->command?->info('Partner logins: partner1 / partner1 through partner5 / partner5.');
        $this->command?->info('Branch logins: branch1 / branch1 through branch5 / branch5.');
        $this->command?->info('Each test branch has 3 active accounts with bank, UPI and QR, mapped to every partner.');
    }

    private function partner(array $entry): Partner
    {
        return Partner::query()->updateOrCreate(['code' => $entry['code']], [
            'name' => $entry['name'],
            'email' => $entry['email'],
            'website_url' => $entry['website_url'],
            'return_url' => $entry['return_url'],
            'callback_url' => $entry['callback_url'],
            'payin_webhook_url' => $entry['payin_webhook_url'],
            'payout_webhook_url' => $entry['payout_webhook_url'],
            'status' => 'active',
            'is_payin_enabled' => true,
            'is_payout_enabled' => true,
            'allow_upi' => true,
            'allow_qr' => true,
            'allow_bank_transfer' => true,
            'manual_payment_type' => 'bank_details',
            'withdraw_url' => null,
            'payout_group' => null,
            'payout_limit_type' => 'daily_reset',
            'deposit_min_amount' => 10000,
            'deposit_max_amount' => 10000000,
            'deposit_daily_limit' => null,
            'withdrawal_min_amount' => 10000,
            'withdrawal_max_amount' => 10000000,
            'withdrawal_daily_limit' => null,
            'session_ttl_minutes' => 30,
        ]);
    }

    private function rate(string $subjectType, string $subjectId, string $side, Direction $direction, string $percent): void
    {
        $percent = RatePercent::normalize($percent);
        $current = CommissionRate::query()
            ->for($subjectType, $subjectId, $side, $direction)
            ->whereNull('effective_to')
            ->lockForUpdate()
            ->first();

        if ($current !== null && RatePercent::compare($current->rate_percent, $percent) === 0) {
            return;
        }

        $now = now();

        if ($current !== null) {
            $current->effective_from->greaterThanOrEqualTo($now)
                ? $current->delete()
                : $current->update(['effective_to' => $now]);
        }

        CommissionRate::query()->create([
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'side' => $side,
            'direction' => $direction,
            'fee_type' => 'percent',
            'rate_percent' => $percent,
            'effective_from' => $now,
        ]);
    }

    private function branches(Partner $partner, Collection $branches): void
    {
        foreach ($branches as $branch) {
            PartnerBranchMapping::query()->updateOrCreate(
                ['partner_id' => $partner->id, 'branch_id' => $branch->id],
                ['status' => 'active', 'is_deposit_enabled' => true, 'is_withdrawal_enabled' => true],
            );
        }
    }

    private function login(Partner|Branch $organisation, Role $role, string $username, UserType $type): void
    {
        $existing = User::query()->where('username', $username)->first();

        if ($existing !== null && $existing->type !== $type) {
            throw new RuntimeException("Username {$username} is already used by a {$existing->type->value} user.");
        }

        User::query()->updateOrCreate(['username' => $username], [
            'name' => $username,
            'email' => null,
            'password' => $username,
            'type' => $type,
            'role_id' => $role->id,
            'partner_id' => $type === UserType::Partner ? $organisation->id : null,
            'branch_id' => $type === UserType::Branch ? $organisation->id : null,
            'status' => UserStatus::Active,
            'email_verified_at' => now(),
        ]);
    }

    private function testBranches(Role $role): void
    {
        $banks = [
            ['HDFC Bank', 'HDFC', 'hdfcbank'],
            ['ICICI Bank', 'ICIC', 'icici'],
            ['State Bank of India', 'SBIN', 'sbi'],
        ];

        foreach (range(1, 5) as $number) {
            $branch = Branch::query()->updateOrCreate(['code' => 'BRANCH'.$number], [
                'name' => 'Test Branch '.$number,
                'status' => 'active',
                'is_deposit_enabled' => true,
                'is_withdrawal_enabled' => true,
                'deposit_limit_type' => 'daily_reset',
                'deposit_min_amount' => null,
                'deposit_max_amount' => null,
                'deposit_daily_limit' => null,
            ]);

            $this->rate('branch', $branch->id, 'branch', Direction::Deposit, '2');
            $this->rate('branch', $branch->id, 'branch', Direction::Withdrawal, '1');
            $this->login($branch, $role, 'branch'.$number, UserType::Branch);

            foreach ($banks as $index => [$bankName, $ifscPrefix, $upiBank]) {
                $slot = $index + 1;
                $numberText = sprintf('%02d%02d', $number, $slot);
                $accountNumber = '91'.$numberText.sprintf('%010d', $number * 10 + $slot);
                $ifsc = $ifscPrefix.'0'.$numberText.'01';
                $upiId = 'branch'.$number.'acc'.$slot.'@ok'.$upiBank;

                PaymentAccount::query()->updateOrCreate(
                    ['branch_id' => $branch->id, 'label' => $bankName.' '.$number.'-'.$slot],
                    [
                        'is_bank_enabled' => true,
                        'is_upi_enabled' => true,
                        'is_qr_enabled' => true,
                        'bank_name' => $bankName,
                        'ifsc' => $ifsc,
                        'account_holder_name' => 'Test Branch '.$number,
                        'account_number_encrypted' => $accountNumber,
                        'account_number_hash' => BlindIndex::of('bank_account', $accountNumber),
                        'account_number_last4' => substr($accountNumber, -4),
                        'upi_id_encrypted' => $upiId,
                        'upi_id_hash' => BlindIndex::of('upi_id', $upiId),
                        'upi_id_last4' => substr('acc'.$slot, -4),
                        'upi_display_name' => 'Test Branch '.$number,
                        'verification' => AccountVerification::Verified,
                        'status' => AccountStatus::Active,
                        'verified_at' => now(),
                        'min_amount' => null,
                        'max_amount' => null,
                        'daily_amount_limit' => null,
                        'daily_count_limit' => null,
                        'max_open_sessions' => 100,
                    ],
                );
            }
        }
    }

    private function key(Partner $partner, string $keyId, string $secret): void
    {
        $key = PartnerApiKey::query()->where('partner_id', $partner->id)->where('status', 'active')->first();

        $attributes = [
            'key_id' => $keyId,
            'secret_encrypted' => $secret,
            'secret_last4' => substr($secret, -4),
            'status' => 'active',
        ];

        if ($key === null) {
            PartnerApiKey::query()->create(['partner_id' => $partner->id, ...$attributes]);

            return;
        }

        $key->forceFill($attributes)->save();
    }
}
