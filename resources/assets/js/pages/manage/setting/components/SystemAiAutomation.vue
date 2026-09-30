<template>
    <div class="setting-component-item">
        <Form v-if="form" v-bind="formOptions" @submit.native.prevent>
            <div class="block-setting-box">
                <h3>{{ $L('AI 模型与语气') }}</h3>
                <div class="form-box">
                    <FormItem :label="$L('服务地址')"><Input v-model="form.base_url" placeholder="https://example.com/v1"/><div class="form-tip">{{ $L('填写兼容 Chat Completions 的 API 基础地址，不含 /chat/completions。') }}</div></FormItem>
                    <FormItem label="API Key"><Input v-model="form.api_key" type="password" autocomplete="new-password" :placeholder="form.api_key_configured ? $L('已配置，留空保留') : $L('未配置')"/></FormItem>
                    <FormItem :label="$L('模型名称')"><Input v-model="form.model"/></FormItem>
                    <FormItem :label="$L('说话语气')"><Input v-model="form.voice" type="textarea" :rows="4" :maxlength="4000"/></FormItem>
                    <FormItem :label="$L('项目范围')"><Select v-model="form.project_ids" multiple filterable><Option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }}</Option></Select><div class="form-tip">{{ $L('仅处理勾选的项目，未选项目不会发送。') }}</div></FormItem>
                </div>
            </div>
            <div class="block-setting-space"></div>
            <div class="block-setting-box">
                <h3>{{ $L('每周 AI 总结') }}</h3>
                <div class="form-box">
                    <FormItem :label="$L('开启')"><i-switch v-model="form.weekly_enabled"/></FormItem>
                    <FormItem :label="$L('接收账号')"><Select v-model="form.weekly_user_ids" multiple filterable><Option v-for="u in users" :key="u.userid" :value="u.userid">{{ u.nickname }} ({{ u.email }})</Option></Select><div class="form-tip">{{ $L('分别私信各账号，只总结其负责或协助的任务，不发送到项目群。') }}</div></FormItem>
                    <FormItem :label="$L('发送日期')"><Select v-model="form.weekly_day"><Option v-for="(name, i) in weekdays" :key="i" :value="i + 1">{{ $L(name) }}</Option></Select></FormItem>
                    <FormItem :label="$L('发送时间')"><TimePicker v-model="form.weekly_time" format="HH:mm" transfer/><div class="form-tip">{{ $L('使用服务器时区；汇总最近七天，每周一次，在指定时间起一小时内发送。') }}</div></FormItem>
                </div>
            </div>
            <div class="block-setting-space"></div>
            <div class="block-setting-box">
                <h3>{{ $L('任务会话 AI 催办') }}</h3>
                <div class="form-box">
                    <FormItem :label="$L('开启')"><i-switch v-model="form.remind_enabled"/></FormItem>
                    <FormItem :label="$L('催办时间')"><TimePicker v-model="form.remind_time" format="HH:mm" transfer/></FormItem>
                    <FormItem :label="$L('仅工作日')"><i-switch v-model="form.workdays_only"/></FormItem>
                    <FormItem :label="$L('到期前小时数')"><InputNumber v-model="form.due_hours" :min="1" :max="168"/></FormItem>
                    <FormItem :label="$L('未更新天数')"><InputNumber v-model="form.stale_days" :min="1" :max="90"/></FormItem>
                    <FormItem :label="$L('催办间隔小时')"><InputNumber v-model="form.interval_hours" :min="24" :max="720"/><div class="form-tip">{{ $L('在任务会话中 @负责人；已完成、取消或归档任务停止催办，每个任务每天最多一次。') }}</div></FormItem>
                </div>
            </div>
            <div class="block-setting-space"></div>
            <div class="block-setting-box">
                <h3>{{ $L('生成测试') }}</h3>
                <div class="form-box">
                    <div class="form-tip">{{ $L('先保存设置，再测试生成；测试只显示结果，不发送消息。') }}</div>
                    <FormItem :label="$L('周报账号')"><Select v-model="previewUser" filterable><Option v-for="u in previewUsers" :key="u.userid" :value="u.userid">{{ u.nickname }}</Option></Select><Button :loading="testing" @click="preview('weekly')">{{ $L('预览周总结') }}</Button></FormItem>
                    <FormItem :label="$L('任务 ID')"><InputNumber v-model="previewTask" :min="1"/><Button :loading="testing" @click="preview('remind')">{{ $L('预览催办') }}</Button></FormItem>
                    <pre v-if="previewText" class="ai-preview">{{ previewText }}</pre>
                </div>
            </div>
            <div class="block-setting-space"></div>
            <div class="block-setting-box">
                <h3>{{ $L('最近发送记录') }}</h3>
                <div class="form-box">
                    <Button @click="history">{{ $L('刷新') }}</Button>
                    <div v-for="row in records" :key="row.id">{{ row.kind === 'weekly' ? $L('每周 AI 总结') : $L('任务会话 AI 催办') }} · ID {{ row.target_id }} · {{ row.period }} · {{ $L(statusNames[row.status] || row.status) }} · {{ row.sent_at || row.updated_at }}</div>
                    <div class="form-tip">{{ $L('发送中记录若长时间未变化，需管理员核对原会话；系统不会自动重发，避免重复。') }}</div>
                </div>
            </div>
        </Form>
        <div class="setting-footer"><Button type="primary" :loading="loading" @click="save">{{ $L('提交') }}</Button><Button @click="load">{{ $L('重置') }}</Button></div>
    </div>
</template>
<script>
import {mapState} from 'vuex';
export default {
    data() {
        return {form: null, projects: [], users: [], records: [], loading: false, testing: false,
            previewUser: null, previewTask: null, previewText: '',
            weekdays: ['星期一', '星期二', '星期三', '星期四', '星期五', '星期六', '星期日'],
            statusNames: {sent: '已发送', pending: '等待处理', sending: '发送中', skipped: '已跳过', failed: '生成或发送失败'}};
    },
    computed: {
        ...mapState(['formOptions']),
        previewUsers() { return this.users.filter(u => this.form.weekly_user_ids.includes(u.userid)); },
    },
    mounted() { this.load(); },
    methods: {
        call(method, data = {}) { return this.$store.dispatch('call', {url: 'aiautomation/' + method, method: ['save', 'preview'].includes(method) ? 'post' : 'get', data}); },
        async load() {
            this.loading = true;
            try {
                const [settings, options] = await Promise.all([this.call('settings'), this.call('options')]);
                this.form = settings.data;
                this.projects = options.data.projects;
                this.users = options.data.users;
                await this.history();
            } catch (e) { $A.messageError(e.msg || '加载失败'); }
            finally { this.loading = false; }
        },
        async save() {
            if (!this.form) return;
            this.loading = true;
            try { const res = await this.call('save', {settings: this.form}); this.form = res.data; $A.messageSuccess(res.msg); }
            catch (e) { $A.messageError(e.msg || '保存失败'); }
            finally { this.loading = false; }
        },
        async history() { const res = await this.call('history'); this.records = res.data; },
        async preview(kind) {
            this.testing = true;
            try { const res = await this.call('preview', {kind, userid: this.previewUser, task_id: this.previewTask}); this.previewText = res.data.text; }
            catch (e) { $A.messageError(e.msg || '生成失败'); }
            finally { this.testing = false; }
        },
    },
};
</script>
<style scoped>
.ai-preview {white-space: pre-wrap; overflow-wrap: anywhere; padding: 16px; background: var(--body-bg-color); line-height: 1.7;}
</style>
