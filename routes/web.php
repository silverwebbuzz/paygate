<?php

use App\Domain\Core\Identity\Models\User;
use App\Http\Shared\Files\FileController;
use App\Http\Shared\Legal\LegalPageController;
use App\Http\Shared\Notifications\NotificationController;
use App\Http\Shared\Notifications\RequestPulseController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
| Portals — served on paygate.local. One login for everyone; each user type
| then lands in its own portal (/admin, /partner, /branch).
*/

Route::redirect('/', '/dashboard')->name('home');

// Published content pages (terms, privacy, help): public.
Route::get('legal/{slug}', [LegalPageController::class, 'show'])->name('legal.show');

Route::middleware(['auth'])->group(function () {
    // Sends each user to their own portal.
    Route::get('dashboard', function (Request $request) {
        /** @var User $user */
        $user = $request->user();

        return redirect()->route($user->type->homeRoute());
    })->name('dashboard');

    // Private files (payment proofs), after an access check. Like the portals,
    // only once 2FA is set up where it is required.
    Route::get('files/{file}', [FileController::class, 'show'])->middleware('two-factor.required')->name('files.show');

    // The bell: mark alerts read.
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])->name('notifications.read');

    // The deposit / withdrawal request sound: polled by every portal page.
    Route::get('notifications/pulse', RequestPulseController::class)->name('notifications.pulse');
});

Route::middleware(['auth', 'user.type:admin', 'two-factor.required'])
    ->prefix('admin')
    ->name('admin.')
    ->group(base_path('routes/portals/admin.php'));

Route::middleware(['auth', 'user.type:partner', 'two-factor.required'])
    ->prefix('partner')
    ->name('partner.')
    ->group(base_path('routes/portals/partner.php'));

Route::middleware(['auth', 'user.type:branch', 'two-factor.required'])
    ->prefix('branch')
    ->name('branch.')
    ->group(base_path('routes/portals/branch.php'));

require __DIR__.'/settings.php';
