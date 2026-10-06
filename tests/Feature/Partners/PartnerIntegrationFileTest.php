<?php

namespace Tests\Feature\Partners;

use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\SystemRoles;
use App\Domain\Partner\Models\Partner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartnerIntegrationFileTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_partner_list_file_has_the_urls_and_not_the_secret()
    {
        $admin = User::factory()->admin()->withTwoFactor()->create();
        $partner = Partner::factory()->create([
            'code' => 'ATOZ',
            'return_url' => 'https://shop.example/return',
            'callback_url' => 'https://shop.example/callback',
            'payin_webhook_url' => 'https://shop.example/hooks/payin',
            'payout_webhook_url' => 'https://shop.example/hooks/payout',
        ]);

        $issued = $this->actingAs($admin)->post(route('admin.partners.api-keys.store', $partner), ['password' => 'password']);
        $issued->assertSessionHasNoErrors();
        $secret = $issued->getSession()->get('inertia.flash_data')['credentials']['secret'];
        $this->assertIsString($secret);

        $once = $this->actingAs($admin)->get(route('admin.partners.integration-file.issued', $partner));
        $once->assertOk();
        $once->assertHeader('content-type', 'text/plain; charset=UTF-8');
        $this->assertStringContainsString('attachment; filename="ATOZ-paygate.txt"', (string) $once->headers->get('content-disposition'));
        $body = $once->getContent();
        $this->assertStringContainsString($secret, $body);
        $this->assertStringContainsString('https://shop.example/return', $body);
        $this->assertStringContainsString('https://shop.example/callback', $body);
        $this->assertStringContainsString('https://shop.example/hooks/payin', $body);
        $this->assertStringContainsString('https://shop.example/hooks/payout', $body);
        $this->assertStringContainsString('POST ', $body);
        $this->assertStringContainsString('/v1/payins', $body);
        $this->assertStringContainsString('/v1/payouts', $body);

        $again = $this->actingAs($admin)->get(route('admin.partners.integration-file.issued', $partner));
        $this->assertStringNotContainsString($secret, $again->getContent());

        $list = $this->actingAs($admin)->get(route('admin.partners.integration-file', $partner));
        $listBody = $list->getContent();
        $this->assertStringContainsString('https://shop.example/hooks/payin', $listBody);
        $this->assertStringContainsString('ends with '.substr($secret, -4), $listBody);
        $this->assertStringNotContainsString($secret, $listBody);
    }

    public function test_a_partner_downloads_their_file_and_others_cannot()
    {
        $partner = Partner::factory()->create(['payin_webhook_url' => 'https://shop.example/hooks/payin']);
        $owner = User::factory()->partner(SystemRoles::PARTNER_OWNER, $partner)->create();
        $viewer = User::factory()->partner(SystemRoles::PARTNER_VIEWER, $partner)->create();

        $issued = $this->actingAs($owner)->post(route('partner.developers.api-keys.store'), ['password' => 'password']);
        $issued->assertSessionHasNoErrors();
        $secret = $issued->getSession()->get('inertia.flash_data')['credentials']['secret'];

        $once = $this->actingAs($owner)->get(route('partner.developers.integration-file.issued'));
        $once->assertOk();
        $this->assertStringContainsString($secret, $once->getContent());

        $plain = $this->actingAs($owner)->get(route('partner.developers.integration-file'));
        $plain->assertOk();
        $this->assertStringContainsString('https://shop.example/hooks/payin', $plain->getContent());
        $this->assertStringNotContainsString($secret, $plain->getContent());

        $this->actingAs($viewer)->get(route('partner.developers.integration-file'))->assertForbidden();
        $this->actingAs(User::factory()->branch()->withTwoFactor()->create())
            ->get(route('admin.partners.integration-file', $partner))
            ->assertForbidden();
    }
}
