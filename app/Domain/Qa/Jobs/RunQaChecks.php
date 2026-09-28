<?php

namespace App\Domain\Qa\Jobs;

use App\Domain\Qa\Checklist;
use App\Domain\Qa\Models\QaResult;
use App\Domain\Qa\TestRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;
use Throwable;

/**
 * Re-runs the automated tests of some checklist items (queue `qa`, one at a
 * time: they share the testing database) and stores a result per item.
 * "Run all" runs the whole suite once and spreads the results.
 */
class RunQaChecks implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    /**
     * @param  list<string>  $keys
     */
    public function __construct(public array $keys, public bool $wholeSuite = false)
    {
        // The `qa` supervisor (config/horizon.php, local and staging only)
        // runs one job at a time on redis-long.
        $this->onConnection(config('queue.default') === 'sync' ? 'sync' : 'redis-long');
        $this->onQueue('qa');
    }

    public function handle(TestRunner $runner): void
    {
        $items = array_intersect_key(Checklist::items(), array_flip($this->keys));
        QaResult::query()->whereIn('check_key', array_keys($items))->update(['auto_status' => 'running', 'updated_at' => now()]);

        try {
            $patterns = array_values(array_unique(array_merge(...array_values(array_map(fn ($item) => $item['tests'], $items)))));
            $run = $runner->run($this->wholeSuite ? null : $patterns);
        } catch (Throwable $exception) {
            QaResult::query()->whereIn('check_key', array_keys($items))->update([
                'auto_status' => 'error',
                'auto_summary' => Str::limit($exception->getMessage(), 190),
                'auto_tests' => null,
                'auto_output' => Str::limit($exception->getMessage(), 6000),
                'auto_finished_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        foreach ($items as $key => $item) {
            $tests = [];

            foreach ($run['tests'] as $id => $result) {
                if (TestRunner::covers($item['tests'], $id)) {
                    $tests[] = ['test' => $id, ...$result];
                }
            }

            $failed = count(array_filter($tests, fn ($test) => $test['status'] === 'failed'));

            [$status, $summary] = match (true) {
                $tests === [] => ['missing', 'No automated test found: check the test names in app/Domain/Qa/Checklist.php.'],
                $failed > 0 => ['failed', "{$failed} of ".count($tests).' failed'],
                default => ['passed', count($tests).' passed'],
            };

            QaResult::query()->whereKey($key)->update([
                'auto_status' => $status,
                'auto_summary' => $summary,
                'auto_tests' => json_encode($tests),
                'auto_output' => $failed > 0 || $tests === [] ? $run['output'] : null,
                'auto_finished_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
