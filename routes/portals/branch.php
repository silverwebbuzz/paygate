<?php

use App\Http\Branch\Accounts\AccountController;
use App\Http\Branch\Balance\BranchBalanceController;
use App\Http\Shared\Audit\AuditLogController;
use App\Http\Shared\Dashboard\DashboardController;
use App\Http\Shared\Reconciliation\CaseController;
use App\Http\Shared\Reconciliation\ReconciliationController;
use App\Http\Shared\Reconciliation\StatementController;
use App\Http\Shared\Reconciliation\StatementImportController;
use App\Http\Shared\Reports\ReportController;
use App\Http\Shared\Settlements\SettlementController;
use App\Http\Shared\Transactions\DepositQueueController;
use App\Http\Shared\Transactions\PayoutQueueController;
use App\Http\Shared\Transactions\TransactionController;
use App\Http\Shared\Users\UserController;
use Illuminate\Support\Facades\Route;

/*
| Branch portal — /branch/*, only for branch users (see routes/web.php).
*/

Route::get('/', [DashboardController::class, 'show'])->name('dashboard');

// Reports: preview and CSV / Excel exports prepared in the background.
Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
Route::post('reports/export', [ReportController::class, 'export'])->middleware('throttle:20,1')->name('reports.export');

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

// Reconciliation: bank statement lines (typed in / imported), their import
// history, the unsettled queue (cases) and the transaction-side view.
Route::controller(StatementController::class)->prefix('statements')->name('statements.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/', 'store')->name('store');
    Route::get('export', 'export')->name('export');
});
Route::controller(StatementImportController::class)->prefix('statements/imports')->name('statement-imports.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('preview', 'preview')->middleware('throttle:20,1')->name('preview');
    Route::post('/', 'store')->name('store');
    Route::delete('{token}', 'cancel')->name('cancel');
});
Route::controller(CaseController::class)->prefix('unsettled')->name('cases.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('{case}/link', 'link')->name('link');
    Route::post('{case}/close', 'close')->name('close');
});
Route::get('utr-reconciliation', [ReconciliationController::class, 'index'])->name('reconciliation.index');

// Settlements with the platform (read-only) and the current position.
Route::get('settlements', [SettlementController::class, 'index'])->name('settlements.index');
Route::get('balance', [BranchBalanceController::class, 'show'])->name('balance');

// What the branch's own users did (read-only).
Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');

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
    Route::put('{user}/password', 'setPassword')->name('password');
    Route::delete('{user}/two-factor', 'resetTwoFactor')->name('two-factor');
});
