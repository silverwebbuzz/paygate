<?php

namespace App\Domain\SecurityReport;

use App\Support\ApiEnvironment;
use App\Support\TwoFactor;
use Illuminate\Support\Str;

/**
 * This server's own configuration against the production rules
 * (Document/Deployment.md, Document/Security-Testing.md). Used by
 * `php artisan paygate:security-check` and the in-app scan. Read-only.
 */
final class ConfigChecks
{
    /**
     * @return list<array{status: string, area: string, check: string, detail: string}> status: pass / fail / warn
     */
    public static function run(): array
    {
        $production = ! app()->environment('local', 'testing');
        $appUrl = (string) config('app.url');
        $env = base_path('.env');

        $required = [
            ['APP_DEBUG is off', ! config('app.debug'), 'Error pages would show code, queries and secrets. Set APP_DEBUG=false.'],
            ['APP_ENV is production or staging', $production, 'Set APP_ENV=production (or staging).'],
            ['APP_KEY is set', Str::length((string) config('app.key')) >= 32, 'Run php artisan key:generate once (never again on a live server).'],
            ['PAYGATE_HASH_KEY is set', Str::length((string) config('paygate.hash_key')) >= 32, 'Set a long random PAYGATE_HASH_KEY once; never change it afterwards.'],
            ['APP_URL uses https', str_starts_with($appUrl, 'https://'), "APP_URL is {$appUrl}."],
            ['Session cookie is https-only', (bool) config('session.secure'), 'Set SESSION_SECURE_COOKIE=true.'],
            ['Session cookie is HttpOnly', (bool) config('session.http_only'), 'Remove SESSION_HTTP_ONLY=false.'],
            ['Session data is encrypted', (bool) config('session.encrypt'), 'Set SESSION_ENCRYPT=true.'],
            ['2FA is enforced for Admin and Branch', ! ApiEnvironment::isLive() || (TwoFactor::on() && config('paygate.auth.enforce_two_factor')), 'On live set PAYGATE_ENFORCE_2FA=true and remove PAYGATE_TWO_FACTOR=false.'],
            ['Partner API IP allowlist is enforced', (bool) config('paygate.api.enforce_ip_allowlist'), 'Set PAYGATE_API_ENFORCE_IP=true.'],
            ['Webhooks to private networks are refused', ! config('paygate.webhooks.allow_private_targets'), 'Set PAYGATE_WEBHOOKS_ALLOW_PRIVATE=false (SSRF).'],
        ];

        $advised = [
            ['QA Checklist is hidden on production', ! (app()->isProduction() && config('paygate.qa.enabled')), 'Its Test data button creates real pay-ins/payouts. Set PAYGATE_QA_CHECKLIST=false on production.'],
            ['Log level is not debug', ! app()->isProduction() || config('logging.channels.'.config('logging.default').'.level', 'debug') !== 'debug', 'Use LOG_LEVEL=info or higher on production.'],
            ['PHP does not advertise its version', ! filter_var(ini_get('expose_php'), FILTER_VALIDATE_BOOLEAN), 'Set expose_php = Off in php.ini (the app also strips the header).'],
            ['.env is not world-readable', ! is_file($env) || (fileperms($env) & 0o004) === 0, 'Run chmod 640 .env (or 600).'],
        ];

        $rows = [];

        foreach ($required as [$check, $ok, $fix]) {
            $rows[] = ['status' => $ok ? 'pass' : 'fail', 'area' => 'Configuration', 'check' => $check, 'detail' => $ok ? 'ok' : $fix];
        }

        foreach ($advised as [$check, $ok, $fix]) {
            $rows[] = ['status' => $ok ? 'pass' : 'warn', 'area' => 'Configuration', 'check' => $check, 'detail' => $ok ? 'ok' : $fix];
        }

        return $rows;
    }
}
