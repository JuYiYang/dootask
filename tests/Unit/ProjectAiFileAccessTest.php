<?php
namespace Tests\Unit;

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\ProjectAiFileController;
use App\Models\File;
use App\Models\User;
use App\Module\ProjectAiFileAccess;
use App\Services\RequestContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\{DB,Schema};
use Tests\TestCase;

class ProjectAiFileAccessTest extends TestCase
{
    private $dispatcher;
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite',DB::connection()->getDriverName());
        $this->assertSame(':memory:',DB::connection()->getDatabaseName());
        $this->dispatcher=Model::getEventDispatcher();Model::unsetEventDispatcher();
        Schema::create('users',function($t){$t->increments('userid');$t->string('email');$t->string('identity');$t->timestamp('disable_at')->nullable();});
        Schema::create('files',function($t){$t->increments('id');$t->integer('pid')->default(0);$t->string('name');$t->string('type')->default('folder');$t->string('ext')->default('');$t->integer('size')->default(0);$t->integer('userid');$t->integer('created_id');$t->integer('share')->default(0);$t->integer('pshare')->default(0);$t->timestamps();$t->softDeletes();});
        Schema::create('file_users',function($t){$t->increments('id');$t->integer('file_id');$t->integer('userid');$t->integer('permission');});
        Schema::create('settings',function($t){$t->increments('id');$t->string('name');$t->text('setting')->nullable();$t->timestamps();});
        DB::table('settings')->insert(['name'=>'system','setting'=>json_encode(['department_owner_project_view'=>'close'])]);
        Schema::create('web_socket_dialogs',function($t){$t->increments('id');$t->string('type');$t->string('group_type')->default('');$t->softDeletes();});
        Schema::create('web_socket_dialog_users',function($t){$t->increments('id');$t->integer('dialog_id');$t->integer('userid');});
        Schema::create('web_socket_dialog_msgs',function($t){$t->increments('id');$t->integer('dialog_id');$t->string('type');$t->text('msg');$t->softDeletes();});
        Schema::create('web_socket_dialog_msg_attachments',function($t){$t->increments('id');$t->integer('msg_id');$t->integer('dialog_id');$t->string('path');$t->string('name');});
        Schema::create('file_contents',function($t){$t->increments('id');$t->integer('fid');$t->text('content');$t->timestamps();$t->softDeletes();});
        DB::table('users')->insert([['userid'=>1,'email'=>'test@example.com','identity'=>',normal,'],['userid'=>2,'email'=>'other@example.com','identity'=>',normal,'],['userid'=>3,'email'=>'temp@example.com','identity'=>',temp,']]);
        $this->file(1,0,1,'自己的文件夹');$this->file(2,0,2,'私有设计');
        $this->file(10,0,2,'只读共享',1,10);$this->file(11,10,2,'设计稿',0,10,'drawio');
        $this->file(20,0,2,'全员共享',1,20);
        $this->file(30,0,2,'可下载共享',1,30);
        DB::table('file_users')->insert([['file_id'=>10,'userid'=>1,'permission'=>0],['file_id'=>20,'userid'=>0,'permission'=>0],['file_id'=>30,'userid'=>1,'permission'=>1]]);
        DB::table('file_contents')->insert(['fid'=>11,'content'=>json_encode(['url'=>'uploads/fixture.drawio','xml'=>'<mxfile>fixture</mxfile>'])]);
    }
    protected function tearDown(): void { Model::setEventDispatcher($this->dispatcher);RequestContext::clean();parent::tearDown(); }
    private function file(int $id,int $pid,int $owner,string $name,int $share=0,int $pshare=0,string $type='folder'): void { DB::table('files')->insert(['id'=>$id,'pid'=>$pid,'userid'=>$owner,'created_id'=>$owner,'name'=>$name,'share'=>$share,'pshare'=>$pshare,'type'=>$type]); }
    private function ids(array $params=[]): array {return array_column(ProjectAiFileAccess::lists(1,$params)['list'],'id');}
    public function test_root_scopes_match_own_and_shared_files_without_private_leaks(): void
    {
        $this->assertSame([1,10,20,30],$this->ids());$this->assertSame([1],$this->ids(['scope'=>'mine']));$this->assertSame([10,20,30],$this->ids(['scope'=>'shared']));
        $this->assertNotContains(20,array_column(ProjectAiFileAccess::lists(3,[])['list'],'id'));
    }
    public function test_subfolder_and_recursive_name_search_use_inherited_permission(): void
    {
        $this->assertSame([11],$this->ids(['pid'=>10]));$this->assertSame([11],$this->ids(['recursive'=>1,'key'=>'设计']));
        $data=ProjectAiFileAccess::detail(1,11);$this->assertSame(0,$data['permission']);$this->assertFalse($data['can_download']);$this->assertNull($data['download_url']);
        $this->assertStringContainsString('/api/projectaifile/content?id=11',$data['content_url']);
    }
    public function test_private_ids_and_download_without_write_permission_are_rejected(): void
    {
        foreach([fn()=>ProjectAiFileAccess::detail(1,2),fn()=>ProjectAiFileAccess::lists(1,['pid'=>2]),fn()=>ProjectAiFileAccess::content(1,11,true)] as $call){try{$call();$this->fail('Permission bypass');}catch(ApiException $e){$this->assertStringContainsString('权限',$e->getMessage());}}
    }
    public function test_revoking_share_blocks_old_ids_and_nested_override_is_checked_per_file(): void
    {
        DB::table('file_users')->where('file_id',10)->delete();$this->assertNotContains(11,$this->ids(['recursive'=>1]));
        try{ProjectAiFileAccess::detail(1,11);$this->fail('Revoked share still readable');}catch(ApiException $e){$this->assertStringContainsString('权限',$e->getMessage());}
        $this->file(12,30,2,'受限子目录',1,12);$this->file(13,12,2,'被覆盖的设计',0,30);
        DB::table('file_users')->insert(['file_id'=>12,'userid'=>2,'permission'=>1]);
        $this->assertNotContains(12,$this->ids(['pid'=>30]));$this->assertNotContains(13,$this->ids(['recursive'=>1]));
    }
    public function test_pagination_exceeds_legacy_root_limit_and_deleted_files_are_excluded(): void
    {
        foreach(range(100,604) as $id)$this->file($id,0,1,'分页'.$id);
        DB::table('files')->where('id',604)->update(['deleted_at'=>now()]);
        $seen=[];$page=1;do{$data=ProjectAiFileAccess::lists(1,['scope'=>'mine','page'=>$page++,'per_page'=>100]);$seen=array_merge($seen,array_column($data['list'],'id'));}while($data['has_more']);
        $this->assertCount(505,$seen);$this->assertNotContains(604,$seen);$this->assertSame(count($seen),count(array_unique($seen)));$this->assertArrayNotHasKey('total',$data);
    }
    public function test_native_design_content_is_readable_and_writable_share_has_download_entry(): void
    {
        $data=ProjectAiFileAccess::content(1,11);$this->assertSame(1,$data['ret']);$this->assertSame('<mxfile>fixture</mxfile>',$data['data']['content']['xml']);
        $this->file(31,30,2,'设计文件',0,30,'drawio');$this->assertTrue(ProjectAiFileAccess::detail(1,31)['can_download']);
    }
    public function test_disabled_account_and_non_get_requests_are_rejected(): void
    {
        DB::table('users')->where('userid',1)->update(['disable_at'=>now()]);
        try{ProjectAiFileAccess::lists(1,[]);$this->fail('Disabled account');}catch(ApiException $e){$this->assertSame('令牌所属账号已失效',$e->getMessage());}
        request()->setMethod('POST');$this->expectException(ApiException::class);$this->expectExceptionMessage('请使用 GET 请求');(new ProjectAiFileController)->lists();
    }
    public function test_standard_login_cannot_replace_the_agent_bearer_token(): void
    {
        request()->setMethod('GET');RequestContext::set('auth',User::find(1));$this->expectException(ApiException::class);$this->expectExceptionMessage('只接受 AI 任务专用令牌');(new ProjectAiFileController)->lists();
    }
    public function test_collaboration_attachment_and_legacy_message_recheck_membership_and_withdrawal(): void
    {
        DB::table('web_socket_dialogs')->insert(['id'=>40,'type'=>'group']);
        DB::table('web_socket_dialog_users')->insert(['dialog_id'=>40,'userid'=>1]);
        DB::table('web_socket_dialog_msgs')->insert(['id'=>50,'dialog_id'=>40,'type'=>'file','msg'=>json_encode(['path'=>'https://example.com/design.png','name'=>'design.png'])]);
        DB::table('web_socket_dialog_msg_attachments')->insert(['id'=>60,'msg_id'=>50,'dialog_id'=>40,'path'=>'https://example.com/design.png','name'=>'design.png']);
        $this->assertSame('https://example.com/design.png',ProjectAiFileAccess::attachment(1,60)->getTargetUrl());
        $this->assertSame('https://example.com/design.png',ProjectAiFileAccess::attachment(1,0,50)->getTargetUrl());
        DB::table('web_socket_dialog_users')->delete();
        foreach([fn()=>ProjectAiFileAccess::attachment(1,60),fn()=>ProjectAiFileAccess::attachment(1,0,50)] as $call){try{$call();$this->fail('Exited group attachment leaked');}catch(ApiException $e){$this->assertSame('无权限访问此文件',$e->getMessage());}}
        DB::table('web_socket_dialog_users')->insert(['dialog_id'=>40,'userid'=>1]);
        DB::table('web_socket_dialog_msgs')->where('id',50)->update(['deleted_at'=>now()]);
        $this->expectException(ApiException::class);$this->expectExceptionMessage('文件不存在或已被删除');ProjectAiFileAccess::attachment(1,60);
    }

}
