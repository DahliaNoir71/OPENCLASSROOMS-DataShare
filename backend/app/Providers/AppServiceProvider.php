<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    /**
     * Two limiters: a general ceiling for the whole API, and a strict one for
     * the auth routes, which are open to everyone and therefore exposed to
     * account enumeration and credential stuffing.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)
                ->by($request->user()?->id ?: $request->ip())
                ->response($this->rejectAndLog('api'));
        });

        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(5)
                ->by($request->ip())
                ->response($this->rejectAndLog('auth'));
        });
    }

    /**
     * A 429 is returned to the caller, not to us, and Laravel keeps quiet about
     * it: HttpException sits in its internalDontReport list, so a credential
     * stuffing run would otherwise leave no trace at all. Hence an explicit
     * line.
     *
     * The rate limiter headers are passed through untouched — Retry-After is
     * part of the API contract. Logged fields are deliberately narrow: the
     * route pattern rather than the resolved path, which on a download URL
     * would carry the share token, and the numeric user id rather than the
     * email address.
     */
    private function rejectAndLog(string $limiter): callable
    {
        return function (Request $request, array $headers) use ($limiter): JsonResponse {
            Log::warning('Rate limit exceeded', [
                'limiter' => $limiter,
                'ip' => $request->ip(),
                'method' => $request->method(),
                'route' => $request->route()?->uri(),
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'message' => 'Trop de requêtes. Réessayez dans quelques instants.',
            ], 429, $headers);
        };
    }
}
