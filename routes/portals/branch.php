<?php

use App\Http\Shared\Users\UserController;
use Illuminate\Support\Facades\Route;

/*
| Branch portal — /branch/*, only for branch users (see routes/web.php).
*/

Route::inertia('/', 'branch/dashboard')->name('dashboard');

// Users (shared controller; UserPolicy limits partner/branch owners to their own organisation).
Route::controller(UserController::class)->prefix('users')->name('users.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/', 'store')->name('store');
    Route::put('{user}', 'update')->name('update');
    Route::put('{user}/status', 'status')->name('status');
    Route::post('{user}/invitation', 'resendInvitation')->name('invitation');
    Route::delete('{user}/two-factor', 'resetTwoFactor')->name('two-factor');
});
