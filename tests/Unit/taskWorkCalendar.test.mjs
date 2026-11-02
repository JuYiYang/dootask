import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';

const source = await readFile(new URL('../../resources/assets/js/mixins/taskWorkCalendar.js', import.meta.url), 'utf8');
const {default: mixin, invalidateTaskWorkCalendars} = await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64'));
globalThis.$A = {messageWarning() {}, messageError() {}};
function context(dispatch) {
    const ctx = {...mixin.data(), $store: {dispatch}, timeOptions: {shortcuts: ['unrestricted']}, $set: (obj, key, value) => {obj[key] = value;}};
    for (const [name, fn] of Object.entries(mixin.methods)) ctx[name] = fn.bind(ctx);
    return ctx;
}
let calls = 0;
const dispatch = async () => {calls++; return {data: {available: true, disabled_dates: ['2026-10-18']}};};
const first = context(dispatch), second = context(dispatch);
await Promise.all([first.loadCalendarYear(2026), second.loadCalendarYear(2026)]);
assert.equal(calls, 1, 'Subtask pickers must share calendar requests');
let options = mixin.computed.taskCalendarOptions.call(first);
assert.equal(options.disabledDate(new Date(2026, 9, 18)), true, 'Sunday must be disabled');
assert.equal(options.disabledDate(new Date(2026, 9, 17)), false, 'Saturday may be selected under single rest');
assert.deepEqual(options.shortcuts, [], 'Shortcuts must not bypass endpoint validation');
first.calendarOvertime = true;
assert.equal(options.disabledDate(new Date(2026, 9, 18)), true, 'Removed overtime UI state must not enable non-working dates');
first.calendarOvertime = false;
first.calendarYears[2026] = {failed: true, disabled_dates: []};
assert.equal(options.disabledDate(new Date(2026, 9, 17)), true, 'Fetch failure must not enable dates');
invalidateTaskWorkCalendars();
await context(dispatch).loadCalendarYear(2026);
assert.equal(calls, 2, 'Saved settings must invalidate shared request cache');
const failed = context(async () => {throw {msg: 'offline'};});
invalidateTaskWorkCalendars();
await failed.loadCalendarYear(2027);
assert.equal(mixin.computed.taskCalendarOptions.call(failed).disabledDate(new Date(2027, 0, 4)), true);
console.log('PASS: shared requests, working dates, overtime, shortcut guard, cache invalidation, fail-closed behavior');

// Quick creation must use the same business-day defaults, including explicit no-limit choices.
const quickSource = await readFile(new URL('../../resources/assets/js/pages/manage/components/TaskAddSimple.vue', import.meta.url), 'utf8');
const quickScript = quickSource.match(/<script>([\s\S]*?)<\/script>/)[1].replace(/import[^;]+;/g, '').replace('export default', 'return');
const quick = new Function('mapState', quickScript)(() => ({}));
$A.runNum = Number;
$A.cloneJSON = value => JSON.parse(JSON.stringify(value));
const requestedDays = [];
const quickContext = {
    ...quick.data(), parentId: 0, projectId: 5, columnId: 9, userId: 1,
    taskPriority: [{name: 'Normal', priority: 1, days: 3, color: '#333', is_default: 1}],
    $set: (obj, key, value) => {obj[key] = value;}, $nextTick: () => {},
    $store: {state: {cacheColumns: []}, dispatch: async (op, request) => {
        requestedDays.push(request.data.days);
        return {data: {times: ['2026-10-26 09:00', '2026-11-02 18:00']}};
    }},
};
for (const [key, method] of Object.entries(quick.methods)) quickContext[key] = method.bind(quickContext);
await quickContext.defaultPriority();
assert.equal(requestedDays.at(-1), 7, 'Initial quick creation must use seven working days regardless of priority duration');
await quickContext.choosePriority(quickContext.taskPriority[0], false);
assert.equal(requestedDays.at(-1), 3, 'An explicit priority selection retains its configured number of working days');
await quickContext.choosePriority({name: 'No limit', priority: 0, days: 0}, false);
assert.deepEqual(quickContext.getData().times, [], 'Explicit no-limit schedules must remain clear');
quickContext.calendarHasNoLimit = false;
assert.equal('times' in quickContext.getData(), false, 'Missing quick-create schedule must use backend default calculation');
console.log('PASS: quick creation seven-working-day default, priority choice, explicit no-limit, backend fallback');


// Hidden year/month tables evaluate disabledDate for years the user did not select.
let unavailableWarnings = 0, unavailableErrors = 0;
$A.messageWarning = () => unavailableWarnings++;
$A.messageError = () => unavailableErrors++;
invalidateTaskWorkCalendars();
const background = context(async () => ({data: {available: false, disabled_dates: []}}));
await background.loadCalendarYear(2027);
assert.equal(unavailableWarnings, 0, 'Background unavailable years must not emit warnings');
assert.equal(mixin.computed.taskCalendarOptions.call(background).disabledDate(new Date(2027, 0, 4)), true, 'Unknown year stays disabled even without a blocked-date list');
invalidateTaskWorkCalendars();
const backgroundFailure = context(async () => {throw {msg: 'hidden year request failed'};});
await backgroundFailure.loadCalendarYear(2027);
assert.equal(unavailableErrors, 0, 'Background fetch failures must not toast');
assert.equal(mixin.computed.taskCalendarOptions.call(backgroundFailure).disabledDate(new Date(2027, 0, 4)), true);
invalidateTaskWorkCalendars();
const foreground = context(async () => ({data: {available: false, disabled_dates: []}}));
await foreground.loadCalendarYear(2027, true);
await foreground.loadCalendarYear(2027, true);
assert.equal(unavailableWarnings, 1, 'Explicit calendar initialization still reports unavailability once');
invalidateTaskWorkCalendars();
await context(async () => {throw {msg: 'current calendar unavailable'};}).loadCalendarYear(2026, true);
assert.equal(unavailableErrors, 1, 'Explicit calendar initialization errors remain visible');
console.log('PASS: background prefetch stays quiet, unknown years stay disabled, explicit failures remain visible');
