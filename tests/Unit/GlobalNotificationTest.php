<?php

namespace Tests\Unit;

use App\Exceptions\ApiException;
use App\Module\GlobalNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class GlobalNotificationTest extends TestCase
{
    private $events;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->events = Model::getEventDispatcher();
        Model::unsetEventDispatcher();
        (require base_path('database/migrations/2026_11_04_000001_create_global_notifications.php'))->up();
        Schema::create('users', function ($table) {
            $table->id('userid');
            $table->string('email');
            $table->string('nickname');
            $table->boolean('bot')->default(false);
            $table->timestamp('disable_at')->nullable();
        });
        Schema::create('task_workers', function ($table) {
            $table->id();
            $table->text('args');
            $table->timestamps();
        });
        foreach ([1, 2, 3] as $id) {
            DB::table('users')->insert([
                'userid' => $id, 'nickname' => "User {$id}", 'email' => "user{$id}@example.test",
                'disable_at' => $id === 3 ? now() : null,
            ]);
        }
    }

    protected function tearDown(): void
    {
        Model::setEventDispatcher($this->events);
        parent::tearDown();
    }

    public function test_self_and_other_accounts_are_deduplicated_and_queue_failure_is_per_channel(): void
    {
        $data = $this->request([2, 1, 2]);
        $result = GlobalNotification::send(1, $data);
        self::assertSame([1, 2], array_column($result['recipients'], 'userid'));
        foreach ($result['recipients'] as $recipient) {
            self::assertSame(['browser', 'desktop', 'app'], array_keys($recipient['channels']));
            foreach ($recipient['channels'] as $channel) {
                self::assertSame(['status' => 'failed', 'reason' => 'queue_failed'], $channel);
            }
        }
        self::assertSame($result, GlobalNotification::send(1, $this->request([1, 2])));
        self::assertSame(1, DB::table('global_notifications')->count());
        self::assertSame(2, DB::table('global_notification_deliveries')->count());

        $this->expectException(ApiException::class);
        GlobalNotification::send(1, $this->request([1]));
    }

    public function test_invalid_recipient_rolls_back_the_entire_batch(): void
    {
        try {
            GlobalNotification::send(1, $this->request([1, 3]));
            self::fail('Disabled account was accepted');
        } catch (ApiException $e) {
            self::assertSame('收件人无效或已停用', $e->getMessage());
        }
        self::assertSame(0, DB::table('global_notifications')->count());
        self::assertSame(0, DB::table('global_notification_deliveries')->count());
    }

    public function test_receipt_requires_the_recipient_and_client_key_and_preserves_other_channel_results(): void
    {
        $data = $this->request([2]);
        GlobalNotification::send(1, $data);
        $delivery = DB::table('global_notification_deliveries')->first();
        $key = str_repeat('a', 32);
        DB::table('global_notification_deliveries')->where('id', $delivery->id)->update([
            'channels' => json_encode([
                'app' => ['status' => 'accepted', 'reason' => ''],
                'desktop' => ['status' => 'offline', 'reason' => 'no_online_client'],
            ]),
            'clients' => json_encode([
                hash('sha256', $key) => ['platform' => 'browser', 'result' => ['status' => 'waiting', 'reason' => '']],
            ]),
        ]);
        $receipt = ['delivery_id' => $delivery->id, 'client_key' => $key, 'status' => 'triggered'];
        self::assertFalse(GlobalNotification::receipt(1, $receipt));
        self::assertFalse(GlobalNotification::receipt(2, array_replace($receipt, ['client_key' => str_repeat('b', 32)])));
        self::assertTrue(GlobalNotification::receipt(2, $receipt));
        self::assertTrue(GlobalNotification::receipt(2, array_replace($receipt, ['status' => 'received'])));
        $channels = GlobalNotification::status(1, $data['request_id'])['recipients'][0]['channels'];
        self::assertSame('triggered', $channels['browser']['status']);
        self::assertSame('offline', $channels['desktop']['status']);
        self::assertSame('accepted', $channels['app']['status']);
        self::assertArrayNotHasKey('client_key', $channels['browser']['devices'][0]);

        $this->expectException(ApiException::class);
        GlobalNotification::status(2, $data['request_id']);
    }

    private function request(array $recipients): array
    {
        return [
            'request_id' => str_repeat('r', 32), 'title' => '测试标题',
            'body' => "纯文本 <em>原样</em>\n第二行", 'recipients' => $recipients,
        ];
    }
}
