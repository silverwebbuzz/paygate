<?php

use App\Http\Api\Middleware\AuthenticatePartner;
use App\Http\Api\Middleware\LogApiRequest;
use App\Http\Api\V1\PayinController;
use Illuminate\Support\Facades\Route;

/*
| Partner API — served on api.paygate.local, stateless, JSON only.
| Documentation for partners: the partner portal › API documentation
| (resources/js/pages/partner/api-docs.tsx); design: Architecture.md §10.
*/

Route::prefix('v1')->name('v1.')->middleware(LogApiRequest::class)->group(function () {
    Route::get('ping', fn () => response()->json([
        'status' => 'ok',
        'time' => now()->toIso8601String(),
    ]))->name('ping');

    Route::middleware(AuthenticatePartner::class)->group(function () {
        Route::post('payins', [PayinController::class, 'store'])->name('payins.store');
        Route::post('payins/status', [PayinController::class, 'status'])->name('payins.status');
        Route::get('payins', [PayinController::class, 'show'])->name('payins.lookup');
        Route::get('payins/{reference}', [PayinController::class, 'show'])->name('payins.show');
        Route::post('payins/{reference}/cancel', [PayinController::class, 'cancel'])->name('payins.cancel');
    });
});
