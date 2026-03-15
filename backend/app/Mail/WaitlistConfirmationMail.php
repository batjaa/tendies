<?php

namespace App\Mail;

use App\Models\WaitlistEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WaitlistConfirmationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public WaitlistEntry $entry,
        public int $position,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "You're on the Tendies waitlist!",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.waitlist-confirmation',
        );
    }
}
