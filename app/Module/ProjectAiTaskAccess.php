<?php

namespace App\Module;

use App\Exceptions\ApiException;
use App\Models\Project;
use App\Models\ProjectAiTaskToken;
use App\Models\ProjectTask;
use App\Models\ProjectLog;
use App\Models\ProjectPermission;
use App\Models\ProjectFlowItem;
use App\Models\ProjectColumn;
use App\Models\ProjectTaskContent;
use App\Models\ProjectTaskFile;
use App\Models\ProjectTaskUser;
use App\Models\ProjectUser;
use App\Models\User;
use App\Models\WebSocketDialog;
use App\Models\WebSocketDialogMsg;
use App\Models\WebSocketDialogUser;
use App\Services\RequestContext;
use Illuminate\Support\Arr;
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

    public static function isPendingTask(ProjectTask $task): bool
    {
        if ($task->complete_at !== null || $task->archived_at !== null) {
            return false;
        }
        if ($task->flow_item_id) {
            $flowItem = ProjectFlowItem::find($task->flow_item_id);
            if ($flowItem) {
                return $flowItem->status === 'start' || $flowItem->name === '待处理';
            }
            if ($task->flow_item_name) {
                $parts = explode('|', $task->flow_item_name);
                return ($parts[0] ?? '') === 'start' || ($parts[1] ?? '') === '待处理';
            }
        }
        return true;
    }

    private static function accessibleTask(int $taskId, int $userId, int $tokenProjectId = 0): ProjectTask
    {
        if ($taskId <= 0) {
            throw new ApiException('任务不存在');
        }
        $task = ProjectTask::userTask($taskId);
        if (!ProjectUser::whereProjectId($task->project_id)->whereUserid($userId)->exists()) {
            throw new ApiException('所属账号已不在任务项目中');
        }
        $isAssigned = DB::table('project_task_users')->where('task_id', $taskId)
            ->where('userid', $userId)->whereIn('owner', [0, 1])->exists();
        if ($isAssigned) {
            return $task;
        }
        if ($tokenProjectId > 0 && (int)$task->project_id === $tokenProjectId && self::isPendingTask($task)) {
            return $task;
        }
        throw new ApiException('仅可操作所属账号负责或协助的任务');
    }

    private static function assignedTask(int $taskId, int $userId): ProjectTask
    {
        return self::accessibleTask($taskId, $userId, 0);
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

    public static function changeStatus(int $userId, int $taskId, int $flowItemId, int $tokenProjectId = 0): array
    {
        if ($flowItemId <= 0) {
            throw new ApiException('请选择目标状态');
        }
        return self::asActor($userId, function () use ($userId, $taskId, $flowItemId, $tokenProjectId) {
            $task = self::accessibleTask($taskId, $userId, $tokenProjectId);
            $isAssigned = DB::table('project_task_users')->where('task_id', $task->id)
                ->where('userid', $userId)->whereIn('owner', [0, 1])->exists();
            $isPending = self::isPendingTask($task);

            if ($task->hasOwner() && !$isPending) {
                $project = Project::userProject($task->project_id);
                ProjectPermission::userTaskPermission($project, ProjectPermission::TASK_STATUS, $task);
            }

            // 待处理任务如果尚未指派给该账号：流转时自动认领为负责人（若无负责人）或添加为协助人（若已有负责人）
            if (!$isAssigned && $isPending) {
                $targetOwner = $task->hasOwner() ? 0 : 1;
                ProjectTaskUser::updateInsert([
                    'task_id' => $task->id,
                    'userid' => $userId,
                ], [
                    'project_id' => $task->project_id,
                    'task_pid' => $task->parent_id ?: $task->id,
                    'owner' => $targetOwner,
                ]);
                if ($targetOwner === 1) {
                    $task->addLog("认领{任务}");
                } else {
                    $task->addLog("修改{任务}协助人员", ['userid' => [$userId]]);
                }
                $task->syncDialogUser();
            }

            $marking = [];
            $task->updateTask(['flow_item_id' => $flowItemId], $marking);
            $data = ProjectTask::oneTask($task->id)->toArray();
            $data['update_marking'] = $marking ?: json_decode('{}');
            $task->pushMsg('update', $data);
            return $data;
        });
    }

    public static function statuses(int $userId, int $taskId, int $tokenProjectId = 0): array
    {
        return self::asActor($userId, function () use ($userId, $taskId, $tokenProjectId) {
            $task = self::accessibleTask($taskId, $userId, $tokenProjectId);
            $isPending = self::isPendingTask($task);
            if ($task->hasOwner() && !$isPending) {
                $project = Project::userProject($task->project_id);
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
                $query->whereIn('id', $current->turns)->where('id', '!=', $current->id);
            }
            return $query->orderBy('sort')->get(['id', 'name', 'status', 'color'])->toArray();
        });
    }

    public static function comment(int $userId, int $taskId, string $text, int $tokenProjectId = 0): array
    {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > 5000) {
            throw new ApiException('评论须为 1 至 5000 字');
        }
        return self::asActor($userId, function () use ($userId, $taskId, $text, $tokenProjectId) {
            User::auth()->checkChatInformation();
            $task = self::accessibleTask($taskId, $userId, $tokenProjectId);
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
            WebSocketDialogUser::updateInsert([
                'dialog_id' => $task->dialog_id,
                'userid' => $userId,
            ], [
                'important' => 1
            ]);
            WebSocketDialog::checkDialog($task->dialog_id);
            $formatted = WebSocketDialogMsg::formatMsg(
                htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $task->dialog_id);
            if (mb_strlen($formatted) > 5000) {
                throw new ApiException('评论过长');
            }
            return WebSocketDialogMsg::sendMsg('', $task->dialog_id, 'text', ['text' => $formatted], $userId);
        });
    }

    public static function extractImages(string $html): array
    {
        if ($html === '') {
            return [];
        }
        preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $matches);
        $urls = [];
        foreach ($matches[1] as $src) {
            $src = trim($src);
            if ($src === '' || str_starts_with($src, 'data:')) {
                continue;
            }
            if (!preg_match('/^https?:\/\//i', $src)) {
                $src = Base::fillUrl(ltrim($src, '/'));
            }
            $urls[] = $src;
        }
        return array_values(array_unique($urls));
    }

    public static function tasks(ProjectAiTaskToken|int $tokenOrUserId, int $page, int $perPage, bool $includeArchived, int $tokenProjectId = 0): array
    {
        if ($tokenOrUserId instanceof ProjectAiTaskToken) {
            $userId = (int)$tokenOrUserId->userid;
            $tokenProjectId = (int)$tokenOrUserId->project_id;
        } else {
            $userId = (int)$tokenOrUserId;
        }

        $assigned = DB::table('project_task_users')
            ->select('task_id')->selectRaw('MAX(owner) as owner')
            ->where('userid', $userId)->groupBy('task_id');
        $query = ProjectTask::query()
            ->leftJoinSub($assigned, 'assigned', 'assigned.task_id', '=', 'project_tasks.id')
            ->join('projects', 'projects.id', '=', 'project_tasks.project_id')
            ->leftJoin('project_columns', 'project_columns.id', '=', 'project_tasks.column_id')
            ->leftJoin('project_flow_items', 'project_flow_items.id', '=', 'project_tasks.flow_item_id')
            ->whereNull('projects.deleted_at')
            ->where(function ($w) use ($tokenProjectId) {
                $w->whereNotNull('assigned.task_id');
                if ($tokenProjectId > 0) {
                    $w->orWhere(function ($sub) use ($tokenProjectId) {
                        $sub->where('project_tasks.project_id', $tokenProjectId)
                            ->whereNull('project_tasks.complete_at')
                            ->where(function ($flowQ) {
                                $flowQ->where('project_tasks.flow_item_id', 0)
                                    ->orWhereNull('project_tasks.flow_item_id')
                                    ->orWhere('project_flow_items.status', 'start')
                                    ->orWhere('project_flow_items.name', '待处理');
                            });
                    });
                }
            })
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
                if ($data['owner'] === null) {
                    $data['role'] = 'unassigned';
                } else {
                    $data['role'] = (int)$data['owner'] === 1 ? 'owner' : 'assistant';
                }
                unset($data['owner']);
                return $data;
            })->all();

        $taskIds = array_column($rows, 'id');
        $imagesMap = [];
        if (!empty($taskIds)) {
            $contents = ProjectTaskContent::whereIn('task_id', $taskIds)->get();
            foreach ($contents as $content) {
                $info = $content->getContentInfo();
                $html = (string)($info['content'] ?? '');
                $imgs = self::extractImages($html);
                if (!empty($imgs)) {
                    $imagesMap[$content->task_id] = $imgs;
                }
            }
            $imageFiles = ProjectTaskFile::whereIn('task_id', $taskIds)->get();
            foreach ($imageFiles as $file) {
                $ext = strtolower((string)$file->ext);
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true) && !empty($file->path)) {
                    $imagesMap[$file->task_id][] = (string)$file->path;
                }
            }
        }
        foreach ($rows as &$taskRow) {
            $imgs = $imagesMap[$taskRow['id']] ?? [];
            $taskRow['images'] = array_values(array_unique($imgs));
        }
        unset($taskRow);

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

    public static function detail(int $userId, int $taskId, int $tokenProjectId = 0): array
    {
        return self::asActor($userId, function () use ($userId, $taskId, $tokenProjectId) {
            $task = self::accessibleTask($taskId, $userId, $tokenProjectId);

            $isAssigned = DB::table('project_task_users')->where('task_id', $task->id)
                ->where('userid', $userId)->whereIn('owner', [0, 1])->first();
            $role = 'unassigned';
            if ($isAssigned) {
                $role = (int)$isAssigned->owner === 1 ? 'owner' : 'assistant';
            }

            $users = DB::table('project_task_users')
                ->join('users', 'users.userid', '=', 'project_task_users.userid')
                ->where('project_task_users.task_id', $task->id)
                ->orderByDesc('project_task_users.owner')
                ->orderBy('users.userid')
                ->select([
                    'users.userid',
                    'users.nickname',
                    'users.userimg',
                    'project_task_users.owner',
                ])->get()->map(function ($u) {
                    return [
                        'userid' => (int)$u->userid,
                        'nickname' => (string)$u->nickname,
                        'userimg' => (string)$u->userimg,
                        'owner' => (int)$u->owner,
                        'role' => (int)$u->owner === 1 ? 'owner' : 'assistant',
                    ];
                })->all();

            $contentInfo = $task->content?->getContentInfo() ?? [];
            $contentHtml = (string)($contentInfo['content'] ?? '');
            $images = self::extractImages($contentHtml);

            $files = ProjectTaskFile::whereTaskId($task->id)->orderByDesc('id')->get()->map(function (ProjectTaskFile $file) {
                $ext = strtolower((string)$file->ext);
                return [
                    'id' => (int)$file->id,
                    'name' => (string)$file->name,
                    'size' => (int)$file->size,
                    'ext' => $ext,
                    'path' => (string)$file->path,
                    'thumb' => (string)$file->thumb,
                    'is_image' => in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true),
                ];
            })->all();

            foreach ($files as $file) {
                if ($file['is_image'] && !empty($file['path'])) {
                    $images[] = $file['path'];
                }
            }
            $images = array_values(array_unique($images));

            $contentText = trim(strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $contentHtml)));

            $project = Project::withTrashed()->find($task->project_id);
            $column = $task->column_id ? ProjectColumn::find($task->column_id) : null;

            return [
                'id' => (int)$task->id,
                'parent_id' => (int)$task->parent_id,
                'project_id' => (int)$task->project_id,
                'project_name' => (string)($project?->name ?? ''),
                'column_id' => (int)$task->column_id,
                'column_name' => (string)($column?->name ?? ''),
                'name' => (string)$task->name,
                'desc' => (string)$task->desc,
                'content' => $contentHtml,
                'content_text' => $contentText,
                'images' => $images,
                'files' => $files,
                'flow_item_id' => (int)$task->flow_item_id,
                'flow_item_name' => (string)$task->flow_item_name,
                'p_level' => (int)$task->p_level,
                'p_name' => (string)$task->p_name,
                'start_at' => (string)($task->start_at ?? ''),
                'end_at' => (string)($task->end_at ?? ''),
                'complete_at' => (string)($task->complete_at ?? ''),
                'archived_at' => (string)($task->archived_at ?? ''),
                'created_at' => (string)$task->created_at,
                'updated_at' => (string)$task->updated_at,
                'role' => $role,
                'users' => $users,
            ];
        });
    }

    public static function logs(int $userId, int $taskId, int $page = 1, int $perPage = 50, int $tokenProjectId = 0): array
    {
        return self::asActor($userId, function () use ($userId, $taskId, $page, $perPage, $tokenProjectId) {
            $task = self::accessibleTask($taskId, $userId, $tokenProjectId);
            $query = ProjectLog::query()->with('user:userid,nickname,userimg');
            if ($task->parent_id > 0) {
                $query->where('task_id', $task->parent_id)
                    ->where('record->subtask->id', $task->id);
            } else {
                $query->where('task_id', $task->id);
            }
            $total = (clone $query)->count();
            $rows = $query->orderByDesc('id')
                ->offset(($page - 1) * $perPage)->limit($perPage)->get()
                ->map(function (ProjectLog $log) {
                    return [
                        'id' => (int)$log->id,
                        'project_id' => (int)$log->project_id,
                        'task_id' => (int)$log->task_id,
                        'userid' => (int)$log->userid,
                        'user' => $log->user ? [
                            'userid' => (int)$log->user->userid,
                            'nickname' => (string)$log->user->nickname,
                            'userimg' => (string)$log->user->userimg,
                        ] : null,
                        'detail' => Doo::translate((string)$log->detail),
                        'record' => $log->record ?: [],
                        'created_at' => (string)$log->created_at,
                    ];
                })->all();
            return [
                'task_id' => (int)$task->id,
                'logs' => $rows,
                'pagination' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'has_more' => $page * $perPage < $total,
                ],
            ];
        });
    }

    public static function discussion(int $userId, int $taskId, int $page = 1, int $perPage = 50, int $tokenProjectId = 0): array
    {
        return self::asActor($userId, function () use ($userId, $taskId, $page, $perPage, $tokenProjectId) {
            $task = self::accessibleTask($taskId, $userId, $tokenProjectId);
            if ($task->parent_id > 0) {
                throw new ApiException('子任务不支持任务讨论');
            }
            if (!$task->dialog_id) {
                return [
                    'task_id' => (int)$task->id,
                    'dialog_id' => 0,
                    'messages' => [],
                    'pagination' => [
                        'page' => $page,
                        'per_page' => $perPage,
                        'total' => 0,
                        'has_more' => false,
                    ],
                ];
            }
            $query = WebSocketDialogMsg::whereDialogId($task->dialog_id)
                ->whereNull('deleted_at')
                ->with('user:userid,nickname,userimg');
            $total = (clone $query)->count();
            $rows = $query->orderByDesc('id')
                ->offset(($page - 1) * $perPage)->limit($perPage)->get()
                ->map(function (WebSocketDialogMsg $msg) {
                    $text = '';
                    if (is_array($msg->msg)) {
                        $text = (string)($msg->msg['text'] ?? '');
                    } elseif (is_string($msg->msg)) {
                        $text = $msg->msg;
                    }
                    return [
                        'id' => (int)$msg->id,
                        'dialog_id' => (int)$msg->dialog_id,
                        'userid' => (int)$msg->userid,
                        'user' => $msg->user ? [
                            'userid' => (int)$msg->user->userid,
                            'nickname' => (string)$msg->user->nickname,
                            'userimg' => (string)$msg->user->userimg,
                        ] : null,
                        'type' => (string)$msg->type,
                        'text' => $text,
                        'msg' => $msg->msg,
                        'created_at' => (string)$msg->created_at,
                    ];
                })->all();
            return [
                'task_id' => (int)$task->id,
                'dialog_id' => (int)$task->dialog_id,
                'messages' => $rows,
                'pagination' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'has_more' => $page * $perPage < $total,
                ],
            ];
        });
    }

    public static function updateTask(int $userId, int $taskId, array $params, int $tokenProjectId = 0): array
    {
        return self::asActor($userId, function () use ($userId, $taskId, $params, $tokenProjectId) {
            $task = self::accessibleTask($taskId, $userId, $tokenProjectId);
            $isPending = self::isPendingTask($task);

            if ($task->hasOwner() && !$isPending) {
                $project = Project::userProject($task->project_id);
                ProjectPermission::userTaskPermission($project, ProjectPermission::TASK_UPDATE, $task);
            }

            $allowedKeys = [
                'name', 'desc', 'content', 'p_level', 'p_name', 'p_color',
                'owner', 'assist', 'times', 'start_at', 'end_at'
            ];
            $filteredParams = [];
            foreach ($allowedKeys as $key) {
                if (Arr::exists($params, $key)) {
                    $filteredParams[$key] = $params[$key];
                }
            }
            if (empty($filteredParams)) {
                throw new ApiException('未提交任何需要修改的任务属性');
            }

            $updateMarking = [];
            $filteredParams = ProjectTask::normalizeTimes($filteredParams, $task);
            $task->updateTask($filteredParams, $updateMarking);

            $data = ProjectTask::oneTask($task->id)->toArray();
            $data['update_marking'] = $updateMarking ?: json_decode('{}');
            $task->pushMsg('update', $data);
            return $data;
        });
    }

    public static function createTask(int $userId, array $params, int $tokenProjectId = 0): array
    {
        return self::asActor($userId, function () use ($params, $tokenProjectId) {
            $projectId = (int)($params['project_id'] ?? $tokenProjectId);
            if ($projectId <= 0) {
                throw new ApiException('缺少项目ID');
            }
            if ($tokenProjectId > 0 && $projectId !== $tokenProjectId) {
                throw new ApiException('AI令牌仅限在所属项目内创建任务');
            }
            $project = Project::userProject($projectId);
            ProjectPermission::userTaskPermission($project, ProjectPermission::TASK_ADD);

            // 列表
            $columnId = $params['column_id'] ?? null;
            $column = null;
            if ($columnId) {
                if (intval($columnId) > 0) {
                    $column = $project->projectColumn->find($columnId);
                }
                if (empty($column)) {
                    $column = ProjectColumn::whereProjectId($project->id)->whereName($columnId)->first();
                }
            } else {
                $column = ProjectColumn::whereProjectId($project->id)->orderBy('id')->first();
            }
            if (empty($column)) {
                $column = ProjectColumn::createInstance([
                    'project_id' => $project->id,
                    'name' => $columnId ?: 'Default',
                ]);
                $column->sort = intval(ProjectColumn::whereProjectId($project->id)->orderByDesc('sort')->value('sort')) + 1;
                $column->save();
                $column->addLog("创建列表：" . $column->name);
            }

            $data = ProjectTask::normalizeTimes($params);
            $task = ProjectTask::addTask(array_merge($data, [
                'parent_id' => 0,
                'project_id' => $project->id,
                'column_id' => $column->id,
            ]));
            $taskData = ProjectTask::oneTask($task->id)->toArray();
            $task->pushMsg('add', $taskData);
            $task->taskPush(null, 0);
            return $taskData;
        });
    }
}
