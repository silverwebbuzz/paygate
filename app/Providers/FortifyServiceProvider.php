<?php

namespace App\Providers;

use App\Domain\Core\Audit\Enums\SecurityEvent;
use App\Domain\Core\Audit\Models\SecurityLog;
use App\Domain\Core\Identity\Models\User;
use App\Http\Responses\PortalLoginResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(LoginResponse::class, PortalLoginResponse::class);
        $this->app->singleton(TwoFactorLoginResponse::class, PortalLoginResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::confirmPasswordsUsing(function (User $user, ?string $password): bool {
            return is_string($password) && Hash::check($password, $user->password);
        });

        // Same as Fortify's default credential check, plus: suspended users cannot log in.
        Fortify::authenticateUsing(function (Request $request): ?User {
            $login = $request->string(Fortify::username())->trim()->lower()->value();
            $user = User::where('username', $login)->first();

            if (! $user || ! Hash::check($request->string('password'), $user->password)) {
                return null;
            }

            if (! $user->isActive()) {
                SecurityLog::record(SecurityEvent::LoginBlockedSuspended, $user);

                throw ValidationException::withMessages([
                    Fortify::username() => __('Your account is inactive. Contact an administrator.'),
                ]);
            }

            return $user;
        });
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        // No email in the system (client request 2026-11-25): no reset-link or
        // email-verification screens. A forgotten password is set again by an
        // administrator from the Users screen.
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/login', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/two-factor-challenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/confirm-password'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('passkeys', function (Request $request) {
            return Limit::perMinute(10)->by(
                ($request->input('credential.id') ?: $request->session()->getId()).'|'.$request->ip(),
            );
        });
    }
}
