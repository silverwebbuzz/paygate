<?php

use App\Http\Partner\Balance\BalanceController;
use App\Http\Partner\Developers\ApiLogController;
use App\Http\Partner\Developers\DeveloperController;
use App\Http\Partner\Profile\BusinessProfileController;
use App\Http\Shared\Dashboard\DashboardController;
use App\Http\Shared\Reports\ReportController;
use App\Http\Shared\Settlements\SettlementController;
use App\Http\Shared\Transactions\TransactionController;
use App\Http\Shared\Users\UserController;
use Illuminate\Support\Facades\Route;

/*
| Partner portal — /partner/*, only for partner users (see routes/web.php).
*/

Route::get('/', [DashboardController::class, 'show'])->name('dashboard');

// Reports: preview and CSV / Excel exports prepared in the background.
Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
Route::post('reports/export', [ReportController::class, 'export'])->middleware('throttle:20,1')->name('reports.export');

Route::get('profile', [BusinessProfileController::class, 'show'])->name('profile');

// The partner's pay-ins (status, timeline, webhooks + resend).
Route::get('payins', [TransactionController::class, 'index'])->name('payins.index');
Route::get('payouts', [TransactionController::class, 'index'])->defaults('direction', 'payout')->name('payouts.index');
Route::get('balance', [BalanceController::class, 'show'])->name('balance');
Route::get('settlements', [SettlementController::class, 'index'])->name('settlements.index');
Route::post('webhooks/{event}/resend', [TransactionController::class, 'resendWebhook'])->name('webhooks.resend');

// API documentation and the partner's own API call log.
Route::get('api-docs', [ApiLogController::class, 'docs'])->name('api-docs');
Route::get('api-logs', [ApiLogController::class, 'index'])->name('api-logs');

// API & Webhooks: credentials, endpoints, allowed IPs.
Route::controller(DeveloperController::class)->prefix('developers')->name('developers.')->group(function () {
    Route::get('/', 'show')->name('show');
    Route::post('api-keys', 'issueKey')->middleware('throttle:10,1')->name('api-keys.store');
    Route::delete('api-keys/{key}', 'revokeKey')->middleware('throttle:10,1')->name('api-keys.destroy');
    Route::put('endpoints', 'updateEndpoints')->name('endpoints');
    Route::put('ip-rules', 'updateIps')->name('ip-rules');
});

// Users (shared controller; UserPolicy limits partner/branch owners to their own organisation).
Route::controller(UserController::class)->prefix('users')->name('users.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/', 'store')->name('store');
    Route::put('{user}', 'update')->name('update');
    Route::put('{user}/status', 'status')->name('status');
    Route::put('{user}/password', 'setPassword')->name('password');
    Route::delete('{user}/two-factor', 'resetTwoFactor')->name('two-factor');
});
