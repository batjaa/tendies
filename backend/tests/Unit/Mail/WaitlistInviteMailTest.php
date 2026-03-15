<?php

namespace Tests\Unit\Mail;

use App\Mail\WaitlistInviteMail;
use App\Models\WaitlistEntry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WaitlistInviteMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_subject_line(): void
    {
        $entry = WaitlistEntry::factory()->invited()->create();
        $mail = new WaitlistInviteMail($entry);

        $mail->assertHasSubject("You're invited to Tendies!");
    }

    public function test_body_contains_invite_link(): void
    {
        $entry = WaitlistEntry::factory()->invited()->create();
        $mail = new WaitlistInviteMail($entry);

        $rendered = $mail->render();

        $this->assertStringContainsString('/auth/waitlist/verify?token=' . $entry->invite_token, $rendered);
    }

    public function test_body_contains_expiry_date(): void
    {
        $entry = WaitlistEntry::factory()->invited()->create();
        $mail = new WaitlistInviteMail($entry);

        $rendered = $mail->render();

        $this->assertStringContainsString($entry->invite_expires_at->format('F j, Y'), $rendered);
    }

    public function test_is_queueable(): void
    {
        $this->assertTrue(
            is_subclass_of(WaitlistInviteMail::class, ShouldQueue::class)
        );
    }
}
