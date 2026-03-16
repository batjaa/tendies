<?php

namespace Tests\Feature;

use App\Models\WaitlistEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WaitlistVerifyTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_invite_redirects_to_register(): void
    {
        $entry = WaitlistEntry::factory()->invited()->create();

        $response = $this->get("/auth/waitlist/verify?token={$entry->invite_token}");

        $response->assertRedirect('/auth/waitlist/register');
        $this->assertEquals($entry->invite_token, session('waitlist_invite_token'));
    }

    public function test_expired_invite_returns_403(): void
    {
        $entry = WaitlistEntry::factory()->expired()->create();

        $response = $this->get("/auth/waitlist/verify?token={$entry->invite_token}");

        $response->assertStatus(403);
    }

    public function test_invalid_token_returns_403(): void
    {
        $response = $this->get('/auth/waitlist/verify?token=nonexistent');

        $response->assertStatus(403);
    }

    public function test_already_accepted_returns_403(): void
    {
        $entry = WaitlistEntry::factory()->accepted()->create();

        $response = $this->get("/auth/waitlist/verify?token={$entry->invite_token}");

        $response->assertStatus(403);
    }
}
