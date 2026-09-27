<?php

use Illuminate\Support\Facades\Route;

/*
| Partner portal — /partner/*, only for partner users (see routes/web.php).
*/

Route::inertia('/', 'partner/dashboard')->name('dashboard');
