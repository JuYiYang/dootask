<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectLog;
use App\Models\ProjectTask;
use App\Models\ProjectTaskUser;
use App\Models\ProjectUser;
use App\Models\User;
use App\Models\WebSocketDialogMsg;
use App\Module\Base;
use App\Module\TaskMailNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class TaskMailNotificationTest extends TestCase
{
    use DatabaseTransactions;

    private $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dispatcher = Model::getEventDispatcher();
        Model::unsetEventDispatcher();
    }

    protected function tearDown(): void
    {
        Model::setEventDispatcher($this->dispatcher);
        parent::tearDown();
    }

    private function user(string $name): User
    {
        $user = User::createInstance([
            'nickname' => $name,
            'email' => uniqid('mail_') . '@example.com',
            'identity' => ',normal,',
        ]);
        $user->save();
        return $user;
    }

    public function test_recipients_exclude_sender_and_non_members_and_mail_includes_recent_history(): void
    {
        $sender = $this->user('发送者');
        $owner = $this->user('负责人');
        $outsider = $this->user('已退出项目');
        $hiddenMember = $this->user('未获任务权限');
        $project = Project::createInstance([
            'name' => '通知项目', 'userid' => $sender->userid, 'personal' => 0,
        ]);
        $project->save();
        foreach ([$sender, $owner, $hiddenMember] as $person) {
            ProjectUser::createInstance([
                'project_id' => $project->id, 'userid' => $person->userid,
                'owner' => $person->userid === $sender->userid ? 1 : 0,
            ])->save();
        }
        $task = ProjectTask::createInstance([
            'project_id' => $project->id, 'parent_id' => 0,
            'name' => '<危险>通知任务', 'userid' => $sender->userid,
            'visibility' => 2, 'dialog_id' => 991234,
        ]);
        $task->save();
        ProjectTaskUser::createInstance([
            'project_id' => $project->id, 'task_id' => $task->id,
            'task_pid' => $task->id, 'userid' => $owner->userid, 'owner' => 1,
        ])->save();
        WebSocketDialogMsg::createInstance([
            'dialog_id' => $task->dialog_id, 'userid' => $outsider->userid,
            'type' => 'text', 'msg' => json_encode(['text' => '外部参与'], JSON_UNESCAPED_UNICODE),
        ])->save();
        WebSocketDialogMsg::createInstance([
            'dialog_id' => $task->dialog_id, 'userid' => $hiddenMember->userid,
            'type' => 'text', 'msg' => json_encode(['text' => '无权限成员'], JSON_UNESCAPED_UNICODE),
        ])->save();
        for ($i = 1; $i <= 51; $i++) {
            WebSocketDialogMsg::createInstance([
                'dialog_id' => $task->dialog_id, 'userid' => $owner->userid,
                'type' => 'text', 'msg' => json_encode(['text' => sprintf('历史消息%02d', $i)], JSON_UNESCAPED_UNICODE),
            ])->save();
            ProjectLog::createInstance([
                'project_id' => $project->id, 'task_id' => $task->id,
                'userid' => $owner->userid, 'detail' => sprintf('历史动态%02d', $i),
            ])->save();
        }
        WebSocketDialogMsg::createInstance([
            'dialog_id' => $task->dialog_id, 'userid' => $owner->userid,
            'type' => 'text', 'msg' => json_encode(['text' => '<b>进度已更新</b>'], JSON_UNESCAPED_UNICODE),
        ])->save();
        ProjectLog::createInstance([
            'project_id' => $project->id, 'task_id' => $task->id,
            'userid' => $owner->userid, 'detail' => '修改任务状态',
        ])->save();

        $candidates = TaskMailNotification::recipients($task, $sender->userid);
        $this->assertSame([$owner->userid], array_column($candidates, 'userid'));
        Base::setting('emailSetting', [
            'smtp_server' => 'smtp.example.com', 'port' => '587',
            'account' => 'sender@example.com', 'password' => 'unused',
        ], true);
        $sent = [];
        $result = TaskMailNotification::send($task, $sender, [$owner->userid],
            function ($recipient, $subject, $html) use (&$sent, $owner, $task) {
                $sent[] = $recipient['userid'];
                $this->assertSame($owner->userid, $recipient['userid']);
                $this->assertStringContainsString('任务通知：', $subject);
                $this->assertStringContainsString('进度已更新', $html);
                $this->assertStringContainsString('修改任务状态', $html);
                $this->assertStringNotContainsString('历史消息01', $html);
                $this->assertStringNotContainsString('历史动态01', $html);
                $this->assertStringContainsString('历史消息51', $html);
                $this->assertStringContainsString('历史动态51', $html);
                $this->assertStringContainsString('&lt;危险&gt;通知任务', $html);
                $this->assertStringContainsString('/single/task/' . $task->id, $html);
            });
        $this->assertSame(['sent' => 1, 'failed' => 0], $result);
        $this->assertSame([$owner->userid], $sent);
        $this->expectException(\InvalidArgumentException::class);
        TaskMailNotification::send($task, $sender, [$outsider->userid], static function () {});
    }
}
