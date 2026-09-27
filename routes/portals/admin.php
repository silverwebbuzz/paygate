<?php

use Illuminate\Support\Facades\Route;

/*
| Admin portal — /admin/*, only for admin users (see routes/web.php).
*/

Route::inertia('/', 'admin/dashboard')->name('dashboard');
