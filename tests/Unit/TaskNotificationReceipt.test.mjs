import assert from 'node:assert/strict';
import {test,beforeEach} from 'node:test';
import {readFile} from 'node:fs/promises';
const source=await readFile(new URL('../../resources/assets/js/utils/taskNotificationReceipt.js',import.meta.url),'utf8');
const receipt=await import('data:text/javascript;base64,'+Buffer.from(source).toString('base64'));
globalThis.$A={randomString:()=> 'c'.repeat(32),originUrl:v=>v,getMsgSimpleDesc:()=> '任务提醒',isElectron:false,isEEUIApp:false};
globalThis.sessionStorage={getItem:()=>null,setItem(){}};
let serial=11;
let data={id:11,userid:7,dialog_id:24,msg:{notification_delivery_id:1}};
beforeEach(()=>{data={...data,id:++serial};});
function context(){const calls=[];return {calls,cacheDialogs:[{id:24,name:'任务提醒'}],$L:x=>x,$store:{dispatch:async(op,request)=>{calls.push(request);return {};}}};}
function states(ctx){return ctx.calls.map(v=>v.data.status);}
test('browser reports real constructor invocation and a later error with increasing sequence',async()=>{
 let error;globalThis.Notification=class{static permission='granted';addEventListener(name,fn){if(name==='error')error=fn;}close(){}};
 const ctx=context();await receipt.deliverManualTaskNotification(ctx,data,()=>{});assert.deepEqual(states(ctx),['received','triggered']);error();assert.equal(states(ctx).at(-1),'failed');assert.ok(ctx.calls[2].data.sequence>ctx.calls[1].data.sequence);
});
test('missing browser permission and unsupported environment report actual causes',async()=>{
 globalThis.Notification=class{static permission='denied';};let ctx=context();await receipt.deliverManualTaskNotification(ctx,data,()=>{});assert.equal(ctx.calls.at(-1).data.reason,'permission_denied');
 delete globalThis.Notification;data={...data,id:++serial};ctx=context();await receipt.deliverManualTaskNotification(ctx,data,()=>{});assert.equal(ctx.calls.at(-1).data.reason,'not_supported');
});
test('a native IPC acknowledgment alone never claims notification was triggered',async()=>{
 const ctx=context();let args;ctx.$Electron={sendMessage:(name,value)=>{args=value;return 'ok';}};
 await receipt.deliverManualTaskNotification(ctx,data,()=>{});assert.deepEqual(states(ctx),['received','received']);assert.equal(args.request_receipt,true);assert.equal(ctx.calls.at(-1).data.reason,'native_pending');
});
test('mobile trigger receipt requires the component shown callback',async()=>{
 const ctx=context();ctx.$isEEUIApp=true;let config;await receipt.deliverManualTaskNotification(ctx,data,value=>{config=value;});assert.ok(!states(ctx).includes('triggered'));config.onShown();assert.equal(states(ctx).at(-1),'triggered');
});
test('constructor errors are failures and ordinary chat messages never create receipts',async()=>{
 globalThis.Notification=class{static permission='granted';constructor(){throw new Error('platform failure');}};const ctx=context();await receipt.deliverManualTaskNotification(ctx,data,()=>{});assert.equal(ctx.calls.at(-1).data.reason,'client_error');
 const count=ctx.calls.length;await receipt.reportNotificationReceipt(ctx.$store,{id:10,msg:{}},'received');assert.equal(ctx.calls.length,count);
});

test('duplicate websocket delivery cannot create a second popup',async()=>{
 let count=0;globalThis.Notification=class{static permission='granted';constructor(){count++;}addEventListener(){}close(){}};
 const ctx=context();await receipt.deliverManualTaskNotification(ctx,data,()=>{});await receipt.deliverManualTaskNotification(ctx,data,()=>{});assert.equal(count,1);
});
