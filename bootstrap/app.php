<?php

use App\Domain\PartnerApi\Exceptions\ApiException;
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
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
    // Listeners live inside their domain module (app/Domain/<Module>/[<Area>/]Listeners).
    ->withEvents(discover: [
        __DIR__.'/../app/Domain/*/Listeners',
        __DIR__.'/../app/Domain/*/*/Listeners',
    ])
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

        // Partner API errors: { "error": { "code", "message", "request_id", ... } }
        // with stable codes partners can program against (Architecture §10).
        $exceptions->render(function (Throwable $exception, Request $request) {
            if ($request->getHost() !== config('app.domains.api')) {
                return null;
            }

            [$status, $code, $message, $details] = match (true) {
                $exception instanceof ApiException => [$exception->status, $exception->errorCode, $exception->getMessage(), $exception->details],
                $exception instanceof ValidationException => [422, 'validation_failed', 'Some fields are missing or invalid.', ['fields' => $exception->errors()]],
                $exception instanceof NotFoundHttpException => [404, 'not_found', 'Not found.', []],
                $exception instanceof MethodNotAllowedHttpException => [405, 'method_not_allowed', 'This method is not allowed here.', []],
                $exception instanceof HttpExceptionInterface => [$exception->getStatusCode(), 'http_error', $exception->getMessage() ?: 'Request failed.', []],
                default => [500, 'server_error', 'Something went wrong on our side. Retry later; if it persists, contact support with the request_id.', []],
            };

            if ($status >= 500) {
                report($exception);
            }

            return response()->json(['error' => [
                'code' => $code,
                'message' => $message,
                'request_id' => Context::get('request_id'),
                ...$details,
            ]], $status);
        });
    })->create();
