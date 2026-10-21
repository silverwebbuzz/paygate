<?php

namespace Tests\Feature\Payins;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\SystemRoles;
use App\Domain\Notification\AlertDispatcher;
use App\Domain\Notification\Enums\Alert;
use App\Domain\Notification\Notifications\PortalAlert;
use App\Domain\PaymentAccount\Models\PaymentAccount;
use App\Domain\Platform\Settings;
use App\Domain\Transaction\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsPayinNetwork;
use Tests\TestCase;

/**
 * Duplicates and the manual paths around them (decided 2026-10-21):
 * double clicks on the partner's site, two deposits with one UTR (same
 * account or two branches), approving with another UTR, and approvals no
 * bank line ever confirms.
 */
class DuplicateSafetyTest extends TestCase
{
    use BuildsPayinNetwork, RefreshDatabase;

    private const UTR = '626812820491';

    private const CUSTOMER = ['id' => 'abc', 'name' => 'ABC'];

    private PaymentAccount $account;

    private Branch $paidBranch;

    private PaymentAccount $paidAccount;

    private User $admin;

    private User $operator;

    private User $paidOperator;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Notification::fake();
        $this->buildNetwork();
        $this->account = $this->activeAccount();
        $this->paidBranch = Branch::factory()->create();
        $this->mapPair($this->partner, $this->paidBranch);
        $this->paidAccount = $this->activeAccount($this->paidBranch);
        $this->rate('partner', $this->partner->id, 'deposit', '6');
        $this->rate('branch', $this->branch->id, 'deposit', '4');
        $this->rate('branch', $this->paidBranch->id, 'deposit', '4');
        $this->admin = User::factory()->admin()->withTwoFactor()->create();
        $this->operator = User::factory()->branch(SystemRoles::BRANCH_OPERATOR, $this->branch)->withTwoFactor()->create();
        $this->paidOperator = User::factory()->branch(SystemRoles::BRANCH_OPERATOR, $this->paidBranch)->withTwoFactor()->create();
    }

    /** A submitted ₹5,000 deposit of customer ABC on the given account. */
    private function depositOn(PaymentAccount $on, string $utr = self::UTR): Transaction
    {
        $other = $on->is($this->account) ? $this->paidAccount : $this->account;
        $other->update(['status' => 'inactive']);
        $payin = $this->submittedPayin(['amount' => 500000, 'customer' => self::CUSTOMER], $utr);
        $other->update(['status' => 'active']);
        $this->assertSame($on->id, $payin->payment_account_id);

        return $payin;
    }

    private function addLine(User $as, PaymentAccount $account, string $utr = self::UTR)
    {
        return $this->actingAs($as)->post(route($as->type->value.'.statements.store'), [
            'payment_account_id' => $account->id,
            'value_date' => now(config('app.business_timezone'))->toDateString(),
            'utr' => $utr,
            'credit' => '5000',
        ]);
    }

    private function approve(User $as, Transaction $payin, string $utr = self::UTR, ?string $note = null)
    {
        return $this->actingAs($as)->post(route($as->type->value.'.deposits.approve', $payin), array_filter(['bank_utr' => $utr, 'note' => $note]));
    }

    private function alerted(User $user, Alert $alert): int
    {
        return Notification::sent($user, PortalAlert::class, fn (PortalAlert $sent) => $sent->alert === $alert)->count();
    }

    public function test_a_double_click_on_the_partners_site_creates_one_deposit()
    {
        $body = fn (string $order, int $amount = 500000) => $this->payinBody(['order_id' => $order, 'amount' => $amount, 'customer' => self::CUSTOMER]);
        $first = $this->api('POST', '/v1/payins', $body('ORD-1'))->assertCreated()->json('data');

        // Same customer and amount, a new order id, seconds later: refused with the first one's link.
        $this->api('POST', '/v1/payins', $body('ORD-2'))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'duplicate_request')
            ->assertJsonPath('error.id', $first['id'])
            ->assertJsonPath('error.payment_url', $first['payment_url']);
        $this->assertSame(1, Transaction::count());

        // Another amount is another deposit.
        $this->api('POST', '/v1/payins', $body('ORD-3', 600000))->assertCreated();

        // Once the first is paid, the customer may deposit the same amount again.
        $token = basename((string) parse_url($first['payment_url'], PHP_URL_PATH));
        $this->post("http://pay.paygate.local/p/{$token}/method", ['method' => 'upi']);
        $this->post("http://pay.paygate.local/p/{$token}/proof", ['utr' => self::UTR])->assertSessionHasNoErrors();
        $this->api('POST', '/v1/payins', $body('ORD-4'))->assertCreated();

        // And after the window, an unpaid one doesn't block either.
        $this->travel(3)->minutes();
        $this->api('POST', '/v1/payins', $body('ORD-5', 600000))->assertCreated();
    }

    public function test_a_second_deposit_with_the_same_utr_is_refused_at_any_branch()
    {
        // "Anyhow" a second deposit got in (the guard was off), at the same or another branch.
        config(['paygate.payin.duplicate_window_seconds' => 0]);
        $first = $this->depositOn($this->account);

        foreach ([$this->account, $this->paidAccount] as $on) {
            $other = $on->is($this->account) ? $this->paidAccount : $this->account;
            $other->update(['status' => 'inactive']);
            [$reference, $token] = $this->createPayin(['amount' => 500000, 'customer' => self::CUSTOMER]);
            $this->post("http://pay.paygate.local/p/{$token}/method", ['method' => 'upi']);
            $other->update(['status' => 'active']);

            // Pending elsewhere: refused, the customer is told it is pending; it stays unpaid.
            $this->post("http://pay.paygate.local/p/{$token}/proof", ['utr' => self::UTR])->assertSessionHasErrors('utr');
            $this->assertSame('awaiting_payment', Transaction::where('reference', $reference)->sole()->status);
        }

        // Admin was alerted once for this UTR (not once per attempt).
        $this->assertSame(1, $this->alerted($this->admin, Alert::UtrConflict));

        // Only one deposit holds it, so the branch's bank line approves that one by itself.
        $this->addLine($this->operator, $this->account)->assertSessionHasNoErrors();
        $this->assertSame('success', $first->refresh()->status);
        $this->assertSame(1, Transaction::where('customer_utr_normalized', self::UTR)->count());
    }

    public function test_approving_with_another_utr_than_the_customers_needs_a_note_and_tells_admin()
    {
        $payin = $this->depositOn($this->account);

        $this->approve($this->operator, $payin, '999912820491')->assertSessionHasErrors('note');
        $this->approve($this->operator, $payin, '999912820491', 'Customer mistyped; bank shows 999912820491')->assertSessionHasNoErrors();

        $this->assertSame('success', $payin->refresh()->status);
        $this->assertSame(1, $this->alerted($this->admin, Alert::ApprovedOtherUtr));

        // The customer's own UTR: no note, no alert.
        $other = $this->submittedPayin(['amount' => 300000], '555512820491');
        $this->approve($this->operator, $other, '555512820491')->assertSessionHasNoErrors();
        $this->assertSame(1, $this->alerted($this->admin, Alert::ApprovedOtherUtr));
    }

    public function test_admin_is_alerted_once_about_approvals_no_bank_line_confirms()
    {
        $unseen = $this->depositOn($this->account);
        $this->approve($this->operator, $unseen)->assertSessionHasNoErrors();
        $seen = $this->depositOn($this->account, '555512820491');
        $this->addLine($this->operator, $this->account, '555512820491')->assertSessionHasNoErrors();
        $this->assertSame('success', $seen->refresh()->status);

        $alerts = app(AlertDispatcher::class);
        $this->assertSame(0, $alerts->approvedWithoutBankLine()); // not 24 hours yet

        $this->travel(25)->hours();
        $this->assertSame(1, $alerts->approvedWithoutBankLine()); // only the one no line confirms
        $this->assertSame(1, $this->alerted($this->admin, Alert::ApprovedWithoutBankLine));
        $this->assertSame(0, $alerts->approvedWithoutBankLine()); // reported once

        app(Settings::class)->set(Settings::UNMATCHED_APPROVAL_HOURS, 0, $this->admin);
        $this->assertSame(0, $alerts->approvedWithoutBankLine());
    }
}
