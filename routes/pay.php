<?php

use App\Http\Checkout\CheckoutController;
use Illuminate\Support\Facades\Route;

/*
| Public payer pages — served on pay.paygate.local. The token in the URL is
| the only key (PaymentSession); each action is throttled per IP and page
| (named limiters in AppServiceProvider).
*/

Route::get('/', fn () => abort(404))->name('home');

Route::controller(CheckoutController::class)->prefix('p/{token}')->name('checkout.')->group(function () {
    Route::get('/', 'show')->middleware('throttle:checkout')->name('show');
    Route::post('method', 'method')->middleware('throttle:checkout-method')->name('method');
    Route::post('proof', 'proof')->middleware('throttle:checkout-proof')->name('proof');
});
