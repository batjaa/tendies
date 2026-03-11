<?php

namespace Tests\Unit;

use App\Models\WaitlistEntry;
use App\Services\WaitlistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WaitlistServiceTest extends TestCase
{
    use RefreshDatabase;

    private WaitlistService $service;

    protected function setUp(): void
    {
        parent::setUp();
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
    }

    public function test_skips_already_invited_entries(): void
    {
        $pending = WaitlistEntry::factory()->create();
        $invited = WaitlistEntry::factory()->invited()->create();

        $result = $this->service->sendInvites(collect([$pending, $invited]));

        $this->assertEquals(1, $result['sent']);
        $this->assertEquals(1, $result['skipped']);
    }

    public function test_skips_accepted_entries(): void
    {
        $accepted = WaitlistEntry::factory()->accepted()->create();

        $result = $this->service->sendInvites(collect([$accepted]));

        $this->assertEquals(0, $result['sent']);
        $this->assertEquals(1, $result['skipped']);
    }
}
