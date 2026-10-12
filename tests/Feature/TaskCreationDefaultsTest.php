<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectColumn;
use App\Models\ProjectTask;
use App\Models\ProjectTaskUser;
use App\Models\ProjectUser;
use App\Models\User;
use App\Module\Base;
use App\Module\TaskCreationDefaults;
use App\Services\RequestContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class TaskCreationDefaultsTest extends TestCase
{
    use DatabaseTransactions;
    private $dispatcher;
    private User $creator;
    private User $assistant;
    private Project $project;
    private ProjectColumn $column;

    protected function setUp(): void
    {
        parent::setUp();
        RequestContext::clean();
        $this->dispatcher = Model::getEventDispatcher();
        Model::unsetEventDispatcher();
        $this->creator = User::createInstance(['email' => uniqid('creator') . '@example.com', 'nickname' => 'Creator', 'identity' => ',normal,']);
        $this->creator->save();
        $this->assistant = User::createInstance(['email' => uniqid('assistant') . '@example.com', 'nickname' => 'Assistant', 'identity' => ',normal,']);
        $this->assistant->save();
        config(['dootask.task_default_assistant_email' => $this->assistant->email]);
        $this->project = Project::createInstance(['name' => 'Creation defaults', 'userid' => $this->creator->userid, 'personal' => 0]);
        $this->project->save();
        foreach ([$this->creator, $this->assistant] as $user) {
            ProjectUser::createInstance(['project_id' => $this->project->id, 'userid' => $user->userid, 'owner' => $user === $this->creator ? 1 : 0])->save();
        }
        $this->column = ProjectColumn::createInstance(['project_id' => $this->project->id, 'name' => 'Tasks']);
        $this->column->save();
        RequestContext::set('auth', $this->creator);
        RequestContext::set('ai_task_actor_id', $this->creator->userid);
        Base::setting('system', ['task_user_limit' => 2000], true);
    }

    protected function tearDown(): void
    {
        RequestContext::clean();
        Model::setEventDispatcher($this->dispatcher);
        parent::tearDown();
    }

    private function createTask(array $extra = []): ProjectTask
    {
        return ProjectTask::addTask(array_merge([
            'parent_id' => 0, 'project_id' => $this->project->id, 'column_id' => $this->column->id,
            'name' => 'Creation test', 'content' => '', 'times' => [], 'owner' => [$this->creator->userid],
            'add_assist' => 0, 'subtasks' => [], 'p_level' => 0, 'p_name' => '', 'p_color' => '',
            'top' => 0, 'visibility' => 1, 'visibility_appointor' => [],
        ], $extra));
    }

    public function test_quick_creation_persists_default_assistant(): void
    {
        $task = $this->createTask();
        $this->assertSame([$this->assistant->userid], ProjectTaskUser::whereTaskId($task->id)->whereOwner(0)->pluck('userid')->all());
        $this->assertSame([$this->creator->userid], ProjectTaskUser::whereTaskId($task->id)->whereOwner(1)->pluck('userid')->all());
    }

    public function test_explicit_assistants_and_removal_are_preserved(): void
    {
        $task = $this->createTask(['assist' => []]);
        $this->assertSame(0, ProjectTaskUser::whereTaskId($task->id)->whereOwner(0)->count());
        $task = $this->createTask(['assist' => [$this->assistant->userid, $this->assistant->userid]]);
        $this->assertSame(1, ProjectTaskUser::whereTaskId($task->id)->whereOwner(0)->count());
    }

    public function test_default_assistant_is_not_added_when_already_owner(): void
    {
        $task = $this->createTask(['owner' => [$this->assistant->userid]]);
        $this->assertSame(0, ProjectTaskUser::whereTaskId($task->id)->whereOwner(0)->count());
        $this->assertSame(1, ProjectTaskUser::whereTaskId($task->id)->whereUserid($this->assistant->userid)->count());
    }

    public function test_default_cannot_grant_access_outside_project_or_to_disabled_account(): void
    {
        ProjectUser::whereProjectId($this->project->id)->whereUserid($this->assistant->userid)->delete();
        $this->assertNull(TaskCreationDefaults::assistant($this->project->id));
        $task = $this->createTask();
        $this->assertSame(0, ProjectTaskUser::whereTaskId($task->id)->whereOwner(0)->count());
        ProjectUser::createInstance(['project_id' => $this->project->id, 'userid' => $this->assistant->userid, 'owner' => 0])->save();
        $this->assistant->disable_at = now();
        $this->assistant->save();
        $this->assertNull(TaskCreationDefaults::assistant($this->project->id));
    }
}
