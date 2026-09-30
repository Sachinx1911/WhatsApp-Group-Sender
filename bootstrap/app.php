<?php

use App\Http\Middleware\VerifyWorkerToken;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Internal routes for the local WhatsApp Web worker: no session, cookies or CSRF.
        then: fn () => Route::middleware([VerifyWorkerToken::class, 'throttle:120,1'])
            ->prefix('internal')
            ->group(base_path('routes/internal.php')),
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
