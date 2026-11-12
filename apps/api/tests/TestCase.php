<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** The origin the SPA is served from (must be listed in SANCTUM_STATEFUL_DOMAINS). */
    protected const SPA_ORIGIN = 'http://localhost:8080';

    /**
     * Send following requests the way the browser app does. Without an Origin/Referer from a
     * stateful domain, Sanctum treats the request as stateless (no session, no cookies).
     */
    protected function spa(): static
    {
        return $this->withHeaders([
            'Origin' => self::SPA_ORIGIN,
            'Referer' => self::SPA_ORIGIN.'/',
        ]);
    }
}
