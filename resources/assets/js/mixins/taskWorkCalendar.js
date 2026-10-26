// Share in-flight requests across main/subtask pickers without retaining stale settings.
const requests = new Map();
export function invalidateTaskWorkCalendars() { requests.clear(); }
async function fetchYear(store, year) {
    const existing = requests.get(year);
    if (existing && Date.now() - existing.time < 30000) return existing.promise;
    const promise = store.dispatch('call', {url: 'workcalendar/dates', data: {year}});
    requests.set(year, {promise, time: Date.now()});
    try { return await promise; } catch (error) { requests.delete(year); throw error; }
}
// Shared by task creation and detail pickers. Unknown calendars stay disabled.
export default {
    data() {
        return {calendarRevision: 0, calendarYears: {}, calendarPending: {}, calendarWarned: {}};
    },
    computed: {
        taskCalendarOptions() {
            return {
                ...this.timeOptions,
                // Range shortcuts can select weekends without consulting disabledDate.
                shortcuts: [],
                disabledDate: date => {
                    const year = date.getFullYear();
                    if (!this.calendarYears[year]) {
                        this.loadCalendarYear(year);
                        return true;
                    }
                    const key = `${year}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
                    return this.calendarYears[year].failed || this.calendarYears[year].disabled_dates.includes(key);
                },
            };
        },
    },
    created() {
        this.loadCalendarYear(new Date().getFullYear());
        emitter.on('taskWorkCalendarChanged', this.reloadTaskCalendar);
    },
    beforeDestroy() { emitter.off('taskWorkCalendarChanged', this.reloadTaskCalendar); },
    methods: {
        reloadTaskCalendar() {
            this.calendarRevision++;
            this.calendarPending = {};
            this.calendarYears = {};
            this.calendarWarned = {};
            this.loadCalendarYear(new Date().getFullYear());
        },
        async loadCalendarYear(year) {
            if (this.calendarPending[year] || this.calendarYears[year]) return;
            this.calendarPending[year] = true;
            const revision = this.calendarRevision;
            try {
                const {data} = await fetchYear(this.$store, year);
                if (revision !== this.calendarRevision) return;
                this.$set(this.calendarYears, year, data);
                if (!data.available && !this.calendarWarned[year]) {
                    this.calendarWarned[year] = true;
                    $A.messageWarning('该年度法定节假日日历尚不可用，请配置自定义日期或开启加班');
                }
            } catch ({msg}) {
                if (revision !== this.calendarRevision) return;
                // Avoid repeating failed requests on each calendar-cell render.
                this.$set(this.calendarYears, year, {available: false, disabled_dates: [] , failed: true});
                $A.messageError(msg);
            } finally {
                if (revision === this.calendarRevision) delete this.calendarPending[year];
            }
        },
        async workingRange(days = 7) {
            const {data} = await this.$store.dispatch('call', {url: 'workcalendar/range', data: {days}});
            return data.times;
        },
    },
};
