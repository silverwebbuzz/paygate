<?php

namespace Tests\Feature\Platform;

use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\SystemRoles;
use App\Domain\Ledger\Ledger;
use App\Domain\Notification\AlertDispatcher;
use App\Domain\Notification\Enums\Alert;
use App\Domain\Notification\Notifications\PortalAlert;
use App\Domain\Transaction\Models\Transaction;
use App\Domain\Transaction\Models\TransactionReversal;
use App\Domain\Webhook\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsPayinNetwork;
use Tests\TestCase;

class ReversalsAndAlertsTest extends TestCase
{
    use BuildsPayinNetwork, RefreshDatabase;

    private User $admin;

    private User $operator;

    private Ledger $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->buildNetwork();
        $this->partner->update(['payin_webhook_url' => 'https://atoz.example/hooks/payin', 'payout_webhook_url' => 'https://atoz.example/hooks/payout', 'is_payout_enabled' => true]);
        $this->activeAccount();
        $this->rate('partner', $this->partner->id, 'deposit', '6');
        $this->rate('branch', $this->branch->id, 'deposit', '4');
        $this->rate('partner', $this->partner->id, 'withdrawal', '5');
        $this->rate('branch', $this->branch->id, 'withdrawal', '3');
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

    private function reverse(Transaction $txn, string $kind, ?string $bearer = null)
    {
        return $this->actingAs($this->admin)->post(route('admin.reversals.store'), [
            'transaction_id' => $txn->id, 'kind' => $kind, 'bearer' => $bearer, 'reason' => 'Bank notice 77', 'external_reference' => 'CB-77',
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

    public function test_a_chargeback_borne_by_the_partner_moves_the_amount_and_keeps_the_fees()
    {
        $payin = $this->approved();
        $this->assertSame([940000, -960000, 20000], $this->positions());

        $this->reverse($payin, 'chargeback', 'partner')->assertSessionHasNoErrors();

        // The partner loses ₹10,000; the branch no longer owes it; the margin stays.
        $this->assertSame([-60000, 40000, 20000], $this->positions());
        $this->assertSame('chargeback', $payin->refresh()->status);
        $reversal = TransactionReversal::sole();
        $this->assertSame('partner', $reversal->bearer);
        $this->assertSame($this->ledger->journalId($payin->id, 'payin_success'), DB::table('ledger_journals')->where('id', $reversal->journal_id)->value('reverses_journal_id'));
        $this->assertTrue(WebhookEvent::where(['transaction_id' => $payin->id, 'event_type' => 'payin.chargeback'])->exists());
        $this->assertTrue($payin->events()->where('event', 'chargeback')->exists());

        // Once only.
        $this->reverse($payin, 'refund')->assertSessionHasErrors('transaction');
    }

    public function test_a_chargeback_borne_by_the_branch_books_nothing()
    {
        $payin = $this->approved();

        $this->reverse($payin, 'chargeback')->assertSessionHasErrors('bearer');
        $this->reverse($payin, 'chargeback', 'branch')->assertSessionHasNoErrors();

        $this->assertSame([940000, -960000, 20000], $this->positions());
        $this->assertNull(TransactionReversal::sole()->journal_id);
        $this->assertSame('chargeback', $payin->refresh()->status);
    }

    public function test_a_refund_debits_the_partner_and_a_returned_payout_is_fully_reversed()
    {
        $payin = $this->approved();
        $this->reverse($payin, 'refund')->assertSessionHasNoErrors();
        $this->assertSame('refunded', $payin->refresh()->status);
        $this->assertSame([-60000, 40000, 20000], $this->positions());

        // Fund and pay a ₹5,000 payout, then it comes back.
        $second = $this->approved(1000000, '626812820492');
        $this->assertSame([880000, -920000, 40000], $this->positions());
        $this->api('POST', '/v1/payouts', [
            'order_id' => 'WD-1', 'amount' => 500000, 'customer' => ['id' => 'c1'],
            'beneficiary' => ['type' => 'bank', 'name' => 'Ravi', 'account_number' => '50100482716640', 'ifsc' => 'HDFC0001203'],
        ])->assertCreated();
        $payout = Transaction::where('direction', 'payout')->sole();
        $this->actingAs($this->operator)->post(route('branch.payouts.complete', $payout), ['bank_utr' => 'IMPS601234567890'])->assertSessionHasNoErrors();
        $this->assertSame([880000 - 525000, -920000 + 515000, 50000], $this->positions());

        $this->reverse($payout->refresh(), 'return')->assertSessionHasNoErrors();

        $this->assertSame([880000, -920000, 40000], $this->positions());
        $this->assertSame('returned', $payout->refresh()->status);
        $this->assertTrue(WebhookEvent::where(['transaction_id' => $payout->id, 'event_type' => 'payout.returned'])->exists());
        $this->assertSame(0, (int) DB::table('ledger_entries')->sum('amount'));

        // A payout can't be "refunded" like a deposit.
        $this->assertSame('success', $second->status);
        $this->reverse($second, 'return')->assertSessionHasErrors('transaction');
    }

    public function test_only_admins_with_the_permission_record_reversals()
    {
        $payin = $this->approved();
        $viewer = User::factory()->admin(SystemRoles::ADMIN_VIEWER)->withTwoFactor()->create();

        $this->actingAs($viewer)->get(route('admin.chargebacks.index'))->assertOk();
        $this->actingAs($viewer)->post(route('admin.reversals.store'), ['transaction_id' => $payin->id, 'kind' => 'refund', 'reason' => 'x'])->assertForbidden();
        $this->actingAs($this->admin)->get(route('admin.refunds.index', ['find' => $payin->reference]))
            ->assertInertia(fn ($page) => $page->component('admin/reversals')->where('candidate.reference', $payin->reference));
    }

    public function test_alerts_reach_the_people_who_can_act_by_bell_and_email()
    {
        Notification::fake();
        $owner = User::factory()->branch(SystemRoles::BRANCH_OWNER, $this->branch)->withTwoFactor()->create();
        $stranger = User::factory()->branch(SystemRoles::BRANCH_OWNER)->withTwoFactor()->create();

        // A deposit waits longer than the threshold (30 min by default): one alert, once.
        $this->submittedPayin(['amount' => 100000]);
        $this->travel(31)->minutes();
        $this->assertSame(1, app(AlertDispatcher::class)->depositsWaiting());
        $this->assertSame(0, app(AlertDispatcher::class)->depositsWaiting());

        Notification::assertSentTo([$this->operator, $owner], PortalAlert::class, fn (PortalAlert $alert, array $channels) => $alert->alert === Alert::DepositsWaiting && $channels === ['database', 'mail']);
        Notification::assertNotSentTo($stranger, PortalAlert::class);

        // Email off for that alert: the bell only.
        $this->operator->forceFill(['notification_preferences' => ['deposits_waiting' => false]])->save();
        $this->assertSame(['database'], (new PortalAlert(Alert::DepositsWaiting, 't', 'b'))->via($this->operator->refresh()));
    }

    public function test_the_bell_shows_unread_alerts_and_preferences_are_saved()
    {
        app(AlertDispatcher::class)->unsettledLines($this->branch->id, 2, null);
        $owner = User::factory()->branch(SystemRoles::BRANCH_OWNER, $this->branch)->withTwoFactor()->create();
        app(AlertDispatcher::class)->unsettledLines($this->branch->id, 3, null);

        // Alerts are queued; deliver them as the worker would.
        Queue::pushed(SendQueuedNotifications::class)->each(fn (SendQueuedNotifications $job) => $job->handle(app(ChannelManager::class)));

        $this->actingAs($owner)->get(route('branch.dashboard'))
            ->assertInertia(fn ($page) => $page->where('alerts.unread', 1)->where('alerts.latest.0.title', '3 bank lines need attention'));
        $this->actingAs($owner)->post(route('notifications.read-all'));
        $this->assertSame(0, $owner->unreadNotifications()->count());

        $this->actingAs($owner)->get(route('notifications.edit'))
            ->assertInertia(fn ($page) => $page->component('settings/notifications')->where('events.0.key', 'deposits_waiting'));
        $this->actingAs($owner)->put(route('notifications.update'), ['email' => ['deposits_waiting' => false, 'payout_assigned' => true]])->assertSessionHasNoErrors();
        $this->assertFalse($owner->refresh()->wantsEmail(Alert::DepositsWaiting));
        $this->assertTrue($owner->wantsEmail(Alert::PayoutAssigned));
        $this->assertFalse($owner->wantsEmail(Alert::SettlementCalculated)); // unticked = off
    }
}
