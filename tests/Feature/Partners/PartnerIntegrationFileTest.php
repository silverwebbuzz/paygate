<?php

namespace Tests\Feature\Partners;

use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\SystemRoles;
use App\Domain\Partner\Models\Partner;
use App\Domain\Partner\PartnerIntegrationFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
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
        $this->assertPdf($once, 'ATOZ-paygate.pdf');
        $html = app(PartnerIntegrationFile::class)->html($partner->fresh(), $secret);
        $this->assertStringContainsString($secret, $html);
        $this->assertStringContainsString('https://shop.example/return', $html);
        $this->assertStringContainsString('https://shop.example/callback', $html);
        $this->assertStringContainsString('https://shop.example/hooks/payin', $html);
        $this->assertStringContainsString('https://shop.example/hooks/payout', $html);
        $this->assertStringContainsString('POST ', $html);
        $this->assertStringContainsString('/v1/payins', $html);
        $this->assertStringContainsString('/v1/payouts', $html);
        $this->assertStringContainsString('Method', $html);
        $this->assertStringContainsString('100 INR', $html);

        $again = $this->actingAs($admin)->get(route('admin.partners.integration-file.issued', $partner));
        $this->assertPdf($again, 'ATOZ-paygate.pdf');
        $this->assertStringNotContainsString($secret, app(PartnerIntegrationFile::class)->html($partner->fresh(), null));

        $list = $this->actingAs($admin)->get(route('admin.partners.integration-file', $partner));
        $this->assertPdf($list, 'ATOZ-paygate.pdf');
        $listHtml = app(PartnerIntegrationFile::class)->html($partner->fresh(), null);
        $this->assertStringContainsString('https://shop.example/hooks/payin', $listHtml);
        $this->assertStringContainsString('ends with '.substr($secret, -4), $listHtml);
        $this->assertStringNotContainsString($secret, $listHtml);
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
        $this->assertPdf($once, $partner->code.'-paygate.pdf');
        $this->assertStringContainsString($secret, app(PartnerIntegrationFile::class)->html($partner->fresh(), $secret));

        $plain = $this->actingAs($owner)->get(route('partner.developers.integration-file'));
        $this->assertPdf($plain, $partner->code.'-paygate.pdf');
        $plainHtml = app(PartnerIntegrationFile::class)->html($partner->fresh(), null);
        $this->assertStringContainsString('https://shop.example/hooks/payin', $plainHtml);
        $this->assertStringNotContainsString($secret, $plainHtml);

        $this->actingAs($viewer)->get(route('partner.developers.integration-file'))->assertForbidden();
        $this->actingAs(User::factory()->branch()->withTwoFactor()->create())
            ->get(route('admin.partners.integration-file', $partner))
            ->assertForbidden();
    }

    public function test_the_list_download_waits_for_the_key_and_the_urls()
    {
        $admin = User::factory()->admin()->withTwoFactor()->create();
        $partner = Partner::factory()->create([
            'code' => 'WAIT1',
            'is_payout_enabled' => true,
            'return_url' => 'https://shop.example/return',
            'callback_url' => 'https://shop.example/callback',
            'payin_webhook_url' => 'https://shop.example/hooks/payin',
        ]);

        $this->actingAs($admin)->post(route('admin.partners.api-keys.store', $partner), ['password' => 'password'])
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)->get(route('admin.partners.index', ['search' => 'WAIT1']))
            ->assertInertia(fn ($page) => $page->where('partners.data.0.file_ready', false));
        $this->actingAs($admin)->get(route('admin.partners.integration-file', $partner))->assertNotFound();

        $partner->forceFill(['payout_webhook_url' => 'https://shop.example/hooks/payout'])->save();

        $this->actingAs($admin)->get(route('admin.partners.index', ['search' => 'WAIT1']))
            ->assertInertia(fn ($page) => $page->where('partners.data.0.file_ready', true));
        $this->actingAs($admin)->get(route('admin.partners.integration-file', $partner))->assertOk();
    }

    private function assertPdf(TestResponse $response, string $filename): void
    {
        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString(
            'attachment; filename="'.$filename.'"',
            (string) $response->headers->get('content-disposition'),
        );
        $body = $response->getContent();
        $this->assertIsString($body);
        $this->assertStringStartsWith('%PDF', $body);
        $this->assertGreaterThan(2000, strlen($body));
    }
}
