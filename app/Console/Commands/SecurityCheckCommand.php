<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Checks a server's configuration against the production rules in
 * Document/Deployment.md and Document/Security-Testing.md. Run it after
 * every deploy and before a security test; it reads config only and
 * changes nothing. Exit code 1 when a FAIL is found.
 */
class SecurityCheckCommand extends Command
{
    protected $signature = 'paygate:security-check';

    protected $description = 'Check this server\'s configuration for unsafe security settings (read-only)';

    public function handle(): int
    {
        $production = ! app()->environment('local', 'testing');
        $appUrl = (string) config('app.url');

        $checks = [
            ['APP_DEBUG is off', ! config('app.debug'), 'Error pages would show code, queries and secrets. Set APP_DEBUG=false.'],
            ['APP_ENV is production or staging', $production, 'Set APP_ENV=production (or staging).'],
            ['APP_KEY is set', Str::length((string) config('app.key')) >= 32, 'Run php artisan key:generate once (never again on a live server).'],
            ['PAYGATE_HASH_KEY is set', Str::length((string) config('paygate.hash_key')) >= 32, 'Set a long random PAYGATE_HASH_KEY once; never change it afterwards.'],
            ['APP_URL uses https', str_starts_with($appUrl, 'https://'), "APP_URL is {$appUrl}."],
            ['Session cookie is https-only', (bool) config('session.secure'), 'Set SESSION_SECURE_COOKIE=true.'],
            ['Session cookie is HttpOnly', (bool) config('session.http_only'), 'Remove SESSION_HTTP_ONLY=false.'],
            ['Session data is encrypted', (bool) config('session.encrypt'), 'Set SESSION_ENCRYPT=true.'],
            ['2FA is enforced for Admin and Branch', (bool) config('paygate.auth.enforce_two_factor'), 'Set PAYGATE_ENFORCE_2FA=true.'],
            ['Partner API IP allowlist is enforced', (bool) config('paygate.api.enforce_ip_allowlist'), 'Set PAYGATE_API_ENFORCE_IP=true.'],
            ['Webhooks to private networks are refused', ! config('paygate.webhooks.allow_private_targets'), 'Set PAYGATE_WEBHOOKS_ALLOW_PRIVATE=false (SSRF).'],
            ['Mail is not the log driver', config('mail.default') !== 'log', 'Password resets and alerts would only go to the log file.'],
        ];

        $warnings = [
            ['QA Checklist is hidden on production', ! (app()->isProduction() && config('paygate.qa.enabled')), 'Its Test data button creates real pay-ins/payouts. Set PAYGATE_QA_CHECKLIST=false on production.'],
            ['Log level is not debug', config('logging.channels.'.config('logging.default').'.level', 'debug') !== 'debug' || ! app()->isProduction(), 'Use LOG_LEVEL=info or higher on production.'],
            ['PHP does not advertise its version', ! filter_var(ini_get('expose_php'), FILTER_VALIDATE_BOOLEAN), 'Set expose_php = Off in php.ini (the app also strips the header).'],
            ['.env is not world-readable', ! is_file(base_path('.env')) || (fileperms(base_path('.env')) & 0o004) === 0, 'Run chmod 640 .env (or 600).'],
        ];

        $rows = [];
        $failed = 0;

        foreach ($checks as [$name, $ok, $fix]) {
            $failed += $ok ? 0 : 1;
            $rows[] = [$ok ? '<info>PASS</info>' : '<error>FAIL</error>', $name, $ok ? '' : $fix];
        }

        foreach ($warnings as [$name, $ok, $fix]) {
            $rows[] = [$ok ? '<info>PASS</info>' : '<comment>WARN</comment>', $name, $ok ? '' : $fix];
        }

        $this->table(['Result', 'Check', 'How to fix'], $rows);

        if ($failed > 0) {
            $this->error("{$failed} check(s) failed. Fix them before go-live or a security test.");

            return self::FAILURE;
        }

        $this->info('All required checks passed.');

        return self::SUCCESS;
    }
}
