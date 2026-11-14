<?php

namespace Tests\Feature\Payins;

use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\SystemRoles;
use App\Domain\Ledger\Ledger;
use App\Domain\Platform\SectionRollout;
use App\Domain\Platform\Settings;
use App\Domain\Transaction\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsPayinNetwork;
use Tests\TestCase;

/**
 * Payments › Deposit and › Withdrawal (client request 2026-11-09): the
 * existing pages are tabs of one menu item and share the `hub` prop (which
 * page, the counts on the tabs, the bank entry bar). Each tab keeps its own
 * page, permission and rules.
 */
class PaymentHubTest extends TestCase
{
    use BuildsPayinNetwork, RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Storage::fake('local');
        $this->buildNetwork();
        $this->activeAccount();
        $this->rate('partner', $this->partner->id, 'deposit', '6');
        $this->rate('branch', $this->branch->id, 'deposit', '4');
        $this->operator = User::factory()->branch(SystemRoles::BRANCH_OPERATOR, $this->branch)->withTwoFactor()->create();
    }

    public function test_every_deposit_tab_shares_the_hub_and_only_manual_entry_has_the_bank_entry()
    {
        $waiting = $this->submittedPayin();
        // A bank line with no deposit behind it: an open unsettled case.
        $this->actingAs($this->operator)->post(route('branch.statements.store'), [
            'payment_account_id' => $this->branch->paymentAccounts()->value('id'),
            'value_date' => now(config('app.business_timezone'))->toDateString(),
            'utr' => '999988887777',
            'credit' => '500.00',
        ])->assertSessionHasNoErrors();

        $tabs = [
            route('branch.deposits.index') => 'deposits/index',
            route('branch.statements.index') => 'statements/index',
            route('branch.cases.index') => 'reconciliation/cases',
            route('branch.reconciliation.index', ['direction' => 'payin']) => 'reconciliation/index',
            route('branch.payins.index') => 'transactions/index',
        ];

        foreach ($tabs as $url => $component) {
            $this->actingAs($this->operator)->get($url)
                ->assertInertia(fn (Assert $page) => $page
                    ->component($component)
                    ->where('hub.kind', 'deposit')
                    ->where('hub.counts.queue', 1)
                    ->where('hub.counts.unsettled', 1)
                    ->where('hub.entry', $component === 'statements/index'
                        ? fn ($entry) => count($entry['accounts']) === 1
                            && $entry['accounts'][0]['branch_id'] === $this->branch->id
                        : null));
        }

        // The bar on the Manual Entry tab approves the waiting deposit.
        $this->actingAs($this->operator)
            ->from(route('branch.statements.index'))
            ->post(route('branch.statements.store'), [
                'payment_account_id' => $waiting->payment_account_id,
                'value_date' => now(config('app.business_timezone'))->toDateString(),
                'utr' => '626812820491',
                'credit' => number_format($waiting->amount / 100, 2, '.', ''),
            ])
            ->assertRedirect(route('branch.statements.index'));

        $this->assertSame('success', $waiting->refresh()->status);
        $this->actingAs($this->operator)->get(route('branch.deposits.index'))
            ->assertInertia(fn (Assert $page) => $page->where('hub.counts.queue', 0));

        // Admin's lists are tabs too; Chargebacks / Payout returns stay pages of their own.
        $admin = User::factory()->admin()->withTwoFactor()->create();
        $this->actingAs($admin)->get(route('admin.transactions.index'))
            ->assertInertia(fn (Assert $page) => $page->where('hub.kind', 'deposit')->where('hub.entry', null));
        $this->actingAs($admin)->get(route('admin.statements.index'))
            ->assertInertia(fn (Assert $page) => $page->where('hub.kind', 'deposit')->has('hub.entry.branches', 1));
        $this->actingAs($admin)->get(route('admin.payout-returns.index'))
            ->assertInertia(fn (Assert $page) => $page->where('hub', null));
    }

    public function test_withdrawal_tabs_and_the_utr_tab_follow_the_direction()
    {
        $this->partner->update(['is_payout_enabled' => true]);
        $this->rate('partner', $this->partner->id, 'withdrawal', '5');
        $this->rate('branch', $this->branch->id, 'withdrawal', '3');
        $ledger = app(Ledger::class);
        DB::transaction(fn () => $ledger->post('adjustment', [
            $ledger->account(Ledger::PARTNER_POSITION, $this->partner->id, $this->branch->id) => 1000000,
            $ledger->account(Ledger::SETTLEMENT_CLEARING) => -1000000,
        ], null, 'Test funding'));

        $reference = $this->api('POST', '/v1/payouts', [
            'order_id' => 'WD-1',
            'amount' => 100000,
            'customer' => ['id' => 'cust-9'],
            'beneficiary' => ['type' => 'bank', 'name' => 'Ravi Kumar', 'account_number' => '50100482716640', 'ifsc' => 'HDFC0001203'],
        ])->assertCreated()->json('data.id');
        $this->assertSame('assigned', Transaction::where('reference', $reference)->value('status'));

        $tabs = [
            route('branch.payouts.index') => 'payouts/index',
            route('branch.reconciliation.index', ['direction' => 'payout']) => 'reconciliation/index',
            route('branch.payouts.history') => 'transactions/index',
        ];

        foreach ($tabs as $url => $component) {
            $this->actingAs($this->operator)->get($url)
                ->assertInertia(fn (Assert $page) => $page
                    ->component($component)
                    ->where('hub.kind', 'withdrawal')
                    ->where('hub.counts.queue', 1)
                    ->where('hub.entry', null));
        }

        // Without a direction, UTR Reconciliation is the Deposit tab.
        $this->actingAs($this->operator)->get(route('branch.reconciliation.index'))
            ->assertInertia(fn (Assert $page) => $page->where('hub.kind', 'deposit'));
    }

    public function test_the_bank_entry_needs_statements_create_and_partners_get_the_list_only()
    {
        $this->submittedPayin();

        // Admin viewer: deposits only (no statements, no reconciliation).
        $viewer = User::factory()->admin(SystemRoles::ADMIN_VIEWER)->withTwoFactor()->create();
        $this->actingAs($viewer)->get(route('admin.deposits.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('hub.kind', 'deposit')
                ->where('hub.counts', ['queue' => 1])
                ->where('hub.entry', null));

        $partnerUser = User::factory()->partner(SystemRoles::PARTNER_OWNER, $this->partner)->create();
        $this->actingAs($partnerUser)->get(route('partner.payins.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('hub.kind', 'deposit')
                ->where('hub.counts', [])
                ->where('hub.entry', null));
        $this->actingAs($partnerUser)->get(route('partner.payouts.index'))
            ->assertInertia(fn (Assert $page) => $page->where('hub.kind', 'withdrawal'));
        $this->actingAs($partnerUser)->get(route('partner.chargebacks.index'))
            ->assertInertia(fn (Assert $page) => $page->where('hub', null));
    }

    public function test_each_tab_is_its_own_section_rollout_item_and_all_withdrawals_split_from_transactions()
    {
        $super = User::factory()->admin()->withTwoFactor()->create();
        $ops = User::factory()->admin(SystemRoles::ADMIN_OPS)->withTwoFactor()->create();

        // Opened from the Withdrawal group only: All withdrawals, not All deposits.
        $this->actingAs($super)->put(route('admin.section-rollout.update'), [
            'active' => true,
            'open' => ['admin' => ['payouts', 'payout_transactions'], 'branch' => [], 'partner' => []],
        ])->assertSessionHasNoErrors();

        $this->actingAs($ops)->get(route('admin.transactions.payouts'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('hub.kind', 'withdrawal')
                ->where('rolloutHidden', fn ($hidden) => collect($hidden)->contains('/admin/transactions') && ! collect($hidden)->contains('/admin/transactions/payouts')));
        $this->actingAs($ops)->get(route('admin.transactions.index'))->assertRedirect(route('admin.dashboard'));

        // Saved before the split (no version): Transactions open opens All withdrawals too.
        app(Settings::class)->set(SectionRollout::SETTING, ['active' => true, 'open' => ['admin' => ['transactions']]], $super);
        $this->assertSame(['transactions', 'payout_transactions'], app(SectionRollout::class)->state()['open']['admin']);
        $this->actingAs($ops)->get(route('admin.transactions.payouts'))->assertOk();

        // Saved again: the new item stands on its own.
        $this->actingAs($super)->put(route('admin.section-rollout.update'), [
            'active' => true,
            'open' => ['admin' => ['transactions'], 'branch' => [], 'partner' => []],
        ]);
        $this->assertSame(['transactions'], app(SectionRollout::class)->state()['open']['admin']);
        $this->actingAs($ops)->get(route('admin.transactions.payouts'))->assertRedirect(route('admin.dashboard'));
    }
}
