<?php

namespace Tests\Unit\Mail;

use App\Mail\WaitlistConfirmationMail;
use App\Models\WaitlistEntry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WaitlistConfirmationMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_subject_line(): void
    {
        $entry = WaitlistEntry::factory()->create();
        $mail = new WaitlistConfirmationMail($entry, 5);

        $mail->assertHasSubject("You're #5 on the waitlist");
    }

    public function test_body_contains_position(): void
    {
        $entry = WaitlistEntry::factory()->create();
        $mail = new WaitlistConfirmationMail($entry, 42);

        $rendered = $mail->render();

        $this->assertStringContainsString('#42', $rendered);
        $this->assertStringContainsString('in line for early access', $rendered);
    }

    public function test_is_queueable(): void
    {
        $this->assertTrue(
            is_subclass_of(WaitlistConfirmationMail::class, ShouldQueue::class)
        );
    }
}
