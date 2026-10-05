<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 50)->nullable()->unique()->after('name');
        });

        $taken = [];

        DB::table('users')->select(['id', 'email'])->lazyById()->each(function (object $user) use (&$taken) {
            $base = preg_replace('/[^a-z0-9._-]/', '', strtolower(strstr((string) $user->email, '@', true) ?: (string) $user->email)) ?? '';
            $base = substr(str_pad($base, 3, '0'), 0, 45);
            $username = $base;

            for ($n = 2; isset($taken[$username]); $n++) {
                $username = $base.$n;
            }

            $taken[$username] = true;
            DB::table('users')->where('id', $user->id)->update(['username' => $username]);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });
    }
};
