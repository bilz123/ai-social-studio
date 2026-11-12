<?php

namespace Tests\Feature;

use App\Support\Health\DatabaseCheck;
use App\Support\Health\HealthCheck;
use App\Support\Health\RedisCheck;
use RuntimeException;
use Tests\TestCase;

class HealthEndpointsTest extends TestCase
{
    public function test_liveness_reports_ok(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertExactJson([
                'status' => 'ok',
                'service' => 'api',
                'api_version' => 'v1',
            ]);
    }

    public function test_readiness_is_ok_when_the_database_is_reachable(): void
    {
        $this->getJson('/api/v1/health/ready')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.database.status', 'ok');
    }

    public function test_readiness_returns_503_without_leaking_error_details(): void
    {
        $this->app->bind(DatabaseCheck::class, fn () => $this->failingCheck('database'));

        $response = $this->getJson('/api/v1/health/ready');

        $response->assertStatus(503)
            ->assertExactJson([
                'status' => 'degraded',
                'checks' => ['database' => ['status' => 'fail']],
            ]);
        $this->assertStringNotContainsString('hunter2', (string) $response->getContent());
    }

    public function test_redis_is_part_of_readiness_when_enabled(): void
    {
        config(['health.checks' => [DatabaseCheck::class, RedisCheck::class]]);
        $this->app->bind(RedisCheck::class, fn () => $this->failingCheck('redis'));

        $this->getJson('/api/v1/health/ready')
            ->assertStatus(503)
            ->assertJsonPath('checks.database.status', 'ok')
            ->assertJsonPath('checks.redis.status', 'fail');
    }

    public function test_health_check_command_succeeds_when_healthy(): void
    {
        $this->artisan('health:check')->assertSuccessful();
    }

    public function test_health_check_command_fails_when_degraded(): void
    {
        $this->app->bind(DatabaseCheck::class, fn () => $this->failingCheck('database'));

        $this->artisan('health:check')->assertFailed();
    }

    private function failingCheck(string $name): HealthCheck
    {
        return new class($name) implements HealthCheck
        {
            public function __construct(private string $name) {}

            public function name(): string
            {
                return $this->name;
            }

            public function check(): void
            {
                throw new RuntimeException('could not connect: password=hunter2');
            }
        };
    }
}
