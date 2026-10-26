<template>
    <div class="system-notice" @click.stop>
        <div class="notice-label">{{$L('系统通知')}}</div>
        <h3>{{msg.title}}</h3>
        <div class="notice-richtext notice-summary" v-html="msg.content"></div>
        <button type="button" class="notice-detail" @click="visible = true">{{$L('查看详情')}} <span>→</span></button>
        <Modal v-model="visible" class-name="system-notice-reader-modal" :fullscreen="expanded || windowPortrait" :closable="false" :mask-closable="false" footer-hide transfer>
            <SystemNoticeReader v-if="visible" :notice="msg" :msg-id="msgId" :fullscreen="expanded || windowPortrait" @close="visible = false" @fullscreen="expanded = !expanded"/>
        </Modal>
    </div>
</template>
<script>
import SystemNoticeReader from "../../SystemNoticeReader.vue";
export default {
    components: {SystemNoticeReader},
    props: {msg: Object, msgId: Number},
    data() { return {visible: false, expanded: false}; },
};
</script>
<style lang="scss" scoped>
.system-notice { min-width: 220px; max-width: 460px; border-top: 4px solid #3578d4; padding-top: 12px; }
.notice-label { color: #3578d4; font-size: 12px; font-weight: 600; margin-bottom: 8px; }
h3 { font-size: 18px; line-height: 1.5; margin: 0 0 12px; overflow-wrap: anywhere; }
.notice-summary { max-height: 260px; overflow: auto; }
.notice-detail { display: flex; justify-content: space-between; width: 100%; padding: 12px 0 0; margin-top: 12px; border: 0; border-top: 1px solid rgba(128,128,128,.2); background: transparent; color: #3578d4; font-size: inherit; cursor: pointer; }
.notice-richtext { line-height: 1.7; overflow-wrap: anywhere; overflow-x: auto;
    ::v-deep img { max-width: 100%; height: auto; }
    ::v-deep p { margin: .5em 0; }
    ::v-deep ul, ::v-deep ol { padding-left: 1.6em; }
    ::v-deep table { border-collapse: collapse; max-width: 100%; }
    ::v-deep td, ::v-deep th { border: 1px solid rgba(128,128,128,.3); padding: 6px 10px; }
    ::v-deep pre { white-space: pre-wrap; }
    ::v-deep a { color: #3578d4; text-decoration: underline; }
}

</style>

<style lang="scss">
.system-notice-reader-modal {
    overflow: hidden;
    .ivu-modal { width: 92vw !important; max-width: 1480px; height: 92vh; height: 92dvh; top: 4vh !important; margin: 0 auto;
        &.ivu-modal-fullscreen { width: 100vw !important; max-width: none; height: 100vh; height: 100dvh; top: 0 !important; }
    }
    .ivu-modal-content { height: 100%; overflow: hidden; }
    .ivu-modal-body { height: 100%; padding: 0 !important; overflow: hidden; }
}
</style>
