<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands()
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(\App\Http\Middleware\Cors::class);
        $middleware->alias([
            'saas.auth' => \App\Http\Middleware\SaasAuthMiddleware::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('eval:run --trigger=scheduled')
            ->dailyAt('03:00')
            ->withoutOverlapping()
            ->onOneServer();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Throwable $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                $debug = config('app.debug');
                return response()->json([
                    'error'   => $debug ? $e->getMessage() : 'Server error',
                    'message' => $debug ? $e->getMessage() : 'An unexpected error occurred.',
                    'trace'   => $debug ? $e->getTrace() : null,
                ], method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500);
            }
        });
    })->create();
