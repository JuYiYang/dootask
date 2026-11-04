<template>
    <div class="global-notification-form">
        <template v-if="!results">
            <p class="notification-intro">{{ $L('向所选成员发送浏览器、桌面客户端和手机 App 通知。') }}</p>
            <Form label-position="top">
                <FormItem :label="$L('收件人')">
                    <UserSelect v-model="recipients" :show-select-all="false" :multiple-max="100" :disabled="!!submission"
                                :show-bot="false" :show-disable="false" avatar-name contextmenu-remove/>
                    <Button v-if="!recipients.includes(userId)" type="text" :disabled="!!submission || recipients.length >= 100"
                            @click="recipients = [...recipients, userId]">{{ $L('添加自己') }}</Button>
                </FormItem>
                <FormItem :label="$L('标题')">
                    <Input v-model="title" :maxlength="60" show-word-limit :disabled="!!submission"/>
                </FormItem>
                <FormItem :label="$L('正文')">
                    <Input v-model="body" type="textarea" :maxlength="300" :rows="5" show-word-limit :disabled="!!submission"/>
                </FormItem>
            </Form>
            <Button type="primary" :disabled="!canSend && !submission" :loading="sending" @click="confirmSend">
                {{ $L(submission ? '重试提交' : '发送通知') }}
            </Button>
        </template>
        <template v-else>
            <div class="result-toolbar">
                <h3>{{ $L('发送结果') }}</h3>
                <Button size="small" :loading="polling" @click="refresh">{{ $L('刷新状态') }}</Button>
            </div>
            <p class="notification-intro">{{ $L('触发回执表示客户端已调用通知，App 受理表示推送服务已接收请求。') }}</p>
            <div v-for="recipient in results" :key="recipient.userid" class="recipient">
                <UserAvatar :userid="recipient.userid" :size="28" show-name/>
                <div v-for="(label, channel) in channelLabels" :key="channel" class="channel">
                    <div>{{ $L(label) }}</div>
                    <div>
                        <strong :class="'state-' + recipient.channels[channel].status">{{ resultText(recipient.channels[channel]) }}</strong>
                        <details v-if="recipient.channels[channel].devices && recipient.channels[channel].devices.length > 1">
                            <summary>{{ $L('设备明细') }}</summary>
                            <div v-for="(device, index) in recipient.channels[channel].devices" :key="index">
                                {{ device.platform || $L('设备 (*)', index + 1) }} · {{ resultText(device) }}
                            </div>
                        </details>
                    </div>
                </div>
            </div>
            <Button @click="reset">{{ $L('新建通知') }}</Button>
        </template>
    </div>
</template>

<script>
import {mapState} from 'vuex';
import UserSelect from '../../../../components/UserSelect';
import {notificationStatusLabels, notificationReasonLabels} from '../../../../utils/taskNotificationReceipt';

const statuses = {
    ...notificationStatusLabels,
    offline: '客户端离线',
    unavailable: '无法发送',
    accepted: '推送服务已受理',
};
const reasons = {
    ...notificationReasonLabels,
    no_online_client: '发送时没有在线客户端',
    queue_failed: '通知投递任务提交失败',
    recipient_unavailable: '收件账号已不可用',
    dispatch_unconfirmed: '服务端投递结果未确认',
    socket_failed: '客户端连接已断开或投递失败',
    app_disabled: '系统未启用 App 推送',
    no_app_device: '没有已注册的 App 设备',
    app_permission_denied: 'App 设备未开启通知权限',
    app_not_configured: '该 App 平台未配置推送',
    provider_rejected: '推送服务拒绝请求',
    provider_unconfirmed: '推送服务受理结果未确认',
};

export default {
    components: {UserSelect},
    data() {
        return {
            title: '', body: '', recipients: [], submission: null, results: null,
            sending: false, polling: false, timer: null, pollUntil: 0,
            channelLabels: {browser: '浏览器', desktop: '桌面端', app: '手机 App'},
        };
    },
    computed: {
        ...mapState(['userId']),
        canSend() {
            return this.title.trim() && this.body.trim() && this.recipients.length > 0 && this.recipients.length <= 100;
        },
    },
    beforeDestroy() {
        this.submission = null;
        clearTimeout(this.timer);
    },
    methods: {
        confirmSend() {
            if (this.submission) {
                this.send();
                return;
            }
            if (!this.canSend || this.sending) return;
            $A.modalConfirm({
                title: this.$L('发送通知'),
                content: this.$L('确定向 (*) 位收件人发送通知吗？', this.recipients.length),
                language: false,
                onOk: () => {
                    if (this.submission || !this.canSend) return;
                    this.submission = {
                        request_id: $A.randomString(32),
                        title: this.title.trim(), body: this.body.trim(),
                        recipients: [...this.recipients],
                    };
                    this.send();
                },
            });
        },
        async send() {
            if (this.sending || !this.submission) return;
            const request = this.submission;
            this.sending = true;
            try {
                const {data} = await this.$store.dispatch('call', {
                    url: 'globalnotification/send', method: 'post', data: request,
                });
                if (this.submission !== request) return;
                this.results = data.recipients;
                this.pollUntil = Date.now() + 60000;
                this.scheduleRefresh();
            } catch (error) {
                if (this.submission !== request) return;
                // Validation failures are safe to edit. Transport failures retry the same snapshot.
                if (error?.ret === 0) this.submission = null;
                $A.messageError(error?.msg || '通知提交失败');
            } finally {
                this.sending = false;
            }
        },
        scheduleRefresh() {
            if (Date.now() < this.pollUntil && this.submission && this.results?.some(row =>
                Object.values(row.channels).some(channel => ['waiting', 'received', 'unconfirmed'].includes(channel.status)))) {
                this.timer = setTimeout(() => this.refresh(true), 2000);
            }
        },
        async refresh(automatic = false) {
            if (this.polling || !this.submission) return;
            clearTimeout(this.timer);
            const request = this.submission;
            this.polling = true;
            try {
                const {data} = await this.$store.dispatch('call', {
                    url: 'globalnotification/status', data: {request_id: request.request_id},
                });
                if (this.submission === request) this.results = data.recipients;
            } catch (error) {
                if (automatic !== true && this.submission === request) $A.messageError(error?.msg || '获取数据失败');
            } finally {
                this.polling = false;
                if (this.submission === request) this.scheduleRefresh();
            }
        },
        resultText(result) {
            const detail = result.reason ? ' · ' + this.$L(reasons[result.reason] || '未确认') : '';
            return this.$L(statuses[result.status] || '未确认') + detail + (result.code ? ' (' + result.code + ')' : '');
        },
        reset() {
            clearTimeout(this.timer);
            this.submission = null;
            this.results = null;
            this.title = '';
            this.body = '';
            this.recipients = [];
        },
    },
};
</script>

<style scoped lang="scss">
.global-notification-form {
    .notification-intro { margin: 0 0 18px; opacity: .75; line-height: 1.55; }
    .result-toolbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
    .recipient {
        margin: 0 0 12px;
        padding: 12px;
        border: 1px solid rgba(128, 128, 128, .3);
        border-radius: 8px;
    }
    .channel {
        display: grid;
        grid-template-columns: 100px minmax(0, 1fr);
        gap: 8px;
        margin-top: 8px;
    }
    .state-triggered, .state-accepted { color: #27824c; }
    .state-failed { color: #d45353; }
    details { margin-top: 5px; }
    summary { cursor: pointer; }
}
</style>
