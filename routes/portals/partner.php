<?php

use App\Http\Partner\Developers\DeveloperController;
use App\Http\Partner\Profile\BusinessProfileController;
use App\Http\Shared\Users\UserController;
use Illuminate\Support\Facades\Route;

/*
| Partner portal — /partner/*, only for partner users (see routes/web.php).
*/

Route::inertia('/', 'partner/dashboard')->name('dashboard');

Route::get('profile', [BusinessProfileController::class, 'show'])->name('profile');

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
    Route::post('{user}/invitation', 'resendInvitation')->name('invitation');
    Route::delete('{user}/two-factor', 'resetTwoFactor')->name('two-factor');
});
