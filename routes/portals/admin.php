<?php

use App\Http\Admin\Accounts\AccountController;
use App\Http\Admin\Accounts\AccountLogController;
use App\Http\Admin\Branches\BranchController;
use App\Http\Admin\Commissions\CommissionController;
use App\Http\Admin\Mappings\MappingController;
use App\Http\Admin\Partners\PartnerController;
use App\Http\Admin\Partners\PartnerKeyController;
use App\Http\Admin\Platform\IpManagementController;
use App\Http\Admin\Platform\PageController;
use App\Http\Admin\Qa\QaChecklistController;
use App\Http\Admin\Qa\SectionRolloutController;
use App\Http\Admin\Reversals\ReversalController;
use App\Http\Admin\Roles\RoleController;
use App\Http\Admin\Settings\GlobalSettingsController;
use App\Http\Admin\Settlements\AdjustmentController;
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
| Admin portal — /admin/*, only for admin users (see routes/web.php).
*/

Route::get('/', [DashboardController::class, 'show'])->name('dashboard');

// Reports: preview and CSV / Excel exports prepared in the background.
Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
Route::post('reports/export', [ReportController::class, 'export'])->middleware('throttle:20,1')->name('reports.export');

// Transactions (all pay-ins) and the Manual Deposit queue of every branch.
Route::get('transactions', [TransactionController::class, 'index'])->name('transactions.index');
Route::get('transactions/payouts', [TransactionController::class, 'index'])->defaults('direction', 'payout')->name('transactions.payouts');
Route::post('webhooks/{event}/resend', [TransactionController::class, 'resendWebhook'])->name('webhooks.resend');
Route::controller(DepositQueueController::class)->prefix('deposits')->name('deposits.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('{transaction}/approve', 'approve')->name('approve');
    Route::post('{transaction}/hold', 'hold')->name('hold');
    Route::post('{transaction}/decline', 'decline')->name('decline');
});

// Manual Payout: withdrawals waiting to be paid (pay with UTR / fail, reassign).
Route::controller(PayoutQueueController::class)->prefix('payouts')->name('payouts.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('{transaction}/start', 'start')->name('start');
    Route::post('{transaction}/complete', 'complete')->name('complete');
    Route::post('{transaction}/fail', 'fail')->name('fail');
    Route::post('{transaction}/reassign', 'reassign')->name('reassign');
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
    Route::post('{case}/approve-late', 'approveLate')->name('approve-late');
});
Route::get('utr-reconciliation', [ReconciliationController::class, 'index'])->name('reconciliation.index');

// Settlement: per-party settlements (calculate on demand, record payments),
// adjustments with maker–checker, commissions, and the cut-off setting.
Route::controller(SettlementController::class)->prefix('settlements')->name('settlements.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('calculate', 'calculate')->name('calculate');
    Route::post('{settlement}/payments', 'pay')->name('pay');
});
Route::controller(AdjustmentController::class)->prefix('settlements/adjustments')->name('adjustments.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/', 'store')->name('store');
    Route::post('{adjustment}/approve', 'approve')->name('approve');
    Route::post('{adjustment}/reject', 'reject')->name('reject');
});
Route::get('commissions', [CommissionController::class, 'index'])->name('commissions.index');

// Refunds (pay-in refunds, returned payouts) and chargebacks.
Route::get('refunds', [ReversalController::class, 'index'])->defaults('group', 'refunds')->name('refunds.index');
Route::get('chargebacks', [ReversalController::class, 'index'])->defaults('group', 'chargebacks')->name('chargebacks.index');
Route::post('reversals', [ReversalController::class, 'store'])->name('reversals.store');

// Platform administration: Global Settings, content pages, IP overview, audit logs.
Route::controller(GlobalSettingsController::class)->prefix('settings')->name('settings.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::put('settlement', 'updateSettlement')->name('settlement');
    Route::put('alerts', 'updateAlerts')->name('alerts');
    Route::put('checkout', 'updateCheckout')->name('checkout');
    Route::post('reasons', 'saveReason')->name('reasons.store');
    Route::put('reasons/{reason}', 'saveReason')->name('reasons.update');
});
Route::controller(PageController::class)->prefix('settings/pages')->name('pages.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/', 'store')->name('store');
    Route::put('{page}', 'update')->name('update');
});
Route::get('ip-management', [IpManagementController::class, 'index'])->name('ip-management.index');
Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');

// Partners (wizard, detail drawer, status, API keys).
Route::controller(PartnerController::class)->prefix('partners')->name('partners.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('create', 'create')->name('create');
    Route::post('/', 'store')->name('store');
    Route::get('{partner}/edit', 'edit')->name('edit');
    Route::put('{partner}', 'update')->name('update');
    Route::put('{partner}/status', 'status')->name('status');
});
Route::post('partners/{partner}/api-keys', [PartnerKeyController::class, 'store'])->middleware('throttle:10,1')->name('partners.api-keys.store');
Route::delete('partners/{partner}/api-keys/{key}', [PartnerKeyController::class, 'destroy'])->middleware('throttle:10,1')->name('partners.api-keys.destroy');

// Branches (form, drawer, status, deposit allowance top-ups).
Route::controller(BranchController::class)->prefix('branches')->name('branches.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('create', 'create')->name('create');
    Route::post('/', 'store')->name('store');
    Route::get('{branch}/edit', 'edit')->name('edit');
    Route::put('{branch}', 'update')->name('update');
    Route::put('{branch}/status', 'status')->name('status');
    Route::post('{branch}/topups', 'topup')->name('topups.store');
});

// Partner ↔ branch mapping.
Route::controller(MappingController::class)->prefix('mappings')->name('mappings.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/', 'store')->name('store');
    Route::put('{mapping}', 'update')->name('update');
});

// Bank & UPI accounts of all branches (verification).
Route::controller(AccountLogController::class)->prefix('accounts/logs')->name('accounts.logs.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('export', 'export')->name('export');
});

Route::controller(AccountController::class)->prefix('accounts')->name('accounts.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/', 'store')->name('store');
    Route::put('{account}', 'update')->name('update');
    Route::post('{account}/approve', 'approve')->name('approve');
    Route::post('{account}/reject', 'reject')->name('reject');
    Route::put('{account}/status', 'status')->name('status');
});

// Roles & permissions.
Route::controller(RoleController::class)->prefix('roles')->name('roles.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/', 'store')->name('store');
    Route::put('{role}', 'update')->name('update');
    Route::delete('{role}', 'destroy')->name('destroy');
});

// Users (shared controller; UserPolicy limits partner/branch owners to their own organisation).
// Users of one partner / branch, managed from that partner's or branch's own page.
Route::get('partners/{partner}/users', [UserController::class, 'partnerIndex'])->name('partners.users.index');
Route::post('partners/{partner}/users', [UserController::class, 'partnerStore'])->name('partners.users.store');
Route::get('branches/{branch}/users', [UserController::class, 'branchIndex'])->name('branches.users.index');
Route::post('branches/{branch}/users', [UserController::class, 'branchStore'])->name('branches.users.store');

Route::controller(UserController::class)->prefix('users')->name('users.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/', 'store')->name('store');
    Route::put('{user}', 'update')->name('update');
    Route::put('{user}/status', 'status')->name('status');
    Route::put('{user}/password', 'setPassword')->name('password');
    Route::delete('{user}/two-factor', 'resetTwoFactor')->name('two-factor');
});

// QA Checklist: features, how to test them, results and test re-runs
// (local and staging only; the controller answers 404 elsewhere).
Route::controller(QaChecklistController::class)->prefix('qa-checklist')->name('qa-checklist.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('run', 'run')->middleware('throttle:30,1')->name('run');
    Route::post('sample', 'sample')->middleware('throttle:30,1')->name('sample');
    Route::put('{key}', 'mark')->name('mark');
});

// Section rollout (super admins only): which sections other users see,
// opened one by one while the client tests.
Route::get('section-rollout', [SectionRolloutController::class, 'index'])->name('section-rollout.index');
Route::put('section-rollout', [SectionRolloutController::class, 'update'])->name('section-rollout.update');

// Design-system reference page with sample data (super admins only).
Route::inertia('ui-kit', 'admin/ui-kit')->middleware('can:super-admin')->name('ui-kit');
