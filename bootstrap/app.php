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
        \Sentry\Laravel\Integration::handles($exceptions);
        $exceptions->render(function (\Throwable $e, Request $request) {
            // Let framework exceptions (validation, auth, auth-z, 404, etc.) render
            // with their native status codes. Only wrap uncategorised 5xx faults.
            if ($e instanceof \Illuminate\Validation\ValidationException
                || $e instanceof \Illuminate\Auth\AuthenticationException
                || $e instanceof \Illuminate\Auth\Access\AuthorizationException
                || $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                return null;
            }

            if ($request->is('api/*') || $request->expectsJson()) {
                $debug = config('app.debug');
                // In production, hide the message + trace but keep the
                // exception class name so on-device error alerts give
                // us enough to diagnose remotely (e.g. "QueryException"
                // vs "RuntimeException") without leaking internals.
                // Sentry separately captures the full trace.
                return response()->json([
                    'error'           => $debug ? $e->getMessage() : 'Server error',
                    'message'         => $debug ? $e->getMessage() : 'An unexpected error occurred.',
                    'exception_class' => $debug ? null : (new \ReflectionClass($e))->getShortName(),
                    'trace'           => $debug ? $e->getTrace() : null,
                ], 500);
            }
        });
    })->create();
