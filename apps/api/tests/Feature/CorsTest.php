<?php

namespace Tests\Feature;

use Tests\TestCase;

class CorsTest extends TestCase
{
    private const ALLOWED = 'http://localhost:5173';

    private const FOREIGN = 'https://evil.example';

    public function test_configuration_never_allows_a_wildcard_with_credentials(): void
    {
        $this->assertTrue(config('cors.supports_credentials'));
        $this->assertNotContains('*', config('cors.allowed_origins'));
        $this->assertSame([self::ALLOWED], config('cors.allowed_origins'));
    }

    public function test_preflight_from_an_allowed_origin_is_accepted(): void
    {
        $this->withHeaders([
            'Origin' => self::ALLOWED,
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'content-type,x-xsrf-token',
        ])->options('/api/v1/health')
            ->assertSuccessful()
            ->assertHeader('Access-Control-Allow-Origin', self::ALLOWED)
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
    }

    public function test_preflight_from_a_foreign_origin_is_never_granted(): void
    {
        // With a single configured origin the CORS library always answers with that origin; the
        // browser then rejects the mismatch. What matters is that the foreign origin is never echoed.
        $response = $this->withHeaders([
            'Origin' => self::FOREIGN,
            'Access-Control-Request-Method' => 'POST',
        ])->options('/api/v1/health');

        $this->assertNotSame(self::FOREIGN, $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertNotSame('*', $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_actual_requests_from_an_allowed_origin_expose_the_documented_headers(): void
    {
        $response = $this->withHeaders(['Origin' => self::ALLOWED])->getJson('/api/v1/health');

        $response->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', self::ALLOWED);
        $this->assertStringContainsString('X-Request-Id', (string) $response->headers->get('Access-Control-Expose-Headers'));
    }

    public function test_actual_requests_from_a_foreign_origin_are_never_granted(): void
    {
        $response = $this->withHeaders(['Origin' => self::FOREIGN])->getJson('/api/v1/health');

        $this->assertNotSame(self::FOREIGN, $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertNotSame('*', $response->headers->get('Access-Control-Allow-Origin'));
    }
}
