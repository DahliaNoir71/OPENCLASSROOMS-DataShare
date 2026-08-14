<?php

use App\Http\Middleware\NoStore;
use Illuminate\Auth\AuthenticationException;
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
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->throttleApi();
        $middleware->api(prepend: [NoStore::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // Laravel's own 401 body reads "Unauthenticated." — the only English
        // string the API would return, in the middle of French validation and
        // rate limit messages. Same status, same shape, one language.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json(['message' => 'Authentification requise.'], 401);
        });

        // Context attached to every reported exception. The route pattern, not
        // the resolved path: a download URL carries the share token, which is a
        // bearer secret and has no place in a log file.
        $exceptions->context(function (): array {
            if (app()->runningInConsole()) {
                return [];
            }

            return [
                'method' => request()->method(),
                'route' => request()->route()?->uri(),
            ];
        });
    })->create();
