const {test, afterEach} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const {JSDOM} = require('jsdom');
const compiler = require('vue-template-compiler');
const dom = new JSDOM('<!doctype html><html><body></body></html>', {url: 'https://work.jikejiacn.com/', pretendToBeVisual: true});
global.window = dom.window;
global.document = dom.window.document;
global.navigator = dom.window.navigator;
const Vue = require('vue/dist/vue.common.js');
Vue.config.productionTip = false;
Vue.config.devtools = false;
Vue.mixin({computed: {windowPortrait() { return false; }}, methods: {$L(text) { return text; }}});
for (const name of ['Icon', 'Loading', 'PageTitle']) Vue.component(name, {render(h) { return h('span'); }});
Vue.component('Button', {render(h) { return h('button', this.$slots.default); }});
const mounted = [];

function definition(file, extra = {}) {
    const parsed = compiler.parseComponent(fs.readFileSync(path.join(__dirname, '../../resources/assets/js/', file), 'utf8'));
    const compiled = compiler.compile(parsed.template.content);
    assert.deepEqual(compiled.errors, []);
    const context = {module: {exports: {}}, window: dom.window,
        requestAnimationFrame: cb => dom.window.requestAnimationFrame(cb),
        cancelAnimationFrame: id => dom.window.cancelAnimationFrame(id), ...extra};
    const code = parsed.script.content.replace(/^import .+;\s*$/gm, '').replace('export default', 'module.exports =');
    vm.runInNewContext(code, context);
    return {...context.module.exports, render: new Function(compiled.render), staticRenderFns: compiled.staticRenderFns.map(code => new Function(code))};
}
const reader = definition('pages/manage/components/SystemNoticeReader.vue');
const single = definition('pages/single/notice.vue', {SystemNoticeReader: {props: ['notice'], render(h) { return h('article', this.notice.title); }}});
function mount(options, props = {}, dispatch = async () => ({}), route = {params: {msgId: '1329'}}) {
    const instance = new (Vue.extend(options))({propsData: props, beforeCreate() {
        this.$store = {dispatch}; this.$route = route; this.$router = {push() {}};
    }}).$mount();
    document.body.append(instance.$el); mounted.push(instance); return instance;
}
function mountReader(extra = {}, dispatch) {
    return mount(reader, {notice: {title: '通知', detail: '<h1>标题</h1><h2>安排</h2><h3>注意事项</h3><p>内容</p>'}, msgId: 1329, ...extra}, dispatch);
}
function clickButton(instance, text) {
    const button = [...instance.$el.querySelectorAll('button')].find(el => el.textContent.trim() === text);
    assert.ok(button, text); button.click(); return button;
}
afterEach(() => { for (const instance of mounted.splice(0)) { instance.$destroy(); instance.$el.remove(); }});

test('article renders its rich text and builds a heading outline, skipping empty headings', async () => {
    const instance = mountReader({notice: {title: '通知', detail: '<h1>标题</h1><h2> </h2><h2>安排</h2><p><strong>重点</strong></p>'}});
    await Vue.nextTick();
    assert.deepEqual(Array.from(instance.headings, h => h.text), ['标题', '安排']);
    assert.equal(instance.$el.querySelector('article strong').textContent, '重点');
    assert.equal(instance.$el.querySelectorAll('nav button').length, 2);
});

test('short notices do not show a redundant outline', async () => {
    const instance = mountReader({notice: {title: '通知', detail: '<p>短通知</p>'}});
    await Vue.nextTick(); assert.equal(instance.$el.querySelector('nav'), null);
});

test('section navigation scrolls the article container and closes the compact outline', async () => {
    const instance = mountReader(); await Vue.nextTick();
    const scroll = instance.$refs.scroll;
    scroll.scrollTop = 50;
    scroll.getBoundingClientRect = () => ({top: 100});
    instance.headings[1].element.getBoundingClientRect = () => ({top: 400});
    let target; scroll.scrollTo = options => { target = options; };
    instance.showOutline = true; instance.jumpTo(1); await Vue.nextTick();
    assert.equal(target.top, 326); assert.equal(target.behavior, 'smooth');
    assert.equal(instance.showOutline, false); assert.equal(instance.activeIndex, 1);
});

test('reading progress and active section track the actual scroll position', async () => {
    const instance = mountReader(); await Vue.nextTick(); const scroll = instance.$refs.scroll;
    Object.defineProperties(scroll, {scrollHeight: {value: 1000}, clientHeight: {value: 400}});
    scroll.scrollTop = 300; scroll.getBoundingClientRect = () => ({top: 0});
    instance.headings.forEach((h, i) => { h.element.getBoundingClientRect = () => ({top: [-300, 20, 200][i]}); });
    instance.updatePosition(); assert.equal(instance.progress, 50); assert.equal(instance.activeIndex, 1);
});

test('image click previews the selected original image and preserves the article image order', async () => {
    const calls = [];
    const instance = mountReader({notice: {title: '通知', detail: '<a href="https://example.com"><img src="https://example.com/a.png"></a><img src="https://example.com/b.png">'}}, (...args) => calls.push(args));
    await Vue.nextTick(); instance.$el.querySelectorAll('article img')[1].click();
    assert.equal(calls.length, 1); assert.equal(calls[0][0], 'previewImage');
    assert.equal(calls[0][1].index, 1); assert.equal(calls[0][1].list[1].src, 'https://example.com/b.png');
});

test('toolbar offers full screen and opens the real authenticated notice URL', async () => {
    const calls = []; const instance = mountReader({}, (...args) => calls.push(args));
    let fullscreen = 0; instance.$on('fullscreen', () => fullscreen++); await Vue.nextTick();
    clickButton(instance, '全屏'); assert.equal(fullscreen, 1);
    clickButton(instance, '新窗口打开'); assert.equal(calls[0][0], 'openWindow');
    assert.equal(calls[0][1].path, '/single/notice/1329');
});

test('standalone reader hides redundant window and full-screen actions', async () => {
    const instance = mountReader({standalone: true, fullscreen: true}); await Vue.nextTick();
    const labels = [...instance.$el.querySelectorAll('header button')].map(el => el.textContent.trim());
    assert.ok(!labels.includes('新窗口打开')); assert.ok(!labels.includes('退出全屏'));
});

test('standalone page reads through the permission-checked message API', async () => {
    const calls = []; const instance = mount(single, {}, async (...args) => { calls.push(args); return {data: {type: 'template', msg: {type: 'system_notice', title: '通知', detail: '<p>内容</p>'}}}; });
    await Vue.nextTick(); await Vue.nextTick();
    assert.equal(calls[0][0], 'call'); assert.equal(calls[0][1].url, 'dialog/msg/one');
    assert.equal(calls[0][1].data.msg_id, 1329); assert.equal(instance.notice.title, '通知');
});

test('revoked or inaccessible messages render an error instead of article content', async () => {
    const instance = mount(single, {}, async () => { throw {msg: '不在成员列表内'}; });
    await Vue.nextTick(); await Vue.nextTick();
    assert.equal(instance.notice, null); assert.equal(instance.error, '不在成员列表内');
    assert.equal(instance.$el.querySelector('article'), null);
});

test('invalid message IDs are rejected before calling the API', async () => {
    let calls = 0; const instance = mount(single, {}, async () => { calls++; }, {params: {msgId: '../invalid'}});
    await Vue.nextTick(); assert.equal(calls, 0); assert.equal(instance.notice, null); assert.ok(instance.error);
});
