<?php

namespace Tests\Unit;

use App\Mail\WaitlistInviteMail;
use App\Models\WaitlistEntry;
use App\Services\WaitlistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class WaitlistServiceTest extends TestCase
{
    use RefreshDatabase;

    private WaitlistService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->service = new WaitlistService;
    }

    public function test_send_invites_to_pending_entries(): void
    {
        $entries = WaitlistEntry::factory()->count(3)->create();

        $result = $this->service->sendInvites($entries);

        $this->assertEquals(3, $result['sent']);
        $this->assertEquals(0, $result['skipped']);

        foreach ($entries as $entry) {
            $entry->refresh();
            $this->assertEquals('invited', $entry->status);
            $this->assertNotNull($entry->invite_token);
            $this->assertNotNull($entry->invited_at);
            $this->assertNotNull($entry->invite_expires_at);
        }

        Mail::assertQueued(WaitlistInviteMail::class, 3);
    }

    public function test_skips_already_invited_entries(): void
    {
        $pending = WaitlistEntry::factory()->create();
        $invited = WaitlistEntry::factory()->invited()->create();

        $result = $this->service->sendInvites(collect([$pending, $invited]));

        $this->assertEquals(1, $result['sent']);
        $this->assertEquals(1, $result['skipped']);

        Mail::assertQueued(WaitlistInviteMail::class, 1);
    }

    public function test_skips_accepted_entries(): void
    {
        $accepted = WaitlistEntry::factory()->accepted()->create();

        $result = $this->service->sendInvites(collect([$accepted]));

        $this->assertEquals(0, $result['sent']);
        $this->assertEquals(1, $result['skipped']);

        Mail::assertNotQueued(WaitlistInviteMail::class);
    }

    public function test_reverts_to_pending_on_mail_failure(): void
    {
        Mail::shouldReceive('to->send')->andThrow(new \RuntimeException('Postmark down'));

        $entry = WaitlistEntry::factory()->create();

        $result = $this->service->sendInvites(collect([$entry]));

        $this->assertEquals(0, $result['sent']);
        $this->assertEquals(1, $result['skipped']);

        $entry->refresh();
        $this->assertEquals('pending', $entry->status);
        $this->assertNull($entry->invite_token);
        $this->assertNull($entry->invited_at);
        $this->assertNull($entry->invite_expires_at);
    }
}
