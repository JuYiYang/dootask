<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\Project;
use App\Models\ProjectColumn;
use App\Models\ProjectAiTaskToken;
use App\Models\ProjectFlow;
use App\Models\ProjectFlowItem;
use App\Models\ProjectTask;
use App\Models\ProjectTaskUser;
use App\Models\ProjectUser;
use App\Models\User;
use App\Models\WebSocketDialogMsg;
use App\Module\ProjectAiTaskAccess;
use App\Module\ProjectColumnSort;
use App\Services\RequestContext;
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
        $column = ProjectColumn::createInstance([
            'project_id' => $project->id, 'name' => 'Processing tasks',
            'flow_item_id' => $node->id, 'sort' => 1,
        ]);
        $column->save();

        $options = ProjectAiTaskAccess::statuses($target->userid, $task->id);
        $this->assertContains($node->id, array_column($options, 'id'));
        ProjectAiTaskAccess::changeStatus($target->userid, $task->id, $node->id);
        $this->assertSame($node->id, (int)$task->fresh()->flow_item_id);
        $this->assertSame($column->id, (int)$task->fresh()->column_id);

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

    public function test_completed_task_can_be_dragged_to_linked_active_list_and_reopened(): void
    {
        $admin = $this->user('admin', true);
        $target = $this->user('target');
        $project = $this->project($admin, $target);
        $task = $this->task($project, $target, 1, 'Completed bug');
        $flow = ProjectFlow::createInstance(['project_id' => $project->id, 'name' => 'Bug flow']);
        $flow->save();
        $active = ProjectFlowItem::createInstance([
            'project_id' => $project->id, 'flow_id' => $flow->id,
            'name' => 'In progress', 'status' => 'progress', 'usertype' => 'add',
        ]);
        $active->save();
        $done = ProjectFlowItem::createInstance([
            'project_id' => $project->id, 'flow_id' => $flow->id,
            'name' => 'Done', 'status' => 'end', 'usertype' => 'add',
            'turns' => json_encode([$active->id]),
        ]);
        $done->save();
        $doneColumn = ProjectColumn::createInstance([
            'project_id' => $project->id, 'name' => 'Done', 'flow_item_id' => $done->id,
        ]);
        $doneColumn->save();
        $activeColumn = ProjectColumn::createInstance([
            'project_id' => $project->id, 'name' => 'In progress', 'flow_item_id' => $active->id,
        ]);
        $activeColumn->save();
        $task->column_id = $doneColumn->id;
        $task->flow_item_id = $done->id;
        $task->complete_at = now();
        $task->save();

        RequestContext::set('auth', $target);
        RequestContext::set('ai_task_actor_id', $target->userid);
        try {
            ProjectColumnSort::apply($project, [
                ['id' => $doneColumn->id, 'task' => []],
                ['id' => $activeColumn->id, 'task' => [$task->id]],
            ], $task->id, $activeColumn->id);
        } finally {
            RequestContext::set('auth', null);
            RequestContext::set('ai_task_actor_id', 0);
        }

        $task->refresh();
        $this->assertSame($activeColumn->id, (int)$task->column_id);
        $this->assertSame($active->id, (int)$task->flow_item_id);
        $this->assertNull($task->complete_at);
    }

    public function test_token_reads_unassigned_pending_tasks_in_project_and_marks_role_unassigned(): void
    {
        $admin = $this->user('admin', true);
        $target = $this->user('target');
        $other = $this->user('other');
        $project = $this->project($admin, $target);
        ProjectUser::createInstance(['project_id' => $project->id, 'userid' => $other->userid, 'owner' => 0])->save();
        $otherProject = $this->project($admin, $other);

        // 1. 本项目内，指派给 other 的待处理任务（target 未被指派）
        $pendingInProject = $this->task($project, $other, 1, 'Pending in project');

        // 2. 本项目内，已完成的任务（target 未被指派）
        $doneInProject = $this->task($project, $other, 1, 'Done in project');
        $doneInProject->complete_at = now();
        $doneInProject->save();

        // 3. 其它项目内，指派给 other 的待处理任务
        $pendingInOtherProject = $this->task($otherProject, $other, 1, 'Pending in other project');

        // 4. 本项目内，指派给 target 的任务
        $assignedTask = $this->task($project, $target, 1, 'My assigned task');

        $issued = ProjectAiTaskAccess::create($project, $admin, $target->userid, 'AI reader');
        $tokenRow = ProjectAiTaskAccess::authorizeRead('Bearer ' . $issued['token']);

        $result = ProjectAiTaskAccess::tasks($tokenRow, 1, 50, false);
        $taskIds = array_column($result['tasks'], 'id');
        $this->assertContains($pendingInProject->id, $taskIds);
        $this->assertContains($assignedTask->id, $taskIds);
        $this->assertNotContains($doneInProject->id, $taskIds);
        $this->assertNotContains($pendingInOtherProject->id, $taskIds);

        $taskMap = array_column($result['tasks'], 'role', 'id');
        $this->assertSame('unassigned', $taskMap[$pendingInProject->id]);
        $this->assertSame('owner', $taskMap[$assignedTask->id]);
    }

    public function test_token_can_access_and_change_pending_task_and_auto_assigns_as_owner_or_assistant(): void
    {
        $admin = $this->user('admin', true);
        $target = $this->user('target');
        $other = $this->user('other');
        $project = $this->project($admin, $target);
        ProjectUser::createInstance(['project_id' => $project->id, 'userid' => $other->userid, 'owner' => 0])->save();

        $flow = ProjectFlow::createInstance(['project_id' => $project->id, 'name' => 'Flow']);
        $flow->save();
        $progressNode = ProjectFlowItem::createInstance([
            'project_id' => $project->id, 'flow_id' => $flow->id,
            'name' => '进行中', 'status' => 'progress', 'usertype' => 'add',
        ]);
        $progressNode->save();
        $startNode = ProjectFlowItem::createInstance([
            'project_id' => $project->id, 'flow_id' => $flow->id,
            'name' => '待处理', 'status' => 'start', 'usertype' => 'add',
            'turns' => [$progressNode->id],
        ]);
        $startNode->save();

        // 场景 A：任务已有负责人 other，状态为待处理 -> target 流转后自动成为协助人
        $taskWithOwner = $this->task($project, $other, 1, 'Task with other owner');
        $taskWithOwner->flow_item_id = $startNode->id;
        $taskWithOwner->save();

        $statuses = ProjectAiTaskAccess::statuses($target->userid, $taskWithOwner->id, $project->id);
        $this->assertNotEmpty($statuses);

        ProjectAiTaskAccess::changeStatus($target->userid, $taskWithOwner->id, $progressNode->id, $project->id);
        $taskWithOwner->refresh();
        $this->assertSame($progressNode->id, (int)$taskWithOwner->flow_item_id);
        $this->assertTrue(ProjectTaskUser::whereTaskId($taskWithOwner->id)->whereUserid($target->userid)->whereOwner(0)->exists());
        $this->assertTrue(ProjectTaskUser::whereTaskId($taskWithOwner->id)->whereUserid($other->userid)->whereOwner(1)->exists());

        // 场景 B：任务原本无负责人，状态为待处理 -> target 流转后自动成为负责人
        $taskNoOwner = ProjectTask::createInstance([
            'project_id' => $project->id, 'parent_id' => 0, 'name' => 'Unowned pending task',
            'userid' => $admin->userid, 'visibility' => 1, 'flow_item_id' => $startNode->id,
        ]);
        $taskNoOwner->save();

        ProjectAiTaskAccess::changeStatus($target->userid, $taskNoOwner->id, $progressNode->id, $project->id);
        $taskNoOwner->refresh();
        $this->assertSame($progressNode->id, (int)$taskNoOwner->flow_item_id);
        $this->assertTrue(ProjectTaskUser::whereTaskId($taskNoOwner->id)->whereUserid($target->userid)->whereOwner(1)->exists());
    }

    public function test_token_can_comment_on_unassigned_pending_task_in_same_project(): void
    {
        $admin = $this->user('admin', true);
        $target = $this->user('target');
        $other = $this->user('other');
        $project = $this->project($admin, $target);
        ProjectUser::createInstance(['project_id' => $project->id, 'userid' => $other->userid, 'owner' => 0])->save();

        $task = $this->task($project, $other, 1, 'Pending task for comment');
        ProjectAiTaskAccess::comment($target->userid, $task->id, 'Comment from pending token', $project->id);

        $dialogId = $task->fresh()->dialog_id;
        $this->assertGreaterThan(0, $dialogId);
        $comment = WebSocketDialogMsg::whereDialogId($dialogId)
            ->whereUserid($target->userid)->whereType('text')->firstOrFail();
        $this->assertSame('Comment from pending token', $comment->msg['text']);
    }

    public function test_token_cannot_access_unassigned_task_if_not_pending_or_different_project(): void
    {
        $admin = $this->user('admin', true);
        $target = $this->user('target');
        $other = $this->user('other');
        $project = $this->project($admin, $target);
        ProjectUser::createInstance(['project_id' => $project->id, 'userid' => $other->userid, 'owner' => 0])->save();
        $otherProject = $this->project($admin, $other);

        $flow = ProjectFlow::createInstance(['project_id' => $project->id, 'name' => 'Flow']);
        $flow->save();
        $progressNode = ProjectFlowItem::createInstance([
            'project_id' => $project->id, 'flow_id' => $flow->id,
            'name' => '进行中', 'status' => 'progress', 'usertype' => 'add',
        ]);
        $progressNode->save();

        // 任务进行中，且不是 target 的任务
        $inProgressTask = $this->task($project, $other, 1, 'In progress task');
        $inProgressTask->flow_item_id = $progressNode->id;
        $inProgressTask->save();

        // 尝试操作非待处理任务 -> 应拒绝
        $this->expectException(ApiException::class);
        ProjectAiTaskAccess::changeStatus($target->userid, $inProgressTask->id, $progressNode->id, $project->id);
    }

    public function test_token_reads_task_logs(): void
    {
        $admin = $this->user('admin', true);
        $target = $this->user('target');
        $project = $this->project($admin, $target);
        $task = $this->task($project, $target, 1, 'Logged task');

        $task->addLog("更新了{任务}描述", ['desc' => 'New description'], $target->userid);

        $result = ProjectAiTaskAccess::logs($target->userid, $task->id, 1, 50, $project->id);
        $this->assertSame($task->id, $result['task_id']);
        $this->assertGreaterThanOrEqual(1, $result['pagination']['total']);
        $this->assertNotEmpty($result['logs']);
        $this->assertSame($target->userid, $result['logs'][0]['userid']);
        $this->assertStringContainsString('描述', $result['logs'][0]['detail']);
    }

    public function test_token_reads_task_discussion(): void
    {
        $admin = $this->user('admin', true);
        $target = $this->user('target');
        $project = $this->project($admin, $target);
        $task = $this->task($project, $target, 1, 'Discussed task');

        // 无讨论群时返回空列表
        $emptyResult = ProjectAiTaskAccess::discussion($target->userid, $task->id, 1, 50, $project->id);
        $this->assertSame(0, $emptyResult['dialog_id']);
        $this->assertEmpty($emptyResult['messages']);

        // 发表讨论后可读回
        ProjectAiTaskAccess::comment($target->userid, $task->id, 'Hello from AI discussion test', $project->id);
        $result = ProjectAiTaskAccess::discussion($target->userid, $task->id, 1, 50, $project->id);
        $this->assertGreaterThan(0, $result['dialog_id']);
        $this->assertCount(1, $result['messages']);
        $this->assertSame('Hello from AI discussion test', $result['messages'][0]['text']);
        $this->assertSame($target->userid, $result['messages'][0]['userid']);
    }

    public function test_token_reads_task_detail_with_content_images_and_files(): void
    {
        $admin = $this->user('admin', true);
        $target = $this->user('target');
        $project = $this->project($admin, $target);
        $task = $this->task($project, $target, 1, 'Task with images and files');

        // 正文富文本中包含图片
        $html = '<p>Bug 复现步骤如下：</p><p><img src="http://192.168.3.6:8080/uploads/task/content/sample.png"></p>';
        \App\Models\ProjectTaskContent::createInstance([
            'project_id' => $project->id,
            'task_id' => $task->id,
            'userid' => $target->userid,
            'desc' => 'Bug 复现步骤如下：',
            'content' => json_encode(['url' => '']),
        ])->save();

        // 模拟附件图片
        \App\Models\ProjectTaskFile::createInstance([
            'project_id' => $project->id,
            'task_id' => $task->id,
            'userid' => $target->userid,
            'name' => 'error_screenshot.png',
            'size' => 12345,
            'ext' => 'png',
            'path' => 'uploads/chat/sample_attachment.png',
        ])->save();

        // 直接测试 extractImages 逻辑
        $extracted = ProjectAiTaskAccess::extractImages($html);
        $this->assertSame(['http://192.168.3.6:8080/uploads/task/content/sample.png'], $extracted);

        $detail = ProjectAiTaskAccess::detail($target->userid, $task->id, $project->id);
        $this->assertSame($task->id, $detail['id']);
        $this->assertSame('Task with images and files', $detail['name']);
        $this->assertSame('owner', $detail['role']);
        $this->assertNotEmpty($detail['users']);
        $this->assertNotEmpty($detail['files']);
        $this->assertSame('error_screenshot.png', $detail['files'][0]['name']);
        $this->assertTrue($detail['files'][0]['is_image']);
        $this->assertContains($detail['files'][0]['path'], $detail['images']);
    }

    public function test_token_tasks_list_includes_images(): void
    {
        $admin = $this->user('admin', true);
        $target = $this->user('target');
        $project = $this->project($admin, $target);
        $task = $this->task($project, $target, 1, 'Task with list images');

        \App\Models\ProjectTaskFile::createInstance([
            'project_id' => $project->id,
            'task_id' => $task->id,
            'userid' => $target->userid,
            'name' => 'list_test.jpg',
            'size' => 54321,
            'ext' => 'jpg',
            'path' => 'uploads/chat/list_test.jpg',
        ])->save();

        $tokenResult = ProjectAiTaskAccess::create($project, $admin, $target->userid, 'Token for list test');
        $tokenRow = ProjectAiTaskAccess::authorizeRead('Bearer ' . $tokenResult['token']);

        $res = ProjectAiTaskAccess::tasks($tokenRow, 1, 50, false);
        $matching = array_values(array_filter($res['tasks'], fn($t) => $t['id'] === $task->id));
        $this->assertNotEmpty($matching);
        $this->assertArrayHasKey('images', $matching[0]);
        $this->assertNotEmpty($matching[0]['images']);
    }
}
