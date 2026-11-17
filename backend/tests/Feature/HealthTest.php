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

    public function test_realtime_config_never_exposes_the_app_encryption_key(): void
    {
        $appKey = 'base64:'.base64_encode(str_repeat('x', 32));
        config()->set('app.key', $appKey);
        config()->set('reverb.apps.apps.0.key', $appKey.'reverb_public_key');

        $this->getJson('/api/v1/public/realtime/config')
            ->assertStatus(503)
            ->assertDontSee($appKey);

        $this->getJson('/health/realtime')
            ->assertStatus(503)
            ->assertJsonPath('status', 'invalid_config');
    }
}
