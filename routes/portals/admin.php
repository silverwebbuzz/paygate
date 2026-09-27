<?php

use App\Http\Admin\Accounts\AccountController;
use App\Http\Admin\Branches\BranchController;
use App\Http\Admin\Mappings\MappingController;
use App\Http\Admin\Partners\PartnerController;
use App\Http\Admin\Partners\PartnerKeyController;
use App\Http\Admin\Roles\RoleController;
use App\Http\Shared\Users\UserController;
use Illuminate\Support\Facades\Route;

/*
| Admin portal — /admin/*, only for admin users (see routes/web.php).
*/

Route::inertia('/', 'admin/dashboard')->name('dashboard');

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
Route::controller(UserController::class)->prefix('users')->name('users.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/', 'store')->name('store');
    Route::put('{user}', 'update')->name('update');
    Route::put('{user}/status', 'status')->name('status');
    Route::post('{user}/invitation', 'resendInvitation')->name('invitation');
    Route::delete('{user}/two-factor', 'resetTwoFactor')->name('two-factor');
});

// Design-system reference page (local development only).
if (app()->isLocal()) {
    Route::inertia('ui-kit', 'admin/ui-kit')->name('ui-kit');
}
