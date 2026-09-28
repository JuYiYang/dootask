<?php

namespace App\Module;

use App\Exceptions\ApiException;
use App\Models\Project;
use App\Models\ProjectColumn;
use App\Models\ProjectFlow;

class ProjectCreationTemplate
{
    /**
     * 从现有项目生成不包含任务及成员的模板快照。
     */
    public static function snapshot(Project $source): array
    {
        $columns = ProjectColumn::whereProjectId($source->id)->orderBy('sort')->orderBy('id')->get();
        $flow = ProjectFlow::with('projectFlowItem')->whereProjectId($source->id)->first();
        $items = $flow?->projectFlowItem ?? collect();
        $columnIndexes = $columns->pluck('id')->flip();
        $flowIndexes = $items->pluck('id')->flip();

        return [
            'name' => mb_substr($source->name . '模板', 0, 20),
            'columns' => $columns->pluck('name')->toArray(),
            'default' => false,
            'config' => [
                'columns' => $columns->map(fn ($column) => [
                    'name' => $column->name,
                    'color' => $column->color ?: '',
                    'flow_index' => $flowIndexes->get($column->flow_item_id),
                ])->toArray(),
                'flow' => $items->map(fn ($item) => [
                    'name' => $item->name,
                    'status' => $item->status,
                    'color' => $item->color ?: '',
                    'turns' => array_values(array_filter(
                        array_map(fn ($id) => $flowIndexes->get($id), $item->turns ?: []),
                        fn ($index) => $index !== null
                    )),
                    'usertype' => $item->usertype ?: 'add',
                    'userlimit' => (int)$item->userlimit,
                    'assign_creator' => !empty($item->userids),
                    'column_index' => $columnIndexes->get($item->columnid),
                ])->toArray(),
            ],
        ];
    }

    /** 保留旧版只有列名的模板，同时校验新模板的引用。 */
    public static function normalizeList(array $list): array
    {
        $normalized = [];
        $hasDefault = false;
        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = trim((string)($item['name'] ?? ''));
            $rawColumns = $item['columns'] ?? [];
            $columns = is_array($rawColumns) ? $rawColumns : explode(',', (string)$rawColumns);
            $columns = array_values(array_filter(array_map('trim', array_filter($columns, 'is_string'))));
            if (empty($item['config'])) {
                $columns = array_values(array_unique($columns));
            }
            if ($name === '' || !$columns) {
                continue;
            }
            if (mb_strlen($name) > 20 || count($columns) > 30 || max(array_map('mb_strlen', $columns)) > 100) {
                throw new ApiException('项目模板内容超出限制');
            }
            $isDefault = !empty($item['default']) && !$hasDefault;
            $hasDefault = $hasDefault || $isDefault;
            $template = ['name' => $name, 'columns' => $columns, 'default' => $isDefault];
            if (!empty($item['config'])) {
                $config = $item['config'];
                if (!is_array($config) || !is_array($config['columns'] ?? null) || !is_array($config['flow'] ?? null)
                    || count($config['columns']) !== count($columns) || count($config['flow']) > 10) {
                    throw new ApiException('项目模板配置无效');
                }
                foreach ($config['columns'] as $index => $column) {
                    if (!is_array($column) || ($column['name'] ?? null) !== $columns[$index]
                        || !self::validIndex($column['flow_index'] ?? null, count($config['flow']))) {
                        throw new ApiException('项目模板列表配置无效');
                    }
                }
                $hasStart = false;
                $hasEnd = false;
                foreach ($config['flow'] as $flow) {
                    if (!is_array($flow) || !in_array($flow['status'] ?? null, ['start', 'progress', 'test', 'end'], true)
                        || !in_array($flow['usertype'] ?? null, ['add', 'replace', 'merge'], true)
                        || !self::validIndex($flow['column_index'] ?? null, count($columns))
                        || !is_array($flow['turns'] ?? null)) {
                        throw new ApiException('项目模板工作流配置无效');
                    }
                    foreach ($flow['turns'] as $turn) {
                        if (!self::validIndex($turn, count($config['flow']), false)) {
                            throw new ApiException('项目模板流转范围无效');
                        }
                    }
                    $hasStart = $hasStart || $flow['status'] === 'start';
                    $hasEnd = $hasEnd || $flow['status'] === 'end';
                }
                if ($config['flow'] && (!$hasStart || !$hasEnd)) {
                    throw new ApiException('项目模板工作流缺少开始或结束状态');
                }
                $template['config'] = $config;
            }
            $normalized[] = $template;
        }
        if (!$normalized) {
            throw new ApiException('参数为空');
        }
        return $normalized;
    }

    public static function resolve(array $params, bool $personal): ?array
    {
        if ($personal) {
            return null;
        }
        $templates = Base::setting('columnTemplate');
        if (array_key_exists('template_index', $params)) {
            if (!preg_match('/^\d+$/', (string)$params['template_index'])) {
                throw new ApiException('项目模板不存在');
            }
            $index = (int)$params['template_index'];
            if ($index === 0) {
                return null;
            }
            if ($index < 0 || !isset($templates[$index - 1])) {
                throw new ApiException('项目模板不存在');
            }
            return $templates[$index - 1];
        }
        if (trim((string)($params['columns'] ?? '')) !== '') {
            return null;
        }
        foreach ($templates as $template) {
            if (!empty($template['default'])) {
                return $template;
            }
        }
        return null;
    }

    /** 为新项目重建工作流 ID 与双向列表关联。 */
    public static function applyFlow(Project $project, array $template, array $columnIds): bool
    {
        $config = $template['config'] ?? null;
        $flow = $config['flow'] ?? [];
        if (!$flow) {
            return false;
        }
        $data = self::flowData($flow, $columnIds, (int)$project->userid);
        $projectFlow = $project->addFlow($data);
        $newFlowIds = $projectFlow->projectFlowItem->pluck('id')->toArray();
        foreach ($config['columns'] as $index => $column) {
            $flowIndex = $column['flow_index'] ?? null;
            if ($flowIndex !== null && isset($newFlowIds[$flowIndex])) {
                ProjectColumn::whereId($columnIds[$index])->whereProjectId($project->id)->update([
                    'flow_item_id' => $newFlowIds[$flowIndex],
                ]);
            }
        }
        return true;
    }

    /** 统一映射临时状态 ID、新项目列表 ID 和状态负责人。 */
    public static function flowData(array $flow, array $columnIds, int $creatorId): array
    {
        $temporaryId = fn ($index) => -10000 - $index;
        $data = [];
        foreach ($flow as $index => $item) {
            $requiresOwner = !empty($item['assign_creator']) || in_array($item['usertype'], ['replace', 'merge'], true)
                || !empty($item['userlimit']);
            $data[] = [
                'id' => $temporaryId($index),
                'name' => $item['name'],
                'status' => $item['status'],
                'color' => $item['color'] ?? '',
                'sort' => $index,
                'turns' => array_map($temporaryId, $item['turns']),
                'userids' => $requiresOwner ? [$creatorId] : [],
                'usertype' => $item['usertype'],
                'userlimit' => (int)$item['userlimit'],
                'columnid' => $columnIds[$item['column_index']] ?? 0,
            ];
        }
        return $data;
    }

    private static function validIndex($index, int $length, bool $nullable = true): bool
    {
        return ($nullable && $index === null) || (is_int($index) && $index >= 0 && $index < $length);
    }
}
