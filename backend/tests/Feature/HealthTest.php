<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_returns_ok(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertOk()
            ->assertJson(['status' => 'ok']);
    }

    public function test_up_endpoint_returns_200(): void
    {
        $response = $this->get('/up');

        $response->assertOk();
    }
}
