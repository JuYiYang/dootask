const {test}=require('node:test'); const assert=require('node:assert/strict');
const fs=require('node:fs');const path=require('node:path');const vm=require('node:vm');const compiler=require('vue-template-compiler');const parser=require('@babel/parser');
const source=compiler.parseComponent(fs.readFileSync(path.join(__dirname,'../../resources/assets/js/pages/manage/components/TaskDetail.vue'),'utf8'));
assert.deepEqual(compiler.compile(source.template.content).errors,[]);
const ast=parser.parse(source.script.content,{sourceType:'module'});const methods=ast.program.body.find(n=>n.type==='ExportDefaultDeclaration').declaration.properties.find(n=>n.key.name==='methods').value;
const names=['openMailNotification','toggleMailAll','sendMailNotification'];const code=methods.properties.filter(n=>names.includes(n.key.name)).map(n=>source.script.content.slice(n.start,n.end)).join(',');
let serial=0;const ui={randomString:()=>String(++serial).padEnd(32,'a'),modalError(){},messageWarning(){},messageSuccess(){}};
const actions=vm.runInNewContext('({'+code+'})',{$A:ui});
function context(dispatch){return {taskDetail:{id:10},isDepartmentReadonly:false,notificationMode:'mail',notificationRequestId:'',notificationTaskId:0,mailShow:false,mailSending:false,mailLoading:false,mailSmtpReady:false,mailSelected:[],mailRecipients:[],$store:{dispatch},...actions};}
test('immediate option loads non-email candidates and sends a stable request ID',async()=>{
 const calls=[];const ctx=context(async(op,args)=>{calls.push(args);return args.url.endsWith('notifyoptions')?{data:{recipients:[{userid:2,nickname:'人员'}]}}:{data:{sent:1,failed:0},msg:'发送成功'};});
 await ctx.openMailNotification('instant');assert.equal(ctx.mailSmtpReady,true);assert.equal(calls[0].url,'projecttaskmail/notifyoptions');ctx.mailSelected=[2];const id=ctx.notificationRequestId;await ctx.sendMailNotification();assert.equal(calls[1].url,'projecttaskmail/notify');assert.equal(calls[1].data.request_id,id);assert.equal(ctx.mailShow,false);
});
test('email option preserves its endpoint and SMTP requirement',async()=>{
 const calls=[];const ctx=context(async(op,args)=>{calls.push(args);return {data:{recipients:[{userid:2}],smtp_ready:false}};});
 await ctx.openMailNotification('mail');ctx.mailSelected=[2];await ctx.sendMailNotification();assert.equal(calls.length,1);assert.equal(calls[0].url,'projecttaskmail/options');assert.equal(ctx.mailSmtpReady,false);
});
test('retries reuse the same request ID and keep the original task selected',async()=>{
 const sends=[];let failed=true;const ctx=context(async(op,args)=>{if(args.url.endsWith('notifyoptions'))return {data:{recipients:[{userid:2}]}};sends.push(args.data);if(failed){failed=false;throw {msg:'网络超时'};}return {data:{sent:1,failed:0},msg:'发送成功'};});
 await ctx.openMailNotification('instant');ctx.mailSelected=[2];ctx.taskDetail.id=20;await ctx.sendMailNotification();assert.equal(ctx.mailShow,true);await ctx.sendMailNotification();assert.equal(sends[0].request_id,sends[1].request_id);assert.equal(sends[1].task_id,10);
});
test('rapid duplicate clicks are blocked while sending',async()=>{
 let release;let sends=0;const ctx=context(async(op,args)=>{if(args.url.endsWith('notifyoptions'))return {data:{recipients:[{userid:2}]}};sends++;return new Promise(resolve=>{release=()=>resolve({data:{sent:1,failed:0},msg:'成功'});});});
 await ctx.openMailNotification('instant');ctx.mailSelected=[2];const pending=ctx.sendMailNotification();await ctx.sendMailNotification();assert.equal(sends,1);release();await pending;assert.equal(ctx.mailSending,false);
});

test('partial immediate failure keeps the dialog and request ID for a deduplicated retry',async()=>{
 const ctx=context(async(op,args)=>args.url.endsWith('notifyoptions')?{data:{recipients:[{userid:2},{userid:7}]}}:{data:{sent:1,failed:1},msg:'部分通知发送失败'});
 await ctx.openMailNotification('instant');const id=ctx.notificationRequestId;ctx.mailSelected=[2,7];await ctx.sendMailNotification();assert.equal(ctx.mailShow,true);assert.equal(ctx.notificationRequestId,id);assert.equal(ctx.mailSending,false);
});
