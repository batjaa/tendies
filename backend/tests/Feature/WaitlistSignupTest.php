<?php

namespace Tests\Feature;

use App\Mail\WaitlistConfirmationMail;
use App\Models\WaitlistEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class WaitlistSignupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_signup_creates_pending_entry(): void
    {
        $response = $this->postJson('/api/waitlist/signup', [
            'email' => 'jane@example.com',
        ]);

        $response->assertCreated()
            ->assertJson(['message' => "You're on the list!", 'position' => 1]);

        $this->assertDatabaseHas('waitlist_entries', [
            'email' => 'jane@example.com',
            'status' => 'pending',
        ]);

        Mail::assertQueued(WaitlistConfirmationMail::class, function ($mail) {
            return $mail->hasTo('jane@example.com') && $mail->position === 1;
        });
    }

    public function test_rejects_duplicate_email(): void
    {
        WaitlistEntry::factory()->create(['email' => 'taken@example.com']);

        $response = $this->postJson('/api/waitlist/signup', [
            'email' => 'taken@example.com',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_rejects_missing_fields(): void
    {
        $response = $this->postJson('/api/waitlist/signup', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }
}
