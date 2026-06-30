<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
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
        // Define a rate limiter for OTP endpoints
        RateLimiter::for('otp', function (Request $request) {
            $key = sprintf('otp:%s', $request->ip());
            // 5 requests per minute by IP (adjust as needed)
            return [Limit::perMinute(5)->by($key)];
        });

        // SEC-05 : plafond global API (utilisateur authentifie ou IP). Surcharge par route possible (ex. throttle:60,1).
        RateLimiter::for('api', function (Request $request) {
            $perMinute = max(60, min(600, (int) env('API_RATE_LIMIT_PER_MINUTE', 240)));

            return Limit::perMinute($perMinute)->by(
                $request->user()
                    ? 'api:user:'.$request->user()->getAuthIdentifier()
                    : 'api:ip:'.$request->ip()
            );
        });
    }
}
