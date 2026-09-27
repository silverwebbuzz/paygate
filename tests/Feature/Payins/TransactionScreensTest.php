<?php

namespace Tests\Feature\Payins;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\SystemRoles;
use App\Domain\Platform\Models\StoredFile;
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
        $payin = $this->submittedPayin(['order_id' => 'ORD-FIND-ME'], '777766665555');
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
}
