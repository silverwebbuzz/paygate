<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Reservations are read from the event order ("allocated" after the
        // last "allocation_released"?). Whole seconds tie when an account is
        // released and another allocated in the same request, so events are
        // stamped with the database clock to the microsecond.
        DB::statement('ALTER TABLE transaction_events ALTER COLUMN created_at TYPE timestamp(6) with time zone');
        DB::statement('ALTER TABLE transaction_events ALTER COLUMN created_at SET DEFAULT clock_timestamp()');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE transaction_events ALTER COLUMN created_at SET DEFAULT CURRENT_TIMESTAMP');
        DB::statement('ALTER TABLE transaction_events ALTER COLUMN created_at TYPE timestamp(0) with time zone');
    }
};
