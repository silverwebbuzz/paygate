<?php

use Illuminate\Support\Facades\Route;

/*
| Branch portal — /branch/*, only for branch users (see routes/web.php).
*/

Route::inertia('/', 'branch/dashboard')->name('dashboard');
