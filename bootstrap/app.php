<?php

declare(strict_types=1);

use App\Domain\OperationRejected;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {})
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('deposits:match')->everyThirtySeconds()->withoutOverlapping();
        $schedule->command('withdrawals:relay')->everyMinute()->withoutOverlapping();
        $schedule->command('commissions:pay')->everyFiveMinutes()->withoutOverlapping();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(
            fn (OperationRejected $e) => response()->json(['message' => $e->getMessage()], 422),
        );
    })->create();
