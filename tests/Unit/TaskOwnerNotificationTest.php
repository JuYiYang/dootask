<?php

namespace Tests\Unit;

use App\Models\ProjectTask;
use App\Models\ProjectTaskPushLog;
use App\Models\ProjectTaskUser;
use App\Module\TaskOwnerNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TaskOwnerNotificationTest extends TestCase
{
    private $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();
        // This suite is run against a disposable in-memory SQLite database only.
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->dispatcher = Model::getEventDispatcher();
        Model::unsetEventDispatcher();
        Schema::create('project_tasks', function ($t) { $t->increments('id'); $t->timestamps(); $t->softDeletes(); });
        Schema::create('project_task_users', function ($t) {
            $t->increments('id'); $t->integer('task_id'); $t->integer('userid'); $t->integer('owner'); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('project_task_push_logs', function ($t) {
            $t->increments('id'); $t->integer('task_id'); $t->integer('userid'); $t->integer('type'); $t->timestamps(); $t->softDeletes();
        });
        DB::table('project_tasks')->insert([['id' => 10], ['id' => 20]]);
        DB::table('project_task_users')->insert([
            ['task_id' => 10, 'userid' => 1, 'owner' => 1], ['task_id' => 10, 'userid' => 2, 'owner' => 1],
            ['task_id' => 20, 'userid' => 1, 'owner' => 1],
        ]);
    }

    protected function tearDown(): void
    {
        Model::setEventDispatcher($this->dispatcher);
        parent::tearDown();
    }

    public function test_same_task_same_owner_is_not_notified_twice(): void
    {
        $calls = 0;
        $deliver = function () use (&$calls) { $calls++; return true; };
        $this->assertTrue(TaskOwnerNotification::once(10, 1, $deliver));
        $this->assertFalse(TaskOwnerNotification::once(10, 1, $deliver));
        $this->assertSame(1, $calls);
        $this->assertSame(1, ProjectTaskPushLog::whereType(4)->count());
    }

    public function test_other_owners_and_tasks_are_independent(): void
    {
        $this->assertTrue(TaskOwnerNotification::once(10, 1, fn () => true));
        $this->assertTrue(TaskOwnerNotification::once(10, 2, fn () => true));
        $this->assertTrue(TaskOwnerNotification::once(20, 1, fn () => true));
        $this->assertSame(3, ProjectTaskPushLog::whereType(4)->count());
    }

    public function test_reassignment_and_soft_deleted_notification_logs_do_not_repeat(): void
    {
        TaskOwnerNotification::once(10, 1, fn () => true);
        ProjectTaskUser::whereTaskId(10)->whereUserid(1)->update(['owner' => 0]);
        ProjectTaskUser::whereTaskId(10)->whereUserid(1)->update(['owner' => 1]);
        ProjectTaskPushLog::whereType(4)->delete();
        $this->assertFalse(TaskOwnerNotification::once(10, 1, fn () => $this->fail('Repeated owner notification')));
    }

    public function test_assistant_promoted_to_owner_is_checked_from_database(): void
    {
        $task = ProjectTask::find(10);
        ProjectTaskUser::whereTaskId(10)->whereUserid(2)->update(['owner' => 0]);
        $task->load('taskUser');
        $this->assertFalse(TaskOwnerNotification::once(10, 2, fn () => $this->fail('Assistant must not be notified as owner')));
        ProjectTaskUser::whereTaskId(10)->whereUserid(2)->update(['owner' => 1]);
        ProjectTaskPushLog::createInstance(['task_id' => 10, 'userid' => 2, 'type' => 0])->save();
        $this->assertTrue(TaskOwnerNotification::once(10, 2, fn () => true));
    }

    public function test_deleted_task_and_removed_owner_do_not_receive_notifications(): void
    {
        ProjectTask::whereKey(10)->delete();
        $this->assertFalse(TaskOwnerNotification::once(10, 1, fn () => $this->fail('Deleted task notified')));
        ProjectTaskUser::whereTaskId(20)->whereUserid(1)->delete();
        $this->assertFalse(TaskOwnerNotification::once(20, 1, fn () => $this->fail('Removed owner notified')));
    }

    public function test_failed_delivery_does_not_consume_notification_and_can_retry(): void
    {
        $this->assertFalse(TaskOwnerNotification::once(10, 1, fn () => false));
        $this->assertSame(0, ProjectTaskPushLog::whereType(4)->count());
        $this->assertTrue(TaskOwnerNotification::once(10, 1, fn () => true));
    }

    public function test_delivery_exception_rolls_back_and_can_retry(): void
    {
        try {
            TaskOwnerNotification::once(10, 1, function () {
                ProjectTaskPushLog::createInstance(['task_id' => 10, 'userid' => 1, 'type' => 4])->save();
                throw new \RuntimeException('transport failed');
            });
            $this->fail('Exception was swallowed');
        } catch (\App\Exceptions\ApiException $e) {
            $this->assertSame('transport failed', $e->getMessage());
        }
        $this->assertSame(0, ProjectTaskPushLog::whereType(4)->count());
        $this->assertTrue(TaskOwnerNotification::once(10, 1, fn () => true));
    }
    public function test_transport_is_non_silent_from_bot_and_includes_self_and_commit_retry(): void
    {
        $task = ProjectTask::createInstance(['id' => 10, 'parent_id' => 0, 'name' => 'Owner test']);
        $args = TaskOwnerNotification::messageArguments($task, 50, 99);
        $this->assertSame('template', $args[2]);
        $this->assertSame('您有一个新任务', $args[3]['title']);
        $this->assertSame(10, $args[3]['list'][0]['id']);
        $this->assertSame(99, $args[4], 'Bot sender avoids self-message suppression');
        $this->assertTrue($args[5], 'Self-assignment must reach the initiating connection too');
        $this->assertTrue($args[6], 'Async delivery retries when the message transaction has not committed');
        $this->assertFalse($args[7], 'Explicit false overrides silent template defaults');
        $task->parent_id = 8;
        $this->assertSame(8, TaskOwnerNotification::messageArguments($task, 50, 99)[3]['list'][0]['id']);
    }

}
