<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Needed for the "no overlapping rate periods" exclusion constraint on commission_rates.
        // btree_gist is a trusted extension: the database owner can create it (no superuser needed).
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
    }

    public function down(): void
    {
        DB::statement('DROP EXTENSION IF EXISTS btree_gist');
    }
};
