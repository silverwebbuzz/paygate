<?php

namespace Tests\Feature\Payins;

use App\Domain\Allocation\UsageCounters;
use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\SystemRoles;
use App\Domain\Network\Models\PartnerBranchMapping;
use App\Domain\Notification\Enums\Alert;
use App\Domain\Notification\Notifications\PortalAlert;
use App\Domain\PaymentAccount\Actions\ChangeAccountStatus;
use App\Domain\PaymentAccount\Enums\AccountStatus;
use App\Domain\Platform\Models\StoredFile;
use App\Domain\Transaction\Actions\ClosePayin;
use App\Domain\Transaction\Models\Transaction;
use App\Domain\Transaction\Models\TransactionEvent;
use App\Http\Checkout\UpiLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsPayinNetwork;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use BuildsPayinNetwork, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildNetwork();
    }

    private function page(string $token): string
    {
        return "http://pay.paygate.local/p/{$token}";
    }

    private function choose(string $token, string $method)
    {
        return $this->post($this->page($token).'/method', ['method' => $method]);
    }

    private function payin(string $reference): Transaction
    {
        return Transaction::where('reference', $reference)->firstOrFail();
    }

    private function reserved(string $scope, string $id): int
    {
        return (int) DB::table('usage_counters')
            ->where(['scope_type' => $scope, 'scope_id' => $id, 'business_date' => UsageCounters::businessDate(), 'direction' => 'deposit'])
            ->value('reserved_amount');
    }

    public function test_the_page_shows_the_amount_and_methods_and_unknown_tokens_404()
    {
        $this->activeAccount();
        [, $token] = $this->createPayin(['amount' => 1253400]);

        $this->get($this->page($token))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('checkout/show')
                ->where('payin.amount', 1253400)
                ->where('payin.customer_name', 'Ravi Kumar')
                ->where('payin.status', 'created')
                ->where('methods', ['upi', 'qr', 'bank_transfer'])
                ->where('account', null));

        [, $nameless] = $this->createPayin(['customer' => ['id' => 'cust-2']]);
        $this->get($this->page($nameless))->assertInertia(fn (Assert $page) => $page->where('payin.customer_name', null));

        $this->get($this->page('not-a-real-token'))->assertNotFound();
    }

    public function test_the_page_only_offers_methods_an_account_can_take_now()
    {
        $this->activeAccount(overrides: ['is_upi_enabled' => false, 'upi_id' => null, 'is_qr_enabled' => false, 'max_open_sessions' => 1]);

        [, $token] = $this->createPayin(['amount' => 500000]);
        $this->get($this->page($token))->assertInertia(fn (Assert $page) => $page->where('methods', ['bank_transfer']));

        $this->choose($token, 'bank_transfer')->assertSessionHasNoErrors();
        $this->get($this->page($token))->assertInertia(fn (Assert $page) => $page->where('methods', ['bank_transfer']));

        [, $other] = $this->createPayin(['amount' => 500000]);
        $this->get($this->page($other))->assertInertia(fn (Assert $page) => $page->where('methods', []));

        $this->artisan('paygate:why-busy', ['--partner' => $this->partner->code, '--amount' => '5000'])
            ->expectsOutputToContain('open-payment slots')
            ->assertSuccessful();
    }

    public function test_every_switch_and_range_of_partner_branch_pair_and_account_is_respected()
    {
        $this->activeAccount(overrides: ['min_amount' => 20000, 'max_amount' => 800000, 'daily_count_limit' => 2]);
        $mapping = PartnerBranchMapping::query()->where(['partner_id' => $this->partner->id, 'branch_id' => $this->branch->id])->sole();
        $offered = function (int $amount = 500000): array {
            [, $token] = $this->createPayin(['amount' => $amount]);

            return $this->get($this->page($token))->viewData('page')['props']['methods'];
        };
        $all = ['upi', 'qr', 'bank_transfer'];

        $this->assertSame($all, $offered());
        $this->assertSame([], $offered(10000));
        $this->assertSame([], $offered(900000));

        $this->partner->update(['allow_qr' => false]);
        $this->assertSame(['upi', 'bank_transfer'], $offered());
        [, $token] = $this->createPayin(['amount' => 500000]);
        $this->choose($token, 'qr')->assertSessionHasErrors('method');
        $this->partner->update(['allow_qr' => true]);

        foreach ([
            [$this->branch, ['deposit_min_amount' => 600000], ['deposit_min_amount' => null]],
            [$this->branch, ['deposit_max_amount' => 400000], ['deposit_max_amount' => null]],
            [$this->branch, ['is_deposit_enabled' => false], ['is_deposit_enabled' => true]],
            [$mapping, ['is_deposit_enabled' => false], ['is_deposit_enabled' => true]],
            [$mapping, ['deposit_daily_limit' => 400000], ['deposit_daily_limit' => null]],
        ] as [$model, $off, $on]) {
            $model->update($off);
            $this->assertSame([], $offered(), json_encode($off));
            $model->update($on);
            $this->assertSame($all, $offered());
        }

        foreach (range(1, 2) as $i) {
            [, $token] = $this->createPayin(['amount' => 500000]);
            $this->choose($token, 'upi')->assertSessionHasNoErrors();
        }

        [, $third] = $this->createPayin(['amount' => 500000]);
        $this->assertSame([], $this->get($this->page($third))->viewData('page')['props']['methods']);
        $this->choose($third, 'upi')->assertSessionHasErrors('method');
    }

    public function test_admin_is_alerted_once_when_customers_cannot_pay()
    {
        Notification::fake();
        $admin = User::factory()->admin(SystemRoles::ADMIN_OPS)->withTwoFactor()->create();
        $this->activeAccount(overrides: ['daily_amount_limit' => 600000]);

        [, $first] = $this->createPayin(['amount' => 500000]);
        $this->choose($first, 'upi')->assertSessionHasNoErrors();

        [$reference, $second] = $this->createPayin(['amount' => 500000]);
        $this->get($this->page($second))->assertInertia(fn (Assert $page) => $page->where('methods', []));
        $this->choose($second, 'upi')->assertSessionHasErrors('method');

        $alerts = Notification::sent($admin, PortalAlert::class, fn (PortalAlert $alert) => $alert->alert === Alert::PayinNoAccount);
        $this->assertCount(1, $alerts);
        $this->assertStringContainsString($reference, $alerts->first()->body);
        $this->assertStringContainsString('account daily amount limit reached', $alerts->first()->body);
    }

    public function test_choosing_a_method_allocates_an_account_and_reserves_its_capacity()
    {
        $account = $this->activeAccount();
        [$reference, $token] = $this->createPayin(['amount' => 500000]);

        $this->choose($token, 'bank_transfer')->assertRedirect($this->page($token));

        $payin = $this->payin($reference);
        $this->assertSame('awaiting_payment', $payin->status);
        $this->assertSame($account->id, $payin->payment_account_id);
        $this->assertSame($this->branch->id, $payin->branch_id);
        $this->assertSame('bank_transfer', $payin->method);
        $this->assertSame(500000, $this->reserved('account', $account->id));
        $this->assertSame(500000, $this->reserved('branch', $this->branch->id));
        $this->assertSame(500000, $this->reserved('partner', $this->partner->id));

        $this->get($this->page($token))->assertInertia(fn (Assert $page) => $page
            ->where('account.account_number', '50100482710001')
            ->where('account.ifsc', 'HDFC0001203')
            ->where('account.upi_id', 'holder1@hdfcbank')
            ->has('account.qr_svg')
            ->missing('account.apps'));
    }

    public function test_the_checkout_qr_is_built_for_that_payins_amount()
    {
        $account = $this->activeAccount();
        [$reference, $token] = $this->createPayin(['amount' => 500000]);

        $this->choose($token, 'qr')->assertRedirect($this->page($token));

        $payin = $this->payin($reference);
        $link = UpiLinks::link($account, $payin);

        $this->assertSame('qr', $payin->method);
        $this->assertStringContainsString('pa=holder1%40hdfcbank', $link);
        $this->assertStringContainsString('am=5000.00', $link);
        $this->assertStringContainsString('cu=INR', $link);
        $this->assertStringContainsString('tr='.$reference, $link);

        $this->get($this->page($token))->assertInertia(fn (Assert $page) => $page
            ->where('payin.method', 'qr')
            ->where('account.qr_svg', fn ($svg) => is_string($svg) && str_contains($svg, '<svg')));
    }

    public function test_accounts_are_used_in_turn()
    {
        $first = $this->activeAccount();
        $second = $this->activeAccount();

        $used = [];

        foreach (range(1, 4) as $i) {
            [$reference, $token] = $this->createPayin();
            $this->choose($token, 'upi');
            $used[] = $this->payin($reference)->payment_account_id;
            $this->travel(1)->seconds();
        }

        $this->assertSame([$first->id, $second->id, $first->id, $second->id], $used);
    }

    public function test_mapped_branches_take_turns_before_a_branch_is_used_again()
    {
        $second = Branch::factory()->create();
        $this->mapPair($this->partner, $second);

        $a1 = $this->activeAccount();
        $a2 = $this->activeAccount();
        $b1 = $this->activeAccount($second);
        $b2 = $this->activeAccount($second);

        $used = [];

        foreach (range(1, 4) as $i) {
            [$reference, $token] = $this->createPayin();
            $this->choose($token, 'upi');
            $used[] = $this->payin($reference)->payment_account_id;
        }

        // No waiting between customers: a burst in the same second still rotates.
        $this->assertSame([$a1->id, $b1->id, $a2->id, $b2->id], $used);
    }

    public function test_full_accounts_are_skipped_and_nothing_left_means_unavailable()
    {
        $small = $this->activeAccount(overrides: ['daily_amount_limit' => 600000]);
        $big = $this->activeAccount();

        [$a, $tokenA] = $this->createPayin(['amount' => 500000]);
        $this->choose($tokenA, 'upi');
        $this->assertSame($small->id, $this->payin($a)->payment_account_id);

        // The small account has only ₹1,000 left: the next one goes elsewhere.
        [$b, $tokenB] = $this->createPayin(['amount' => 500000]);
        $this->choose($tokenB, 'upi');
        $this->assertSame($big->id, $this->payin($b)->payment_account_id);

        app(ChangeAccountStatus::class)->handle($this->networkAdmin, $big, AccountStatus::Inactive, 'Too big for now');

        [$c, $tokenC] = $this->createPayin(['amount' => 500000]);
        $this->choose($tokenC, 'upi')->assertSessionHasErrors('method');
        $this->assertSame('created', $this->payin($c)->status);
        $this->assertSame(0, $this->reserved('account', $big->id) - 500000);
    }

    public function test_only_mapped_active_branches_and_accounts_that_support_the_method_are_used()
    {
        $unmapped = Branch::factory()->create();
        $this->activeAccount($unmapped);
        $noUpi = $this->activeAccount(overrides: ['is_upi_enabled' => false, 'upi_id' => null, 'is_qr_enabled' => false]);

        [, $token] = $this->createPayin();
        $this->choose($token, 'upi')->assertSessionHasErrors('method');

        [$reference, $token] = $this->createPayin();
        $this->choose($token, 'bank_transfer')->assertSessionHasNoErrors();
        $this->assertSame($noUpi->id, $this->payin($reference)->payment_account_id);
    }

    public function test_branch_and_partner_daily_limits_apply()
    {
        $this->activeAccount();
        $this->branch->update(['deposit_daily_limit' => 700000]);

        [, $a] = $this->createPayin(['amount' => 500000]);
        $this->choose($a, 'upi')->assertSessionHasNoErrors();
        [, $b] = $this->createPayin(['amount' => 500000]);
        $this->choose($b, 'upi')->assertSessionHasErrors('method');

        // A top-up branch can only take what's left of its allowance.
        $this->branch->update(['deposit_limit_type' => 'topup', 'deposit_topup_balance' => 900000]);
        [, $c] = $this->createPayin(['amount' => 300000]);
        $this->choose($c, 'upi')->assertSessionHasNoErrors();
    }

    public function test_switching_to_a_method_the_account_lacks_moves_to_another_account_and_releases_the_first()
    {
        $upiOnly = $this->activeAccount(overrides: ['is_bank_enabled' => false, 'bank_name' => null, 'ifsc' => null, 'account_number' => null]);
        $bank = $this->activeAccount();

        [$reference, $token] = $this->createPayin(['amount' => 200000]);
        $this->choose($token, 'upi');
        $this->assertSame($upiOnly->id, $this->payin($reference)->payment_account_id);

        $this->choose($token, 'bank_transfer')->assertSessionHasNoErrors();
        $this->assertSame($bank->id, $this->payin($reference)->payment_account_id);
        $this->assertSame(0, $this->reserved('account', $upiOnly->id));
        $this->assertSame(200000, $this->reserved('account', $bank->id));
        $this->assertSame(200000, $this->reserved('partner', $this->partner->id));
    }

    public function test_approving_after_a_switch_confirms_the_new_accounts_reservation()
    {
        $this->rate('partner', $this->partner->id, 'deposit', '6');
        $this->rate('branch', $this->branch->id, 'deposit', '4');
        $this->activeAccount(overrides: ['is_bank_enabled' => false, 'bank_name' => null, 'ifsc' => null, 'account_number' => null]);
        $bank = $this->activeAccount();

        [$reference, $token] = $this->createPayin(['amount' => 200000]);
        $this->choose($token, 'upi');
        $this->choose($token, 'bank_transfer');
        $this->post($this->page($token).'/proof', ['utr' => '626812820491'])->assertSessionHasNoErrors();
        $this->actingAs(User::factory()->admin()->withTwoFactor()->create())->post(route('admin.deposits.approve', $this->payin($reference)), ['bank_utr' => '626812820491'])->assertSessionHasNoErrors();

        $this->assertSame(0, $this->reserved('account', $bank->id));
    }

    public function test_the_customer_submits_a_utr_or_a_screenshot()
    {
        Storage::fake('local');
        $this->activeAccount();

        [$reference, $token] = $this->createPayin();
        $this->post($this->page($token).'/proof', ['utr' => '6268 1282 0491'])->assertSessionHasErrors('utr'); // no method yet
        $this->choose($token, 'upi');
        $this->post($this->page($token).'/proof', [])->assertSessionHasErrors('utr');
        $this->post($this->page($token).'/proof', ['utr' => '12'])->assertSessionHasErrors('utr');
        $this->post($this->page($token).'/proof', ['utr' => '6268 1282 0491'])->assertSessionHasNoErrors();

        $payin = $this->payin($reference);
        $this->assertSame('payment_submitted', $payin->status);
        $this->assertSame('626812820491', $payin->customer_utr_normalized);
        $this->assertNotNull($payin->submitted_at);

        // Screenshot only.
        [$reference, $token] = $this->createPayin();
        $this->choose($token, 'bank_transfer');
        $this->post($this->page($token).'/proof', ['photo' => UploadedFile::fake()->image('paid.png')])->assertSessionHasNoErrors();

        $file = StoredFile::where('attachable_id', $this->payin($reference)->id)->firstOrFail();
        $this->assertSame('payment_proof', $file->purpose);
        Storage::disk('local')->assertExists($file->path);
        $this->assertStringNotContainsString('public', $file->path);
    }

    public function test_a_utr_is_one_payment_a_claimed_one_is_refused_and_a_declined_one_flagged()
    {
        $this->activeAccount();
        $tokens = [];

        foreach (['A', 'B', 'C'] as $order) {
            [${'ref'.$order}, $tokens[$order]] = $this->createPayin();
            $this->choose($tokens[$order], 'upi');
        }

        $this->post($this->page($tokens['A']).'/proof', ['utr' => '626812820491'])->assertSessionHasNoErrors();
        // Same UTR, even typed differently, while A waits for the branch.
        $this->post($this->page($tokens['B']).'/proof', ['utr' => '6268 1282 0491'])->assertSessionHasErrors('utr');
        $this->assertSame('awaiting_payment', $this->payin($refB)->status);

        // Once A is declined, the UTR is free again, and the branch sees it was used on A.
        $admin = User::factory()->admin()->withTwoFactor()->create();
        $this->actingAs($admin)->post(route('admin.deposits.decline', $this->payin($refA)), ['reason_code' => 'payment_not_found'])->assertSessionHasNoErrors();
        $this->post($this->page($tokens['B']).'/proof', ['utr' => '626812820491'])->assertSessionHasNoErrors();
        $event = TransactionEvent::where(['transaction_id' => $this->payin($refB)->id, 'event' => 'proof_submitted'])->firstOrFail();
        $this->assertSame($refA, $event->data['possible_duplicate_of']);

        // Approved with it, B holds the UTR for good.
        $this->rate('partner', $this->partner->id, 'deposit', '6');
        $this->rate('branch', $this->branch->id, 'deposit', '4');
        $this->actingAs($admin)->post(route('admin.deposits.approve', $this->payin($refB)), ['bank_utr' => '626812820491'])->assertSessionHasNoErrors();
        $this->post($this->page($tokens['C']).'/proof', ['utr' => '626812820491'])->assertSessionHasErrors('utr');
    }

    public function test_expiry_releases_capacity_and_the_page_expires_on_the_spot()
    {
        $account = $this->activeAccount();
        [$reference, $token] = $this->createPayin(['amount' => 300000]);
        $this->choose($token, 'upi');
        $this->assertSame(300000, $this->reserved('account', $account->id));

        $this->travel(16)->minutes();

        // The customer opens the page before the job runs: already expired.
        $this->get($this->page($token))->assertInertia(fn (Assert $page) => $page->where('payin.status', 'expired')->where('account', null));
        $this->assertSame(0, $this->reserved('account', $account->id));

        // The scheduled job skips it (already closed) and handles the rest.
        [$other] = $this->createPayin();
        $this->travel(16)->minutes();
        $this->assertSame(1, app(ClosePayin::class)->expireDue());
        $this->assertSame('expired', $this->payin($other)->status);
        $this->assertSame(1, TransactionEvent::where(['transaction_id' => $this->payin($reference)->id, 'event' => 'allocation_released'])->count());
    }

    public function test_submitted_payins_do_not_expire()
    {
        $this->activeAccount();
        [$reference, $token] = $this->createPayin();
        $this->choose($token, 'upi');
        $this->post($this->page($token).'/proof', ['utr' => '626812820491']);

        $this->travel(1)->hours();
        app(ClosePayin::class)->expireDue();

        $this->assertSame('payment_submitted', $this->payin($reference)->status);
    }

    public function test_cancelling_releases_the_reservation()
    {
        $account = $this->activeAccount();
        [$reference, $token] = $this->createPayin(['amount' => 250000]);
        $this->choose($token, 'upi');

        $this->api('POST', "/v1/payins/{$reference}/cancel")->assertOk();

        $this->assertSame(0, $this->reserved('account', $account->id));
        $this->get($this->page($token))->assertInertia(fn (Assert $page) => $page->where('payin.status', 'cancelled'));
    }

    public function test_page_views_and_method_changes_do_not_use_up_the_submit_limit()
    {
        $this->activeAccount();
        [$reference, $token] = $this->createPayin();

        foreach (range(1, 12) as $i) {
            $this->get($this->page($token))->assertOk();
        }

        foreach (range(1, 6) as $i) {
            $this->choose($token, $i % 2 ? 'upi' : 'qr')->assertRedirect();
        }

        $this->post($this->page($token).'/proof', ['utr' => '626812820491'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('payment_submitted', $this->payin($reference)->status);
    }

    public function test_a_customer_who_submitted_proof_frees_their_account_slot()
    {
        $account = $this->activeAccount(overrides: ['max_open_sessions' => 1]);

        [$first, $tokenA] = $this->createPayin(['amount' => 100000]);
        $this->choose($tokenA, 'upi')->assertSessionHasNoErrors();

        // The only slot is taken by a customer still on the page.
        [, $tokenB] = $this->createPayin(['amount' => 100000]);
        $this->choose($tokenB, 'upi')->assertSessionHasErrors('method');

        // Once the first customer submits their UTR the slot frees up,
        // while their amount stays reserved.
        $this->post($this->page($tokenA).'/proof', ['utr' => '626812820491'])->assertSessionHasNoErrors();
        $this->choose($tokenB, 'upi')->assertSessionHasNoErrors();

        $this->assertSame(200000, $this->reserved('account', $account->id));
        $this->assertSame(1, (int) DB::table('usage_counters')->where(['scope_type' => 'account', 'scope_id' => $account->id])->value('open_sessions'));
        $this->assertSame('payment_submitted', $this->payin($first)->status);
    }
}
