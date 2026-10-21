<?php

namespace Tests\Feature\Platform;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\SystemRoles;
use App\Domain\Ledger\Ledger;
use App\Domain\Settlement\Models\Settlement;
use App\Domain\Transaction\Models\Chargeback;
use App\Domain\Transaction\Models\Transaction;
use App\Domain\Transaction\PayinData;
use App\Domain\Webhook\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsPayinNetwork;
use Tests\TestCase;

/**
 * Chargebacks (decided 2026-10-21): the branch raises one with the bank's
 * notice and the deposit's UTR; Admin approves (booked that day, the
 * partner bears it) or rejects. The deposit stays Success.
 */
class ChargebackTest extends TestCase
{
    use BuildsPayinNetwork, RefreshDatabase;

    private User $admin;

    private User $operator;

    private Ledger $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Storage::fake('local');
        $this->buildNetwork();
        $this->partner->update(['payin_webhook_url' => 'https://atoz.example/hooks/payin']);
        $this->activeAccount();
        $this->rate('partner', $this->partner->id, 'deposit', '6');
        $this->rate('branch', $this->branch->id, 'deposit', '4');
        $this->admin = User::factory()->admin()->withTwoFactor()->create();
        $this->operator = User::factory()->branch(SystemRoles::BRANCH_OPERATOR, $this->branch)->withTwoFactor()->create();
        $this->ledger = app(Ledger::class);
    }

    private function approved(int $amount = 1000000, string $utr = '626812820491'): Transaction
    {
        $payin = $this->submittedPayin(['amount' => $amount], $utr);
        $this->actingAs($this->operator)->post(route('branch.deposits.approve', $payin), ['bank_utr' => $utr])->assertSessionHasNoErrors();

        return $payin->refresh();
    }

    private function raise(Transaction $payin, array $overrides = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->operator)->post(route('branch.payins.chargeback', $payin), [
            'amount' => '10000',
            'utr' => '626812820491',
            'reason' => 'UPI complaint 4471',
            'proof' => UploadedFile::fake()->create('npci-notice.pdf', 120, 'application/pdf'),
            ...$overrides,
        ]);
    }

    private function positions(): array
    {
        return [
            $this->ledger->balance(Ledger::PARTNER_POSITION, $this->partner->id, $this->branch->id),
            $this->ledger->balance(Ledger::BRANCH_POSITION, $this->partner->id, $this->branch->id),
            $this->ledger->balance(Ledger::PLATFORM_MARGIN),
        ];
    }

    public function test_a_raised_chargeback_books_nothing_until_admin_approves_it()
    {
        $payin = $this->approved();
        $this->assertSame([940000, -960000, 20000], $this->positions());

        $this->raise($payin)->assertSessionHasNoErrors();

        $chargeback = Chargeback::sole();
        $this->assertSame('requested', $chargeback->status);
        $this->assertSame(1000000, $chargeback->amount);
        $this->assertSame($this->operator->id, $chargeback->requested_by);
        $this->assertNotNull($chargeback->proof_file_id);
        $this->assertSame(['success', 'requested'], [$payin->refresh()->status, $payin->chargeback_status]);
        $this->assertSame([940000, -960000, 20000], $this->positions());
        $this->assertTrue($payin->events()->where('event', 'chargeback_requested')->exists());

        $this->actingAs($this->admin)->post(route('admin.chargebacks.approve', $chargeback), ['note' => 'NPCI notice checked'])->assertSessionHasNoErrors();

        // The partner bears the full ₹10,000; the branch no longer owes it; fees stay earned.
        $this->assertSame([-60000, 40000, 20000], $this->positions());
        $this->assertSame(['success', 'approved'], [$payin->refresh()->status, $payin->chargeback_status]);
        $chargeback->refresh();
        $this->assertSame('approved', $chargeback->status);
        $this->assertSame($this->admin->id, $chargeback->decided_by);
        $this->assertSame('chargeback', DB::table('ledger_journals')->where('id', $chargeback->journal_id)->value('type'));
        $this->assertSame($this->ledger->journalId($payin->id, 'payin_success'), DB::table('ledger_journals')->where('id', $chargeback->journal_id)->value('reverses_journal_id'));
        $this->assertSame(0, (int) DB::table('ledger_entries')->sum('amount'));

        // The partner hears of it: status still success, with the chargeback beside it.
        $hook = WebhookEvent::where(['transaction_id' => $payin->id, 'event_type' => 'payin.chargeback'])->sole();
        $this->assertSame('success', $hook->payload['data']['status']);
        $this->assertSame(1000000, $hook->payload['data']['chargeback']['amount']);
        $this->assertSame(1000000, PayinData::forPartner($payin)['chargeback']['amount']);

        // Approved: no second chargeback, and no refund on top.
        $this->raise($payin)->assertSessionHasErrors('transaction');
        $this->actingAs($this->admin)->post(route('admin.reversals.store'), ['transaction_id' => $payin->id, 'kind' => 'refund', 'reason' => 'x'])->assertSessionHasErrors('transaction');
    }

    public function test_the_utr_must_be_the_deposits_and_the_amount_at_most_the_deposit()
    {
        $payin = $this->approved();

        $this->raise($payin, ['utr' => '626812829999'])->assertSessionHasErrors('utr');
        $this->raise($payin, ['amount' => '10000.01'])->assertSessionHasErrors('amount');
        $this->raise($payin, ['proof' => null])->assertSessionHasErrors('proof');
        $this->raise($payin, ['proof' => UploadedFile::fake()->create('notice.exe', 10)])->assertSessionHasErrors('proof');
        $this->assertSame(0, Chargeback::count());

        // The branch may lower the amount; a different-format UTR is normalised.
        $this->raise($payin, ['amount' => '4000', 'utr' => ' 6268 1282 0491 '])->assertSessionHasNoErrors();
        $this->assertSame(400000, Chargeback::sole()->amount);

        // One open chargeback per deposit.
        $this->raise($payin)->assertSessionHasErrors('transaction');
    }

    public function test_only_a_successful_deposit_can_be_charged_back()
    {
        $pending = $this->submittedPayin(['amount' => 500000], '626812820777');

        $this->raise($pending, ['utr' => '626812820777'])->assertSessionHasErrors('transaction');
        $this->assertNull($pending->refresh()->chargeback_status);
    }

    public function test_a_rejected_chargeback_books_nothing_and_can_be_raised_again()
    {
        $payin = $this->approved();
        $this->raise($payin)->assertSessionHasNoErrors();
        $first = Chargeback::sole();

        $this->actingAs($this->admin)->post(route('admin.chargebacks.reject', $first), [])->assertSessionHasErrors('note');
        $this->actingAs($this->admin)->post(route('admin.chargebacks.reject', $first), ['note' => 'Notice is for another UTR'])->assertSessionHasNoErrors();

        $this->assertSame('rejected', $first->refresh()->status);
        $this->assertSame(['success', 'rejected'], [$payin->refresh()->status, $payin->chargeback_status]);
        $this->assertSame([940000, -960000, 20000], $this->positions());
        $this->assertFalse(WebhookEvent::where(['transaction_id' => $payin->id, 'event_type' => 'payin.chargeback'])->exists());

        // Already decided.
        $this->actingAs($this->admin)->post(route('admin.chargebacks.approve', $first))->assertSessionHasErrors('chargeback');

        $this->raise($payin)->assertSessionHasNoErrors();
        $this->assertSame(2, Chargeback::count());
        $this->assertSame('requested', $payin->refresh()->chargeback_status);
    }

    public function test_who_may_raise_and_approve()
    {
        $payin = $this->approved();
        $partnerUser = User::factory()->partner(SystemRoles::PARTNER_OWNER, $this->partner)->withTwoFactor()->create();
        $otherBranch = User::factory()->branch(SystemRoles::BRANCH_OWNER, Branch::factory()->create())->withTwoFactor()->create();
        $viewer = User::factory()->admin(SystemRoles::ADMIN_VIEWER)->withTwoFactor()->create();

        // Another branch can't see the deposit.
        $this->raise($payin, [], $otherBranch)->assertForbidden();
        $this->assertSame(0, Chargeback::count());

        // Admin may raise it for the branch, but someone else approves.
        $this->actingAs($this->admin)->post(route('admin.transactions.chargeback', $payin), [
            'amount' => '10000', 'utr' => '626812820491', 'proof' => UploadedFile::fake()->image('notice.png'),
        ])->assertSessionHasNoErrors();
        $chargeback = Chargeback::sole();
        $this->actingAs($this->admin)->post(route('admin.chargebacks.approve', $chargeback))->assertSessionHasErrors('chargeback');
        $this->actingAs($viewer)->post(route('admin.chargebacks.approve', $chargeback))->assertForbidden();
        $this->actingAs($viewer)->get(route('admin.chargebacks.index'))->assertOk();

        // The partner sees no pending chargeback and never the notice.
        $this->actingAs($partnerUser)->get(route('partner.payins.index'))
            ->assertInertia(fn ($page) => $page->where('transactions.data.0.chargeback', null)->where('transactions.data.0.can.chargeback', false));
        $this->actingAs($partnerUser)->get(route('files.show', $chargeback->proof_file_id))->assertNotFound();
        $this->actingAs($otherBranch)->get(route('files.show', $chargeback->proof_file_id))->assertNotFound();
        $this->actingAs($this->operator)->get(route('files.show', $chargeback->proof_file_id))->assertOk();

        $checker = User::factory()->admin(SystemRoles::ADMIN_FINANCE)->withTwoFactor()->create();
        $this->actingAs($checker)->post(route('admin.chargebacks.approve', $chargeback))->assertSessionHasNoErrors();

        $this->actingAs($partnerUser)->get(route('partner.payins.index', ['status' => 'chargeback']))
            ->assertInertia(fn ($page) => $page
                ->where('transactions.data.0.chargeback.status', 'approved')
                ->where('transactions.data.0.chargeback.amount', 1000000)
                ->where('transactions.data.0.chargeback.proof_file_id', null)
                ->where('summary.chargeback.count', 1)
                ->where('summary.chargeback.amount', 1000000));
    }

    public function test_the_branch_list_offers_the_button_on_successful_deposits_only()
    {
        $payin = $this->approved();
        $this->travel(1)->minutes();
        $this->submittedPayin(['amount' => 500000], '626812820777');

        $this->actingAs($this->operator)->get(route('branch.payins.index'))
            ->assertInertia(fn ($page) => $page
                ->where('transactions.data.0.can.chargeback', false)
                ->where('transactions.data.1.reference', $payin->reference)
                ->where('transactions.data.1.can.chargeback', true));

        $this->raise($payin)->assertSessionHasNoErrors();

        $this->actingAs($this->operator)->get(route('branch.payins.index', ['status' => 'chargeback']))
            ->assertInertia(fn ($page) => $page
                ->where('transactions.total', 1)
                ->where('transactions.data.0.chargeback.status', 'requested')
                ->where('transactions.data.0.chargeback.utr', '626812820491')
                ->where('transactions.data.0.can.chargeback', false)
                ->where('summary.chargeback.pending', 1));
    }

    public function test_it_lands_in_the_settlement_of_the_approval_day_not_the_deposits()
    {
        $payin = $this->approved();
        $this->travel(1)->minutes();
        $this->actingAs($this->admin)->post(route('admin.settlements.calculate'), ['party_type' => 'partner', 'party_id' => $this->partner->id])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('admin.settlements.calculate'), ['party_type' => 'branch', 'party_id' => $this->branch->id])->assertSessionHasNoErrors();

        // Raised two days later, approved a week after the deposit.
        $this->travel(2)->days();
        $this->raise($payin)->assertSessionHasNoErrors();
        $this->travel(5)->days();
        $this->actingAs($this->admin)->post(route('admin.chargebacks.approve', Chargeback::sole()))->assertSessionHasNoErrors();
        $this->travel(1)->minutes();

        $this->actingAs($this->admin)->post(route('admin.settlements.calculate'), ['party_type' => 'partner', 'party_id' => $this->partner->id])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('admin.settlements.calculate'), ['party_type' => 'branch', 'party_id' => $this->branch->id])->assertSessionHasNoErrors();

        $partner = Settlement::query()->where('party_type', 'partner')->orderByDesc('period_end')->firstOrFail();
        $branch = Settlement::query()->where('party_type', 'branch')->orderByDesc('period_end')->firstOrFail();

        $this->assertSame([0, -1000000, 0], [$partner->gross_payin, $partner->chargebacks_total, $partner->adjustments_total]);
        $this->assertSame([940000, -60000], [$partner->opening_balance, $partner->closing_balance]);
        $this->assertSame([0, 1000000], [$branch->gross_payin, $branch->chargebacks_total]);
        $this->assertSame([-960000, 40000], [$branch->opening_balance, $branch->closing_balance]);

        // The first settlements (the deposit's day) have no chargeback.
        $this->assertSame(0, (int) Settlement::query()->whereKeyNot([$partner->id, $branch->id])->sum('chargebacks_total'));

        $owner = User::factory()->branch(SystemRoles::BRANCH_OWNER, $this->branch)->withTwoFactor()->create();
        $this->actingAs($owner)->get(route('branch.settlements.index'))
            ->assertInertia(fn ($page) => $page->where('items.data.0.figures.chargebacks', 1000000));
    }
}
