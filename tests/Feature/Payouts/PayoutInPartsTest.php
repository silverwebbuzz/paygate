<?php

namespace Tests\Feature\Payouts;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\SystemRoles;
use App\Domain\Ledger\Ledger;
use App\Domain\Notification\AlertDispatcher;
use App\Domain\Notification\Enums\Alert;
use App\Domain\Notification\Notifications\PortalAlert;
use App\Domain\Payout\Models\PayoutPayment;
use App\Domain\Reconciliation\Models\StatementEntry;
use App\Domain\Settlement\Models\Settlement;
use App\Domain\Transaction\Models\PayoutReturn;
use App\Domain\Transaction\Models\Transaction;
use App\Domain\Webhook\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsPayinNetwork;
use Tests\TestCase;

/**
 * A payout paid in parts (decided 2026-10-23): the branch sends one
 * withdrawal as several bank transfers, hours or days apart, each with its
 * amount, UTR, date and proof. Success (booked once) when they add up.
 * The client's case: ₹1,000 paid as 2 × ₹500, one bounces.
 */
class PayoutInPartsTest extends TestCase
{
    use BuildsPayinNetwork, RefreshDatabase;

    private User $operator;

    private User $partnerUser;

    private Ledger $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Storage::fake('local');
        $this->buildNetwork();
        $this->activeAccount();
        $this->partner->update(['is_payout_enabled' => true, 'payout_webhook_url' => 'https://atoz.example/hooks/payout']);
        $this->rate('partner', $this->partner->id, 'withdrawal', '5');
        $this->rate('branch', $this->branch->id, 'withdrawal', '3');
        $this->operator = User::factory()->branch(SystemRoles::BRANCH_OPERATOR, $this->branch)->withTwoFactor()->create();
        $this->partnerUser = User::factory()->partner(SystemRoles::PARTNER_OWNER, $this->partner)->withTwoFactor()->create();
        $this->ledger = app(Ledger::class);

        DB::transaction(fn () => $this->ledger->post('adjustment', [
            $this->ledger->account(Ledger::PARTNER_POSITION, $this->partner->id, $this->branch->id) => 1000000,
            $this->ledger->account(Ledger::SETTLEMENT_CLEARING) => -1000000,
        ], null, 'Test funding'));
    }

    private function payout(int $amount = 100000): Transaction
    {
        $id = $this->api('POST', '/v1/payouts', [
            'order_id' => 'WD-'.bin2hex(random_bytes(4)),
            'amount' => $amount,
            'customer' => ['id' => 'cust-9'],
            'beneficiary' => ['type' => 'bank', 'name' => 'Ravi Kumar', 'account_number' => '50100482716640', 'ifsc' => 'HDFC0001203'],
        ])->assertCreated()->json('data.id');

        return Transaction::where('reference', $id)->firstOrFail();
    }

    private function pay(Transaction $payout, int $amount, string $utr, array $overrides = [])
    {
        return $this->actingAs($this->operator)->post(route('branch.payouts.complete', $payout), $this->transfer($amount, $utr, $overrides));
    }

    private function position(): array
    {
        return $this->ledger->partnerPositions($this->partner->id)[$this->branch->id] ?? ['balance' => 0, 'reserved' => 0];
    }

    private function journals(Transaction $payout): int
    {
        return (int) DB::table('ledger_journals')->where('transaction_id', $payout->id)->whereIn('type', ['payout_part', 'payout_success'])->count();
    }

    public function test_a_payout_paid_in_two_parts_is_booked_once_when_they_add_up()
    {
        $payout = $this->payout();

        // ₹500 today: processing; that ₹500 is booked today (partner −, branch +,
        // no fees yet) and leaves the hold.
        $this->pay($payout, 50000, 'IMPS700000000001')->assertSessionHasNoErrors();
        $payout->refresh();
        $this->assertSame('processing', $payout->status);
        $this->assertSame(1, $this->journals($payout));
        $this->assertSame(50000, (int) PayoutPayment::sum('amount'));
        $this->assertSame(['balance' => 1000000 - 50000, 'reserved' => 105000 - 50000], $this->position());
        $this->assertSame(50000, $this->ledger->balance(Ledger::BRANCH_POSITION, $this->partner->id, $this->branch->id));

        // Money left the bank: it can't fail or move now, and the rest can't be overpaid.
        $this->actingAs($this->operator)->post(route('branch.payouts.fail', $payout), ['reason_code' => 'invalid_beneficiary'])->assertSessionHasErrors('status');
        $this->pay($payout, 50001, 'IMPS700000000002')->assertSessionHasErrors('amount');
        // One UTR is one transfer.
        $this->pay($payout, 50000, 'IMPS700000000001')->assertSessionHasErrors('bank_utr');

        $this->actingAs($this->operator)->get(route('branch.payouts.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('items.data.0.paid_amount', 50000)
                ->where('items.data.0.payments.0.utr', 'IMPS700000000001')
                ->where('items.data.0.payments.0.recorded_by', $this->operator->name));

        // The rest the next day: success, that ₹500 booked with the fees.
        $this->travel(1)->days();
        $this->pay($payout, 50000, 'IMPS700000000002')->assertSessionHasNoErrors();
        $payout->refresh();
        $this->assertSame('success', $payout->status);
        $this->assertSame(2, $this->journals($payout));
        $this->assertSame(100000 + 3000, $this->ledger->balance(Ledger::BRANCH_POSITION, $this->partner->id, $this->branch->id));
        $this->assertSame(2000, $this->ledger->balance(Ledger::PLATFORM_MARGIN));
        $this->assertSame([false, true], PayoutPayment::query()->orderBy('created_at')->pluck('fees_booked')->all());
        $this->assertSame(1000000 - 105000, $this->ledger->balance(Ledger::PARTNER_POSITION, $this->partner->id, $this->branch->id));
        $this->assertSame(0, $this->position()['reserved']);
        $this->assertSame('IMPS700000000001', $payout->bank_utr_normalized);

        // The partner API lists both transfers; `utr` stays the first.
        $this->api('GET', "/v1/payouts/{$payout->reference}")
            ->assertJsonPath('data.status', 'success')
            ->assertJsonPath('data.utr', 'IMPS700000000001')
            ->assertJsonPath('data.paid_amount', 100000)
            ->assertJsonCount(2, 'data.payments')
            ->assertJsonPath('data.payments.1.utr', 'IMPS700000000002');
    }

    public function test_each_transfer_needs_a_proof_and_a_past_date()
    {
        $payout = $this->payout();

        $this->pay($payout, 50000, 'IMPS700000000001', ['proof' => null])->assertSessionHasErrors('proof');
        $this->pay($payout, 50000, 'IMPS700000000001', ['proof' => UploadedFile::fake()->create('x.exe', 10)])->assertSessionHasErrors('proof');
        $this->pay($payout, 50000, 'IMPS700000000001', ['paid_at' => now()->addDay()->format('Y-m-d\TH:i')])->assertSessionHasErrors('paid_at');
        $this->pay($payout, 0, 'IMPS700000000001')->assertSessionHasErrors('amount');
        $this->assertSame(0, PayoutPayment::count());

        // A PDF is fine.
        $this->pay($payout, 50000, 'IMPS700000000001', ['proof' => UploadedFile::fake()->create('slip.pdf', 40, 'application/pdf')])->assertSessionHasNoErrors();
        $this->assertSame('payout_proof', DB::table('files')->where('id', PayoutPayment::sole()->proof_file_id)->value('purpose'));
    }

    public function test_the_partner_sees_each_transfer_and_its_proof_but_not_the_branch()
    {
        $payout = $this->payout();
        $this->pay($payout, 50000, 'IMPS700000000001')->assertSessionHasNoErrors();
        $this->pay($payout, 50000, 'IMPS700000000002')->assertSessionHasNoErrors();
        $proof = PayoutPayment::query()->orderBy('paid_at')->firstOrFail()->proof_file_id;

        $this->actingAs($this->partnerUser)->get(route('partner.payouts.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('transactions.data.0.branch', null)
                ->where('transactions.data.0.payments.0.proof_file_id', $proof)
                ->where('transactions.data.0.payments.0.recorded_by', null)
                ->where('transactions.data.0.payments.0.bank_line', null)
                ->has('transactions.data.0.payments', 2));

        $this->actingAs($this->partnerUser)->get(route('files.show', $proof))->assertOk();
        $this->actingAs($this->operator)->get(route('files.show', $proof))->assertOk();

        $otherPartner = User::factory()->partner(SystemRoles::PARTNER_OWNER)->withTwoFactor()->create();
        $otherBranch = User::factory()->branch(SystemRoles::BRANCH_OWNER, Branch::factory()->create())->withTwoFactor()->create();
        $this->actingAs($otherPartner)->get(route('files.show', $proof))->assertNotFound();
        $this->actingAs($otherBranch)->get(route('files.show', $proof))->assertNotFound();
    }

    public function test_each_transfer_links_its_own_bank_line()
    {
        $payout = $this->payout();
        $line = fn (string $utr) => $this->actingAs($this->operator)->post(route('branch.statements.store'), [
            'payment_account_id' => $this->branch->paymentAccounts()->value('id'),
            'value_date' => now(config('app.business_timezone'))->toDateString(),
            'utr' => $utr,
            'credit' => '',
            'debit' => '500',
        ])->assertSessionHasNoErrors();

        // The statement shows the first transfer before the branch records it.
        $line('IMPS700000000001');
        $this->pay($payout, 50000, 'IMPS700000000001')->assertSessionHasNoErrors();
        $first = StatementEntry::where('utr_normalized', 'IMPS700000000001')->sole();
        $this->assertSame(['matched', $payout->id], [$first->status, $first->transaction_id]);

        // The second is recorded first, then the statement arrives.
        $this->pay($payout, 50000, 'IMPS700000000002')->assertSessionHasNoErrors();
        $line('IMPS700000000002');

        $lines = StatementEntry::query()->orderBy('created_at')->get();
        $this->assertSame(['reconciled', 'reconciled'], $lines->pluck('status')->all());
        $this->assertSame(PayoutPayment::query()->orderBy('paid_at')->orderBy('created_at')->pluck('id')->all(), $lines->pluck('payout_payment_id')->all());
    }

    public function test_one_transfer_bounces_and_comes_back_and_later_the_other_too()
    {
        $payout = $this->payout();
        $this->pay($payout, 50000, 'IMPS700000000001')->assertSessionHasNoErrors();
        $this->pay($payout, 50000, 'IMPS700000000002')->assertSessionHasNoErrors();
        [$first, $second] = PayoutPayment::query()->orderBy('paid_at')->orderBy('created_at')->get()->all();
        $partner = fn () => $this->ledger->balance(Ledger::PARTNER_POSITION, $this->partner->id, $this->branch->id);
        $before = $partner();

        $this->actingAs($this->partnerUser)->post(route('partner.payouts.return', $payout), ['reason' => 'Customer got only ₹500'])->assertSessionHasNoErrors();
        $return = PayoutReturn::sole();
        $confirm = fn (array $overrides = []) => $this->actingAs($this->operator)->post(route('branch.payout-returns.confirm', $return), [
            'amount' => '500',
            'utr' => 'NEFTRET0000001',
            'returned_at' => now()->setTimezone('Asia/Kolkata')->subMinute()->format('Y-m-d\TH:i'),
            'proof' => UploadedFile::fake()->create('credit.pdf', 40, 'application/pdf'),
            ...$overrides,
        ]);

        // Two transfers: the branch must say which one came back, and not more than it.
        $confirm()->assertSessionHasErrors('payout_payment_id');
        $confirm(['payout_payment_id' => $second->id, 'amount' => '500.01'])->assertSessionHasErrors('amount');
        $confirm(['payout_payment_id' => $second->id])->assertSessionHasNoErrors();

        $this->assertSame($before + 50000, $partner());
        $this->assertSame([$second->id, 'approved'], [$return->refresh()->payout_payment_id, $return->status]);
        $this->assertSame('success', $payout->refresh()->status);

        $this->actingAs($this->operator)->get(route('branch.payouts.history'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('transactions.data.0.payments.0.returned', null)
                ->where('transactions.data.0.payments.1.returned', 50000)
                ->where('transactions.data.0.can.return_record', true));

        // Days later the first one bounces too: raised again, booked again.
        $this->actingAs($this->partnerUser)->post(route('partner.payouts.return', $payout), ['reason' => 'Now the rest too'])->assertSessionHasNoErrors();
        $again = PayoutReturn::query()->where('status', 'requested')->sole();
        $this->actingAs($this->operator)->post(route('branch.payout-returns.confirm', $again), [
            'payout_payment_id' => $first->id, 'amount' => '500', 'utr' => 'NEFTRET0000002',
            'returned_at' => now()->setTimezone('Asia/Kolkata')->subMinute()->format('Y-m-d\TH:i'),
            'proof' => UploadedFile::fake()->image('credit.png'),
        ])->assertSessionHasNoErrors();

        $this->assertSame($before + 100000, $partner());
        $this->api('GET', "/v1/payouts/{$payout->reference}")->assertJsonPath('data.returned_total', 100000);

        // Both transfers came back: nothing left to return.
        $this->actingAs($this->partnerUser)->post(route('partner.payouts.return', $payout), ['reason' => 'x'])->assertSessionHasErrors('transaction');
    }

    public function test_the_partner_hears_of_each_part_by_webhook()
    {
        $payout = $this->payout();
        $this->pay($payout, 30000, 'IMPS700000000001')->assertSessionHasNoErrors();

        $partial = WebhookEvent::where(['transaction_id' => $payout->id, 'event_type' => 'payout.partial'])->sole();
        $this->assertSame('processing', $partial->payload['data']['status']);
        $this->assertSame(30000, $partial->payload['data']['paid_amount']);
        $this->assertSame('IMPS700000000001', $partial->payload['data']['payments'][0]['utr']);

        $this->pay($payout, 70000, 'IMPS700000000002')->assertSessionHasNoErrors();
        $this->assertSame(1, WebhookEvent::where(['transaction_id' => $payout->id, 'event_type' => 'payout.partial'])->count());
        $success = WebhookEvent::where(['transaction_id' => $payout->id, 'event_type' => 'payout.success'])->sole();
        $this->assertSame(100000, $success->payload['data']['paid_amount']);
    }

    public function test_a_payout_left_half_paid_for_a_day_alerts_the_branch_and_admin_once()
    {
        Notification::fake();
        $admin = User::factory()->admin(SystemRoles::ADMIN_OPS)->withTwoFactor()->create();
        $stranger = User::factory()->branch(SystemRoles::BRANCH_OPERATOR, Branch::factory()->create())->withTwoFactor()->create();
        $payout = $this->payout();
        $alerts = app(AlertDispatcher::class);

        $this->pay($payout, 50000, 'IMPS700000000001')->assertSessionHasNoErrors();
        $this->travel(23)->hours();
        $this->assertSame(0, $alerts->payoutsPartPaid());

        $this->travel(2)->hours();
        $this->assertSame(1, $alerts->payoutsPartPaid());
        $this->assertSame(0, $alerts->payoutsPartPaid());
        Notification::assertSentTo([$this->operator, $admin], PortalAlert::class, fn (PortalAlert $alert) => $alert->alert === Alert::PayoutPartPaid);
        Notification::assertNotSentTo([$stranger, $this->partnerUser], PortalAlert::class, fn (PortalAlert $alert) => $alert->alert === Alert::PayoutPartPaid);

        // Another part, still not all of it: a day later it alerts again.
        $this->pay($payout, 20000, 'IMPS700000000002')->assertSessionHasNoErrors();
        $this->travel(25)->hours();
        $this->assertSame(1, $alerts->payoutsPartPaid());

        // Paid in full: nothing more.
        $this->pay($payout, 30000, 'IMPS700000000003')->assertSessionHasNoErrors();
        $this->travel(25)->hours();
        $this->assertSame(0, $alerts->payoutsPartPaid());
    }

    public function test_parts_either_side_of_midnight()
    {
        $india = (string) config('app.business_timezone');
        // 23 Oct 23:58 India: the first ₹500 leaves the bank.
        $this->travelTo(now()->setTimezone($india)->setTime(23, 58)->utc());
        $payout = $this->payout();
        $this->pay($payout, 50000, 'IMPS700000000001', ['paid_at' => now()->setTimezone($india)->format('Y-m-d\TH:i')])->assertSessionHasNoErrors();
        $first = PayoutPayment::sole();
        $sentDay = $first->paid_at->setTimezone($india)->toDateString();

        // 00:03 the next day: the rest. Completed (and booked) on the new day.
        $this->travel(5)->minutes();
        $this->pay($payout, 50000, 'IMPS700000000002', ['paid_at' => now()->setTimezone($india)->format('Y-m-d\TH:i')])->assertSessionHasNoErrors();
        $this->assertSame('success', $payout->refresh()->status);
        $this->assertNotSame($sentDay, $payout->succeeded_at->setTimezone($india)->toDateString());

        // The first transfer bounced at 23:59 on its own day: that date is accepted.
        $this->actingAs($this->partnerUser)->post(route('partner.payouts.return', $payout), ['reason' => 'Only ₹500 arrived'])->assertSessionHasNoErrors();
        $this->actingAs($this->operator)->post(route('branch.payout-returns.confirm', PayoutReturn::sole()), [
            'payout_payment_id' => $first->id, 'amount' => '500', 'utr' => 'NEFTRET0000001',
            'returned_at' => $sentDay.'T23:59',
            'proof' => UploadedFile::fake()->image('credit.png'),
        ])->assertSessionHasNoErrors();
        $this->assertSame($sentDay, PayoutReturn::sole()->returned_at->setTimezone($india)->toDateString());
        // The return reverses that transfer's own booking.
        $this->assertSame($first->journal_id, DB::table('ledger_journals')->where('id', PayoutReturn::sole()->journal_id)->value('reverses_journal_id'));
    }

    public function test_each_days_settlement_shows_what_was_sent_that_day()
    {
        $india = (string) config('app.business_timezone');
        $admin = User::factory()->admin()->withTwoFactor()->create();
        $settle = function () use ($admin) {
            $this->actingAs($admin)->post(route('admin.settlements.calculate'), ['party_type' => 'branch', 'party_id' => $this->branch->id])->assertSessionHasNoErrors();
            $this->actingAs($admin)->post(route('admin.settlements.calculate'), ['party_type' => 'partner', 'party_id' => $this->partner->id])->assertSessionHasNoErrors();
        };
        $latest = fn (string $party) => Settlement::query()->where('party_type', $party)->orderByDesc('period_end')->firstOrFail();

        // The partner's funding settles first so the next periods hold only
        // the payout (the branch has nothing booked yet).
        $this->travel(1)->minutes();
        $this->actingAs($admin)->post(route('admin.settlements.calculate'), ['party_type' => 'partner', 'party_id' => $this->partner->id])->assertSessionHasNoErrors();

        // 23:58: ₹500 leaves the bank and is booked that day.
        $this->travelTo(now()->setTimezone($india)->addDay()->setTime(23, 58)->utc());
        $payout = $this->payout();
        $this->pay($payout, 50000, 'IMPS700000000001')->assertSessionHasNoErrors();
        $this->travel(1)->minutes();
        $settle();
        $this->assertSame([50000, 0], [$latest('branch')->gross_payout, $latest('branch')->branch_commission]);
        $this->assertSame([50000, 0], [$latest('partner')->gross_payout, $latest('partner')->partner_commission]);

        // 00:03 next day: the other ₹500, with the fees.
        $this->travel(4)->minutes();
        $this->pay($payout, 50000, 'IMPS700000000002')->assertSessionHasNoErrors();
        $this->travel(1)->minutes();
        $settle();
        $this->assertSame([50000, 3000], [$latest('branch')->gross_payout, $latest('branch')->branch_commission]);
        $this->assertSame([50000, 5000], [$latest('partner')->gross_payout, $latest('partner')->partner_commission]);

        // The dashboard for that day: one payout, ₹500 sent, the fee.
        $this->actingAs($this->partnerUser)->get(route('partner.settlement-dashboard', ['range' => 'today']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('figures.total.withdraw.count', 1)
                ->where('figures.total.withdraw.amount', 50000)
                ->where('figures.total.withdraw.commission', 5000));
    }
}
