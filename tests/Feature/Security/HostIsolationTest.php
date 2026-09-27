<?php

namespace Tests\Feature\Security;

use Tests\TestCase;

class HostIsolationTest extends TestCase
{
    public function test_portal_routes_do_not_answer_on_the_api_or_payer_hosts()
    {
        foreach (['api.paygate.local', 'pay.paygate.local'] as $host) {
            $this->get("http://{$host}/login")->assertNotFound();
            $this->get("http://{$host}/dashboard")->assertNotFound();
            $this->get("http://{$host}/horizon")->assertNotFound();
        }
    }

    public function test_package_routes_without_a_domain_only_answer_on_the_portal_host()
    {
        $this->get('http://pay.paygate.local/passkeys/login/options')->assertNotFound();
        $this->get('http://api.paygate.local/passkeys/login/options')->assertNotFound();
        $this->get('http://paygate.local/passkeys/login/options')->assertOk();
    }

    public function test_api_host_serves_json()
    {
        $this->get('http://api.paygate.local/v1/ping')->assertOk()->assertJson(['status' => 'ok']);
        $this->get('http://api.paygate.local/v1/missing')->assertNotFound()->assertJsonStructure(['message']);
    }

    public function test_every_response_has_a_request_id()
    {
        $this->get('http://api.paygate.local/v1/ping')->assertHeader('X-Request-Id');
    }
}
