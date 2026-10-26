<template>
    <div class="single-notice">
        <PageTitle :title="notice ? notice.title : $L('系统通知')"/>
        <Loading v-if="loading"/>
        <SystemNoticeReader v-else-if="notice" :notice="notice" :msg-id="messageId" fullscreen standalone @close="close"/>
        <div v-else class="notice-error"><p>{{error}}</p><Button @click="close">{{$L('返回')}}</Button></div>
    </div>
</template>
<script>
import SystemNoticeReader from "../manage/components/SystemNoticeReader.vue";
export default {
    components: {SystemNoticeReader},
    data() { return {loading: false, notice: null, error: '', requestId: 0}; },
    computed: {
        messageId() { return /^\d+$/.test(this.$route.params.msgId) ? Number(this.$route.params.msgId) : 0; },
    },
    watch: {
        messageId: {immediate: true, handler() { this.load(); }},
    },
    methods: {
        async load() {
            const requestId = ++this.requestId;
            this.notice = null;
            this.error = '';
            this.loading = true;
            try {
                if (!this.messageId) throw {msg: this.$L('消息不存在或已被删除')};
                const {data} = await this.$store.dispatch('call', {url: 'dialog/msg/one', data: {msg_id: this.messageId}});
                if (requestId !== this.requestId) return;
                if (data.type !== 'template' || data.msg?.type !== 'system_notice') throw {msg: this.$L('消息不存在或已被删除')};
                this.notice = data.msg;
            } catch ({msg}) {
                if (requestId === this.requestId) this.error = msg || this.$L('加载失败');
            } finally {
                if (requestId === this.requestId) this.loading = false;
            }
        },
        close() { this.$router.push({name: 'manage-messenger'}); },
    },
};
</script>
<style scoped>
.single-notice { height: 100vh; height: 100dvh; overflow: hidden; }
.notice-error { padding: 48px 20px; text-align: center; }
.notice-error p { margin-bottom: 20px; }
</style>
