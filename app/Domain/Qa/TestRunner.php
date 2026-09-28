<?php

namespace App\Domain\Qa;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Runs PHPUnit for the QA checklist and returns one result per test.
 *
 * The run happens in a clean environment (`env -i`), exactly like
 * `make test`: phpunit.xml then points Laravel at the separate testing
 * database. Without the clean environment the worker's own variables
 * (DB_DATABASE of the app) would leak into the tests, which wipe their
 * database. As a second guard, nothing runs if phpunit.xml's database is the
 * app's own.
 */
class TestRunner
{
    /**
     * @param  list<string>|null  $patterns  "ClassTest" or "ClassTest::test_name"; null = the whole suite
     * @return array{tests: array<string, array{status: string, message: string|null}>, output: string}
     */
    public function run(?array $patterns): array
    {
        $this->guard();

        $junit = storage_path('app/private/qa/junit-'.Str::uuid()->toString().'.xml');
        @mkdir(dirname($junit), 0775, true);

        $command = [
            'env', '-i',
            'PATH='.(getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'),
            'HOME='.sys_get_temp_dir(),
            PHP_BINARY, 'vendor/bin/phpunit', '--colors=never', '--log-junit', $junit,
        ];

        if ($patterns !== null) {
            $command[] = '--filter';
            $command[] = implode('|', $patterns);
        }

        try {
            $result = Process::path(base_path())->timeout(900)->run($command);
            $output = trim($result->output()."\n".$result->errorOutput());

            if (! is_file($junit)) {
                throw new RuntimeException('PHPUnit did not finish: '.Str::limit($output, 3000));
            }

            return ['tests' => $this->parse((string) file_get_contents($junit)), 'output' => Str::limit($output, 6000)];
        } finally {
            @unlink($junit);
        }
    }

    /**
     * Test ids as the checklist writes them: "ClassTest::test_name" (with
     * " with data set …" for data-provider cases).
     *
     * @return array<string, array{status: string, message: string|null}>
     */
    public function parse(string $xml): array
    {
        $document = simplexml_load_string($xml);

        if ($document === false) {
            throw new RuntimeException('The test report could not be read.');
        }

        $tests = [];

        foreach ($document->xpath('//testcase') ?: [] as $case) {
            $id = class_basename((string) $case['class']).'::'.$case['name'];
            $problem = $case->failure[0] ?? $case->error[0] ?? null;

            $tests[$id] = match (true) {
                $problem !== null => ['status' => 'failed', 'message' => Str::limit(trim((string) $problem), 1500)],
                isset($case->skipped) => ['status' => 'skipped', 'message' => null],
                default => ['status' => 'passed', 'message' => null],
            };
        }

        return $tests;
    }

    /**
     * Whether a test id belongs to one of the item's patterns.
     *
     * @param  list<string>  $patterns
     */
    public static function covers(array $patterns, string $id): bool
    {
        foreach ($patterns as $pattern) {
            if (str_starts_with($id, str_contains($pattern, '::') ? $pattern : $pattern.'::')) {
                return true;
            }
        }

        return false;
    }

    public function available(): ?string
    {
        if (app()->isProduction()) {
            return 'Tests never run on production.';
        }

        if (! is_file(base_path('vendor/bin/phpunit'))) {
            return 'PHPUnit is not installed on this server (composer install without --no-dev).';
        }

        return null;
    }

    private function guard(): void
    {
        if (($problem = $this->available()) !== null) {
            throw new RuntimeException($problem);
        }

        preg_match('/name="DB_DATABASE"\s+value="([^"]+)"/', (string) @file_get_contents(base_path('phpunit.xml')), $match);
        $testing = $match[1] ?? null;

        if ($testing === null || $testing === config('database.connections.'.config('database.default').'.database')) {
            throw new RuntimeException('phpunit.xml must use its own database (DB_DATABASE), never the application’s.');
        }
    }
}
