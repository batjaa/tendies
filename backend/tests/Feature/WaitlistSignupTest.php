<?php

namespace Tests\Feature;

use App\Models\WaitlistEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WaitlistSignupTest extends TestCase
{
    use RefreshDatabase;

    public function test_signup_creates_pending_entry(): void
    {
        $response = $this->postJson('/api/waitlist/signup', [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
        ]);

        $response->assertCreated()
            ->assertJson(['message' => "You're on the list!", 'position' => 1]);

        $this->assertDatabaseHas('waitlist_entries', [
            'email' => 'jane@example.com',
            'status' => 'pending',
        ]);
    }

    public function test_rejects_duplicate_email(): void
    {
        WaitlistEntry::factory()->create(['email' => 'taken@example.com']);

        $response = $this->postJson('/api/waitlist/signup', [
            'name' => 'Dup',
            'email' => 'taken@example.com',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_rejects_missing_fields(): void
    {
        $response = $this->postJson('/api/waitlist/signup', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email']);
    }
}
