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

    public function test_preflight_from_a_foreign_origin_gets_no_cors_headers(): void
    {
        $this->withHeaders([
            'Origin' => self::FOREIGN,
            'Access-Control-Request-Method' => 'POST',
        ])->options('/api/v1/health')
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_actual_requests_from_an_allowed_origin_expose_the_documented_headers(): void
    {
        $response = $this->withHeaders(['Origin' => self::ALLOWED])->getJson('/api/v1/health');

        $response->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', self::ALLOWED);
        $this->assertStringContainsString('X-Request-Id', (string) $response->headers->get('Access-Control-Expose-Headers'));
    }

    public function test_actual_requests_from_a_foreign_origin_get_no_cors_headers(): void
    {
        $this->withHeaders(['Origin' => self::FOREIGN])
            ->getJson('/api/v1/health')
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }
}
