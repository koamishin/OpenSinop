<?php

declare(strict_types=1);

use App\Http\Middleware\EnforceHttps;
use App\Http\Middleware\EnforceSchoolRegistration;
use App\Http\Middleware\EnforceSecureRuntime;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleColorTheme;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // First: refuse to serve anything at all on a PHP runtime that is still
        // exposed to CVE-2026-109618 / CVE-2026-109619.
        $middleware->append(EnforceSecureRuntime::class);
        $middleware->append(EnforceHttps::class);
        $middleware->encryptCookies(except: ['appearance', 'color-theme', 'sidebar_state']);
        // One unauthenticated POST route only. A wildcard here would exempt every
        // future webhook from CSRF without anyone deciding that at review time.
        $middleware->validateCsrfTokens(except: ['api/webhooks/circle']);

        $middleware->web(append: [
            EnforceSchoolRegistration::class,
            HandleAppearance::class,
            HandleColorTheme::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['mfa_code', 'userToken', 'encryptionKey', 'deviceToken', 'deviceEncryptionKey']);
    })->create();
