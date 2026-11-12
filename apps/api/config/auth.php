<?php

use App\Models\User;

return [

    'defaults' => [
        'guard' => 'web',
        'passwords' => 'users',
    ],

    // The SPA authenticates with the Sanctum cookie session, which resolves through the "web"
    // session guard (see config/sanctum.php). There are no API tokens.
    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => User::class,
        ],
    ],

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => 'password_reset_tokens',
            // Minutes a reset link stays valid, and seconds before another one may be requested.
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => 10800,

    // Minutes an email verification link stays valid.
    'verification' => [
        'expire' => 60,
    ],

    // Reject passwords found in known data breaches (queries the Have I Been Pwned range API with
    // a k-anonymity prefix; the password never leaves the server). Off by default so local
    // development and tests need no outbound network; turn on in production.
    'check_breached_passwords' => (bool) env('AUTH_CHECK_BREACHED_PASSWORDS', false),

];
