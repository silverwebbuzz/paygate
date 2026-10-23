<?php

namespace Tests\Feature\Platform;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\SystemRoles;
use App\Domain\Ledger\Ledger;
use App\Domain\Payout\PayoutData;
use App\Domain\Settlement\Models\Settlement;
use App\Domain\Transaction\Models\PayoutReturn;
use App\Domain\Transaction\Models\Transaction;
use App\Domain\Webhook\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsPayinNetwork;
use Tests\TestCase;

/**
 * Payout returns (decided 2026-10-22): the partner says a paid payout never
 * reached its customer; the branch confirms how much came back (full or
 * part) with the credit's UTR and the bank's proof, booked that day
 * (branch −amount, partner +amount, fees kept), or rejects it. The payout
 * stays Success.
 */
class PayoutReturnTest extends TestCase
{
    use BuildsPayinNetwork, RefreshDatabase;

    private User $admin;

    private User $operator;

    private User $partnerUser;

    private Ledger $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Storage::fake('local');
        $this->buildNetwork();
        $this->partner->update(['payout_webhook_url' => 'https://atoz.example/hooks/payout', 'is_payout_enabled' => true]);
        $this->activeAccount();
        $this->rate('partner', $this->partner->id, 'deposit', '6');
        $this->rate('branch', $this->branch->id, 'deposit', '4');
        $this->rate('partner', $this->partner->id, 'withdrawal', '5');
        $this->rate('branch', $this->branch->id, 'withdrawal', '3');
        $this->admin = User::factory()->admin()->withTwoFactor()->create();
        $this->operator = User::factory()->branch(SystemRoles::BRANCH_OPERATOR, $this->branch)->withTwoFactor()->create();
        $this->partnerUser = User::factory()->partner(SystemRoles::PARTNER_OWNER, $this->partner)->withTwoFactor()->create();
        $this->ledger = app(Ledger::class);
    }

    /**
     * Funds the pair with a ₹10,000 deposit, then pays a ₹5,000 payout.
     */
    private function paidPayout(string $order = 'WD-1'): Transaction
    {
        if (Transaction::where('direction', 'payin')->doesntExist()) {
            $payin = $this->submittedPayin(['amount' => 1000000], '626812820491');
            $this->actingAs($this->operator)->post(route('branch.deposits.approve', $payin), ['bank_utr' => '626812820491'])->assertSessionHasNoErrors();
        }

        $this->api('POST', '/v1/payouts', [
            'order_id' => $order, 'amount' => 500000, 'customer' => ['id' => 'c1'],
            'beneficiary' => ['type' => 'bank', 'name' => 'Ravi', 'account_number' => '50100482716640', 'ifsc' => 'HDFC0001203'],
        ])->assertCreated();
        $payout = Transaction::where(['direction' => 'payout', 'partner_transaction_id' => $order])->sole();
        $this->actingAs($this->operator)->post(route('branch.payouts.complete', $payout), ['bank_utr' => 'IMPS60123456'.random_int(1000, 9999)])->assertSessionHasNoErrors();

        return $payout->refresh();
    }

    private function raise(Transaction $payout, ?User $as = null)
    {
        $as ??= $this->partnerUser;

        return $this->actingAs($as)->post(route($as->isType(UserType::Admin) ? 'admin.transactions.return' : 'partner.payouts.return', $payout), [
            'reason' => 'Customer says the second ₹2,500 never arrived',
        ]);
    }

    private function confirmation(array $overrides = []): array
    {
        return [
            'amount' => '2500',
            // The credit's own UTR (the money coming back), not the payout's.
            'utr' => 'IMPS77310455',
            // As typed on the bank statement (India time); booking is on the confirmation day.
            'returned_at' => now()->setTimezone('Asia/Kolkata')->subMinute()->format('Y-m-d\TH:i'),
            'note' => 'Second transfer bounced: account closed',
            'proof' => UploadedFile::fake()->create('statement.pdf', 80, 'application/pdf'),
            ...$overrides,
        ];
    }

    private function positions(): array
    {
        return [
            $this->ledger->balance(Ledger::PARTNER_POSITION, $this->partner->id, $this->branch->id),
            $this->ledger->balance(Ledger::BRANCH_POSITION, $this->partner->id, $this->branch->id),
            $this->ledger->balance(Ledger::PLATFORM_MARGIN),
        ];
    }

    public function test_a_raised_return_books_nothing_until_the_branch_confirms_what_came_back()
    {
        $payout = $this->paidPayout();
        // Deposit 940000 / −960000 / 20000, then payout −525000 / +515000 / +10000.
        $this->assertSame([415000, -445000, 30000], $this->positions());

        $this->raise($payout)->assertSessionHasNoErrors();

        $return = PayoutReturn::sole();
        $this->assertSame(['requested', null, $this->partnerUser->id], [$return->status, $return->amount, $return->requested_by]);
        $this->assertSame(['success', 'requested'], [$payout->refresh()->status, $payout->return_status]);
        $this->assertSame([415000, -445000, 30000], $this->positions());
        $this->assertTrue($payout->events()->where('event', 'return_requested')->exists());

        // The branch sees it waiting under Returns, with the buttons.
        $this->actingAs($this->operator)->get(route('branch.payouts.history', ['status' => 'return']))
            ->assertInertia(fn ($page) => $page
                ->where('transactions.total', 1)
                ->where('transactions.data.0.payout_return.status', 'requested')
                ->where('transactions.data.0.can.return_decide', true)
                ->where('transactions.data.0.can.return_record', false)
                ->where('summary.return.pending', 1));

        // Half came back.
        $this->actingAs($this->operator)->post(route('branch.payout-returns.confirm', $return), $this->confirmation())->assertSessionHasNoErrors();

        // Partner +₹2,500, branch −₹2,500; fees and margin untouched.
        $this->assertSame([665000, -695000, 30000], $this->positions());
        $this->assertSame(['success', 'approved'], [$payout->refresh()->status, $payout->return_status]);
        $return->refresh();
        $this->assertSame(['approved', 250000, 'IMPS77310455', $this->operator->id], [$return->status, $return->amount, $return->utr, $return->decided_by]);
        $this->assertSame('payout_return', DB::table('ledger_journals')->where('id', $return->journal_id)->value('type'));
        $this->assertSame($this->ledger->journalId($payout->id, 'payout_success'), DB::table('ledger_journals')->where('id', $return->journal_id)->value('reverses_journal_id'));
        $this->assertSame(0, (int) DB::table('ledger_entries')->sum('amount'));

        // The partner hears of it: status still success, with the return beside it.
        $hook = WebhookEvent::where(['transaction_id' => $payout->id, 'event_type' => 'payout.returned'])->sole();
        $this->assertSame('success', $hook->payload['data']['status']);
        $this->assertSame(250000, $hook->payload['data']['returned']['amount']);
        $this->assertSame('IMPS77310455', PayoutData::forPartner($payout)['returned']['utr']);

        // Confirmed: no second return on the same payout.
        $this->raise($payout)->assertSessionHasErrors('transaction');
        $this->actingAs($this->operator)->post(route('branch.payout-returns.confirm', $return), $this->confirmation())->assertSessionHasErrors('return');
    }

    public function test_the_amount_is_at_most_the_payout_and_the_utr_and_proof_are_required()
    {
        $payout = $this->paidPayout();
        $this->raise($payout)->assertSessionHasNoErrors();
        $return = PayoutReturn::sole();
        $confirm = fn (array $overrides) => $this->actingAs($this->operator)->post(route('branch.payout-returns.confirm', $return), $this->confirmation($overrides));

        $confirm(['amount' => '5000.01'])->assertSessionHasErrors('amount');
        $confirm(['amount' => '0'])->assertSessionHasErrors('amount');
        $confirm(['utr' => 'AB-1'])->assertSessionHasErrors('utr');
        $confirm(['proof' => null])->assertSessionHasErrors('proof');
        $confirm(['returned_at' => null])->assertSessionHasErrors('returned_at');
        // Not in the future, not before the payout was paid.
        $confirm(['returned_at' => now()->setTimezone('Asia/Kolkata')->addHour()->format('Y-m-d\TH:i')])->assertSessionHasErrors('returned_at');
        $confirm(['returned_at' => now()->setTimezone('Asia/Kolkata')->subDays(2)->format('Y-m-d\TH:i')])->assertSessionHasErrors('returned_at');
        $this->assertSame('requested', $return->refresh()->status);

        // The full payout may come back; the UTR is normalised.
        $confirm(['amount' => '5000', 'utr' => ' imps 7731 0455 '])->assertSessionHasNoErrors();
        $this->assertSame([500000, 'IMPS77310455'], [$return->refresh()->amount, $return->utr]);
        // Full return: still success, the fee stays earned.
        $this->assertSame([915000, -945000, 30000], $this->positions());
        $this->assertSame('success', $payout->refresh()->status);
    }

    public function test_a_rejected_return_books_nothing_and_can_be_raised_again()
    {
        $payout = $this->paidPayout();
        $this->raise($payout)->assertSessionHasNoErrors();
        $first = PayoutReturn::sole();

        $this->actingAs($this->operator)->post(route('branch.payout-returns.reject', $first), [])->assertSessionHasErrors('note');
        $this->actingAs($this->operator)->post(route('branch.payout-returns.reject', $first), ['note' => 'Both transfers show as credited'])->assertSessionHasNoErrors();

        $this->assertSame(['success', 'rejected'], [$payout->refresh()->status, $payout->return_status]);
        $this->assertSame([415000, -445000, 30000], $this->positions());
        $this->assertFalse(WebhookEvent::where(['transaction_id' => $payout->id, 'event_type' => 'payout.returned'])->exists());

        // The partner sees why, and may raise it again.
        $this->actingAs($this->partnerUser)->get(route('partner.payouts.index'))
            ->assertInertia(fn ($page) => $page
                ->where('transactions.data.0.payout_return.status', 'rejected')
                ->where('transactions.data.0.payout_return.decision_note', 'Both transfers show as credited')
                ->where('transactions.data.0.can.return_raise', true));

        $this->raise($payout)->assertSessionHasNoErrors();
        $this->assertSame(2, PayoutReturn::count());
        $this->assertSame('requested', $payout->refresh()->return_status);
    }

    public function test_the_branch_may_record_a_return_it_found_itself()
    {
        $payout = $this->paidPayout();

        $this->actingAs($this->operator)->get(route('branch.payouts.history'))
            ->assertInertia(fn ($page) => $page->where('transactions.data.0.can.return_record', true)->where('transactions.data.0.can.return_raise', false));

        $this->actingAs($this->operator)->post(route('branch.payouts.return', $payout), $this->confirmation())->assertSessionHasNoErrors();

        $return = PayoutReturn::sole();
        $this->assertSame(['approved', 250000, $this->operator->id, $this->operator->id], [$return->status, $return->amount, $return->requested_by, $return->decided_by]);
        $this->assertSame([665000, -695000, 30000], $this->positions());
        $this->assertTrue(WebhookEvent::where(['transaction_id' => $payout->id, 'event_type' => 'payout.returned'])->exists());
    }

    public function test_who_may_raise_confirm_and_see_the_proof()
    {
        $payout = $this->paidPayout();
        $otherBranch = User::factory()->branch(SystemRoles::BRANCH_OWNER, Branch::factory()->create())->withTwoFactor()->create();
        $partnerViewer = User::factory()->partner(SystemRoles::PARTNER_VIEWER, $this->partner)->withTwoFactor()->create();
        $adminViewer = User::factory()->admin(SystemRoles::ADMIN_VIEWER)->withTwoFactor()->create();

        // The branch records rather than raises; a partner viewer can't raise.
        $this->actingAs($this->operator)->post(route('admin.transactions.return', $payout), ['reason' => 'x'])->assertForbidden();
        $this->raise($payout, $partnerViewer)->assertForbidden();
        $this->assertSame(0, PayoutReturn::count());

        // Admin may raise it for the partner (after a call), but not confirm it too.
        $this->raise($payout, $this->admin)->assertSessionHasNoErrors();
        $return = PayoutReturn::sole();
        $this->actingAs($this->admin)->post(route('admin.payout-returns.confirm', $return), $this->confirmation())->assertSessionHasErrors('return');
        $this->actingAs($adminViewer)->post(route('admin.payout-returns.confirm', $return), $this->confirmation())->assertForbidden();
        $this->actingAs($otherBranch)->post(route('branch.payout-returns.confirm', $return), $this->confirmation())->assertForbidden();
        $this->actingAs($this->partnerUser)->post(route('branch.payout-returns.confirm', $return), $this->confirmation())->assertForbidden();

        // Another admin confirms it.
        $checker = User::factory()->admin(SystemRoles::ADMIN_FINANCE)->withTwoFactor()->create();
        $this->actingAs($checker)->post(route('admin.payout-returns.confirm', $return), $this->confirmation(['proof' => UploadedFile::fake()->image('credit.png')]))->assertSessionHasNoErrors();
        $proof = $return->refresh()->proof_file_id;

        // The bank's proof: Admin and the branch, never the partner.
        $this->actingAs($this->operator)->get(route('files.show', $proof))->assertOk()->assertHeader('Content-Disposition', 'inline; filename="credit.png"');
        $this->actingAs($this->partnerUser)->get(route('files.show', $proof))->assertNotFound();
        $this->actingAs($otherBranch)->get(route('files.show', $proof))->assertNotFound();

        $this->actingAs($this->partnerUser)->get(route('partner.payouts.index', ['status' => 'return']))
            ->assertInertia(fn ($page) => $page
                ->where('transactions.data.0.payout_return.status', 'approved')
                ->where('transactions.data.0.payout_return.amount', 250000)
                ->where('transactions.data.0.payout_return.proof_file_id', null)
                ->where('summary.return.count', 1)
                ->where('summary.return.amount', 250000));
    }

    public function test_only_a_paid_payout_can_be_returned_and_refunds_no_longer_take_payouts()
    {
        $payout = $this->paidPayout();

        $this->actingAs($this->admin)->post(route('admin.reversals.store'), ['transaction_id' => $payout->id, 'kind' => 'return', 'reason' => 'x'])->assertSessionHasErrors('kind');
        $this->actingAs($this->admin)->post(route('admin.reversals.store'), ['transaction_id' => $payout->id, 'kind' => 'refund', 'reason' => 'x'])->assertSessionHasErrors('transaction');

        $this->api('POST', '/v1/payouts', [
            'order_id' => 'WD-2', 'amount' => 100000, 'customer' => ['id' => 'c1'],
            'beneficiary' => ['type' => 'bank', 'name' => 'Ravi', 'account_number' => '50100482716640', 'ifsc' => 'HDFC0001203'],
        ])->assertCreated();
        $waiting = Transaction::where('partner_transaction_id', 'WD-2')->sole();
        $this->raise($waiting)->assertSessionHasErrors('transaction');
        $this->assertNull($waiting->refresh()->return_status);
    }

    public function test_it_lands_in_the_settlement_of_the_confirmation_day_not_the_payouts()
    {
        $payout = $this->paidPayout();
        $this->travel(1)->minutes();
        $this->actingAs($this->admin)->post(route('admin.settlements.calculate'), ['party_type' => 'partner', 'party_id' => $this->partner->id])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('admin.settlements.calculate'), ['party_type' => 'branch', 'party_id' => $this->branch->id])->assertSessionHasNoErrors();

        // Raised two days later, confirmed three days after that.
        $this->travel(2)->days();
        $this->raise($payout)->assertSessionHasNoErrors();
        $this->travel(3)->days();
        // It came back the day after it was raised; the branch confirms it three days later.
        $cameBack = now()->subDays(2)->startOfMinute();
        $this->actingAs($this->operator)->post(route('branch.payout-returns.confirm', PayoutReturn::sole()), $this->confirmation([
            'returned_at' => $cameBack->copy()->setTimezone('Asia/Kolkata')->format('Y-m-d\TH:i'),
        ]))->assertSessionHasNoErrors();
        $this->assertSame($cameBack->toIso8601String(), PayoutReturn::sole()->returned_at->utc()->toIso8601String());
        $this->travel(1)->minutes();

        $this->actingAs($this->admin)->post(route('admin.settlements.calculate'), ['party_type' => 'partner', 'party_id' => $this->partner->id])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('admin.settlements.calculate'), ['party_type' => 'branch', 'party_id' => $this->branch->id])->assertSessionHasNoErrors();

        $partner = Settlement::query()->where('party_type', 'partner')->orderByDesc('period_end')->firstOrFail();
        $branch = Settlement::query()->where('party_type', 'branch')->orderByDesc('period_end')->firstOrFail();

        $this->assertSame([0, 250000, 0], [$partner->gross_payout, $partner->returns_total, $partner->adjustments_total]);
        $this->assertSame([415000, 665000], [$partner->opening_balance, $partner->closing_balance]);
        $this->assertSame([0, -250000], [$branch->gross_payout, $branch->returns_total]);
        $this->assertSame([-445000, -695000], [$branch->opening_balance, $branch->closing_balance]);
        $this->assertSame(0, (int) Settlement::query()->whereKeyNot([$partner->id, $branch->id])->sum('returns_total'));

        $owner = User::factory()->branch(SystemRoles::BRANCH_OWNER, $this->branch)->withTwoFactor()->create();
        $this->actingAs($owner)->get(route('branch.settlements.index'))
            ->assertInertia(fn ($page) => $page->where('items.data.0.figures.returns', -250000));

        // The settlement dashboard shows the return on its own line, on the confirmation day.
        $this->actingAs($this->partnerUser)->get(route('partner.settlement-dashboard', ['range' => '24h']))
            ->assertInertia(fn ($page) => $page->where('figures.total.return.count', 1)->where('figures.total.return.amount', 250000)->where('figures.total.withdraw.count', 0));
    }

    public function test_payout_returns_are_their_own_section_rollout_item()
    {
        $payout = $this->paidPayout();
        $rollout = fn (array $branch, array $partner) => $this->actingAs($this->admin)->put(route('admin.section-rollout.update'), [
            'active' => true, 'open' => ['admin' => [], 'branch' => $branch, 'partner' => $partner],
        ])->assertSessionHasNoErrors();

        // Pay-out open, Payout returns closed: no tab, column or buttons, and the actions are refused.
        $rollout(['payout_history'], ['payouts']);
        $this->actingAs($this->partnerUser)->get(route('partner.payouts.index', ['status' => 'return']))
            ->assertInertia(fn ($page) => $page
                ->where('filters.status', null)
                ->missing('summary.return')
                ->where('transactions.data.0.can.return_raise', false)
                ->where('transactions.data.0.payout_return', null)
                ->where('rolloutHidden', fn ($hidden) => collect($hidden)->contains('/partner/payout-returns')));
        $this->actingAs($this->partnerUser)->get(route('partner.payout-returns.index'))->assertRedirect(route('partner.dashboard'));
        $this->raise($payout)->assertForbidden();
        $this->actingAs($this->operator)->post(route('branch.payouts.return', $payout), $this->confirmation())->assertForbidden();
        $this->assertSame(0, PayoutReturn::count());

        // Opened: the page, the tab and the buttons are back.
        $rollout(['payout_history', 'payout_returns'], ['payouts', 'payout_returns']);
        $this->raise($payout)->assertSessionHasNoErrors();
        $this->actingAs($this->operator)->get(route('branch.payout-returns.index'))
            ->assertInertia(fn ($page) => $page
                ->where('page', 'return')
                ->where('filters.status', 'return')
                ->where('transactions.total', 1)
                ->where('transactions.data.0.can.return_decide', true));
    }
}
