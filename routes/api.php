<?php

use Illuminate\Support\Facades\Route;

/*
| Partner API — served on api.paygate.local, stateless, JSON only.
| Endpoints are added from Phase 4 onward (docs: Document/Architecture.md §10).
*/

Route::prefix('v1')->name('v1.')->group(function () {
    Route::get('ping', fn () => response()->json([
        'status' => 'ok',
        'time' => now()->toIso8601String(),
    ]))->name('ping');
});
