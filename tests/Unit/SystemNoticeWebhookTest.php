<?php

namespace Tests\Unit;

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\SystemNoticeController;
use App\Models\Setting;
use App\Module\SystemNoticeWebhook;
use App\Services\RequestContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SystemNoticeWebhookTest extends TestCase
{
    private $dispatcher;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->dispatcher = Model::getEventDispatcher();
        Model::unsetEventDispatcher();
        Schema::create('settings', function ($t) {
            $t->increments('id'); $t->string('name'); $t->text('setting')->nullable(); $t->timestamps();
        });
        Schema::create('web_socket_dialogs', function ($t) {
            $t->increments('id'); $t->string('type'); $t->string('group_type')->nullable(); $t->string('name'); $t->integer('session_id')->default(0); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('web_socket_dialog_msgs', function ($t) {
            $t->increments('id'); $t->string('type'); $t->integer('dialog_id'); $t->text('msg'); $t->timestamps(); $t->softDeletes();
            foreach (['session_id', 'reply_id', 'forward_id', 'userid', 'link', 'bot', 'read', 'send'] as $field) $t->integer($field)->default(0);
            $t->string('dialog_type')->nullable(); $t->string('mtype')->nullable(); $t->text('key')->nullable();
        });
        Schema::create('users', function ($t) {
            $t->increments('userid'); $t->string('email'); $t->integer('bot')->default(0); $t->string('identity')->default('normal'); $t->timestamp('disable_at')->nullable(); $t->timestamps();
        });
        Schema::create('web_socket_dialog_users', function ($t) {
            $t->increments('id'); $t->integer('dialog_id'); $t->integer('userid'); $t->integer('hide')->default(0); $t->timestamp('last_at')->nullable(); $t->timestamps();
        });
        Schema::create('task_workers', function ($t) {
            $t->increments('id'); $t->text('args'); $t->timestamps();
        });
        RequestContext::set('setting_system', ['all_group_mute' => 'open']);
        $this->token = 'snw_' . str_repeat('a', 64);
        RequestContext::set('setting_systemNoticeWebhook', ['token_hash' => hash('sha256', $this->token)]);
    }

    protected function tearDown(): void
    {
        Model::setEventDispatcher($this->dispatcher);
        RequestContext::clean();
        parent::tearDown();
    }

    private function input(array $overrides = []): array
    {
        return array_merge(['event_id' => 'release:20261026.1', 'title' => '更新通知',
            'content' => '<p style="color: #c00"><strong>准备更新</strong></p>',
            'detail' => '<h2>安排</h2><ul><li>先保存</li></ul><table><tr><td>10 分钟</td></tr></table>'], $overrides);
    }

    public function test_richtext_card_and_detail_keep_their_structure(): void
    {
        $payload = SystemNoticeWebhook::payload($this->input());
        $this->assertSame('system_notice', $payload['type']);
        $this->assertStringContainsString('<strong>', $payload['content']);
        $this->assertStringContainsString('color:', $payload['content']);
        $this->assertStringContainsString('<table>', $payload['detail']);
        $this->assertStringContainsString('<li>', $payload['detail']);
        $this->assertSame('更新通知', $payload['title_raw']);
    }

    public function test_scripts_handlers_dangerous_urls_and_css_are_removed(): void
    {
        $html = '<p onclick="alert(1)" style="position:fixed;background-image:url(javascript:alert(1))">内容</p>'
            . '<script>alert(1)</script><iframe src="https://example.com"></iframe>'
            . '<img src="x" onerror="alert(1)"><a href="javascript:alert(1)">危险链接</a>'
            . '<a href="https://example.com/news">查看公告</a>';
        $payload = SystemNoticeWebhook::payload($this->input(['content' => $html, 'detail' => $html]));
        foreach (['content', 'detail'] as $field) {
            foreach (['<script', '<iframe', 'onclick', 'onerror', 'javascript:', 'position:'] as $danger) {
                $this->assertStringNotContainsString($danger, $payload[$field]);
            }
            $this->assertStringContainsString('https://example.com/news', $payload[$field]);
            $this->assertStringContainsString('target="_blank"', $payload[$field]);
        }
    }

    public function test_empty_or_oversized_or_invalid_payloads_are_rejected(): void
    {
        $inputs = [
            ['event_id' => '../path'], ['event_id' => ''], ['event_id' => str_repeat('a', 101)],
            ['title' => str_repeat('字', 101)], ['content' => str_repeat('字', 10001)],
            ['detail' => str_repeat('字', 50001)], ['content' => []], ['detail' => '<script>alert(1)</script>'],
            ['title' => '<b></b>'], ['content' => '<p>&nbsp;</p>'],
        ];
        foreach ($inputs as $input) {
            try {
                SystemNoticeWebhook::payload($this->input($input));
                $this->fail('Invalid payload was accepted');
            } catch (ApiException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function test_only_the_separate_active_webhook_key_is_accepted(): void
    {
        $settings = ['token_hash' => hash('sha256', $this->token)];
        SystemNoticeWebhook::authorize('Bearer ' . $this->token, $settings);
        $this->assertTrue(true);
        foreach (['', 'Bearer dai_' . str_repeat('a', 64), 'Bearer ' . 'snw_' . str_repeat('b', 64), $this->token] as $header) {
            try {
                SystemNoticeWebhook::authorize($header, $settings);
                $this->fail('Invalid authorization accepted');
            } catch (ApiException $e) {
                $this->assertSame('通知 Webhook 密钥无效或已停用', $e->getMessage());
            }
        }
        $this->expectException(ApiException::class);
        SystemNoticeWebhook::authorize('Bearer ' . $this->token, []);
    }

    public function test_retries_find_the_existing_message_even_when_deleted(): void
    {
        DB::table('web_socket_dialogs')->insert(['id' => 24, 'type' => 'group', 'group_type' => 'all', 'name' => '全体成员']);
        DB::table('web_socket_dialog_msgs')->insert(['id' => 80, 'type' => 'template', 'dialog_id' => 24,
            'msg' => json_encode(SystemNoticeWebhook::payload($this->input())), 'deleted_at' => now()]);
        $result = SystemNoticeWebhook::send('Bearer ' . $this->token, $this->input(['dialog_id' => 999, 'userid' => 999]));
        $this->assertSame(['message_id' => 80, 'dialog_id' => 24, 'duplicate' => true], $result);
        $this->assertSame(1, DB::table('web_socket_dialog_msgs')->count());
    }

    public function test_missing_all_members_group_does_not_fall_back_to_another_group(): void
    {
        DB::table('web_socket_dialogs')->insert(['id' => 99, 'type' => 'group', 'group_type' => 'user', 'name' => '普通群']);
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('全体成员群聊不存在，无法发送系统通知');
        SystemNoticeWebhook::send('Bearer ' . $this->token, $this->input(['dialog_id' => 99]));
    }

    public function test_rotating_key_only_stores_hash_and_revocation_invalidates_it(): void
    {
        $result = SystemNoticeWebhook::regenerate();
        $saved = Setting::whereName('systemNoticeWebhook')->first()->setting;
        $this->assertSame(['token_hash' => hash('sha256', $result['token'])], $saved);
        $settings = SystemNoticeWebhook::settings();
        $this->assertTrue($settings['enabled']);
        $this->assertArrayNotHasKey('token', $settings);
        $this->assertArrayNotHasKey('token_hash', $settings);
        SystemNoticeWebhook::revoke();
        $this->assertFalse(SystemNoticeWebhook::settings()['enabled']);
        $this->expectException(ApiException::class);
        SystemNoticeWebhook::authorize('Bearer ' . $result['token'], Setting::whereName('systemNoticeWebhook')->first()->setting);
    }

    public function test_new_notification_is_stored_as_system_bot_card_and_retries_do_not_resend(): void
    {
        $this->assertFalse(app()->bound('swoole'), 'Tests must not use a live Swoole runtime');
        DB::table('web_socket_dialogs')->insert(['id' => 24, 'type' => 'group', 'group_type' => 'all', 'name' => '全体成员']);
        DB::table('web_socket_dialogs')->insert(['id' => 99, 'type' => 'group', 'group_type' => 'user', 'name' => '普通群']);
        DB::table('users')->insert(['userid' => 9, 'email' => 'system-msg@bot.system', 'bot' => 1]);
        DB::table('web_socket_dialog_users')->insert(['dialog_id' => 24, 'userid' => 1]);
        $fake = SystemNoticeWebhook::payload($this->input());
        $fake['type'] = 'content';
        DB::table('web_socket_dialog_msgs')->insert(['type' => 'template', 'dialog_id' => 99, 'msg' => json_encode($fake)]);
        $first = SystemNoticeWebhook::send('Bearer ' . $this->token, $this->input(['dialog_id' => 99]));
        $this->assertFalse($first['duplicate']);
        $this->assertSame(24, $first['dialog_id']);
        $row = DB::table('web_socket_dialog_msgs')->where('dialog_id', 24)->first();
        $this->assertSame(9, $row->userid);
        $this->assertSame('template', $row->type);
        $msg = json_decode($row->msg, true);
        $this->assertSame('system_notice', $msg['type']);
        $this->assertStringContainsString('<strong>', $msg['content']);
        $this->assertStringContainsString('<table>', $msg['detail']);
        $second = SystemNoticeWebhook::send('Bearer ' . $this->token, $this->input());
        $this->assertTrue($second['duplicate']);
        $this->assertSame($first['message_id'], $second['message_id']);
        $this->assertSame(2, DB::table('web_socket_dialog_msgs')->count());
        $this->assertSame(1, DB::table('task_workers')->count());
        $this->assertSame(1, DB::table('web_socket_dialog_users')->count());
    }

    public function test_management_endpoints_require_an_admin_session(): void
    {
        RequestContext::set('auth', false);
        $controller = new SystemNoticeController();
        foreach (['settings', 'regenerate', 'revoke'] as $method) {
            try {
                $controller->$method();
                $this->fail('Unauthenticated key management accepted');
            } catch (ApiException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $normal = \App\Models\User::createInstance(['userid' => 1, 'identity' => 'normal']);
        RequestContext::set('auth', $normal);
        foreach (['settings', 'regenerate', 'revoke'] as $method) {
            try {
                $controller->$method();
                $this->fail('Non-admin key management accepted');
            } catch (ApiException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $this->assertSame(0, DB::table('settings')->count());
    }
}
