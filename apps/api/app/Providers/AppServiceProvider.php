<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
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

        // Outside production, fail loudly on lazy loading (N+1), on reading attributes that were
        // not loaded, and on mass-assigning attributes that are not in $fillable.
        Model::shouldBeStrict(! $this->app->isProduction());

        $this->configurePasswordRules();
        $this->configureAuthorization();
        $this->configureRateLimiting();
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

    private function configurePasswordRules(): void
    {
        Password::defaults(fn (): Password => Password::min(12)
            ->letters()
            ->mixedCase()
            ->numbers()
            ->symbols()
            ->when(
                (bool) config('auth.check_breached_passwords'),
                fn (Password $rule): Password => $rule->uncompromised(),
            ));
    }

    private function configureAuthorization(): void
    {
        // Abilities containing a dot are permission names ("users.view"): answer them from the
        // user's roles. Everything else (policy abilities like "view") falls through to policies.
        Gate::before(function (User $user, string $ability): ?bool {
            return str_contains($ability, '.') ? $user->hasPermission($ability) : null;
        });
    }

    private function configureRateLimiting(): void
    {
        // General API limit: per signed-in user, per IP for guests.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(
            (int) config('api.rate_limit_per_minute'),
        )->by($this->actorKey($request)));

        RateLimiter::for('register', fn (Request $request) => [
            Limit::perMinute(5)->by((string) $request->ip()),
            Limit::perHour(30)->by((string) $request->ip()),
        ]);

        // Brute-force protection: tight per account+IP, looser per IP to also slow down
        // credential stuffing that rotates through many emails.
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by($this->emailKey($request).'|'.$request->ip()),
            Limit::perMinute(20)->by((string) $request->ip()),
        ]);

        RateLimiter::for('password-email', fn (Request $request) => [
            Limit::perMinute(3)->by($this->emailKey($request).'|'.$request->ip()),
            Limit::perHour(20)->by((string) $request->ip()),
        ]);

        RateLimiter::for('password-reset', fn (Request $request) => [
            Limit::perMinute(5)->by($this->emailKey($request).'|'.$request->ip()),
            Limit::perMinute(20)->by((string) $request->ip()),
        ]);

        RateLimiter::for('verification', fn (Request $request) => Limit::perMinute(6)->by($this->actorKey($request)));

        // Password change, email change, account deletion.
        RateLimiter::for('sensitive', fn (Request $request) => Limit::perMinute(5)->by($this->actorKey($request)));
    }

    private function actorKey(Request $request): string
    {
        $id = $request->user()?->getAuthIdentifier();

        return $id !== null ? 'user:'.$id : 'ip:'.$request->ip();
    }

    private function emailKey(Request $request): string
    {
        $email = $request->input('email');

        return is_string($email) ? Str::lower(trim($email)) : '';
    }
}
