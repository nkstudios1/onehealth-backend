<?php

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Exceptions\ApiExceptionRenderer;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\SecureApiHeaders;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        // `api:` gives every route in routes/api.php the /api prefix and the "api" middleware group.
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Runs on EVERY request, first and last, so even a 404 or a crash carries a request id
        // and the security headers.
        $middleware->prepend(AssignRequestId::class);
        $middleware->append(SecureApiHeaders::class);

        // The API uses cookie sessions (like the web group), so the order below matters:
        // JSON first, then cookies, then the session, then the forgery check that needs the session.
        $middleware->api(prepend: [
            ForceJsonResponse::class,
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            PreventRequestForgery::class,
        ]);

        // Use on any route that needs a logged-in, still-active account: ->middleware(['auth:web', 'active'])
        $middleware->alias(['active' => EnsureAccountActive::class]);

        // Default is strict: only requests from the same origin, or carrying the XSRF header the
        // frontend sends, pass. Do NOT turn allowSameSite on without reading the guide (it trusts
        // every sibling subdomain).
        $middleware->preventRequestForgery();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => ApiExceptionRenderer::shouldRender($request));

        // One place turns every exception into the standard error response.
        $exceptions->render(function (Throwable $e, Request $request) {
            return ApiExceptionRenderer::shouldRender($request)
                ? ApiExceptionRenderer::render($e, $request)
                : null;
        });

        // Expected failures are not bugs: don't flood the error log with them.
        // (A 5xx ApiException is the exception: those are logged below.)
        $exceptions->dontReport(ApiException::class);
        $exceptions->report(function (ApiException $e) {
            if ($e->status() >= 500) {
                Log::error($e->getMessage(), ['code' => $e->errorCode->value, 'exception' => $e]);
            }
        })->stop();

        // Never copy these into the session "old input" after a failure.
        $exceptions->dontFlash(['password', 'password_confirmation', 'nin', 'code', 'resetToken', 'token']);
    })->create();
