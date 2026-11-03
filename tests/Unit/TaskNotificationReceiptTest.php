<?php
namespace Tests\Unit;

use App\Exceptions\ApiException;
use App\Module\TaskNotificationReceipt;
use App\Services\RequestContext;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\{DB,Schema};
use Tests\TestCase;

class TaskNotificationReceiptTest extends TestCase
{
    private $dispatcher;
    private int $delivery;
    private string $source;
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite',DB::connection()->getDriverName());$this->assertSame(':memory:',DB::connection()->getDatabaseName());
        $this->dispatcher=Model::getEventDispatcher();Model::unsetEventDispatcher();
        (require base_path('database/migrations/2026_11_03_000001_create_task_notification_deliveries_table.php'))->up();
        Schema::create('users',function($t){$t->increments('userid');$t->string('nickname');$t->string('email');});
        Schema::create('web_socket_dialog_msgs',function($t){$t->increments('id');$t->string('type');$t->text('msg');$t->timestamps();$t->softDeletes();});
        DB::table('users')->insert(['userid'=>2,'nickname'=>'收件人','email'=>'test@example.com']);
        Carbon::setTestNow(Carbon::parse('2026-11-03 12:00:00'));
        $this->source='task-instant-notify:'.str_repeat('a',64);
        $this->delivery=TaskNotificationReceipt::prepare(10,1,2,TaskNotificationReceipt::key(10,1,str_repeat('r',32)),$this->source);
        DB::table('web_socket_dialog_msgs')->insert(['id'=>11,'type'=>'template','msg'=>json_encode(['source'=>$this->source,'notification_delivery_id'=>$this->delivery]),'created_at'=>now()]);
    }
    protected function tearDown(): void { Carbon::setTestNow();Model::setEventDispatcher($this->dispatcher);RequestContext::clean();parent::tearDown(); }
    private function results(): array { return TaskNotificationReceipt::results(10,1,str_repeat('r',32))[0]; }
    private function ack(string $state,int $seq=1,string $reason='',string $device='device'): void
    {
        TaskNotificationReceipt::record(2,['delivery_id'=>$this->delivery,'message_id'=>11,'client_id'=>str_repeat('c',32),'platform'=>'browser','status'=>$state,'reason'=>$reason,'sequence'=>$seq],$device);
    }
    public function test_waiting_times_out_without_claiming_delivery_or_failure_and_late_receipt_recovers(): void
    {
        TaskNotificationReceipt::sent($this->delivery,11);$this->assertSame('waiting',$this->results()['status']);
        Carbon::setTestNow(now()->addSeconds(31));$this->assertSame('unconfirmed',$this->results()['status']);$this->assertSame('no_receipt',$this->results()['reason']);
        $this->ack('received');$this->assertSame('received',$this->results()['status']);
        Carbon::setTestNow(now()->addSeconds(31));$this->assertSame('trigger_unconfirmed',$this->results()['reason']);
        $this->ack('triggered',2);$this->assertSame('triggered',$this->results()['status']);
    }
    public function test_receipt_is_scoped_to_recipient_and_real_message_and_results_to_sender(): void
    {
        $this->assertSame([],TaskNotificationReceipt::results(10,2,str_repeat('r',32)));
        foreach([[3,11],[2,99]] as [$user,$message]){
            try{TaskNotificationReceipt::record($user,['delivery_id'=>$this->delivery,'message_id'=>$message,'client_id'=>str_repeat('c',32),'platform'=>'browser','status'=>'triggered','sequence'=>1],'device');$this->fail('Forged receipt accepted');}catch(ApiException $e){$this->assertNotEmpty($e->getMessage());}
        }
        $this->assertSame([],$this->results()['devices']);
    }
    public function test_device_event_sequence_preserves_order_and_aggregates_multiple_devices(): void
    {
        $this->ack('triggered',200);$this->ack('failed',100,'client_error');$this->ack('received',300);
        $this->assertSame('triggered',$this->results()['status']);
        $this->ack('failed',400,'client_error');$this->assertSame('failed',$this->results()['status']);
        $this->ack('triggered',1,'','other-device');$this->assertSame('triggered',$this->results()['status']);$this->assertCount(2,$this->results()['devices']);
    }
    public function test_late_trigger_outranks_a_duplicate_received_event_and_pending_reason_is_retained(): void
    {
        $this->ack('received',1000,'native_pending');$this->ack('received',1100);
        $this->assertSame('native_pending',$this->results()['reason']);
        $this->ack('triggered',1050);$this->assertSame('triggered',$this->results()['status']);
    }
    public function test_invalid_failure_reason_and_withdrawn_message_cannot_create_receipt(): void
    {
        try{$this->ack('failed',1,'arbitrary secret');$this->fail('Unknown reason accepted');}catch(ApiException $e){$this->assertSame('参数错误',$e->getMessage());}
        DB::table('web_socket_dialog_msgs')->where('id',11)->update(['deleted_at'=>now()]);$this->expectException(ApiException::class);$this->ack('triggered');
    }
    public function test_ack_can_arrive_before_sender_finishes_and_duplicate_prepare_keeps_receipt(): void
    {
        $this->ack('triggered');TaskNotificationReceipt::sent($this->delivery,11);
        $same=TaskNotificationReceipt::prepare(10,1,2,TaskNotificationReceipt::key(10,1,str_repeat('r',32)),$this->source);
        $this->assertSame($this->delivery,$same);$this->assertSame('triggered',$this->results()['status']);$this->assertSame(1,DB::table('task_notification_deliveries')->count());
    }
    public function test_transport_errors_are_safe_and_retryable_without_exposing_exception_text(): void
    {
        TaskNotificationReceipt::failed($this->delivery,new \RuntimeException('secret transport diagnostic'));
        $this->assertSame('send_failed',$this->results()['reason']);$this->assertTrue($this->results()['retryable']);
        $this->assertStringNotContainsString('secret',json_encode($this->results()));
        TaskNotificationReceipt::sent($this->delivery,11);$this->assertSame('waiting',$this->results()['status']);
    }
    public function test_device_storage_is_bounded_and_schema_does_not_change_existing_tasks(): void
    {
        foreach(range(1,32) as $id)$this->ack('received',1,'','device-'.$id);
        $this->assertCount(32,$this->results()['devices']);
        $this->expectException(ApiException::class);$this->ack('received',1,'','device-33');
    }
    public function test_structured_or_overflowing_parameters_are_rejected_without_touching_results(): void
    {
        $valid=['delivery_id'=>$this->delivery,'message_id'=>11,'client_id'=>str_repeat('c',32),'platform'=>'browser','status'=>'received','sequence'=>1];
        foreach([['client_id'=>[]],['delivery_id'=>[]],['sequence'=>'99999999999999999999999'],['reason'=>[]]] as $bad){
            try{TaskNotificationReceipt::record(2,array_replace($valid,$bad),'device');$this->fail('Malformed receipt accepted');}catch(ApiException $e){$this->assertSame('参数错误',$e->getMessage());}
        }
        $this->assertSame([],$this->results()['devices']);
    }

}
