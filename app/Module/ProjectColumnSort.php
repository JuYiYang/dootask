<?php

namespace App\Module;

use App\Exceptions\ApiException;
use App\Models\AbstractModel;
use App\Models\Project;
use App\Models\ProjectColumn;
use App\Models\ProjectPermission;
use App\Models\ProjectTask;

class ProjectColumnSort
{
    /**
     * 保存看板顺序。只有明确的跨列表拖拽才会触发列表关联状态。
     */
    public static function apply(Project $project, array $sort, int $dragTaskId = 0, int $dragColumnId = 0): ?array
    {
        $targetColumn = null;
        $dragTask = null;
        if ($dragTaskId || $dragColumnId) {
            $targetColumn = ProjectColumn::whereProjectId($project->id)->find($dragColumnId);
            $dragTask = ProjectTask::allData()->where('project_tasks.project_id', $project->id)
                ->whereNull('project_tasks.archived_at')->find($dragTaskId);
            if (!$targetColumn || !$dragTask || !self::containsTask($sort, $dragColumnId, $dragTaskId)) {
                throw new ApiException('拖拽目标无效');
            }
        }

        $flowChanged = AbstractModel::transaction(function () use ($project, $sort, $targetColumn, $dragTask) {
            foreach ($sort as $item) {
                if (!is_array($item) || empty($item['id']) || !isset($item['task']) || !is_array($item['task'])) {
                    continue;
                }
                $columnId = (int)$item['id'];
                if (!ProjectColumn::whereProjectId($project->id)->whereId($columnId)->exists()) {
                    throw new ApiException('列表不存在');
                }
                foreach ($item['task'] as $index => $taskId) {
                    $task = ProjectTask::allData()->where('project_tasks.project_id', $project->id)
                        ->whereNull('project_tasks.archived_at')->find((int)$taskId);
                    if (!$task) {
                        continue;
                    }
                    if ((int)$task->column_id !== $columnId) {
                        ProjectPermission::userTaskPermission($project, ProjectPermission::TASK_MOVE, $task);
                    }
                    if (ProjectTask::whereId($task->id)->whereProjectId($project->id)->whereNull('archived_at')->change([
                        'column_id' => $columnId,
                        'sort' => $index,
                    ])) {
                        ProjectTask::whereParentId($task->id)->whereProjectId($project->id)->change([
                            'column_id' => $columnId,
                        ]);
                    }
                }
            }

            if (!$targetColumn || !$dragTask || (int)$dragTask->column_id === (int)$targetColumn->id) {
                return false;
            }
            $flowItemId = (int)$targetColumn->flow_item_id;
            if (!$flowItemId || (int)$dragTask->flow_item_id === $flowItemId) {
                return false;
            }
            $dragTask = ProjectTask::allData()->where('project_tasks.project_id', $project->id)
                ->findOrFail($dragTask->id);
            if ($dragTask->hasOwner()) {
                ProjectPermission::userTaskPermission($project, ProjectPermission::TASK_STATUS, $dragTask);
            }
            $marking = [];
            $dragTask->updateTask([
                'task_id' => $dragTask->id,
                'flow_item_id' => $flowItemId,
                'column_id' => $targetColumn->id,
            ], $marking);
            return true;
        });

        if (!$flowChanged) {
            return null;
        }
        $data = ProjectTask::oneTask($dragTaskId)->toArray();
        $dragTask->pushMsg('update', $data);
        return $data;
    }

    private static function containsTask(array $sort, int $columnId, int $taskId): bool
    {
        foreach ($sort as $item) {
            if (is_array($item) && (int)($item['id'] ?? 0) === $columnId && is_array($item['task'] ?? null)) {
                return in_array($taskId, array_map('intval', $item['task']), true);
            }
        }
        return false;
    }
}
