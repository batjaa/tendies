<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WaitlistEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WaitlistRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_without_session_token_returns_403(): void
    {
        $response = $this->get('/auth/waitlist/register');

        $response->assertStatus(403);
    }

    public function test_get_with_valid_token_shows_form_with_prefilled_email(): void
    {
        $entry = WaitlistEntry::factory()->invited()->create(['email' => 'trader@example.com']);

        $response = $this->withSession(['waitlist_invite_token' => $entry->invite_token])
            ->get('/auth/waitlist/register');

        $response->assertStatus(200);
        $response->assertSee('trader@example.com');
    }

    public function test_post_creates_user_and_marks_entry_accepted(): void
    {
        $entry = WaitlistEntry::factory()->invited()->create(['email' => 'trader@example.com']);

        $response = $this->withSession(['waitlist_invite_token' => $entry->invite_token])
            ->post('/auth/waitlist/register', [
                'email' => 'trader@example.com',
                'name' => 'Test Trader',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'waitlist_invite_token' => $entry->invite_token,
            ]);

        $response->assertRedirect('/onboarding/connect');

        $this->assertDatabaseHas('users', ['email' => 'trader@example.com', 'name' => 'Test Trader']);
        $user = User::where('email', 'trader@example.com')->first();
        $this->assertNotNull($user->trial_ends_at);
        $this->assertTrue($user->trial_ends_at->isFuture());

        $entry->refresh();
        $this->assertEquals('accepted', $entry->status);
        $this->assertNotNull($entry->accepted_at);

        $this->assertAuthenticatedAs($user);
    }

    public function test_post_with_expired_token_returns_403(): void
    {
        $entry = WaitlistEntry::factory()->expired()->create();

        $response = $this->post('/auth/waitlist/register', [
            'email' => 'new@example.com',
            'name' => 'Test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'waitlist_invite_token' => $entry->invite_token,
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_post_with_already_accepted_token_returns_403(): void
    {
        $entry = WaitlistEntry::factory()->accepted()->create();

        $response = $this->post('/auth/waitlist/register', [
            'email' => 'new@example.com',
            'name' => 'Test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'waitlist_invite_token' => $entry->invite_token,
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_post_with_duplicate_email_fails_validation(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        $entry = WaitlistEntry::factory()->invited()->create(['email' => 'taken@example.com']);

        $response = $this->withSession(['waitlist_invite_token' => $entry->invite_token])
            ->post('/auth/waitlist/register', [
                'email' => 'taken@example.com',
                'name' => 'Test',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'waitlist_invite_token' => $entry->invite_token,
            ]);

        $response->assertSessionHasErrors('email');
    }

    public function test_post_with_password_validation_failures(): void
    {
        $entry = WaitlistEntry::factory()->invited()->create();

        $response = $this->withSession(['waitlist_invite_token' => $entry->invite_token])
            ->post('/auth/waitlist/register', [
                'email' => $entry->email,
                'name' => 'Test',
                'password' => 'short',
                'password_confirmation' => 'short',
                'waitlist_invite_token' => $entry->invite_token,
            ]);

        $response->assertSessionHasErrors('password');
    }

    public function test_post_clears_session_token(): void
    {
        $entry = WaitlistEntry::factory()->invited()->create();

        $this->withSession(['waitlist_invite_token' => $entry->invite_token])
            ->post('/auth/waitlist/register', [
                'email' => $entry->email,
                'name' => 'Test',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'waitlist_invite_token' => $entry->invite_token,
            ]);

        $this->assertNull(session('waitlist_invite_token'));
    }

    public function test_post_with_hidden_field_token_only_works(): void
    {
        $entry = WaitlistEntry::factory()->invited()->create();

        // No session token — only the hidden field
        $response = $this->post('/auth/waitlist/register', [
            'email' => $entry->email,
            'name' => 'Test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'waitlist_invite_token' => $entry->invite_token,
        ]);

        $response->assertRedirect('/onboarding/connect');
        $this->assertDatabaseHas('users', ['email' => $entry->email]);
    }
}
