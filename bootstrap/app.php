<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Broadcast;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(__DIR__ . '/../routes/channels.php')
    ->withMiddleware(function (Middleware $middleware): void {
        // Enable CORS globally (including preflight OPTIONS requests)
        $middleware->use([
            \Illuminate\Http\Middleware\HandleCors::class,
            \App\Http\Middleware\SecurityHeaders::class,
        ]);

        // Register route middleware aliases
        $middleware->alias([
            'role' => \App\Http\Middleware\RoleMiddleware::class,
            'driver.approved' => \App\Http\Middleware\EnsureDriverApproved::class,
        ]);

        // SEC-05 : limite globale sur les routes /api (nom du rate limiter : api).
        $middleware->api(append: [
            ThrottleRequests::class.':api',
        ]);

        $middleware->validateCsrfTokens(except: []);
    })
    ->withSchedule(function ($schedule) {
        $schedule->command('rides:expire')->everyMinute();
        $schedule->command('drivers:expire-stale')->everyFiveMinutes();
        $schedule->command('drivers:check-debts')->dailyAt('08:00');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
