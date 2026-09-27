<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\EnsureUserType;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireTwoFactor;
use App\Http\Middleware\RestrictToPortalHost;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Each host name only answers its own routes (see config/app.php "domains").
        then: function (): void {
            Route::middleware('web')
                ->domain(config('app.domains.app'))
                ->group(base_path('routes/web.php'));

            Route::middleware('api')
                ->domain(config('app.domains.api'))
                ->name('api.')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->domain(config('app.domains.pay'))
                ->name('pay.')
                ->group(base_path('routes/pay.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestId::class);

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            RestrictToPortalHost::class,
            EnsureUserIsActive::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'user.type' => EnsureUserType::class,
            'two-factor.required' => RequireTwoFactor::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->getHost() === config('app.domains.api') || $request->expectsJson(),
        );
    })->create();
