<?php

namespace Tests\Feature\Platform;

use App\Support\Hosts;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Areas on their own hosts (default) and on one shared host with path
 * prefixes (single-domain setup, Webuzo-Server-Setup.md).
 */
class HostsTest extends TestCase
{
    public function test_separate_hosts_need_no_prefix()
    {
        config(['app.url' => 'https://example.com', 'app.domains.api' => 'api.example.com', 'app.paths.api' => '']);

        $this->assertSame('https://api.example.com/v1', Hosts::url('api', '/v1'));
        $this->assertTrue(Hosts::isApiRequest(Request::create('https://api.example.com/v1/ping')));
        $this->assertFalse(Hosts::isApiRequest(Request::create('https://example.com/v1/ping')));
        $this->assertSame('/v1/payins?x=1', Hosts::apiRequestUri(Request::create('https://api.example.com/v1/payins?x=1')));
    }

    public function test_single_host_tells_the_api_apart_by_its_prefix()
    {
        config([
            'app.url' => 'https://example.com',
            'app.domains' => ['app' => 'example.com', 'api' => 'example.com', 'pay' => 'example.com'],
            'app.paths' => ['api' => 'api', 'pay' => 'pay'],
        ]);

        $this->assertSame('https://example.com/api/v1', Hosts::url('api', '/v1'));
        $this->assertSame('https://example.com/pay/p/abc', Hosts::url('pay', '/p/abc'));
        $this->assertSame('https://example.com/login', Hosts::url('app', '/login'));

        $this->assertTrue(Hosts::isApiRequest(Request::create('https://example.com/api/v1/ping')));
        $this->assertFalse(Hosts::isApiRequest(Request::create('https://example.com/admin')));
        $this->assertFalse(Hosts::isApiRequest(Request::create('https://example.com/apiary')));

        // Partners sign the path relative to the API base, prefix or not.
        $this->assertSame('/v1/payins?x=1', Hosts::apiRequestUri(Request::create('https://example.com/api/v1/payins?x=1')));
    }
}
