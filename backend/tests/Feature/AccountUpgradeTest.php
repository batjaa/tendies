<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;
use Tests\Traits\CreatesPersonalAccessClient;

class AccountUpgradeTest extends TestCase
{
    use CreatesPersonalAccessClient, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPersonalAccessClient();
    }

    public function test_anonymous_user_can_upgrade(): void
    {
        $user = User::factory()->anonymous()->create();
        Passport::actingAs($user);

        $response = $this->postJson('/api/v1/account/upgrade', [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'securepass',
            'password_confirmation' => 'securepass',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'tier']])
            ->assertJson(['user' => ['name' => 'Jane Doe', 'email' => 'jane@example.com']]);

        $user->refresh();
        $this->assertEquals('jane@example.com', $user->email);
        $this->assertFalse($user->isAnonymous());
    }

    public function test_registered_user_cannot_upgrade(): void
    {
        $user = User::factory()->create(['email' => 'existing@example.com']);
        Passport::actingAs($user);

        $response = $this->postJson('/api/v1/account/upgrade', [
            'name' => 'Jane',
            'email' => 'new@example.com',
            'password' => 'securepass',
            'password_confirmation' => 'securepass',
        ]);

        $response->assertStatus(409)
            ->assertJson(['error' => 'already_registered']);
    }

    public function test_upgrade_rejects_taken_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $user = User::factory()->anonymous()->create();
        Passport::actingAs($user);

        $response = $this->postJson('/api/v1/account/upgrade', [
            'name' => 'Jane',
            'email' => 'taken@example.com',
            'password' => 'securepass',
            'password_confirmation' => 'securepass',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_upgrade_requires_auth(): void
    {
        $response = $this->postJson('/api/v1/account/upgrade', [
            'name' => 'Jane',
            'email' => 'jane@example.com',
            'password' => 'securepass',
            'password_confirmation' => 'securepass',
        ]);

        $response->assertUnauthorized();
    }
}
