const {test, afterEach} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const {JSDOM} = require('jsdom');
const dom = new JSDOM('<body></body>', {url:'https://work.jikejiacn.com/'});
global.window=dom.window;global.document=dom.window.document;global.navigator=dom.window.navigator;
const Vue=require('vue/dist/vue.common.js'); const vuex=require('vuex'); const emitter=require('mitt')();
Vue.config.productionTip=false;Vue.config.devtools=false;
const compiler=require('vue-template-compiler'); const parser=require('@babel/parser');
const errors=[];const helpers={eeuiAppSetScrollDisabled(){},dayjs:()=>({unix:()=>0}),messageError:m=>errors.push(m)};
const file=f=>fs.readFileSync(path.join(__dirname,'../../resources/assets/js/',f),'utf8');
const script=compiler.parseComponent(file('components/SearchBox.vue')).script.content.replace(/^import .+;\s*$/gm,'').replace('export default','module.exports =');
const context={module:{exports:{}},mapGetters:vuex.mapGetters,mapState:vuex.mapState,emitter,transformEmojiToHtml:s=>s,SEARCH_AI_SYSTEM_PROMPT:'',withLanguagePreferencePrompt:s=>s,$A:helpers,setTimeout,clearTimeout};vm.runInNewContext(script,context);
const managed=compiler.parseComponent(file('pages/manage.vue')).script.content;
const ast=parser.parse(managed,{sourceType:'module'});const object=ast.program.body.find(n=>n.type==='ExportDefaultDeclaration').declaration;
const methods=object.properties.find(n=>n.key.name==='methods').value;const search=methods.properties.find(n=>n.key.name==='handleSearchProjectTask');
const sidebar=vm.runInNewContext('({'+managed.slice(search.start,search.end)+'})',{emitter}).handleSearchProjectTask;
let instance;
function mount(dispatch=async()=>({data:[]})) {
 instance=new (Vue.extend({...context.module.exports,render:h=>h('div')}))({beforeCreate(){this.$store={state:{microAppsIds:[]},getters:{aiVisible:false,appAiHidden:false},dispatch};this.$L=s=>s;}}).$mount();return instance;
}
afterEach(()=>{if(instance){instance.$destroy();instance=null;}errors.length=0;});
test('sidebar opens the common result list with partial title instead of exact-title filtering',async()=>{
 const box=mount();const calls=[];const parent={menuProjectTaskSearch:'  代理报价 ', $route:{name:'manage-dashboard',params:{}},toggleRoute:async(...args)=>calls.push(['route',...args]),$store:{dispatch:async(...args)=>calls.push(['dispatch',...args])},highlightTaskCard:id=>calls.push(['highlight',id])};
 sidebar.call(parent);await Vue.nextTick();assert.equal(box.showModal,true);assert.equal(box.searchKey,'代理报价');assert.equal(box.action,'task');
 await box.onClick({type:'task',rawData:{id:572,project_id:2,parent_id:57}});assert.equal(calls[0][0],'route');assert.equal(calls[1][1],'openTask');assert.equal(calls[2][1],57);assert.equal(box.showModal,false);
});
test('dashboard event accepts null and does not reuse the sidebar callback',()=>{
 const box=mount();box.onShow({key:'#572',type:'task',onTask:()=>assert.fail('Stale callback')});emitter.emit('openSearch',null);assert.equal(box.taskSelect,null);assert.equal(box.showModal,true);
});
test('task matches are kept for partial titles and number searches',async()=>{
 const box=mount(async(op,args)=>{assert.equal(args.url,'search/task');return {data:[{id:572,name:'代理报价抓取',desc:'',project_id:1},{id:573,name:'修复代理报价异常',desc:'',project_id:1}]};});
 box.searchKey='代理报价';await box.searchTask('代理报价');assert.equal(box.items.length,2);assert.equal(box.items[0].id,572);assert.equal(box.loadIng,0);
});
test('task search failure is surfaced instead of looking like a successful empty search',async()=>{
 const box=mount(async()=>{throw {msg:'搜索服务异常'};});await box.searchTask('代理');assert.equal(errors[0],'搜索服务异常');assert.equal(box.loadIng,0);
});
