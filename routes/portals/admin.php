<?php

use App\Http\Admin\Roles\RoleController;
use App\Http\Shared\Users\UserController;
use Illuminate\Support\Facades\Route;

/*
| Admin portal — /admin/*, only for admin users (see routes/web.php).
*/

Route::inertia('/', 'admin/dashboard')->name('dashboard');

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
