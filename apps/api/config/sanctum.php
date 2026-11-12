<?php

use App\Http\Middleware\AuthenticateWebSession;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

return [

    /*
    | Hosts (with port) whose requests are treated as the first-party SPA and therefore get the
    | cookie session + CSRF protection. Matching uses the Origin (or Referer) header.
    */
    'stateful' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'SANCTUM_STATEFUL_DOMAINS',
        'localhost,localhost:5173,localhost:8080,127.0.0.1,127.0.0.1:8080',
    ))))),

    'guard' => ['web'],

    // No bearer tokens are issued in this application.
    'expiration' => null,

    'token_prefix' => '',

    // The CSRF cookie endpoint is served under /api so the gateway needs a single /api route:
    // GET /api/sanctum/csrf-cookie
    'prefix' => 'api/sanctum',

    'middleware' => [
        // Signs the user out of other devices when the password changes.
        'authenticate_session' => AuthenticateWebSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];
