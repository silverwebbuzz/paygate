<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Structural rules of the codebase (Implementation-Plan.md, "module structure").
 * They fail the build when code is put in the wrong place.
 */
class ArchitectureTest extends TestCase
{
    private const LEDGER_TABLES = ['ledger_accounts', 'ledger_journals', 'ledger_entries', 'ledger_balances'];

    public function test_money_is_booked_only_by_the_ledger_module()
    {
        foreach ($this->phpFiles('app') as $path => $code) {
            if (str_starts_with($path, 'app/Domain/Ledger/')) {
                continue;
            }

            foreach (self::LEDGER_TABLES as $table) {
                $this->assertStringNotContainsString("'{$table}'", $code, "{$path} touches {$table}; use the Ledger module instead.");
            }

            $this->assertDoesNotMatchRegularExpression('/use App\\\\Domain\\\\Ledger\\\\Models\\\\/', $code, "{$path} uses Ledger models directly; call a Ledger action/service instead.");
        }
    }

    public function test_domain_code_does_not_depend_on_the_http_layer()
    {
        foreach ($this->phpFiles('app/Domain') as $path => $code) {
            $this->assertDoesNotMatchRegularExpression('/use App\\\\Http\\\\/', $code, "{$path} depends on the HTTP layer.");
        }
    }

    public function test_http_layer_does_not_query_the_database_directly()
    {
        foreach ($this->phpFiles('app/Http') as $path => $code) {
            $this->assertDoesNotMatchRegularExpression('/\bDB::(table|insert|update|delete|statement|unprepared)\b/', $code, "{$path} queries the database directly; put the logic in a domain action.");
        }
    }

    public function test_every_model_lives_in_a_domain_module()
    {
        $this->assertDirectoryDoesNotExist($this->root().'/app/Models', 'Models belong in app/Domain/<Module>/Models.');
    }

    /**
     * @return iterable<string, string> relative path => source
     */
    private function phpFiles(string $directory): iterable
    {
        $root = $this->root();

        if (! is_dir("{$root}/{$directory}")) {
            return;
        }

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$directory}", RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() === 'php') {
                yield substr($file->getPathname(), strlen($root) + 1) => (string) file_get_contents($file->getPathname());
            }
        }
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
