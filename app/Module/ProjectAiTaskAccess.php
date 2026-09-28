<?php

namespace App\Module;

use App\Exceptions\ApiException;
use App\Models\Project;
use App\Models\ProjectAiTaskToken;
use App\Models\ProjectTask;
use App\Models\ProjectPermission;
use App\Models\ProjectFlowItem;
use App\Models\ProjectUser;
use App\Models\User;
use App\Models\WebSocketDialog;
use App\Models\WebSocketDialogMsg;
use App\Services\RequestContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

class ProjectAiTaskAccess
{
    public static function managementProject(int $projectId): Project
    {
        User::auth();
        return Project::userProject($projectId, true, true);
    }

    public static function create(Project $project, User $actor, int $userId, string $name): array
    {
        if ($userId <= 0 || (!$actor->isAdmin() && $userId !== (int)$actor->userid)) {
            throw new ApiException('仅系统管理员可为其他账号生成跨项目令牌');
        }
        $target = User::whereUserid($userId)->first();
        if (!$target || $target->isDisable(true) || !ProjectUser::whereProjectId($project->id)->whereUserid($userId)->exists()) {
            throw new ApiException('请选择当前项目的有效成员');
        }
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) {
            throw new ApiException('令牌名称长度须为 1 至 100 字');
        }
        $token = 'dai_' . bin2hex(random_bytes(32));
        $row = DB::transaction(function () use ($project, $userId, $actor, $name, $token) {
            Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            ProjectAiTaskToken::whereProjectId($project->id)->whereUserid($userId)
                ->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $row = ProjectAiTaskToken::createInstance([
            'project_id' => $project->id,
            'userid' => $userId,
            'created_by' => $actor->userid,
            'name' => $name,
            'token_hash' => hash('sha256', $token),
            'token_suffix' => substr($token, -6),
            'expires_at' => null,
            ]);
            $row->save();
            return $row;
        });
        return ['id' => $row->id, 'token' => $token, 'expires_at' => $row->expires_at];
    }

    public static function authorize(string $authorization): ProjectAiTaskToken
    {
        if (!preg_match('/^Bearer (dai_[a-f0-9]{64})$/', trim($authorization), $matches)) {
            throw new ApiException('只接受 AI 任务专用令牌');
        }
        $token = $matches[1];
        $row = ProjectAiTaskToken::whereTokenHash(hash('sha256', $token))
            ->whereNull('revoked_at')->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })->first();
        if (!$row) {
            throw new ApiException('令牌无效或已过期');
        }
        $target = User::whereUserid($row->userid)->first();
        if (!$target || $target->isDisable(true)
            || !Project::whereId($row->project_id)->whereNull('archived_at')->exists()
            || !ProjectUser::whereProjectId($row->project_id)->whereUserid($row->userid)->exists()) {
            throw new ApiException('令牌所属账号已失效');
        }
        $limitKey = 'project-ai-task-token:' . $row->id;
        if (RateLimiter::tooManyAttempts($limitKey, 60)) {
            throw new ApiException('请求过于频繁，请稍后重试');
        }
        RateLimiter::hit($limitKey, 60);
        $row->last_used_at = now();
        $row->save();
        return $row;
    }

    public static function authorizeRead(string $authorization): ProjectAiTaskToken
    {
        return self::authorize($authorization);
    }

    private static function assignedTask(int $taskId, int $userId): ProjectTask
    {
        if ($taskId <= 0 || !DB::table('project_task_users')->where('task_id', $taskId)
            ->where('userid', $userId)->whereIn('owner', [0, 1])->exists()) {
            throw new ApiException('仅可操作所属账号负责或协助的任务');
        }
        $task = ProjectTask::userTask($taskId);
        if (!ProjectUser::whereProjectId($task->project_id)->whereUserid($userId)->exists()) {
            throw new ApiException('所属账号已不在任务项目中');
        }
        return $task;
    }

    private static function asActor(int $userId, callable $callback): mixed
    {
        $actor = User::whereUserid($userId)->firstOrFail();
        $oldAuth = RequestContext::get('auth', false);
        $oldActor = RequestContext::get('ai_task_actor_id', 0);
        RequestContext::set('auth', $actor);
        RequestContext::set('ai_task_actor_id', $userId);
        try {
            return $callback();
        } finally {
            RequestContext::set('auth', $oldAuth);
            RequestContext::set('ai_task_actor_id', $oldActor);
        }
    }

    public static function changeStatus(int $userId, int $taskId, int $flowItemId): array
    {
        if ($flowItemId <= 0) {
            throw new ApiException('请选择目标状态');
        }
        return self::asActor($userId, function () use ($userId, $taskId, $flowItemId) {
            $task = self::assignedTask($taskId, $userId);
            if ($task->hasOwner()) {
                $project = Project::userProject($task->project_id);
                ProjectPermission::userTaskPermission($project, ProjectPermission::TASK_STATUS, $task);
            }
            $marking = [];
            $task->updateTask(['flow_item_id' => $flowItemId], $marking);
            $data = ProjectTask::oneTask($task->id)->toArray();
            $data['update_marking'] = $marking ?: json_decode('{}');
            $task->pushMsg('update', $data);
            return $data;
        });
    }

    public static function statuses(int $userId, int $taskId): array
    {
        return self::asActor($userId, function () use ($userId, $taskId) {
            $task = self::assignedTask($taskId, $userId);
            $project = Project::userProject($task->project_id);
            if ($task->hasOwner()) {
                ProjectPermission::userTaskPermission($project, ProjectPermission::TASK_STATUS, $task);
            }
            $current = $task->flow_item_id ? ProjectFlowItem::find($task->flow_item_id) : null;
            if ($current && $current->userlimit
                && $task->useridInTheProject($userId) !== 2
                && !in_array($userId, $current->userids)) {
                return [];
            }
            $query = ProjectFlowItem::whereProjectId($task->project_id)->whereHas('projectFlow');
            if ($current) {
                $query->whereIn('id', $current->turns);
            }
            return $query->orderBy('sort')->get(['id', 'name', 'status', 'color'])->toArray();
        });
    }

    public static function comment(int $userId, int $taskId, string $text): array
    {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > 5000) {
            throw new ApiException('评论须为 1 至 5000 字');
        }
        return self::asActor($userId, function () use ($userId, $taskId, $text) {
            User::auth()->checkChatInformation();
            $task = self::assignedTask($taskId, $userId);
            if ($task->parent_id > 0) {
                throw new ApiException('子任务不支持任务讨论');
            }
            if (!$task->dialog_id) {
                DB::transaction(function () use ($task) {
                    $locked = ProjectTask::whereKey($task->id)->lockForUpdate()->firstOrFail();
                    if (!$locked->dialog_id) {
                        $dialog = WebSocketDialog::createGroup($locked->name, $locked->relationUserids(), 'task');
                        $locked->dialog_id = $dialog->id;
                        $locked->save();
                    }
                    $task->dialog_id = $locked->dialog_id;
                });
                $task->pushMsg('dialog');
            }
            WebSocketDialog::checkDialog($task->dialog_id);
            $formatted = WebSocketDialogMsg::formatMsg($text, $task->dialog_id);
            if (mb_strlen($formatted) > 5000) {
                throw new ApiException('评论过长');
            }
            return WebSocketDialogMsg::sendMsg('', $task->dialog_id, 'text', ['text' => $formatted], $userId);
        });
    }

    public static function tasks(int $userId, int $page, int $perPage, bool $includeArchived): array
    {
        $assigned = DB::table('project_task_users')
            ->select('task_id')->selectRaw('MAX(owner) as owner')
            ->where('userid', $userId)->groupBy('task_id');
        $query = ProjectTask::query()
            ->joinSub($assigned, 'assigned', 'assigned.task_id', '=', 'project_tasks.id')
            ->join('projects', 'projects.id', '=', 'project_tasks.project_id')
            ->leftJoin('project_columns', 'project_columns.id', '=', 'project_tasks.column_id')
            ->whereNull('projects.deleted_at')
            ->select([
                'project_tasks.id', 'project_tasks.parent_id', 'project_tasks.project_id',
                'projects.name as project_name', 'project_tasks.column_id',
                'project_columns.name as column_name', 'project_tasks.name', 'project_tasks.desc',
                'project_tasks.flow_item_id', 'project_tasks.flow_item_name',
                'project_tasks.p_level', 'project_tasks.p_name',
                'project_tasks.start_at', 'project_tasks.end_at',
                'project_tasks.complete_at', 'project_tasks.archived_at',
                'project_tasks.created_at', 'project_tasks.updated_at',
                'assigned.owner',
            ]);
        if (!$includeArchived) {
            $query->whereNull('project_tasks.archived_at');
        }
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('project_tasks.updated_at')
            ->orderByDesc('project_tasks.id')
            ->offset(($page - 1) * $perPage)->limit($perPage)->get()
            ->map(function ($task) {
                $data = $task->getAttributes();
                $data['role'] = (int)$data['owner'] === 1 ? 'owner' : 'assistant';
                unset($data['owner']);
                return $data;
            })->all();
        return [
            'tasks' => $rows,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'has_more' => $page * $perPage < $total,
            ],
        ];
    }
}
