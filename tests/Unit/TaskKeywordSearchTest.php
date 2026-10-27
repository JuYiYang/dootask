<?php
namespace Tests\Unit;

use App\Http\Controllers\Api\SearchController;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TaskKeywordSearchTest extends TestCase
{
    private $dispatcher;
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->dispatcher = Model::getEventDispatcher(); Model::unsetEventDispatcher();
        Schema::create('projects', function ($t) { $t->increments('id'); $t->string('name'); $t->softDeletes(); $t->timestamps(); });
        DB::table('projects')->insert([['id' => 1, 'name' => '集客运'], ['id' => 2, 'name' => '集邮运']]);
        Schema::create('project_tasks', function ($t) {
            $t->increments('id'); $t->integer('project_id'); $t->string('name'); $t->text('desc')->default('');
            $t->integer('parent_id')->default(0); $t->integer('dialog_id')->default(0);
            $t->timestamp('start_at')->nullable(); $t->timestamp('end_at')->nullable();
            $t->timestamp('archived_at')->nullable(); $t->timestamp('complete_at')->nullable(); $t->softDeletes(); $t->timestamps();
        });
        Schema::create('project_users', function ($t) { $t->increments('id'); $t->integer('project_id'); $t->integer('userid'); });
        foreach (['project_task_users', 'project_task_tags', 'project_task_files'] as $table) {
            Schema::create($table, function ($t) { $t->increments('id'); $t->integer('task_id'); $t->integer('owner')->default(0); $t->integer('userid')->default(0); $t->softDeletes(); });
        }
        DB::table('project_users')->insert(['project_id' => 1, 'userid' => 1]);
        foreach ([57 => '代理报价优化', 157 => '客户 API 对接', 572 => '代理报价抓取', 573 => '修复代理报价异常'] as $id => $name) {
            DB::table('project_tasks')->insert(['id' => $id, 'project_id' => 1, 'name' => $name]);
        }
        DB::table('project_tasks')->insert(['id' => 957, 'project_id' => 2, 'name' => '代理报价无权限']);
        DB::table('project_tasks')->insert(['id' => 857, 'project_id' => 1, 'name' => '代理报价归档', 'archived_at' => now()]);
        DB::table('project_tasks')->insert(['id' => 757, 'project_id' => 1, 'name' => '代理报价删除', 'deleted_at' => now()]);
    }
    protected function tearDown(): void { Model::setEventDispatcher($this->dispatcher); parent::tearDown(); }
    private function search(string $key, int $take = 50): array
    {
        $method = new \ReflectionMethod(SearchController::class, 'searchTaskByMysql');
        return $method->invoke(new SearchController(), 1, $key, $take);
    }
    public function test_same_named_tasks_return_their_own_project_and_task_ids(): void
    {
        DB::table('project_users')->insert(['project_id' => 2, 'userid' => 1]);
        DB::table('project_tasks')->whereIn('id', [572, 957])->update(['name' => '同名任务']);
        $rows = $this->search('同名任务');
        $this->assertSame([957, 572], array_column($rows, 'id'));
        $this->assertSame(['集邮运', '集客运'], array_column($rows, 'project_name'));
    }
    public function test_partial_title_returns_all_matching_tasks(): void
    {
        $this->assertSame([573, 572, 57], array_column($this->search('代理报价'), 'id'));
    }
    public function test_partial_id_is_fuzzy_and_complete_id_is_ranked_first(): void
    {
        $this->assertSame([57, 573, 572, 157], array_column($this->search('57'), 'id'));
        $this->assertSame(57, $this->search('57', 1)[0]['id']);
    }
    public function test_hash_number_and_full_width_hash_have_the_same_results(): void
    {
        $this->assertSame([57, 573, 572, 157], array_column($this->search('#57'), 'id'));
        $this->assertSame([572], array_column($this->search(' ＃ 572 '), 'id'));
    }
    public function test_nonmember_archived_and_deleted_tasks_are_excluded(): void
    {
        $ids = array_column($this->search('57'), 'id');
        foreach ([957,857,757] as $id) $this->assertNotContains($id, $ids);
    }
    public function test_no_match_does_not_return_an_arbitrary_task(): void
    {
        $this->assertSame([], $this->search('不存在的关键词'));
    }
}
