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
        $role = Role::bySlug(SystemRoles::PARTNER_OWNER);
        $branches = Branch::query()->orderBy('code')->get();

        DB::transaction(function () use ($role, $branches): void {
            foreach (self::PARTNERS as $entry) {
                $partner = $this->partner($entry);
                $this->rate($partner, Direction::Deposit, '5');
                $this->rate($partner, Direction::Withdrawal, '4');
                $this->branches($partner, $branches);
                $this->login($partner, $role, $entry['username']);
                $this->key($partner, $entry['key_id'], $entry['secret']);
            }
        });

        $count = $branches->count();
        $this->command?->info("Seeded 5 test partners, deposit 5%, withdrawal 4%, {$count} branch".($count === 1 ? '' : 'es').' each.');
        $this->command?->info('Logins: partner1 / partner1 through partner5 / partner5.');
        $this->command?->info('API keys: pk_test_partner1 … pk_test_partner5, secrets sk_test_partner1 … sk_test_partner5.');

        if ($count === 0) {
            $this->command?->warn('No branches exist yet, so these partners have nowhere to send a payment.');
        }
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
            'is_payout_enabled' => false,
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

    private function rate(Partner $partner, Direction $direction, string $percent): void
    {
        $percent = RatePercent::normalize($percent);
        $current = CommissionRate::query()
            ->for('partner', $partner->id, 'partner', $direction)
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
            'subject_type' => 'partner',
            'subject_id' => $partner->id,
            'side' => 'partner',
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

    private function login(Partner $partner, Role $role, string $username): void
    {
        $existing = User::query()->where('username', $username)->first();

        if ($existing !== null && $existing->type !== UserType::Partner) {
            throw new RuntimeException("Username {$username} is already used by a {$existing->type->value} user.");
        }

        User::query()->updateOrCreate(['username' => $username], [
            'name' => $username,
            'email' => null,
            'password' => $username,
            'type' => UserType::Partner,
            'role_id' => $role->id,
            'partner_id' => $partner->id,
            'branch_id' => null,
            'status' => UserStatus::Active,
            'email_verified_at' => now(),
        ]);
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
