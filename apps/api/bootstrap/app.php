<?php

use App\Exceptions\SocialIntegrationException;
use App\Http\Middleware\AssignRequestId;
use App\Support\Problem\ProblemRenderer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        // API only: the React SPA is served by the gateway, so there is no web (session) routing yet.
        // Liveness/readiness live under /api/v1/health (no session or cookie work per probe).
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Trust no proxy unless TRUSTED_PROXIES lists the gateway (comma-separated IPs/CIDRs).
        $trustedProxies = array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', '')))));

        $middleware->trustProxies(
            at: $trustedProxies ?: null,
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        // Global so even unmatched routes (404) and CORS preflights carry a request id.
        $middleware->prepend(AssignRequestId::class);

        // Sanctum SPA mode: requests from the SPA origin (SANCTUM_STATEFUL_DOMAINS) get the cookie
        // session and CSRF protection; everything else stays stateless.
        $middleware->statefulApi();

        $middleware->throttleApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->expectsJson(),
        );

        // Expected, user-facing failures; provider errors are logged by the integration itself.
        $exceptions->dontReport(SocialIntegrationException::class);

        // Every API error is RFC 9457 problem+json with a stable machine-readable code.
        $exceptions->render(
            fn (Throwable $e, Request $request) => $request->is('api/*')
                ? ProblemRenderer::render($e, $request)
                : null,
        );
    })->create();
