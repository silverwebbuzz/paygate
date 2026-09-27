<?php

use Illuminate\Support\Facades\Route;

/*
| Public payer pages — served on pay.paygate.local.
| The /p/{token} payment page is added in Phase 5 (Document/Architecture.md §5.4).
*/

Route::get('/', fn () => abort(404))->name('home');
