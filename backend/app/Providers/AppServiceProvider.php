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
     * Four limiters: a general ceiling for the whole API, a strict one for the
     * auth routes, which are open to everyone and therefore exposed to account
     * enumeration and credential stuffing, one for uploads, whose ceiling has
     * nothing to do with a plain JSON call — each request can carry up to
     * 1 GiB — and one for public downloads, the only route where two distinct
     * attacks aim at the same endpoint, hence its two simultaneous limits.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute((int) config('datashare.throttle.api'))
                ->by($request->user()?->id ?: $request->ip())
                ->response($this->rejectAndLog('api'));
        });

        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute((int) config('datashare.throttle.auth'))
                ->by($request->ip())
                ->response($this->rejectAndLog('auth'));
        });

        RateLimiter::for('uploads', function (Request $request) {
            return Limit::perMinute((int) config('datashare.throttle.uploads'))
                ->by($request->user()?->id ?: $request->ip())
                ->response($this->rejectAndLog('uploads'));
        });

        // Two simultaneous limits rather than one: a public download is the
        // only route two distinct attacks aim at. The first bounds guessing the
        // share password of a known link, the second bounds sweeping the token
        // space from one source. Both have to pass — ThrottleRequests checks
        // every limit before incrementing any.
        //
        // The response callback goes on each limit, not on the limiter: the 429
        // is built from the limit that tripped, so a limit without it would
        // answer Laravel's English default and leave no log line.
        RateLimiter::for('downloads', function (Request $request) {
            // Defence in depth, not a fix: ThrottleRequests already hashes the
            // composed key before it reaches the cache store. But that is a
            // static the framework lets anyone flip (withoutHashingKeys). A
            // share token is a bearer secret; whether it stays out of the
            // `cache` table must not depend on a framework default.
            //
            // sha256 rather than sha1: this is key derivation, not signing, so
            // collision resistance is not what is at stake — but at equal cost
            // there is no reason to reach for the weaker digest, and it spares
            // the reader (and the linter) the question.
            $token = hash('sha256', (string) $request->route('token'));

            return [
                Limit::perMinute((int) config('datashare.throttle.downloads_per_token'))
                    ->by('dl-token:'.$token)
                    ->response($this->rejectAndLog('downloads')),
                Limit::perMinute((int) config('datashare.throttle.downloads_per_ip'))
                    ->by('dl-ip:'.$request->ip())
                    ->response($this->rejectAndLog('downloads')),
            ];
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
