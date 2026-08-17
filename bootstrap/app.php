<?php

use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\HandleTheme;
use App\Http\Middleware\PreventSearchIndexing;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'admin' => EnsureAdmin::class,
        ]);

        $middleware->encryptCookies(except: ['theme', 'sidebar_state']);

        $middleware->web(append: [
            PreventSearchIndexing::class,
            HandleTheme::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(function (Response $response): Response {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');

            return $response;
        });
    })->create();
