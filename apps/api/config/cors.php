<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing
|--------------------------------------------------------------------------
| Default deployment is same-origin (the nginx gateway serves the SPA and proxies /api), which
| needs no CORS at all. This policy only matters when the SPA runs on another origin, for
| example `npm run dev` on http://localhost:5173 calling the API directly.
|
| Rules: explicit origins only (CORS_ALLOWED_ORIGINS, comma-separated, never "*"), credentials
| allowed for the Sanctum cookie session, a short header allow-list, no wildcard methods.
| AppServiceProvider refuses to boot if "*" is combined with credentials.
*/

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')),
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Accept',
        'Content-Type',
        'Idempotency-Key',
        'X-Requested-With',
        'X-Request-Id',
        'X-XSRF-TOKEN',
    ],

    'exposed_headers' => [
        'Retry-After',
        'X-API-Version',
        'X-Request-Id',
    ],

    'max_age' => 600,

    'supports_credentials' => true,

];
