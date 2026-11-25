<?php

namespace Tests\Feature\Payins;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\SystemRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsPayinNetwork;
use Tests\TestCase;

/**
 * The deposit / withdrawal request sound's poll (client request 2026-11-25).
 */
class RequestPulseTest extends TestCase
{
    use BuildsPayinNetwork, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->buildNetwork();
        $this->activeAccount();
        $this->manualApproval();
    }

    public function test_it_reports_the_newest_submitted_deposit_in_scope()
    {
        $operator = User::factory()->branch(SystemRoles::BRANCH_OPERATOR, $this->branch)->withTwoFactor()->create();
        $other = User::factory()->branch(SystemRoles::BRANCH_OWNER, Branch::factory()->create())->withTwoFactor()->create();

        $this->actingAs($operator)->getJson(route('notifications.pulse'))
            ->assertOk()->assertJson(['deposit' => null, 'withdrawal' => null]);

        // Created but not yet paid: no request yet.
        $this->createPayin();
        $this->actingAs($operator)->getJson(route('notifications.pulse'))->assertJson(['deposit' => null]);

        $payin = $this->submittedPayin();

        $at = $this->actingAs($operator)->getJson(route('notifications.pulse'))->json('deposit');
        $this->assertNotNull($at);
        $this->assertSame($payin->submitted_at->utc()->format('Y-m-d\TH:i:s'), substr($at, 0, 19));

        // Another branch doesn't hear this branch's deposits.
        $this->actingAs($other)->getJson(route('notifications.pulse'))->assertJson(['deposit' => null]);
    }

    public function test_guests_are_refused()
    {
        $this->getJson(route('notifications.pulse'))->assertUnauthorized();
    }
}
