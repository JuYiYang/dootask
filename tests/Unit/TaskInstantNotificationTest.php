<?php
namespace Tests\Unit;

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\ProjectTaskMailController;
use App\Models\ProjectTask;
use App\Models\User;
use App\Module\TaskInstantNotification;
use App\Module\TaskMailNotification;
use App\Services\RequestContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TaskInstantNotificationTest extends TestCase
{
    private $dispatcher;
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->dispatcher = Model::getEventDispatcher(); Model::unsetEventDispatcher();
        (require base_path('database/migrations/2026_11_03_000001_create_task_notification_deliveries_table.php'))->up();
        Schema::create('users', function ($t) {
            $t->increments('userid'); $t->string('nickname'); $t->string('email')->default(''); $t->integer('bot')->default(0);
            $t->string('identity')->default('normal'); $t->timestamp('disable_at')->nullable();
        });
        Schema::create('project_users', function ($t) { $t->increments('id'); $t->integer('project_id'); $t->integer('userid'); $t->integer('owner')->default(0); });
        Schema::create('project_task_users', function ($t) { $t->increments('id'); $t->integer('project_id'); $t->integer('task_id'); $t->integer('task_pid'); $t->integer('userid'); $t->integer('owner')->default(0); });
        Schema::create('project_task_visibility_users', function ($t) { $t->increments('id'); $t->integer('task_id'); $t->integer('userid'); });
        Schema::create('web_socket_dialog_msgs', function ($t) { $t->increments('id'); $t->integer('dialog_id'); $t->integer('userid'); $t->integer('bot')->default(1); $t->string('type'); $t->text('msg'); $t->timestamps(); $t->softDeletes(); });
        foreach (range(1, 7) as $id) {
            DB::table('users')->insert(['userid' => $id, 'nickname' => '成员' . $id, 'email' => 'invalid-address', 'bot' => $id === 4 ? 1 : 0, 'disable_at' => $id === 3 ? now() : null]);
            if ($id !== 5) DB::table('project_users')->insert(['project_id' => 1, 'userid' => $id]);
            if ($id !== 6) DB::table('project_task_users')->insert(['project_id' => 1, 'task_id' => $id === 7 ? 11 : 10, 'task_pid' => 10, 'userid' => $id]);
        }
    }
    protected function tearDown(): void { Model::setEventDispatcher($this->dispatcher); RequestContext::clean(); parent::tearDown(); }
    private function task(int $visibility = 1, int $creator = 1): ProjectTask
    {
        return ProjectTask::createInstance(['id' => 10, 'name' => '测试任务', 'project_id' => 1, 'userid' => $creator, 'visibility' => $visibility, 'dialog_id' => 0, 'parent_id' => 0]);
    }
    private function sender(): User { return User::findOrFail(1); }
    private function record(int $userId, array $payload): array
    {
        DB::table('web_socket_dialog_msgs')->insert(['dialog_id' => 20 + $userId, 'userid' => 99, 'type' => 'template', 'msg' => json_encode($payload)]);
        return ['ret' => 1];
    }
    public function test_instant_candidates_do_not_require_email_and_exclude_sender_bots_disabled_and_nonmembers(): void
    {
        $this->assertSame([2, 7], array_column(TaskMailNotification::recipients($this->task(), 1, false), 'userid'));
        $this->assertSame([], TaskMailNotification::recipients($this->task(), 1));
    }
    public function test_private_task_excludes_unrelated_creator_without_visibility(): void
    {
        $task = $this->task(2, 6);
        $this->assertSame([2,7], array_column(TaskMailNotification::recipients($task, 1, false), 'userid'));
        DB::table('project_task_visibility_users')->insert(['task_id' => 10, 'userid' => 6]);
        $this->assertSame([2,6,7], array_column(TaskMailNotification::recipients($task, 1, false), 'userid'));
    }
    public function test_same_request_only_delivers_each_person_once_even_after_message_is_deleted(): void
    {
        $first = TaskInstantNotification::send($this->task(), $this->sender(), [2,7], str_repeat('a',32), fn($id,$msg) => $this->record($id,$msg));
        DB::table('web_socket_dialog_msgs')->update(['deleted_at' => now()]);
        $second = TaskInstantNotification::send($this->task(), $this->sender(), [2,7], str_repeat('a',32), fn() => $this->fail('Duplicate delivery'));
        $this->assertSame(['sent' => 2, 'failed' => 0, 'duplicates' => 0], $first);
        $this->assertSame(['sent' => 2, 'failed' => 0, 'duplicates' => 2], $second);
        $this->assertSame(2, DB::table('web_socket_dialog_msgs')->count());
    }
    public function test_new_explicit_request_can_remind_again_without_affecting_owner_notification_dedup(): void
    {
        foreach (['a','b'] as $key) TaskInstantNotification::send($this->task(), $this->sender(), [2], str_repeat($key,32), fn($id,$msg) => $this->record($id,$msg));
        $this->assertSame(2, DB::table('web_socket_dialog_msgs')->count());
        $payload = json_decode(DB::table('web_socket_dialog_msgs')->first()->msg, true);
        $this->assertSame('task_list', $payload['type']); $this->assertSame(10, $payload['list'][0]['id']);
    }
    public function test_failed_recipient_can_retry_without_duplicating_successful_recipient(): void
    {
        $first = TaskInstantNotification::send($this->task(), $this->sender(), [2,7], str_repeat('a',32), fn($id,$msg) => $id === 7 ? ['ret' => 0] : $this->record($id,$msg));
        $this->assertSame(1, $first['failed']);
        $second = TaskInstantNotification::send($this->task(), $this->sender(), [2,7], str_repeat('a',32), fn($id,$msg) => $this->record($id,$msg));
        $this->assertSame(['sent' => 2, 'failed' => 0, 'duplicates' => 1], $second);
        $this->assertSame(2, DB::table('web_socket_dialog_msgs')->count());
    }
    public function test_unrelated_and_duplicate_recipients_are_rejected_before_any_delivery(): void
    {
        foreach ([[5],[6],[2,2]] as $ids) {
            try { TaskInstantNotification::send($this->task(), $this->sender(), $ids, str_repeat('a',32), fn() => $this->fail('Invalid recipient delivered')); $this->fail('Invalid recipient accepted'); }
            catch (ApiException $e) { $this->assertSame('请选择有效的发送人', $e->getMessage()); }
        }
        $this->assertSame(0, DB::table('web_socket_dialog_msgs')->count());
    }
    public function test_request_id_is_required(): void
    {
        $this->expectException(ApiException::class);
        TaskInstantNotification::send($this->task(), $this->sender(), [2], '');
    }
    public function test_transport_is_explicitly_non_silent_with_bot_sender_and_commit_retry(): void
    {
        $args = TaskInstantNotification::messageArguments(22, 99, ['type' => 'task_list']);
        $this->assertSame('template', $args[2]); $this->assertSame(99, $args[4]);
        $this->assertTrue($args[5]); $this->assertTrue($args[6]); $this->assertFalse($args[7]); $this->assertTrue($args[9]);
    }
    public function test_candidate_endpoint_requires_login(): void
    {
        RequestContext::set('auth', false); $this->expectException(ApiException::class);
        (new ProjectTaskMailController())->notifyoptions();
    }
    public function test_manual_self_notification_is_allowed_and_deduplicated(): void
    {
        $this->assertSame([1,2,7], array_column(TaskMailNotification::recipients($this->task(), 1, false, true), 'userid'));
        $sent = TaskInstantNotification::send($this->task(), $this->sender(), [1], str_repeat('s',32), fn($id,$msg) => $this->record($id,$msg));
        $again = TaskInstantNotification::send($this->task(), $this->sender(), [1], str_repeat('s',32), fn()=> $this->fail('Duplicate self notification'));
        $this->assertSame(1,$sent['sent']);$this->assertSame(1,$again['duplicates']);
        $this->assertSame(1,DB::table('web_socket_dialog_msgs')->count());
    }
    public function test_manual_self_option_still_requires_membership_visibility_and_enabled_account(): void
    {
        DB::table('project_task_users')->where('userid',1)->delete();
        $this->assertContains(1, array_column(TaskMailNotification::recipients($this->task(), 1, false, true), 'userid'));
        $this->assertNotContains(1, array_column(TaskMailNotification::recipients($this->task(2,6), 1, false, true), 'userid'));
        DB::table('project_users')->where('userid',1)->delete();
        $this->assertNotContains(1, array_column(TaskMailNotification::recipients($this->task(), 1, false, true), 'userid'));
    }
    public function test_mail_can_be_sent_to_self_through_the_manual_channel(): void
    {
        Schema::create('settings',function($t){$t->increments('id');$t->string('name');$t->text('setting')->nullable();$t->timestamps();});
        Schema::create('projects',function($t){$t->increments('id');$t->string('name');$t->softDeletes();});
        Schema::create('project_task_contents',function($t){$t->increments('id');$t->integer('task_id');});
        DB::table('projects')->insert(['id'=>1,'name'=>'测试项目']);
        DB::table('settings')->insert(['name'=>'emailSetting','setting'=>json_encode(['smtp_server'=>'smtp.invalid','port'=>'587','account'=>'sender@example.com','password'=>'isolated-test','ignore_addr'=>''])]);
        DB::table('users')->where('userid',1)->update(['email'=>'self@example.com']);
        $this->assertSame([1],array_column(TaskMailNotification::recipients($this->task(),1,true,true),'userid'));
        $deliveries=[];
        $result=TaskMailNotification::send($this->task(),$this->sender(),[1],function($recipient,$subject,$html)use(&$deliveries){$deliveries[]=$recipient['userid'];$this->assertStringContainsString('测试任务',$html);});
        $this->assertSame(['sent'=>1,'failed'=>0],$result);$this->assertSame([1],$deliveries);
    }

    public function test_manual_transport_overrides_mute_without_changing_automatic_transport(): void
    {
        $job = (new \ReflectionClass(\App\Tasks\WebSocketDialogMsgTask::class))->newInstanceWithoutConstructor();
        $this->assertSame(['silence'=>true,'force_notify'=>false],$job->notificationOptions(true));
        $this->assertSame(['silence'=>false,'force_notify'=>false],$job->notificationOptions(false));
        $job->setForceNotify(true);
        $this->assertSame(['silence'=>false,'force_notify'=>true],$job->notificationOptions(true));
        $copy=unserialize(serialize($job));
        $this->assertSame(['silence'=>false,'force_notify'=>true],$copy->notificationOptions(true));
        $payload = \App\Models\WebSocketDialogMsg::createInstance(['type'=>'template','msg'=>json_encode(['type'=>'task_list','title'=>'任务提醒'])]);
        $this->assertSame('task_list',$payload->msg['type']);
    }

}
