<?php

namespace App\Http\Middleware;

use Illuminate\Session\Middleware\AuthenticateSession;

/**
 * Laravel's AuthenticateSession talks to the *default* guard. Route middleware such as
 * `auth:sanctum` switches the default guard for the rest of the process, and Sanctum's request
 * guard has no session methods. Pinning this middleware to the "web" session guard makes it
 * behave the same however many requests a process serves (queue workers, test suites, Octane).
 */
class AuthenticateWebSession extends AuthenticateSession
{
    protected function guard()
    {
        return $this->auth->guard('web');
    }
}
