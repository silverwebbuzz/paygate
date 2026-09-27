<?php

use App\Http\Branch\Accounts\AccountController;
use App\Http\Shared\Transactions\DepositQueueController;
use App\Http\Shared\Transactions\PayoutQueueController;
use App\Http\Shared\Transactions\TransactionController;
use App\Http\Shared\Users\UserController;
use Illuminate\Support\Facades\Route;

/*
| Branch portal — /branch/*, only for branch users (see routes/web.php).
*/

Route::inertia('/', 'branch/dashboard')->name('dashboard');

// Manual Deposit: approve / hold / decline pay-ins paid into this branch.
Route::controller(DepositQueueController::class)->prefix('deposits')->name('deposits.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('{transaction}/approve', 'approve')->name('approve');
    Route::post('{transaction}/hold', 'hold')->name('hold');
    Route::post('{transaction}/decline', 'decline')->name('decline');
});
Route::get('payins', [TransactionController::class, 'index'])->name('payins.index');
Route::get('payout-history', [TransactionController::class, 'index'])->defaults('direction', 'payout')->name('payouts.history');

// Manual Payout: withdrawals waiting to be paid (pay with UTR / fail).
Route::controller(PayoutQueueController::class)->prefix('payouts')->name('payouts.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('{transaction}/start', 'start')->name('start');
    Route::post('{transaction}/complete', 'complete')->name('complete');
    Route::post('{transaction}/fail', 'fail')->name('fail');
});

// The branch's own bank & UPI accounts.
Route::controller(AccountController::class)->prefix('accounts')->name('accounts.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/', 'store')->name('store');
    Route::put('{account}', 'update')->name('update');
    Route::put('{account}/status', 'status')->name('status');
});

// Users (shared controller; UserPolicy limits partner/branch owners to their own organisation).
Route::controller(UserController::class)->prefix('users')->name('users.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/', 'store')->name('store');
    Route::put('{user}', 'update')->name('update');
    Route::put('{user}/status', 'status')->name('status');
    Route::post('{user}/invitation', 'resendInvitation')->name('invitation');
    Route::delete('{user}/two-factor', 'resetTwoFactor')->name('two-factor');
});
