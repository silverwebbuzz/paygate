<?php

namespace App\Console\Commands;

use App\Domain\SecurityReport\ConfigChecks;
use Illuminate\Console\Command;

/**
 * Checks a server's configuration against the production rules in
 * Document/Deployment.md and Document/Security-Testing.md (ConfigChecks).
 * Run it after every deploy and before a security test; it reads config
 * only and changes nothing. Exit code 1 when a FAIL is found.
 */
class SecurityCheckCommand extends Command
{
    protected $signature = 'paygate:security-check';

    protected $description = 'Check this server\'s configuration for unsafe security settings (read-only)';

    public function handle(): int
    {
        $rows = ConfigChecks::run();
        $failed = count(array_filter($rows, fn (array $row) => $row['status'] === 'fail'));

        $this->table(['Result', 'Check', 'How to fix'], array_map(fn (array $row) => [
            match ($row['status']) {
                'pass' => '<info>PASS</info>',
                'fail' => '<error>FAIL</error>',
                default => '<comment>WARN</comment>',
            },
            $row['check'],
            $row['status'] === 'pass' ? '' : $row['detail'],
        ], $rows));

        if ($failed > 0) {
            $this->error("{$failed} check(s) failed. Fix them before go-live or a security test.");

            return self::FAILURE;
        }

        $this->info('All required checks passed.');

        return self::SUCCESS;
    }
}
