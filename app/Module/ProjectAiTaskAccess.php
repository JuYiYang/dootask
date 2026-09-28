<?php

namespace App\Module;

use App\Exceptions\ApiException;
use App\Models\Project;
use App\Models\ProjectAiTaskToken;
use App\Models\ProjectTask;
use App\Models\ProjectUser;
use App\Models\User;
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
        $row = ProjectAiTaskToken::createInstance([
            'project_id' => $project->id,
            'userid' => $userId,
            'created_by' => $actor->userid,
            'name' => $name,
            'token_hash' => hash('sha256', $token),
            'token_suffix' => substr($token, -6),
            'expires_at' => now()->addDays(90),
        ]);
        $row->save();
        return ['id' => $row->id, 'token' => $token, 'expires_at' => $row->expires_at];
    }

    public static function authorizeRead(string $authorization): ProjectAiTaskToken
    {
        if (!preg_match('/^Bearer (dai_[a-f0-9]{64})$/', trim($authorization), $matches)) {
            throw new ApiException('只接受 AI 任务只读令牌');
        }
        $token = $matches[1];
        $row = ProjectAiTaskToken::whereTokenHash(hash('sha256', $token))
            ->whereNull('revoked_at')->where('expires_at', '>', now())->first();
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
