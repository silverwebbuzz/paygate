<?php

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
