<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Outl1ne\NovaSettings\Models\Settings;
use Tests\TestCase;
use Tests\Traits\CreatesPersonalAccessClient;

class AuthRegisterTest extends TestCase
{
    use CreatesPersonalAccessClient, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPersonalAccessClient();
        Settings::updateOrCreate(['key' => 'waitlist_mode'], ['value' => false]);
    }

    public function test_registers_new_user_and_returns_token(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'securepass',
            'password_confirmation' => 'securepass',
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'tier']])
            ->assertJson(['user' => ['name' => 'Jane Doe', 'email' => 'jane@example.com', 'tier' => 'free']]);

        $this->assertDatabaseHas('users', ['email' => 'jane@example.com']);
        $this->assertNotEmpty($response->json('token'));
    }

    public function test_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $response = $this->postJson('/api/auth/register', [
            'name' => 'Dup',
            'email' => 'taken@example.com',
            'password' => 'securepass',
            'password_confirmation' => 'securepass',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_rejects_short_password(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Test',
            'email' => 'test@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_rejects_missing_fields(): void
    {
        $response = $this->postJson('/api/auth/register', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'password']);
    }

    public function test_blocks_registration_when_waitlist_mode_enabled(): void
    {
        Settings::updateOrCreate(['key' => 'waitlist_mode'], ['value' => true]);

        $response = $this->postJson('/api/auth/register', [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'securepass',
            'password_confirmation' => 'securepass',
        ]);

        $response->assertForbidden()
            ->assertJson(['error' => 'waitlist_active']);
    }
}
