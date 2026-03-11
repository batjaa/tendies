<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tests\Traits\CreatesPersonalAccessClient;

class AuthLoginTest extends TestCase
{
    use CreatesPersonalAccessClient, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPersonalAccessClient();
    }

    public function test_login_returns_token_and_revokes_old_tokens(): void
    {
        $user = User::factory()->create([
            'email' => 'jane@example.com',
            'password' => Hash::make('securepass'),
        ]);

        // Create an existing token to verify revocation.
        $user->createToken('old-cli');
        $this->assertDatabaseCount('oauth_access_tokens', 1);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'jane@example.com',
            'password' => 'securepass',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'tier']])
            ->assertJson(['user' => ['email' => 'jane@example.com']]);

        // Old token revoked, new one created.
        $this->assertDatabaseCount('oauth_access_tokens', 1);
    }

    public function test_login_rejects_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'jane@example.com',
            'password' => Hash::make('securepass'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'jane@example.com',
            'password' => 'wrongpass',
        ]);

        $response->assertUnauthorized()
            ->assertJson(['error' => 'invalid_credentials']);
    }

    public function test_login_rejects_unknown_email(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'securepass',
        ]);

        $response->assertUnauthorized()
            ->assertJson(['error' => 'invalid_credentials']);
    }

    public function test_login_rejects_missing_fields(): void
    {
        $response = $this->postJson('/api/auth/login', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }
}
