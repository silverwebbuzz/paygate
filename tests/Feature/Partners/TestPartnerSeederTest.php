<?php

namespace Tests\Feature\Partners;

use App\Domain\Branch\Models\Branch;
use App\Domain\Commission\Enums\Direction;
use App\Domain\Commission\RateBook;
use App\Domain\Commission\RatePercent;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Partner\Models\Partner;
use App\Domain\PaymentAccount\Models\PaymentAccount;
use Database\Seeders\TestPartnerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TestPartnerSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_five_partners_with_rates_branches_and_logins(): void
    {
        Branch::factory()->count(2)->create();

        $this->seed(TestPartnerSeeder::class);

        $codes = ['ECLINIC', 'TRILOK', 'MENET', 'SWBUZZIN', 'SWBUZZ'];
        $this->assertSame(5, Partner::query()->whereIn('code', $codes)->count());

        $partner = Partner::query()->where('code', 'ECLINIC')->firstOrFail();
        $this->assertSame('active', $partner->status->value);
        $this->assertTrue($partner->is_payin_enabled);
        $this->assertFalse($partner->is_payout_enabled);
        $this->assertNull($partner->withdraw_url);
        $this->assertNull($partner->payout_group);
        $this->assertSame(7, $partner->branches()->count());

        $branch = Branch::query()->where('code', 'BRANCH1')->firstOrFail();
        $this->assertSame('active', $branch->status->value);
        $this->assertTrue($branch->is_deposit_enabled);
        $this->assertSame(3, PaymentAccount::query()->where('branch_id', $branch->id)->where('status', 'active')->where('is_bank_enabled', true)->where('is_upi_enabled', true)->where('is_qr_enabled', true)->count());
        $this->assertSame(0, RatePercent::compare('2', (string) app(RateBook::class)->current('branch', $branch->id, 'branch', Direction::Deposit)));

        $branchUser = User::query()->where('username', 'branch1')->firstOrFail();
        $this->assertSame($branch->id, $branchUser->branch_id);
        $this->assertTrue(Hash::check('branch1', $branchUser->password));
        $this->assertSame(0, RatePercent::compare('5', (string) app(RateBook::class)->current('partner', $partner->id, 'partner', Direction::Deposit)));
        $this->assertSame(0, RatePercent::compare('4', (string) app(RateBook::class)->current('partner', $partner->id, 'partner', Direction::Withdrawal)));

        $user = User::query()->where('username', 'partner1')->firstOrFail();
        $this->assertSame($partner->id, $user->partner_id);
        $this->assertTrue(Hash::check('partner1', $user->password));

        $key = $partner->activeApiKey()->firstOrFail();
        $this->assertSame('pk_test_partner1', $key->key_id);
        $this->assertSame('sk_test_partner1', $key->secret_encrypted);

        $this->seed(TestPartnerSeeder::class);

        $this->assertSame(1, $partner->activeApiKey()->count());
        $this->assertSame(5, User::query()->whereIn('username', ['partner1', 'partner2', 'partner3', 'partner4', 'partner5'])->count());
        $this->assertSame(15, PaymentAccount::query()->whereIn('branch_id', Branch::query()->whereIn('code', ['BRANCH1', 'BRANCH2', 'BRANCH3', 'BRANCH4', 'BRANCH5'])->pluck('id'))->count());
    }
}
