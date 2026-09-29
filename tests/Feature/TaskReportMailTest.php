<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectTaskUser;
use App\Models\ProjectUser;
use App\Models\User;
use App\Module\Base;
use App\Module\TaskReportMail;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TaskReportMailTest extends TestCase
{
    use DatabaseTransactions;

    private $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        $this->dispatcher = Model::getEventDispatcher();
        Model::unsetEventDispatcher();
    }

    protected function tearDown(): void
    {
        Model::setEventDispatcher($this->dispatcher);
        parent::tearDown();
    }

    private function user(): User
    {
        $user = User::createInstance([
            'email' => uniqid('report_') . '@example.com',
            'nickname' => '汇报用户',
            'identity' => ',normal,',
        ]);
        $user->save();
        return $user;
    }

    private function task(User $user, string $name, int $owner, ?string $endAt = null): ProjectTask
    {
        $project = Project::createInstance([
            'name' => '汇报项目 ' . uniqid(), 'userid' => $user->userid, 'personal' => 0,
        ]);
        $project->save();
        ProjectUser::createInstance([
            'project_id' => $project->id, 'userid' => $user->userid, 'owner' => 1,
        ])->save();
        $task = ProjectTask::createInstance([
            'project_id' => $project->id, 'parent_id' => 0, 'name' => $name,
            'userid' => $user->userid, 'visibility' => 1, 'end_at' => $endAt,
        ]);
        $task->save();
        ProjectTaskUser::createInstance([
            'project_id' => $project->id, 'task_id' => $task->id, 'task_pid' => $task->id,
            'userid' => $user->userid, 'owner' => $owner,
        ])->save();
        return $task;
    }

    public function test_schedule_and_scope(): void
    {
        $monday = Carbon::parse('2026-09-28 09:01:00');
        $setting = ['task_report_enabled' => 'open', 'task_report_time' => '09:00',
            'task_report_days' => 'weekdays'];
        $this->assertTrue(TaskReportMail::due($setting, $monday));
        $this->assertFalse(TaskReportMail::due($setting, $monday->copy()->setTime(8, 59)));
        $this->assertFalse(TaskReportMail::due($setting, $monday->copy()->next(Carbon::SATURDAY)));

        $user = $this->user();
        $overdue = $this->task($user, '逾期', 1, '2026-09-27 18:00:00');
        $today = $this->task($user, '今日', 0, '2026-09-28 18:00:00');
        $future = $this->task($user, '未来', 1, '2026-09-29 18:00:00');
        $done = $this->task($user, '完成', 1);
        $done->complete_at = $monday;
        $done->save();

        $all = TaskReportMail::tasksForUser($user->userid, 'all', $monday);
        $this->assertEqualsCanonicalizing([$overdue->id, $today->id, $future->id],
            array_column($all, 'id'));
        $due = TaskReportMail::tasksForUser($user->userid, 'due', $monday);
        $this->assertEqualsCanonicalizing([$overdue->id, $today->id], array_column($due, 'id'));
    }

    public function test_sends_once_per_account_per_day(): void
    {
        $user = $this->user();
        $this->task($user, '待办事项', 1);
        Base::setting('emailSetting', [
            'smtp_server' => 'smtp.example.com', 'port' => '587',
            'account' => 'sender@example.com', 'password' => 'unused',
            'task_report_enabled' => 'open', 'task_report_time' => '09:00',
            'task_report_days' => 'daily', 'task_report_scope' => 'all',
        ], true);
        $sent = [];
        $sender = function ($recipient, $subject, $html) use (&$sent, $user) {
            if ($recipient->userid === $user->userid) {
                $sent[] = $recipient->userid;
                $this->assertStringContainsString('待办事项', $html);
                $this->assertStringContainsString('任务汇报', $subject);
                $this->assertStringContainsString('<!doctype html>', $html);
                $this->assertStringContainsString('role="presentation"', $html);
                $this->assertStringContainsString('未完成', $html);
            }
        };
        $now = Carbon::parse('2026-09-28 09:01:00');
        $this->assertTrue(TaskReportMail::due(Base::setting('emailSetting'), $now));
        $this->assertNotEmpty(TaskReportMail::tasksForUser($user->userid, 'all', $now));
        $first = TaskReportMail::run($now, $sender);
        $this->assertGreaterThanOrEqual(1, $first['sent']);
        $this->assertSame(0, $first['failed']);
        TaskReportMail::run($now->copy()->addHour(), $sender);
        $this->assertSame([$user->userid], $sent);
        $this->assertSame(1, DB::table('task_report_mail_deliveries')
            ->where('userid', $user->userid)->count());
    }

    public function test_rich_email_escapes_task_content(): void
    {
        $groups = [
            'overdue' => ['title' => '逾期任务', 'color' => '#d95745', 'background' => '#fff1ed',
                'tasks' => [['id' => 42, 'project' => '测试项目', 'name' => '<script>alert(1)</script>',
                    'role' => '负责人', 'status' => '待测试', 'due' => '2026-09-27 18:00']]],
            'today' => ['title' => '今日到期', 'color' => '#b77919', 'background' => '#fff6df', 'tasks' => []],
            'other' => ['title' => '其他未完成任务', 'color' => '#286f69', 'background' => '#eaf8f4', 'tasks' => []],
        ];
        $html = view('email.task-report', [
            'systemName' => 'DooTask', 'userName' => '测试用户',
            'date' => '2026年09月28日', 'total' => 1, 'groups' => $groups,
        ])->render();
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('已逾期', $html);
    }
}
