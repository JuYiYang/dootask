<template>
    <div class="block-setting-box">
        <h3>{{$L('系统通知 Webhook')}}</h3>
        <div class="form-box">
            <FormItem :label="$L('状态')">
                <span>{{ $L(settings.enabled ? '开启' : '关闭') }}</span>
                <div class="form-tip">{{$L('通知固定发送到全体成员群，支持富文本卡片和详情。')}}</div>
                <div v-if="!settings.dialog_id" class="form-tip">{{$L('全体成员群聊不存在，无法发送系统通知')}}</div>
            </FormItem>
            <FormItem label="Webhook URL"><Input :value="settings.url" readonly/></FormItem>
            <FormItem v-if="token" label="Authorization">
                <Input :value="`Bearer ${token}`" readonly/>
                <div class="form-tip">{{$L('密钥仅显示一次，请立即保存；重新生成后旧密钥失效。')}}</div>
            </FormItem>
            <FormItem>
                <Button :loading="loading" :disabled="!ready || !settings.dialog_id" @click="regenerate">{{$L(settings.enabled ? '重新生成' : '生成密钥')}}</Button>
                <Button v-if="settings.enabled" :loading="loading" @click="update('revoke')">{{$L('停用')}}</Button>
                <div class="form-tip">{{$L('生成和停用立即生效，无需点击提交。')}}</div>
            </FormItem>
            <FormItem :label="$L('请求示例')"><pre class="webhook-example">{{example}}</pre></FormItem>
        </div>
    </div>
</template>
<script>
export default {
    data() {
        return {settings: {enabled: false, url: '', dialog_id: null}, token: '', loading: false, ready: false,
            example: JSON.stringify({event_id: 'release-20261026-001', title: '系统升级通知', content: '<p><strong>今晚 22:00</strong> 开始更新。</p>', detail: '<h2>更新安排</h2><p>预计耗时 10 分钟。</p><ul><li>请提前保存工作。</li></ul>'}, null, 2)};
    },
    mounted() {
        this.$store.dispatch('call', {url: 'systemnotice/settings'}).then(({data}) => {
            this.settings = data;
            this.ready = true;
        }).catch(({msg}) => $A.messageError(msg));
    },
    methods: {
        regenerate() {
            if (!this.settings.enabled) return this.update('regenerate');
            $A.modalConfirm({content: '重新生成后旧密钥立即失效，是否继续？', onOk: () => this.update('regenerate')});
        },
        async update(action) {
            this.loading = true;
            try {
                const {data} = await this.$store.dispatch('call', {url: `systemnotice/${action}`, method: 'post'});
                this.token = data.token || '';
                const {token, ...settings} = data;
                this.settings = settings;
                $A.messageSuccess('修改成功');
            } catch ({msg}) {
                $A.messageError(msg);
            } finally {
                this.loading = false;
            }
        },
    },
};
</script>
<style scoped>
.webhook-example { white-space: pre-wrap; overflow-wrap: anywhere; padding: 12px; background: rgba(128,128,128,.08); border-radius: 6px; line-height: 1.6; }
</style>
