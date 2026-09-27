<?php

use App\Domain\Core\Identity\Models\User;
use App\Http\Shared\Auth\InvitationController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
| Portals — served on paygate.local. One login for everyone; each user type
| then lands in its own portal (/admin, /partner, /branch).
*/

Route::redirect('/', '/dashboard')->name('home');

// Invitation links (emailed by App\Domain\Core\Identity\Actions\SendInvitation).
Route::middleware('guest')->group(function () {
    Route::get('invitation/{token}', [InvitationController::class, 'show'])->name('invitation.show');
    Route::post('invitation', [InvitationController::class, 'store'])->middleware('throttle:6,1')->name('invitation.store');
});

Route::middleware(['auth', 'verified'])->group(function () {
    // Sends each user to their own portal.
    Route::get('dashboard', function (Request $request) {
        /** @var User $user */
        $user = $request->user();

        return redirect()->route($user->type->homeRoute());
    })->name('dashboard');
});

Route::middleware(['auth', 'verified', 'user.type:admin', 'two-factor.required'])
    ->prefix('admin')
    ->name('admin.')
    ->group(base_path('routes/portals/admin.php'));

Route::middleware(['auth', 'verified', 'user.type:partner', 'two-factor.required'])
    ->prefix('partner')
    ->name('partner.')
    ->group(base_path('routes/portals/partner.php'));

Route::middleware(['auth', 'verified', 'user.type:branch', 'two-factor.required'])
    ->prefix('branch')
    ->name('branch.')
    ->group(base_path('routes/portals/branch.php'));

require __DIR__.'/settings.php';
