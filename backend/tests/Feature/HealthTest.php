<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_live_health_endpoint_returns_ok(): void
    {
        $response = $this->getJson('/health');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'ok',
                'app' => 'Conexa',
                'version' => '1.0.0-rc.2',
            ]);
    }

    public function test_ready_health_endpoint_returns_ready_with_database_check(): void
    {
        $response = $this->getJson('/health/ready');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'ready',
                'version' => '1.0.0-rc.2',
                'checks' => [
                    'database' => 'ok',
                ],
            ]);
    }
}
