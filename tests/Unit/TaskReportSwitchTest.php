<?php
namespace Tests\Unit;

use App\Module\TaskReportMail;
use App\Services\RequestContext;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\{DB, Schema, View};
use Tests\TestCase;

class TaskReportSwitchTest extends TestCase
{
    private $dispatcher;
    private array $setting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        config(['cache.default'=>'array']);
        $this->dispatcher=Model::getEventDispatcher(); Model::unsetEventDispatcher();
        Schema::create('settings', function($t){$t->increments('id');$t->string('name');$t->text('setting')->nullable();$t->timestamps();});
        Schema::create('users',function($t){$t->increments('userid');$t->string('nickname');$t->string('email');$t->string('identity');$t->timestamp('disable_at')->nullable();});
        Schema::create('projects',function($t){$t->increments('id');$t->string('name');$t->softDeletes();$t->timestamp('archived_at')->nullable();});
        Schema::create('project_users',function($t){$t->increments('id');$t->integer('project_id');$t->integer('userid');});
        Schema::create('project_task_users',function($t){$t->increments('id');$t->integer('task_id');$t->integer('userid');$t->integer('owner');});
        Schema::create('project_tasks',function($t){$t->increments('id');$t->integer('project_id');$t->string('name');$t->string('flow_item_name');$t->timestamp('end_at')->nullable();$t->timestamp('complete_at')->nullable();$t->timestamp('archived_at')->nullable();$t->softDeletes();});
        Schema::create('task_report_mail_deliveries',function($t){$t->increments('id');$t->integer('userid');$t->date('report_date');$t->timestamp('sent_at');$t->timestamps();});
        $this->setting=['smtp_server'=>'smtp.invalid','port'=>'587','account'=>'sender@example.com','password'=>'test-only','task_report_enabled'=>'open','task_report_time'=>'09:00','task_report_days'=>'daily','task_report_scope'=>'all','ignore_addr'=>''];
        DB::table('settings')->insert(['name'=>'emailSetting','setting'=>json_encode($this->setting)]);
        DB::table('settings')->insert(['name'=>'system','setting'=>json_encode(['system_alias'=>'测试系统'])]);
        DB::table('projects')->insert(['id'=>1,'name'=>'隔离测试项目']);
        DB::table('project_tasks')->insert(['id'=>1,'project_id'=>1,'name'=>'隔离任务','flow_item_name'=>'start|待处理|']);
        foreach([1,2] as $id){
            DB::table('users')->insert(['userid'=>$id,'nickname'=>'测试成员','email'=>'user'.$id.'@example.com','identity'=>',normal,']);
            DB::table('project_users')->insert(['project_id'=>1,'userid'=>$id]);
            DB::table('project_task_users')->insert(['task_id'=>1,'userid'=>$id,'owner'=>1]);
        }
    }
    protected function tearDown(): void { Model::setEventDispatcher($this->dispatcher);RequestContext::clean();parent::tearDown(); }
    private function closeReport(): void { DB::table('settings')->where('name','emailSetting')->update(['setting'=>json_encode(array_replace($this->setting,['task_report_enabled'=>'close']))]); }
    public function test_saved_close_overrides_stale_open_in_task_context(): void
    {
        RequestContext::save('setting_emailSetting',$this->setting);
        $this->closeReport();
        $this->assertSame(['sent'=>0,'failed'=>0],TaskReportMail::run(Carbon::parse('2026-10-28 18:30:00'),fn()=> $this->fail('Closed report sent')));
        $this->assertSame(0,DB::table('task_report_mail_deliveries')->count());
    }
    public function test_latest_open_overrides_stale_close_and_daily_dedup_still_works(): void
    {
        RequestContext::save('setting_emailSetting',array_replace($this->setting,['task_report_enabled'=>'close']));
        $calls=0;$send=function()use(&$calls){$calls++;};$now=Carbon::parse('2026-10-28 18:30:00');
        $this->assertSame(['sent'=>2,'failed'=>0],TaskReportMail::run($now,$send));
        $this->assertSame(['sent'=>0,'failed'=>0],TaskReportMail::run($now,$send));
        $this->assertSame(2,$calls);
    }
    public function test_closing_during_render_prevents_that_delivery(): void
    {
        View::composer('email.task-report',fn()=> $this->closeReport());
        $this->assertSame(['sent'=>0,'failed'=>0],TaskReportMail::run(Carbon::parse('2026-10-28 18:30:00'),fn()=> $this->fail('Closed during render')));
        $this->assertSame(0,DB::table('task_report_mail_deliveries')->count());
    }
    public function test_closing_after_first_delivery_stops_remaining_recipients(): void
    {
        $calls=0;$send=function()use(&$calls){$calls++;$this->closeReport();};
        $this->assertSame(['sent'=>1,'failed'=>0],TaskReportMail::run(Carbon::parse('2026-10-28 18:30:00'),$send));
        $this->assertSame(1,$calls);$this->assertSame(1,DB::table('task_report_mail_deliveries')->count());
    }
}
