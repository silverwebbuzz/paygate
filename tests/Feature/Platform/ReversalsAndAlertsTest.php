<?php

namespace Tests\Feature\Platform;

use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\SystemRoles;
use App\Domain\Ledger\Ledger;
use App\Domain\Notification\AlertDispatcher;
use App\Domain\Notification\Enums\Alert;
use App\Domain\Notification\Notifications\PortalAlert;
use App\Domain\Transaction\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
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

    /** Client request 2026-11-18: deposit refunds are not used. */
    public function test_deposits_cannot_be_refunded()
    {
        $payin = $this->approved();

        $this->actingAs($this->admin)->get('/admin/refunds')->assertNotFound();
        $this->actingAs($this->admin)->post('/admin/reversals', ['transaction_id' => $payin->id, 'kind' => 'refund', 'reason' => 'x'])->assertNotFound();
        $this->assertSame('success', $payin->refresh()->status);
    }

    public function test_alerts_reach_the_people_who_can_act_in_the_bell()
    {
        Notification::fake();
        $owner = User::factory()->branch(SystemRoles::BRANCH_OWNER, $this->branch)->withTwoFactor()->create();
        $stranger = User::factory()->branch(SystemRoles::BRANCH_OWNER)->withTwoFactor()->create();

        // A deposit waits longer than the threshold (30 min by default): one alert, once.
        $this->submittedPayin(['amount' => 100000]);
        $this->travel(31)->minutes();
        $this->assertSame(1, app(AlertDispatcher::class)->depositsWaiting());
        $this->assertSame(0, app(AlertDispatcher::class)->depositsWaiting());

        Notification::assertSentTo([$this->operator, $owner], PortalAlert::class, fn (PortalAlert $alert, array $channels) => $alert->alert === Alert::DepositsWaiting && $channels === ['database']);
        Notification::assertNotSentTo($stranger, PortalAlert::class);

        // Turned off for that alert: not stored, so not in the bell (no email at all).
        $this->operator->forceFill(['notification_preferences' => ['deposits_waiting' => false]])->save();
        $this->assertSame([], (new PortalAlert(Alert::DepositsWaiting, 't', 'b'))->via($this->operator->refresh()));
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
            ->assertInertia(fn ($page) => $page->component('settings/notifications')->where('events.0.key', 'deposits_waiting')->where('events.0.enabled', true));
        $this->actingAs($owner)->put(route('notifications.update'), ['enabled' => ['deposits_waiting' => false, 'payout_assigned' => true]])->assertSessionHasNoErrors();
        $this->assertFalse($owner->refresh()->wantsAlert(Alert::DepositsWaiting));
        $this->assertTrue($owner->wantsAlert(Alert::PayoutAssigned));
        $this->assertFalse($owner->wantsAlert(Alert::SettlementCalculated)); // switched off = off
    }
}
