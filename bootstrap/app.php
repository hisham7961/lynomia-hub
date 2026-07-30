<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));
        $middleware->appendToGroup('web', \App\Http\Middleware\HubMaintenance::class);
        $middleware->appendToGroup('web', \App\Http\Middleware\SecurityHeaders::class);
        $middleware->appendToGroup('web', \App\Http\Middleware\Observability::class);
        $middleware->appendToGroup('api', \App\Http\Middleware\SecurityHeaders::class);
        $middleware->appendToGroup('api', \App\Http\Middleware\Observability::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // كل استثناء يُجمَّع في مركز الأخطاء (بلا كسر المعالجة الأصلية)
        $exceptions->report(fn (\Throwable $e) => \App\Support\ErrorLog::exception($e));
    })->create();
