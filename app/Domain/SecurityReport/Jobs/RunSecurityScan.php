<?php

namespace App\Domain\SecurityReport\Jobs;

use App\Domain\SecurityReport\Models\SecurityScan;
use App\Domain\SecurityReport\SecurityScanner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs one security scan (SecurityScanner) in the background and stores the
 * results. Queue `reports` on redis-long, which runs in every environment.
 */
class RunSecurityScan implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public string $scanId)
    {
        $this->onConnection(config('queue.default') === 'sync' ? 'sync' : 'redis-long');
        $this->onQueue('reports');
    }

    public function handle(SecurityScanner $scanner): void
    {
        $scan = SecurityScan::query()->find($this->scanId);

        if ($scan === null) {
            return;
        }

        $scan->forceFill(['status' => 'running'])->save();

        try {
            ['results' => $results, 'performance' => $performance] = $scanner->run();
        } catch (Throwable $exception) {
            report($exception);
            $scan->forceFill(['status' => 'error', 'error' => Str::limit($exception->getMessage(), 2000), 'finished_at' => now()])->save();

            return;
        }

        $count = fn (string $status) => count(array_filter($results, fn (array $row) => $row['status'] === $status));

        $scan->forceFill([
            'status' => 'done',
            'results' => $results,
            'performance' => $performance,
            'passed' => $count('pass'),
            'failed' => $count('fail'),
            'warnings' => $count('warn'),
            'finished_at' => now(),
        ])->save();
    }
}
