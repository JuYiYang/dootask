<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Module\Base;
use App\Module\MeetingRoom;
use App\Services\RequestContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MeetingRoomTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        RequestContext::clean();
        Base::setting('meetingSetting', [
            'open' => 'open',
            'appid' => 'test-app',
            'api_key' => 'test-key',
            'api_secret' => 'test-secret',
        ]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        RequestContext::clean();
        parent::tearDown();
    }

    private function meeting(): Meeting
    {
        $meeting = Meeting::createInstance([
            'meetingid' => uniqid('test_'),
            'channel' => 'DooTask:test/' . uniqid(),
            'name' => 'Meeting room test',
            'userid' => 1,
        ]);
        $meeting->save();
        return $meeting;
    }

    public function test_empty_room_closes_without_waiting_ten_minutes_and_is_idempotent(): void
    {
        $meeting = $this->meeting();
        Http::fake(['api.sd-rtn.com/*' => Http::response([
            'success' => true, 'data' => ['channel_exist' => false],
        ])]);
        $this->assertTrue(MeetingRoom::afterLeave($meeting->id));
        $this->assertNotNull($meeting->fresh()->end_at);
        $this->assertTrue(MeetingRoom::afterLeave($meeting->id));
        Http::assertSentCount(1);
    }

    public function test_room_with_other_members_remains_open(): void
    {
        $meeting = $this->meeting();
        Http::fake(['api.sd-rtn.com/*' => Http::response([
            'success' => true, 'data' => ['channel_exist' => true],
        ])]);
        $this->assertFalse(MeetingRoom::afterLeave($meeting->id));
        $this->assertNull($meeting->fresh()->end_at);
    }

    public function test_failed_or_incomplete_channel_query_does_not_close_room(): void
    {
        $meeting = $this->meeting();
        Http::fake(['api.sd-rtn.com/*' => Http::sequence()
            ->push(['success' => false], 401)
            ->push(['success' => true, 'data' => []])]);
        $this->assertNull(MeetingRoom::checkAndClose($meeting->id));
        $this->assertNull(MeetingRoom::checkAndClose($meeting->id));
        $this->assertNull($meeting->fresh()->end_at);
    }

    public function test_join_during_channel_query_prevents_closing(): void
    {
        $meeting = $this->meeting();
        Http::fake(function () use ($meeting) {
            Meeting::whereId($meeting->id)->update(['updated_at' => now()->addSecond()]);
            return Http::response(['success' => true, 'data' => ['channel_exist' => false]]);
        });
        $this->assertFalse(MeetingRoom::checkAndClose($meeting->id));
        $this->assertNull($meeting->fresh()->end_at);
    }

    public function test_guest_can_report_leaving_only_the_shared_room(): void
    {
        $meeting = $this->meeting();
        Cache::put(Meeting::CACHE_KEY . '_test-share', [
            'meetingid' => $meeting->meetingid,
        ], 60);
        Http::fake(['api.sd-rtn.com/*' => Http::response([
            'success' => true, 'data' => ['channel_exist' => false],
        ])]);
        $this->postJson('/api/meeting/leave', [
            'meetingid' => 'other-room', 'sharekey' => 'test-share',
        ])->assertOk()->assertJsonPath('ret', 0);
        Http::assertNothingSent();
        $this->postJson('/api/meeting/leave', [
            'meetingid' => $meeting->meetingid, 'sharekey' => 'test-share',
        ])->assertOk()->assertJsonPath('ret', 1)->assertJsonPath('data.closed', true);
        $this->assertNotNull($meeting->fresh()->end_at);
    }

    public function test_missing_rest_credentials_cannot_close_room(): void
    {
        $meeting = $this->meeting();
        Base::setting('meetingSetting', ['open' => 'open', 'appid' => 'test-app']);
        $this->assertNull(MeetingRoom::checkAndClose($meeting->id));
        $this->assertNull($meeting->fresh()->end_at);
        Http::assertNothingSent();
    }
}
