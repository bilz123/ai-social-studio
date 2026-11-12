<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use LogicException;

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
        $this->guardCorsConfiguration();

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(
            (int) config('api.rate_limit_per_minute'),
        )->by((string) $request->ip()));
    }

    /**
     * Fail fast on an unsafe CORS setup: browsers refuse a wildcard origin together with
     * credentials, and allowing every origin to send credentialed requests is never intended.
     */
    private function guardCorsConfiguration(): void
    {
        if (config('cors.supports_credentials') && in_array('*', config('cors.allowed_origins', []), true)) {
            throw new LogicException('CORS_ALLOWED_ORIGINS must list explicit origins; "*" is not allowed with credentials.');
        }
    }
}
