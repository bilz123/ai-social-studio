<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AddApiVersionHeader
{
    public function handle(Request $request, Closure $next, string $version = 'v1'): Response
    {
        $response = $next($request);
        $response->headers->set('X-API-Version', $version);

        return $response;
    }
}
