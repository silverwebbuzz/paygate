<?php

namespace App\Domain\SecurityReport;

use App\Domain\PartnerApi\ApiAuthenticator;
use App\Domain\Platform\Models\Page;
use App\Support\Hosts;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * The automated part of the security report: the server checks its own
 * configuration (ConfigChecks) and then calls its own public addresses the
 * way an outsider would, the same checks as scripts/security-scan.sh.
 *
 * Read-only: it never logs in and creates no data. It does trip the API
 * lockout and the payment-page rate limit for the server's own IP address
 * for about a minute (real partners and customers are not affected), and
 * sends a light load (config paygate.security_scan).
 */
class SecurityScanner
{
    private const UA = 'PayGate-SecurityScan/1';

    /** @var list<array{status: string, area: string, check: string, detail: string}> */
    private array $rows = [];

    /**
     * @return array{portal: string, api: string, pay: string}
     */
    public static function target(): array
    {
        return [
            'portal' => Hosts::url('app'),
            'api' => Hosts::url('api'),
            'pay' => Hosts::url('pay'),
        ];
    }

    /**
     * @return array{results: list<array{status: string, area: string, check: string, detail: string}>, performance: list<array<string, mixed>>}
     */
    public function run(): array
    {
        $this->rows = ConfigChecks::run();
        ['portal' => $portal, 'api' => $api, 'pay' => $pay] = self::target();

        $this->transport($portal);
        $this->headers($portal, $api, $pay);
        $this->cookies($portal);
        $this->exposure($portal);
        $this->errors($portal, $api);
        $this->partnerApi($api);
        $this->checkout($pay);
        $this->login($portal);
        $performance = $this->performance($portal, $api, $pay);

        return ['results' => $this->rows, 'performance' => $performance];
    }

    private function transport(string $portal): void
    {
        $host = (string) parse_url($portal, PHP_URL_HOST);

        if (! str_starts_with($portal, 'https://')) {
            $this->add('fail', 'TLS', 'Site uses https', "The portal address is {$portal}.");

            return;
        }

        $context = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'SNI_enabled' => true, 'peer_name' => $host]]);
        $socket = @stream_socket_client("ssl://{$host}:443", $errno, $error, 10, STREAM_CLIENT_CONNECT, $context);

        if ($socket === false) {
            $this->add('warn', 'TLS', 'Certificate valid', "Could not connect from the server to {$host}:443 ({$error}). Check with SSL Labs.");
        } else {
            $meta = stream_get_meta_data($socket);
            $protocol = (string) ($meta['crypto']['protocol'] ?? 'unknown');
            $this->add(in_array($protocol, ['TLSv1.2', 'TLSv1.3'], true) ? 'pass' : 'fail', 'TLS', 'Modern TLS negotiated', $protocol);

            $cert = openssl_x509_parse(stream_context_get_params($socket)['options']['ssl']['peer_certificate'] ?? '');
            $expires = is_array($cert) ? (int) ($cert['validTo_time_t'] ?? 0) : 0;
            $days = (int) floor(($expires - time()) / 86400);
            $this->add($expires === 0 ? 'fail' : ($days > 14 ? 'pass' : ($days > 0 ? 'warn' : 'fail')), 'TLS', 'Certificate valid',
                $expires === 0 ? 'Could not read the certificate.' : 'Expires '.date('j M Y', $expires)." ({$days} days).");
            fclose($socket);
        }

        $response = $this->get('http://'.$host.'/');
        $location = (string) $response?->header('Location');
        $this->add($response !== null && $response->redirect() && str_starts_with($location, 'https://') ? 'pass' : 'fail', 'TLS', 'http:// redirects to https://',
            $response === null ? 'No answer on http://' : $response->status().($location !== '' ? ' → '.$location : ''));
    }

    private function headers(string $portal, string $api, string $pay): void
    {
        $required = ['Strict-Transport-Security', 'X-Content-Type-Options', 'X-Frame-Options', 'Referrer-Policy'];

        foreach (['Portal' => $portal.'/login', 'API' => $api.'/v1/ping', 'Payment page' => $pay.'/p/'.Str::random(43)] as $name => $url) {
            $response = $this->get($url);

            if ($response === null) {
                $this->add('fail', 'Headers', "{$name} answers", "No answer from {$url}");

                continue;
            }

            foreach ($required as $header) {
                $value = (string) $response->header($header);

                if ($header === 'Strict-Transport-Security' && ! str_starts_with($url, 'https://')) {
                    $this->add('fail', 'Headers', "{$header} ({$name})", 'Not https, so browsers ignore HSTS.');

                    continue;
                }

                $this->add($value !== '' ? 'pass' : 'fail', 'Headers', "{$header} ({$name})", $value !== '' ? $value : 'missing');
            }

            $powered = (string) $response->header('X-Powered-By');
            $this->add($powered === '' ? 'pass' : 'warn', 'Headers', "No X-Powered-By ({$name})", $powered === '' ? 'hidden' : $powered);

            $server = (string) $response->header('Server');
            $this->add(preg_match('/\d+\.\d+/', $server) === 1 ? 'warn' : 'pass', 'Headers', "Server version hidden ({$name})", $server !== '' ? $server : 'none');

            if ($name === 'Portal') {
                $csp = (string) $response->header('Content-Security-Policy');
                $this->add($csp !== '' ? 'pass' : 'fail', 'Headers', 'Content-Security-Policy (Portal)', $csp !== '' ? $csp : 'missing');
            }
        }
    }

    private function cookies(string $portal): void
    {
        $response = $this->get($portal.'/login');
        /** @var list<string> $cookies */
        $cookies = $response?->headers()['Set-Cookie'] ?? [];

        if ($cookies === []) {
            $this->add('warn', 'Cookies', 'Session cookie flags', 'No cookie was set on /login.');

            return;
        }

        foreach ($cookies as $cookie) {
            $name = Str::before((string) $cookie, '=');
            $missing = [];

            foreach (['secure' => 'Secure', 'samesite' => 'SameSite'] + ($name === 'XSRF-TOKEN' ? [] : ['httponly' => 'HttpOnly']) as $flag => $label) {
                if (! str_contains(strtolower((string) $cookie), $flag)) {
                    $missing[] = $label;
                }
            }

            $this->add($missing === [] ? 'pass' : 'fail', 'Cookies', "Cookie {$name} flags", $missing === [] ? 'Secure, HttpOnly / SameSite set' : 'Missing: '.implode(', ', $missing));
        }
    }

    private function exposure(string $portal): void
    {
        $paths = ['/.env', '/.env.example', '/.git/config', '/.git/HEAD', '/composer.json', '/composer.lock', '/package.json',
            '/storage/logs/laravel.log', '/vendor/composer/installed.json', '/phpinfo.php', '/info.php', '/server-status',
            '/adminer.php', '/adminer', '/telescope', '/_ignition/health-check', '/docker-compose.yml', '/compose.yaml'];

        foreach ($paths as $path) {
            $status = $this->get($portal.$path)?->status();
            $this->add($status === 200 ? 'fail' : 'pass', 'Exposure', "{$path} not public", $status === 200 ? 'HTTP 200: publicly readable' : 'HTTP '.($status ?? 'no answer'));
        }

        foreach (['/horizon', '/admin', '/partner', '/branch', '/admin/qa-checklist', '/admin/security-report'] as $path) {
            $status = $this->get($portal.$path)?->status();
            $this->add($status === 200 ? 'fail' : 'pass', 'Access', "{$path} needs login", $status === 200 ? 'HTTP 200 without login' : 'HTTP '.($status ?? 'no answer'));
        }
    }

    private function errors(string $portal, string $api): void
    {
        $leak = '/Whoops|Stack trace|vendor\/laravel|Ignition|SQLSTATE|APP_KEY/i';

        $body = (string) $this->get($portal.'/this-page-does-not-exist-'.Str::random(8))?->body();
        $this->add(preg_match($leak, $body) === 1 ? 'fail' : 'pass', 'Errors', '404 page leaks no debug info', preg_match($leak, $body) === 1 ? 'Debug output visible' : 'clean');

        $body = (string) $this->send('post', $api.'/v1/payins', fn ($request) => $request->withBody('{bad json', 'application/json'))?->body();
        $leaks = preg_match('/Stack trace|vendor\/|SQLSTATE|\.php/i', $body) === 1;
        $this->add($leaks ? 'fail' : 'pass', 'Errors', 'API errors leak no internals', Str::limit($body, 120));

        $status = $this->send('send', $portal.'/', fn ($request) => $request, 'TRACE')?->status();
        $this->add($status === 200 ? 'fail' : 'pass', 'Errors', 'TRACE method disabled', 'HTTP '.($status ?? 'no answer'));
    }

    private function partnerApi(string $api): void
    {
        $status = $this->get($api.'/v1/ping')?->status();
        $this->add($status === 200 ? 'pass' : 'warn', 'API', 'Health endpoint answers', 'HTTP '.($status ?? 'no answer'));

        $status = $this->send('post', $api.'/v1/payins', fn ($request) => $request->asJson())?->status();
        $this->add($status === 401 ? 'pass' : 'fail', 'API', 'Unsigned request refused', 'HTTP '.($status ?? 'no answer'));

        $status = $this->send('get', $api.'/v1/balance', fn ($request) => $request->withHeaders([
            'X-Key-Id' => 'pk_live_scan', 'X-Timestamp' => (string) time(), 'X-Nonce' => 'scan'.Str::random(20), 'X-Signature' => 'deadbeef',
        ]))?->status();
        $this->add($status === 401 ? 'pass' : 'fail', 'API', 'Forged signature refused', 'HTTP '.($status ?? 'no answer'));

        $lockedAfter = null;

        for ($i = 1; $i <= ApiAuthenticator::MAX_FAILURES_PER_MINUTE + 5; $i++) {
            if ($this->send('post', $api.'/v1/payins', fn ($request) => $request->asJson())?->status() === 429) {
                $lockedAfter = $i;
                break;
            }
        }

        $this->add($lockedAfter !== null ? 'pass' : 'fail', 'API', 'Brute-force lockout',
            $lockedAfter !== null ? "429 after {$lockedAfter} failed attempts" : 'No 429 after '.(ApiAuthenticator::MAX_FAILURES_PER_MINUTE + 5).' failed attempts');

        $cors = (string) $this->send('get', $api.'/v1/ping', fn ($request) => $request->withHeaders(['Origin' => 'https://evil.example']))?->header('Access-Control-Allow-Origin');
        $open = $cors === '*' || $cors === 'https://evil.example';
        $this->add($open ? 'warn' : 'pass', 'API', 'CORS not open to any site', $cors !== '' ? "Access-Control-Allow-Origin: {$cors}" : 'not sent');
    }

    private function checkout(string $pay): void
    {
        $status = $this->get($pay.'/p/'.Str::random(43))?->status();
        $this->add($status === 404 ? 'pass' : 'fail', 'Payment page', 'Random payment link refused', 'HTTP '.($status ?? 'no answer'));

        $status = $this->get($pay."/p/'%20OR%201=1--")?->status();
        $this->add(in_array($status, [400, 404], true) ? 'pass' : 'fail', 'Payment page', 'Injection in the link refused', 'HTTP '.($status ?? 'no answer'));

        $token = Str::random(43);
        $limitedAfter = null;

        for ($i = 1; $i <= 70; $i++) {
            if ($this->get($pay.'/p/'.$token)?->status() === 429) {
                $limitedAfter = $i;
                break;
            }
        }

        $this->add($limitedAfter !== null ? 'pass' : 'warn', 'Payment page', 'Link guessing rate-limited', $limitedAfter !== null ? "429 after {$limitedAfter} requests" : 'No 429 within 70 requests a minute');
    }

    private function login(string $portal): void
    {
        $response = $this->get($portal.'/login');
        $csrf = $response !== null && (str_contains((string) $response->header('Set-Cookie'), 'XSRF-TOKEN') || preg_match('/csrf|_token/i', $response->body()) === 1);
        $this->add($csrf ? 'pass' : 'warn', 'Login', 'CSRF protection present', $csrf ? 'token issued' : 'no CSRF token seen');

        $status = $this->send('post', $portal.'/login', fn ($request) => $request->acceptJson()->asJson()->withBody('{"email":"scan@example.com","password":"x"}', 'application/json'))?->status();
        $this->add(in_array($status, [419, 422, 302], true) ? 'pass' : 'warn', 'Login', 'Login without CSRF token refused', 'HTTP '.($status ?? 'no answer'));
    }

    /**
     * A light load per page (config paygate.security_scan): requests per
     * second and response times as seen from the server itself, so without
     * internet distance.
     *
     * @return list<array{url: string, requests: int, concurrency: int, per_second: float, p50: int, p95: int, p99: int, failed: int}>
     */
    private function performance(string $portal, string $api, string $pay): array
    {
        $requests = max(1, (int) config('paygate.security_scan.load_requests'));
        $concurrency = max(1, (int) config('paygate.security_scan.load_concurrency'));
        $page = Page::query()->where('status', 'published')->value('slug');
        $targets = array_filter([
            'Partner API (health check)' => $api.'/v1/ping',
            'Portal login page' => $portal.'/login',
            'Payment page (content page)' => is_string($page) ? $pay.'/legal/'.$page : null,
        ]);
        $rows = [];

        foreach ($targets as $name => $url) {
            $started = microtime(true);

            try {
                $responses = Http::pool(fn (Pool $pool) => array_map(
                    fn () => $pool->withUserAgent(self::UA)->timeout(20)->get($url),
                    range(1, $requests),
                ), $concurrency);
            } catch (Throwable) {
                $responses = [];
            }

            $elapsed = max(0.001, microtime(true) - $started);
            $times = [];
            $failed = 0;

            foreach ($responses as $response) {
                if (! $response instanceof Response || $response->serverError() || $response->status() === 0) {
                    $failed++;

                    continue;
                }

                $times[] = (int) round(((float) ($response->handlerStats()['total_time'] ?? 0)) * 1000);
            }

            $failed += $requests - count($responses);
            sort($times);
            $percentile = fn (int $p) => $times === [] ? 0 : $times[(int) min(count($times) - 1, max(0, (int) ceil($p / 100 * count($times)) - 1))];

            $rows[] = [
                'name' => $name,
                'url' => $url,
                'requests' => $requests,
                'concurrency' => $concurrency,
                'per_second' => round(count($times) / $elapsed, 1),
                'p50' => $percentile(50),
                'p95' => $percentile(95),
                'p99' => $percentile(99),
                'failed' => $failed,
            ];

            $this->add($failed === 0 && $percentile(95) <= 1000 ? 'pass' : 'warn', 'Performance', "{$name}: no errors, 95 % under 1 s",
                $failed === 0 ? $percentile(95).' ms' : "{$failed} of {$requests} failed");
        }

        return $rows;
    }

    private function get(string $url): ?Response
    {
        return $this->send('get', $url, fn ($request) => $request);
    }

    /**
     * @param  callable(PendingRequest): PendingRequest  $prepare
     */
    private function send(string $method, string $url, callable $prepare, ?string $verb = null): ?Response
    {
        try {
            $request = $prepare(Http::withUserAgent(self::UA)->withoutRedirecting()->timeout(20)->connectTimeout(10));

            return $verb !== null ? $request->send($verb, $url) : $request->{$method}($url);
        } catch (Throwable) {
            return null;
        }
    }

    private function add(string $status, string $area, string $check, string $detail): void
    {
        $this->rows[] = ['status' => $status, 'area' => $area, 'check' => $check, 'detail' => $detail];
    }
}
