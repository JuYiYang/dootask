<?php

namespace Tests\Unit;

use App\Exceptions\ApiException;
use App\Module\ProjectCreationTemplate;
use PHPUnit\Framework\TestCase;

class ProjectCreationTemplateTest extends TestCase
{
    public function test_keeps_duplicate_column_names_in_a_snapshot_and_only_one_default(): void
    {
        $config = [
            'columns' => [
                ['name' => '待办', 'color' => '', 'flow_index' => 0],
                ['name' => '待办', 'color' => '#123456', 'flow_index' => 1],
            ],
            'flow' => [
                ['name' => '开始', 'status' => 'start', 'usertype' => 'add', 'turns' => [0, 1], 'column_index' => 0],
                ['name' => '结束', 'status' => 'end', 'usertype' => 'replace', 'turns' => [0, 1], 'column_index' => 1],
            ],
        ];

        $list = ProjectCreationTemplate::normalizeList([
            ['name' => '研发', 'columns' => '待办,待办', 'default' => true, 'config' => $config],
            ['name' => '运营', 'columns' => '策划,执行', 'default' => true],
        ]);

        $this->assertSame(['待办', '待办'], $list[0]['columns']);
        $this->assertTrue($list[0]['default']);
        $this->assertFalse($list[1]['default']);
    }

    public function test_rejects_flow_references_outside_the_template(): void
    {
        $this->expectException(ApiException::class);
        ProjectCreationTemplate::normalizeList([
            [
                'name' => '无效模板',
                'columns' => '待办',
                'config' => [
                    'columns' => [['name' => '待办', 'flow_index' => 9]],
                    'flow' => [],
                ],
            ],
        ]);
    }

    public function test_rebuilds_status_references_and_maps_owner_to_project_creator(): void
    {
        $flows = ProjectCreationTemplate::flowData([
            ['name' => '待办', 'status' => 'start', 'color' => '', 'turns' => [0, 1], 'usertype' => 'add', 'userlimit' => 0, 'assign_creator' => false, 'column_index' => 0],
            ['name' => '完成', 'status' => 'end', 'color' => '', 'turns' => [1], 'usertype' => 'replace', 'userlimit' => 0, 'assign_creator' => true, 'column_index' => 1],
        ], [101, 102], 77);

        $this->assertSame([-10000, -10001], $flows[0]['turns']);
        $this->assertSame(101, $flows[0]['columnid']);
        $this->assertSame([], $flows[0]['userids']);
        $this->assertSame([77], $flows[1]['userids']);
        $this->assertSame(102, $flows[1]['columnid']);
    }
}
