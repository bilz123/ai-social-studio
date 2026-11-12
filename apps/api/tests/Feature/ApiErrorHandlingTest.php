<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class ApiErrorHandlingTest extends TestCase
{
    public function test_unknown_api_route_returns_a_problem_document(): void
    {
        $response = $this->getJson('/api/v1/does-not-exist');

        $response->assertNotFound()
            ->assertJsonPath('code', 'not_found')
            ->assertJsonPath('status', 404)
            ->assertJsonPath('type', 'about:blank')
            ->assertJsonPath('request_id', $response->headers->get('X-Request-Id'));
        $this->assertStringContainsString('application/problem+json', (string) $response->headers->get('Content-Type'));
    }

    public function test_wrong_method_returns_405_problem(): void
    {
        $this->postJson('/api/v1/health')
            ->assertStatus(405)
            ->assertJsonPath('code', 'method_not_allowed');
    }

    public function test_validation_errors_are_problem_documents_that_do_not_echo_input(): void
    {
        Route::middleware('api')->post('/api/v1/_test/validate', function (Request $request) {
            $request->validate(['name' => 'required']);
        });

        $response = $this->postJson('/api/v1/_test/validate', ['password' => 'hunter2']);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonStructure(['errors' => ['name']]);
        $this->assertStringNotContainsString('hunter2', (string) $response->getContent());
    }

    public function test_unhandled_exceptions_return_a_generic_500(): void
    {
        config(['app.debug' => false]);
        Route::middleware('api')->get('/api/v1/_test/boom', function () {
            throw new RuntimeException('database password is hunter2');
        });

        $response = $this->getJson('/api/v1/_test/boom');

        $response->assertStatus(500)
            ->assertJsonPath('code', 'internal_error')
            ->assertJsonMissingPath('debug');
        $this->assertStringNotContainsString('hunter2', (string) $response->getContent());
    }

    public function test_debug_mode_adds_exception_details_to_5xx_only(): void
    {
        config(['app.debug' => true]);
        Route::middleware('api')->get('/api/v1/_test/boom', function () {
            throw new RuntimeException('visible in debug');
        });

        $this->getJson('/api/v1/_test/boom')
            ->assertStatus(500)
            ->assertJsonPath('debug.message', 'visible in debug');
        $this->getJson('/api/v1/does-not-exist')
            ->assertJsonMissingPath('debug');
    }

    public function test_problem_responses_are_not_used_outside_the_api(): void
    {
        $this->get('/not-an-api-route')->assertNotFound();
    }
}
