<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    public function test_liveness_does_not_depend_on_external_services(): void
    {
        $this->getJson('/health/live')
            ->assertOk()
            ->assertExactJson([
                'status' => 'ok',
                'service' => 'revieworbit-api',
            ]);
    }

    public function test_versioned_status_endpoint_is_available(): void
    {
        $this->getJson('/api/v1/status')
            ->assertOk()
            ->assertJsonPath('data.version', 'v1');
    }

    public function test_responses_receive_a_traceable_request_id(): void
    {
        $response = $this->getJson('/health/live');

        $this->assertTrue(Str::isUlid($response->headers->get('X-Request-ID')));
    }
}
