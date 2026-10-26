<template>
    <div class="block-setting-box">
        <h3>{{$L('任务工作日历')}}</h3>
        <div class="form-box">
            <FormItem :label="$L('每周休息')">
                <RadioGroup v-model="form.week_mode">
                    <Radio label="single">{{$L('单休')}}</Radio>
                    <Radio label="double">{{$L('双休')}}</Radio>
                </RadioGroup>
            </FormItem>
            <FormItem v-if="form.week_mode === 'single'" :label="$L('单休休息日')">
                <Select v-model="form.rest_day" style="width: 180px" transfer>
                    <Option :value="0">{{$L('周日')}}</Option>
                    <Option :value="6">{{$L('周六')}}</Option>
                </Select>
            </FormItem>
            <FormItem :label="$L('法定节假日')">
                <div>{{$L('自动跳过国家法定节假日，调休补班日可选。')}}</div>
                <div class="form-tip">{{$L('默认周期为7个工作日，包含开始日期；跨越休息日不计入工作日。')}}</div>
                <div v-if="unavailable" class="form-tip" style="color: #d97706">{{$L('该年度法定节假日日历尚不可用，请配置自定义日期或开启加班')}}</div>
            </FormItem>
            <FormItem :label="$L('自定义日期')">
                <div v-for="(row, index) in form.overrides" :key="index" class="calendar-override">
                    <DatePicker :value="row.date" type="date" format="yyyy-MM-dd" :editable="false" transfer @on-change="row.date = $event"/>
                    <Select v-model="row.type" style="width: 120px" transfer>
                        <Option value="work">{{$L('加班工作日')}}</Option>
                        <Option value="rest">{{$L('休息日')}}</Option>
                    </Select>
                    <Button type="text" @click="form.overrides.splice(index, 1)">{{$L('删除')}}</Button>
                </div>
                <Button @click="form.overrides.push({date: '', type: 'work'})">{{$L('添加日期')}}</Button>
                <div class="form-tip">{{$L('自定义日期优先于法定安排和每周休息规则。')}}</div>
            </FormItem>
        </div>
    </div>
</template>
<script>
import {invalidateTaskWorkCalendars} from "../../../../mixins/taskWorkCalendar";
export default {
    data() {
        return {form: {week_mode: 'single', rest_day: 0, overrides: []}, saved: null, unavailable: false};
    },
    mounted() {
        this.$store.dispatch('call', {url: 'workcalendar/settings'}).then(({data}) => {
            this.form = data;
            this.saved = $A.cloneJSON(data);
        }).catch(({msg}) => $A.messageError(msg));
        this.$store.dispatch('call', {url: 'workcalendar/dates', data: {year: new Date().getFullYear()}})
            .then(({data}) => { this.unavailable = !data.available; }).catch(() => { this.unavailable = true; });
    },
    methods: {
        resetForm() { if (this.saved) this.form = $A.cloneJSON(this.saved); },
        async save() {
            if (!this.saved) throw this.$L('工作日历尚未加载');
            const {data} = await this.$store.dispatch('call', {url: 'workcalendar/save', method: 'post', data: {settings: this.form}});
            this.form = data;
            this.saved = $A.cloneJSON(data);
            invalidateTaskWorkCalendars();
            emitter.emit('taskWorkCalendarChanged');
        },
    },
};
</script>
<style scoped>
.calendar-override { display: flex; gap: 8px; margin-bottom: 8px; flex-wrap: wrap; }
</style>
