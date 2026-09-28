<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\Project;
use App\Models\ProjectAiTaskToken;
use App\Models\ProjectFlow;
use App\Models\ProjectFlowItem;
use App\Models\ProjectTask;
use App\Models\ProjectTaskUser;
use App\Models\ProjectUser;
use App\Models\User;
use App\Models\WebSocketDialogMsg;
use App\Module\ProjectAiTaskAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ProjectAiTaskAccessTest extends TestCase
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

    private function user(string $name, bool $admin = false): User
    {
        $user = User::createInstance([
            'email' => uniqid('ai_task_' . $name) . '@test.local',
            'nickname' => $name,
            'identity' => $admin ? ',admin,' : ',normal,',
        ]);
        $user->save();
        return $user;
    }

    private function project(User $owner, User $member): Project
    {
        $project = Project::createInstance([
            'name' => 'AI task ' . uniqid(), 'userid' => $owner->userid, 'personal' => 0,
        ]);
        $project->save();
        ProjectUser::createInstance(['project_id' => $project->id, 'userid' => $owner->userid, 'owner' => 1])->save();
        ProjectUser::createInstance(['project_id' => $project->id, 'userid' => $member->userid, 'owner' => 0])->save();
        return $project;
    }

    private function task(Project $project, User $assignee, int $owner, string $name): ProjectTask
    {
        $task = ProjectTask::createInstance([
            'project_id' => $project->id, 'parent_id' => 0, 'name' => $name,
            'userid' => $assignee->userid, 'visibility' => 1,
        ]);
        $task->save();
        ProjectTaskUser::createInstance([
            'project_id' => $project->id, 'task_id' => $task->id, 'task_pid' => $task->id,
            'userid' => $assignee->userid, 'owner' => $owner,
        ])->save();
        return $task;
    }

    public function test_token_reads_assigned_tasks_across_projects_and_rotation_invalidates_old_token(): void
    {
        $admin = $this->user('admin', true);
        $target = $this->user('target');
        $other = $this->user('other');
        $source = $this->project($admin, $target);
        $second = $this->project($admin, $target);
        $ownerTask = $this->task($source, $target, 1, 'Owned task');
        $assistantTask = $this->task($second, $target, 0, 'Assisted task');
        $this->task($second, $other, 1, 'Unrelated task');
        $archived = $this->task($second, $target, 1, 'Archived task');
        $archived->archived_at = now();
        $archived->save();

        $issued = ProjectAiTaskAccess::create($source, $admin, $target->userid, 'AI reader');
        $this->assertStringStartsWith('dai_', $issued['token']);
        $this->assertNull($issued['expires_at']);
        $this->assertNull(ProjectAiTaskToken::findOrFail($issued['id'])->getAttribute('token'));
        $this->assertSame($target->userid,
            ProjectAiTaskAccess::authorizeRead('Bearer ' . $issued['token'])->userid);
        $result = ProjectAiTaskAccess::tasks($target->userid, 1, 50, false);
        $this->assertSame(2, $result['pagination']['total']);
        $this->assertEqualsCanonicalizing([$ownerTask->id, $assistantTask->id],
            array_column($result['tasks'], 'id'));
        $this->assertEqualsCanonicalizing(['owner', 'assistant'],
            array_column($result['tasks'], 'role'));
        $this->assertSame(3, ProjectAiTaskAccess::tasks($target->userid, 1, 50, true)['pagination']['total']);

        $replacement = ProjectAiTaskAccess::create($source, $admin, $target->userid, 'AI replacement');
        $this->assertNotSame($issued['token'], $replacement['token']);
        $this->assertNotNull(ProjectAiTaskToken::findOrFail($issued['id'])->revoked_at);
        $this->assertSame($target->userid,
            ProjectAiTaskAccess::authorize('Bearer ' . $replacement['token'])->userid);
        $this->expectException(ApiException::class);
        ProjectAiTaskAccess::authorize('Bearer ' . $issued['token']);
    }

    public function test_token_cannot_write_unassigned_tasks(): void
    {
        $admin = $this->user('admin', true);
        $target = $this->user('target');
        $other = $this->user('other');
        $project = $this->project($admin, $target);
        $task = $this->task($project, $other, 1, 'Unassigned');
        $this->expectException(ApiException::class);
        ProjectAiTaskAccess::changeStatus($target->userid, $task->id, 1);
    }

    public function test_token_cannot_comment_on_unassigned_tasks(): void
    {
        $admin = $this->user('admin', true);
        $target = $this->user('target');
        $other = $this->user('other');
        $project = $this->project($admin, $target);
        $task = $this->task($project, $other, 1, 'Unassigned');
        $this->expectException(ApiException::class);
        ProjectAiTaskAccess::comment($target->userid, $task->id, 'Should be rejected');
    }

    public function test_token_changes_assigned_task_status_and_posts_comment_as_its_account(): void
    {
        $admin = $this->user('admin', true);
        $target = $this->user('target');
        $project = $this->project($admin, $target);
        $task = $this->task($project, $target, 1, 'Assigned task');
        $flow = ProjectFlow::createInstance(['project_id' => $project->id, 'name' => 'Test flow']);
        $flow->save();
        $node = ProjectFlowItem::createInstance([
            'project_id' => $project->id, 'flow_id' => $flow->id,
            'name' => 'Processing', 'status' => 'progress', 'usertype' => 'add',
        ]);
        $node->save();

        $options = ProjectAiTaskAccess::statuses($target->userid, $task->id);
        $this->assertContains($node->id, array_column($options, 'id'));
        ProjectAiTaskAccess::changeStatus($target->userid, $task->id, $node->id);
        $this->assertSame($node->id, (int)$task->fresh()->flow_item_id);

        ProjectAiTaskAccess::comment($target->userid, $task->id, 'AI <b>comment</b>');
        $dialogId = $task->fresh()->dialog_id;
        $this->assertGreaterThan(0, $dialogId);
        $comment = WebSocketDialogMsg::whereDialogId($dialogId)
            ->whereUserid($target->userid)->whereType('text')->firstOrFail();
        $this->assertStringContainsString('&lt;b&gt;', $comment->msg['text']);
    }

    public function test_non_admin_cannot_issue_cross_project_token_for_other_member(): void
    {
        $owner = $this->user('owner');
        $member = $this->user('member');
        $project = $this->project($owner, $member);
        $this->expectException(ApiException::class);
        ProjectAiTaskAccess::create($project, $owner, $member->userid, 'Forbidden');
    }
}
