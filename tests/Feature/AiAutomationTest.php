<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectTaskUser;
use App\Models\ProjectUser;
use App\Models\User;
use App\Module\AiAutomation;
use App\Module\AiAutomationModel;
use App\Module\AiAutomationSettings;
use App\Module\Base;
use App\Services\RequestContext;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiAutomationTest extends TestCase
{
    use DatabaseTransactions;

    private $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();
        RequestContext::clean();
        config(['cache.default' => 'array']);
        $this->dispatcher = Model::getEventDispatcher();
        Model::unsetEventDispatcher();
        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse('2026-09-30 10:10:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        RequestContext::clean();
        Model::setEventDispatcher($this->dispatcher);
        parent::tearDown();
    }

    private function user(): User
    {
        $user = User::createInstance(['email' => uniqid('automation') . '@example.com', 'nickname' => 'Test', 'identity' => ',normal,']);
        $user->save();
        return $user;
    }

    private function task(User $user, int $owner = 1): ProjectTask
    {
        $project = Project::createInstance(['name' => 'Automation project', 'userid' => $user->userid, 'personal' => 0]);
        $project->save();
        ProjectUser::createInstance(['project_id' => $project->id, 'userid' => $user->userid, 'owner' => 1])->save();
        $task = ProjectTask::createInstance(['project_id' => $project->id, 'parent_id' => 0, 'name' => 'Task', 'userid' => $user->userid,
            'end_at' => now()->subDay(), 'updated_at' => now()->subDays(5)]);
        $task->save();
        ProjectTaskUser::createInstance(['project_id' => $project->id, 'task_id' => $task->id, 'task_pid' => $task->id,
            'userid' => $user->userid, 'owner' => $owner])->save();
        return $task;
    }

    private function settings(): array
    {
        return array_replace(AiAutomationSettings::defaults(), ['base_url' => 'https://model.example/v1', 'api_key' => 'test-secret', 'model' => 'test-model']);
    }

    public function test_disabled_automation_never_calls_model_or_sends(): void
    {
        Base::setting('aiAutomation', AiAutomationSettings::defaults());
        AiAutomation::run(fn() => $this->fail('Unexpected message'));
        Http::assertNothingSent();
    }

    public function test_schedule_has_one_hour_window_and_handles_midnight(): void
    {
        $settings = array_replace($this->settings(), ['weekly_enabled' => true, 'weekly_day' => 3, 'weekly_time' => '23:30']);
        $this->assertTrue(AiAutomation::weeklyDue($settings, Carbon::parse('2026-09-30 23:40')));
        $this->assertFalse(AiAutomation::weeklyDue($settings, Carbon::parse('2026-09-30 22:40')));
        $this->assertFalse(AiAutomation::weeklyDue($settings, Carbon::parse('2026-09-30 10:40')));
    }

    public function test_secret_is_redacted_and_blank_preserves_it(): void
    {
        Base::setting('aiAutomation', $this->settings());
        $public = AiAutomationSettings::publicData();
        $this->assertSame('', $public['api_key']);
        $this->assertTrue($public['api_key_configured']);
        $validated = AiAutomationSettings::validate($public, $this->settings());
        $this->assertSame('test-secret', $validated['api_key']);
    }

    public function test_empty_form_strings_normalized_by_middleware_can_be_saved(): void
    {
        $data = AiAutomationSettings::validate(['base_url' => null, 'api_key' => null, 'model' => null], AiAutomationSettings::defaults());
        $this->assertSame('', $data['base_url']);
        $this->assertSame('', $data['api_key']);
        $this->assertFalse($data['weekly_enabled']);
        $this->assertFalse($data['remind_enabled']);
        $this->assertSame('test-secret', AiAutomationSettings::validate(['api_key' => null], $this->settings())['api_key']);
    }

    public function test_enabling_requires_explicit_project_and_recipient_scope(): void
    {
        $this->expectException(ApiException::class);
        AiAutomationSettings::validate(['weekly_enabled' => true], $this->settings());
    }

    public function test_completed_canceled_and_recently_updated_tasks_are_not_chased(): void
    {
        $task = $this->task($this->user());
        $this->assertSame('任务已逾期', AiAutomation::reminderReason($this->settings(), $task, now(), null));
        $task->complete_at = now();
        $this->assertNull(AiAutomation::reminderReason($this->settings(), $task, now(), null));
        $task->complete_at = null;
        $task->flow_item_name = '已取消';
        $this->assertNull(AiAutomation::reminderReason($this->settings(), $task, now(), null));
        $task->flow_item_name = '进行中';
        $task->end_at = null;
        $task->updated_at = now()->subDays(5);
        $this->assertSame('任务长期没有更新', AiAutomation::reminderReason($this->settings(), $task, now(), null));
        $this->assertNull(AiAutomation::reminderReason($this->settings(), $task, now(), now()->toDateTimeString()));
    }

    public function test_weekly_context_respects_assignment_membership_and_project_scope(): void
    {
        $user = $this->user();
        $owned = $this->task($user);
        $assisted = $this->task($user, 0);
        $other = $this->task($this->user());
        $settings = array_replace($this->settings(), ['project_ids' => [$owned->project_id, $assisted->project_id, $other->project_id]]);
        $context = AiAutomation::weeklyContext($settings, $user, now());
        $this->assertEqualsCanonicalizing([$owned->id, $assisted->id], array_column($context['tasks'], 'id'));
        $this->assertSame(rtrim((string)config('dootask.task_report_base_url'), '/') . '/single/task/' . $owned->id, $context['tasks'][0]['url']);
        ProjectUser::whereProjectId($assisted->project_id)->whereUserid($user->userid)->delete();
        $this->assertSame([$owned->id], array_column(AiAutomation::weeklyContext($settings, $user, now())['tasks'], 'id'));
    }

    public function test_weekly_summary_separates_completed_work_from_existing_backlog(): void
    {
        $user = $this->user();
        $completed = $this->task($user);
        $completed->complete_at = now()->subDay();
        $completed->save();
        $oldCompleted = $this->task($user);
        $oldCompleted->complete_at = now()->subDays(8);
        $oldCompleted->save();
        $waiting = $this->task($user, 0);
        $waiting->flow_item_name = '待测试';
        $waiting->created_at = now()->subMonth();
        $waiting->save();
        $settings = array_replace($this->settings(), ['project_ids' => [$completed->project_id, $oldCompleted->project_id, $waiting->project_id]]);
        $context = AiAutomation::weeklyContext($settings, $user, now());
        $this->assertSame(2, $context['total']);
        $this->assertSame(1, array_sum(array_column($context['projects'], 'completed_this_week')));
        $this->assertSame(1, array_sum(array_column($context['projects'], 'current_open')));
        $this->assertSame(1, array_sum(array_column($context['projects'], 'overdue_open')));
        // 项目同名仍按ID分别聚合，完成项不进入未完成状态统计。
        $statuses = collect($context['projects'])->pluck('open_by_status')->flatten(1)->all();
        $this->assertSame([['status' => '待测试', 'count' => 1]], $statuses);
        $this->assertNotContains($oldCompleted->id, array_column($context['tasks'], 'id'));
    }

    public function test_model_uses_configured_endpoint_and_does_not_expose_error_body(): void
    {
        Http::fake(['model.example/*' => Http::response(['choices' => [['message' => ['content' => 'Progress?']]]])]);
        $this->assertSame('Progress?', AiAutomationModel::generate($this->settings(), 'Summarize', ['name' => 'Task']));
        Http::assertSent(fn($request) => $request->url() === 'https://model.example/v1/chat/completions'
            && $request['model'] === 'test-model' && $request->hasHeader('Authorization', 'Bearer test-secret'));
    }

    public function test_failed_model_response_has_safe_error(): void
    {
        Http::fake(['model.example/*' => Http::response(['error' => 'secret-server-error'], 401)]);
        $this->expectExceptionMessage('AI 生成失败，请检查模型配置');
        AiAutomationModel::generate($this->settings(), 'Summarize', []);
    }

    public function test_duplicate_weekly_run_generates_and_delivers_only_once(): void
    {
        $user = $this->user();
        $task = $this->task($user);
        Base::setting('aiAutomation', array_replace($this->settings(), ['weekly_enabled' => true, 'weekly_day' => 3,
            'weekly_time' => '10:00', 'weekly_user_ids' => [$user->userid], 'project_ids' => [$task->project_id]]));
        Http::fake(['model.example/*' => Http::response(['choices' => [['message' => ['content' => 'Weekly summary']]]])]);
        $sent = 0;
        $sender = function () use (&$sent) { $sent++; return Base::retSuccess('success', (object)['id' => 123]); };
        AiAutomation::run($sender);
        AiAutomation::run($sender);
        $this->assertSame(1, $sent);
        Http::assertSentCount(1);
        $this->assertSame('sent', DB::table('ai_automation_deliveries')->where('kind', 'weekly')->where('target_id', $user->userid)->value('status'));
    }

    public function test_non_admin_cannot_read_settings(): void
    {
        RequestContext::set('auth', $this->user());
        $this->expectException(ApiException::class);
        (new \App\Http\Controllers\Api\AiAutomationController())->settings();
    }

    public function test_disabled_during_model_generation_does_not_send(): void
    {
        $task = $this->task($this->user());
        $settings = array_replace($this->settings(), ['remind_enabled' => true, 'project_ids' => [$task->project_id]]);
        Base::setting('aiAutomation', $settings);
        Http::fake(function () use ($settings) {
            Base::setting('aiAutomation', array_replace($settings, ['remind_enabled' => false]));
            return Http::response(['choices' => [['message' => ['content' => 'Progress?']]]]);
        });
        AiAutomation::run(fn() => $this->fail('Disabled automation sent a message'));
        Http::assertSentCount(1);
    }

    public function test_duplicate_reminder_does_not_send_again(): void
    {
        $task = $this->task($this->user());
        Base::setting('aiAutomation', array_replace($this->settings(), ['remind_enabled' => true, 'project_ids' => [$task->project_id]]));
        Http::fake(['model.example/*' => Http::response(['choices' => [['message' => ['content' => 'Progress?']]]])]);
        $sent = 0;
        $sender = function ($dialogId, $botId, $text) use (&$sent) {
            $sent++;
            $this->assertStringContainsString('class="mention user"', $text);
            return Base::retSuccess('success', (object)['id' => 123]);
        };
        AiAutomation::run($sender);
        AiAutomation::run($sender);
        $this->assertSame(1, $sent);
        Http::assertSentCount(1);
    }

    public function test_completed_during_generation_does_not_send_reminder(): void
    {
        $task = $this->task($this->user());
        Base::setting('aiAutomation', array_replace($this->settings(), ['remind_enabled' => true, 'project_ids' => [$task->project_id]]));
        Http::fake(function () use ($task) {
            ProjectTask::whereKey($task->id)->update(['complete_at' => now()]);
            return Http::response(['choices' => [['message' => ['content' => 'Progress?']]]]);
        });
        AiAutomation::run(fn() => $this->fail('Completed task was chased'));
        Http::assertSentCount(1);
        $this->assertSame('skipped', DB::table('ai_automation_deliveries')->where('kind', 'remind')->where('target_id', $task->id)->value('status'));
    }
}
