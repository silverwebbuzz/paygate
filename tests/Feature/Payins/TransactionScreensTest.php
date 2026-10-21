<?php

namespace Tests\Feature\Payins;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\SystemRoles;
use App\Domain\Platform\Models\StoredFile;
use App\Domain\Transaction\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsPayinNetwork;
use Tests\TestCase;

class TransactionScreensTest extends TestCase
{
    use BuildsPayinNetwork, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->buildNetwork();
        $this->activeAccount();
        $this->rate('partner', $this->partner->id, 'deposit', '6');
        $this->rate('branch', $this->branch->id, 'deposit', '4');
    }

    private function partial(User $user, string $url, string $component, string $props)
    {
        return $this->actingAs($user)->get($url, [
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => $component,
            'X-Inertia-Partial-Data' => $props,
            'X-Inertia-Version' => Inertia::getVersion(),
        ]);
    }

    public function test_the_queue_shows_submitted_pay_ins_to_their_branch_only()
    {
        $payin = $this->submittedPayin();
        $owner = User::factory()->branch(SystemRoles::BRANCH_OWNER, $this->branch)->withTwoFactor()->create();
        $other = User::factory()->branch(SystemRoles::BRANCH_OWNER, Branch::factory()->create())->withTwoFactor()->create();

        $this->actingAs($owner)->get(route('branch.deposits.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('deposits/index')
                ->where('tab', 'pending')
                ->where('tabs.pending.count', 1)
                ->where('items.data.0.reference', $payin->reference)
                ->where('items.data.0.can.decide', true)
                ->has('reasons.payment_not_found'));

        $this->actingAs($other)->get(route('branch.deposits.index'))
            ->assertInertia(fn (Assert $page) => $page->where('tabs.pending.count', 0)->has('items.data', 0));
    }

    public function test_each_portal_sees_only_what_it_may()
    {
        $payin = $this->submittedPayin();
        $this->actingAs(User::factory()->admin()->withTwoFactor()->create())
            ->post(route('admin.deposits.approve', $payin), ['bank_utr' => '626812820491']);

        $admin = User::factory()->admin()->withTwoFactor()->create();
        $partnerUser = User::factory()->partner(SystemRoles::PARTNER_OWNER, $this->partner)->create();
        $branchUser = User::factory()->branch(SystemRoles::BRANCH_OWNER, $this->branch)->withTwoFactor()->create();

        $adminDetail = $this->partial($admin, route('admin.transactions.index', ['txn' => $payin->id]), 'transactions/index', 'detail')->json('props.detail');
        $this->assertSame(25068, $adminDetail['commission']['margin'] ?? null);
        $this->assertNotEmpty($adminDetail['ledger']);
        $this->assertNotEmpty($adminDetail['audit']);

        $partnerPage = $this->partial($partnerUser, route('partner.payins.index', ['txn' => $payin->id]), 'transactions/index', 'transactions,detail')->json('props');
        $this->assertNull($partnerPage['transactions']['data'][0]['branch']);
        $this->assertNull($partnerPage['transactions']['data'][0]['account']);
        $this->assertArrayNotHasKey('branch', $partnerPage['detail']['commission']);
        $this->assertArrayNotHasKey('margin', $partnerPage['detail']['commission']);
        $this->assertNull($partnerPage['detail']['ledger']);
        $this->assertSame([], $partnerPage['detail']['proofs']);

        $branchPage = $this->partial($branchUser, route('branch.payins.index', ['txn' => $payin->id]), 'transactions/index', 'detail')->json('props.detail');
        $this->assertArrayNotHasKey('partner', $branchPage['commission']);
        $this->assertNull($branchPage['webhooks']);
        $this->assertNull($branchPage['ledger']);

        // Another partner can't open it.
        $stranger = User::factory()->partner(SystemRoles::PARTNER_OWNER)->create();
        $this->assertNull($this->partial($stranger, route('partner.payins.index', ['txn' => $payin->id]), 'transactions/index', 'detail')->json('props.detail'));
    }

    public function test_search_finds_by_reference_order_id_and_utr()
    {
        $payin = $this->submittedPayin(['order_id' => 'ORD-FIND-ME', 'customer' => ['id' => 'cust-1', 'name' => 'Ravi Kumar']], '777766665555');
        $admin = User::factory()->admin()->withTwoFactor()->create();

        foreach ([$payin->reference, 'ORD-FIND-ME', '7777 6666 5555', 'cust-1'] as $term) {
            $this->actingAs($admin)->get(route('admin.transactions.index', ['search' => $term]))
                ->assertInertia(fn (Assert $page) => $page->has('transactions.data', 1));
        }
    }

    public function test_payment_proofs_are_shown_only_to_admin_and_the_receiving_branch()
    {
        Storage::fake('local');
        [$reference, $token] = $this->createPayin();
        $this->post("http://pay.paygate.local/p/{$token}/method", ['method' => 'upi']);
        $this->post("http://pay.paygate.local/p/{$token}/proof", ['photo' => UploadedFile::fake()->image('paid.png')]);
        $file = StoredFile::firstOrFail();

        $this->actingAs(User::factory()->admin()->withTwoFactor()->create())->get(route('files.show', $file))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs(User::factory()->branch(SystemRoles::BRANCH_OPERATOR, $this->branch)->withTwoFactor()->create())->get(route('files.show', $file))->assertOk();

        $this->actingAs(User::factory()->partner(SystemRoles::PARTNER_OWNER, $this->partner)->create())->get(route('files.show', $file))->assertNotFound();
        $this->actingAs(User::factory()->branch(SystemRoles::BRANCH_OWNER, Branch::factory()->create())->withTwoFactor()->create())->get(route('files.show', $file))->assertNotFound();
        auth()->logout();
        $this->get(route('files.show', $file))->assertRedirect(route('login'));

        $this->assertDatabaseHas('audit_logs', ['action' => 'file.viewed']);
        $this->assertNotNull($reference);
    }

    public function test_rows_show_who_decided_the_proof_link_and_how_it_was_created()
    {
        Storage::fake('local');
        [$reference, $token] = $this->createPayin();
        $this->get("http://pay.paygate.local/p/{$token}");
        $this->post("http://pay.paygate.local/p/{$token}/method", ['method' => 'upi']);
        $this->post("http://pay.paygate.local/p/{$token}/proof", ['photo' => UploadedFile::fake()->image('paid.png')]);
        $payin = Transaction::where('reference', $reference)->firstOrFail();

        $admin = User::factory()->admin()->withTwoFactor()->create(['name' => 'Asha Admin']);
        $this->actingAs($admin)->post(route('admin.deposits.approve', $payin), ['bank_utr' => '626812820491'])->assertSessionHasNoErrors();

        $this->actingAs($admin)->get(route('admin.deposits.index', ['tab' => 'approved']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('items.data.0.decided_by', 'Asha Admin')
                ->where('items.data.0.origin', 'api')
                ->where('items.data.0.proof_file_id', StoredFile::firstOrFail()->id)
                ->where('items.data.0.link_opened_at', fn ($at) => is_string($at))
                ->where('items.data.0.branch.code', $this->branch->code));

        $partnerUser = User::factory()->partner(SystemRoles::PARTNER_OWNER, $this->partner)->create();
        $this->actingAs($partnerUser)->get(route('partner.payins.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('transactions.data.0.decided_by', null)
                ->where('transactions.data.0.proof_file_id', null));
    }

    public function test_lists_filter_by_partner_branch_and_date()
    {
        $payin = $this->submittedPayin();
        $admin = User::factory()->admin()->withTwoFactor()->create();
        $other = Branch::factory()->create();
        $today = now(config('app.business_timezone'))->toDateString();
        $tomorrow = now(config('app.business_timezone'))->addDay()->toDateString();

        $count = fn (string $route, array $query, string $key) => count($this->actingAs($admin)->get(route($route, $query))->viewData('page')['props'][$key]['data']);

        foreach ([['admin.transactions.index', 'transactions'], ['admin.deposits.index', 'items']] as [$route, $key]) {
            $this->assertSame(1, $count($route, [], $key));
            $this->assertSame(1, $count($route, ['branches' => [$this->branch->id]], $key));
            $this->assertSame(0, $count($route, ['branches' => [$other->id]], $key));
            $this->assertSame(1, $count($route, ['partner' => $this->partner->id], $key));
            $this->assertSame(1, $count($route, ['from' => $today, 'to' => $today], $key));
            $this->assertSame(0, $count($route, ['from' => $tomorrow], $key));
        }

        $this->actingAs($admin)->get(route('admin.transactions.index'))
            ->assertInertia(fn (Assert $page) => $page->has('branch_options', 2)->has('partners', 1));

        // Approved normally means today only; a date range widens it.
        $this->actingAs($admin)->post(route('admin.deposits.approve', $payin), ['bank_utr' => '626812820491']);
        $this->travel(2)->days();
        $this->assertSame(0, $count('admin.deposits.index', ['tab' => 'approved'], 'items'));
        $this->assertSame(1, $count('admin.deposits.index', ['tab' => 'approved', 'from' => $today], 'items'));
    }
}
