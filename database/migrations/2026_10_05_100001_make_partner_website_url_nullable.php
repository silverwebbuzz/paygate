<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->string('website_url')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('partners')->whereNull('website_url')->update(['website_url' => '']);

        Schema::table('partners', function (Blueprint $table) {
            $table->string('website_url')->nullable(false)->change();
        });
    }
};
