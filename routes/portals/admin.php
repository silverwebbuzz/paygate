<?php

use Illuminate\Support\Facades\Route;

/*
| Admin portal — /admin/*, only for admin users (see routes/web.php).
*/

Route::inertia('/', 'admin/dashboard')->name('dashboard');

// Design-system reference page (local development only).
if (app()->isLocal()) {
    Route::inertia('ui-kit', 'admin/ui-kit')->name('ui-kit');
}
