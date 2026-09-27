<?php

namespace Tests\Feature\Payins;

use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\SystemRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsPayinNetwork;
use Tests\TestCase;

class PartnerApiPagesTest extends TestCase
{
    use BuildsPayinNetwork, RefreshDatabase;

    public function test_developers_see_the_docs_with_their_key_and_their_own_api_log()
    {
        $this->buildNetwork();
        $this->createPayin(['order_id' => 'ORD-DOCS']);
        $developer = User::factory()->partner(SystemRoles::PARTNER_DEVELOPER, $this->partner)->create();

        $this->actingAs($developer)->get(route('partner.api-docs'))
            ->assertInertia(fn (Assert $page) => $page->component('partner/api-docs')->where('key_id', $this->keyId)->where('base_url', 'http://api.paygate.local/v1'));

        $this->actingAs($developer)->get(route('partner.api-logs'))
            ->assertInertia(fn (Assert $page) => $page->component('partner/api-logs')->has('logs.data', 1)->where('logs.data.0.order_id', 'ORD-DOCS'));

        // Another partner's log stays private.
        $stranger = User::factory()->partner(SystemRoles::PARTNER_OWNER)->create();
        $this->actingAs($stranger)->get(route('partner.api-logs'))->assertInertia(fn (Assert $page) => $page->has('logs.data', 0));
    }

    public function test_viewers_cannot_open_developer_pages()
    {
        $viewer = User::factory()->partner(SystemRoles::PARTNER_VIEWER)->create();

        $this->actingAs($viewer)->get(route('partner.api-docs'))->assertForbidden();
        $this->actingAs($viewer)->get(route('partner.api-logs'))->assertForbidden();
    }
}
