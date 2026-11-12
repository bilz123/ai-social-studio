<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiVersioningAndRequestIdTest extends TestCase
{
    public function test_v1_responses_carry_the_api_version_header(): void
    {
        $this->getJson('/api/v1/health')->assertHeader('X-API-Version', 'v1');
    }

    public function test_an_unknown_version_is_a_404_problem(): void
    {
        $this->getJson('/api/v2/health')
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
    }

    public function test_a_request_id_is_generated_when_absent(): void
    {
        $response = $this->getJson('/api/v1/health');

        $this->assertMatchesRegularExpression(
            '/^[A-Za-z0-9._-]{8,64}$/',
            (string) $response->headers->get('X-Request-Id'),
        );
    }

    public function test_a_well_formed_incoming_request_id_is_propagated(): void
    {
        $this->withHeaders(['X-Request-Id' => 'trace-12345678'])
            ->getJson('/api/v1/health')
            ->assertHeader('X-Request-Id', 'trace-12345678');
    }

    public function test_a_malformed_incoming_request_id_is_replaced(): void
    {
        $response = $this->withHeaders(['X-Request-Id' => "bad id\nwith newline"])
            ->getJson('/api/v1/health');

        $this->assertNotSame("bad id\nwith newline", $response->headers->get('X-Request-Id'));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9._-]{8,64}$/', (string) $response->headers->get('X-Request-Id'));
    }
}
