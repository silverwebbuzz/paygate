<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL features the Laravel schema builder doesn't cover
 * (CHECK constraints, append-only triggers). Used by migrations.
 */
final class Pg
{
    public static function check(string $table, string $name, string $expression): void
    {
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
    }

    /**
     * `column IN ('a', 'b')` for a CHECK constraint.
     *
     * @param  list<string>  $values
     */
    public static function in(string $column, array $values): string
    {
        $quoted = array_map(fn (string $value) => "'".str_replace("'", "''", $value)."'", $values);

        return $column.' IN ('.implode(', ', $quoted).')';
    }

    /**
     * Reject UPDATE, DELETE and TRUNCATE on a history table. Uses the
     * reject_log_modification() function created with the audit logs.
     */
    public static function appendOnly(string $table): void
    {
        DB::statement("CREATE TRIGGER {$table}_no_update_delete BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION reject_log_modification()");
        DB::statement("CREATE TRIGGER {$table}_no_truncate BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION reject_log_modification()");
    }
}
