<template>
    <section class="notice-reader no-dark-content">
        <header class="reader-toolbar">
            <button type="button" class="reader-back" :title="$L('返回')" @click="$emit('close')"><Icon type="ios-arrow-back"/>{{$L('返回')}}</button>
            <span class="reader-title" :title="notice.title">{{notice.title}}</span>
            <div class="reader-tools">
                <button v-if="headings.length > 1" type="button" class="reader-directory" :aria-expanded="showOutline" @click="showOutline = !showOutline">{{$L('章节目录')}}</button>
                <button v-if="msgId && !standalone" type="button" @click="openWindow">{{$L('新窗口打开')}}</button>
                <button v-if="!standalone && !windowPortrait" type="button" @click="$emit('fullscreen')">{{$L(fullscreen ? '退出全屏' : '全屏')}}</button>
                <button type="button" :title="$L('关闭')" :aria-label="$L('关闭')" @click="$emit('close')"><Icon type="md-close"/></button>
            </div>
            <div class="reader-progress" aria-hidden="true"><i :style="{width: `${progress}%`}"></i></div>
        </header>
        <div ref="scroll" class="reader-scroll" @scroll.passive="onScroll">
            <div class="reader-layout" :class="{'has-outline': headings.length > 1, 'outline-open': showOutline}">
                <nav v-if="headings.length > 1" class="reader-outline" :aria-label="$L('章节目录')">
                    <strong>{{$L('章节目录')}}</strong>
                    <button v-for="(heading, index) in headings" :key="index" type="button" :class="{active: activeIndex === index, nested: heading.level > baseLevel}" :aria-current="activeIndex === index ? 'location' : null" @click="jumpTo(index)">{{heading.text}}</button>
                </nav>
                <article ref="article" class="reader-article user-select-auto" @click="onArticleClick" @load.capture="updatePosition" v-html="notice.detail"></article>
            </div>
        </div>
    </section>
</template>
<script>
export default {
    props: {
        notice: {type: Object, required: true},
        msgId: {type: Number, default: 0},
        fullscreen: Boolean,
        standalone: Boolean,
    },
    data() {
        return {headings: [], activeIndex: 0, showOutline: false, progress: 0, frame: null};
    },
    computed: {
        baseLevel() { return Math.min(...this.headings.map(item => item.level)); },
    },
    watch: {
        'notice.detail'() { this.$nextTick(this.collectHeadings); },
        fullscreen() { this.$nextTick(this.updatePosition); },
    },
    mounted() {
        this.collectHeadings();
        window.addEventListener('resize', this.updatePosition);
    },
    beforeDestroy() {
        window.removeEventListener('resize', this.updatePosition);
        if (this.frame !== null) cancelAnimationFrame(this.frame);
    },
    methods: {
        collectHeadings() {
            const article = this.$refs.article;
            if (!article) return;
            this.headings = Array.from(article.querySelectorAll('h1,h2,h3,h4'))
                .map(element => ({text: element.textContent.trim(), level: Number(element.tagName.slice(1)), element}))
                .filter(item => item.text);
            this.updatePosition();
        },
        jumpTo(index) {
            this.showOutline = false;
            this.$nextTick(() => {
                const scroll = this.$refs.scroll;
                const heading = this.headings[index];
                if (!scroll || !heading) return;
                const top = heading.element.getBoundingClientRect().top - scroll.getBoundingClientRect().top + scroll.scrollTop - 24;
                scroll.scrollTo({top: Math.max(0, top), behavior: 'smooth'});
                this.activeIndex = index;
            });
        },
        onScroll() {
            if (this.frame !== null) return;
            this.frame = requestAnimationFrame(() => { this.frame = null; this.updatePosition(); });
        },
        updatePosition() {
            const scroll = this.$refs.scroll;
            if (!scroll) return;
            const available = scroll.scrollHeight - scroll.clientHeight;
            this.progress = available > 0 ? Math.max(0, Math.min(100, scroll.scrollTop / available * 100)) : 100;
            const threshold = scroll.getBoundingClientRect().top + 48;
            let active = 0;
            this.headings.forEach((heading, index) => {
                if (heading.element.getBoundingClientRect().top <= threshold) active = index;
            });
            this.activeIndex = active;
        },
        onArticleClick(event) {
            if (event.target.tagName !== 'IMG') return;
            event.preventDefault();
            event.stopPropagation();
            const images = Array.from(this.$refs.article.querySelectorAll('img'));
            this.$store.dispatch('previewImage', {
                index: images.indexOf(event.target),
                list: images.map(image => ({src: image.currentSrc || image.src, width: image.naturalWidth || -1, height: image.naturalHeight || -1})),
            });
        },
        openWindow() {
            if (!this.msgId) return;
            this.$store.dispatch('openWindow', {
                name: `system-notice-${this.msgId}`,
                path: `/single/notice/${this.msgId}`,
                title: this.notice.title,
                titleFixed: true,
                mode: 'window', width: 1180, height: 860, minWidth: 480, minHeight: 480,
            });
        },
    },
};
</script>
<style lang="scss" scoped>
.notice-reader { display: flex; flex-direction: column; height: 100%; min-height: 0; background: #f5f6f8; color: #252930; }
.reader-toolbar { display: flex; align-items: center; flex-shrink: 0; position: relative; gap: 16px; min-height: 58px; padding: 8px 20px; background: #fff; border-bottom: 1px solid #e8eaf0;
    button { display: inline-flex; align-items: center; justify-content: center; gap: 4px; padding: 7px 10px; border: 0; border-radius: 6px; background: transparent; color: #555f70; font-size: 13px; cursor: pointer; white-space: nowrap;
        &:hover, &:focus-visible { background: #edf3fc; color: #2469bd; }
    }
}
.reader-title { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 13px; color: #737c8a; }
.reader-tools { display: flex; align-items: center; gap: 4px; }
.reader-toolbar .reader-directory { display: none; }
.reader-progress { position: absolute; height: 2px; left: 0; right: 0; bottom: -1px; i { display: block; height: 100%; background: #3578d4; } }
.reader-scroll { flex: 1; min-height: 0; overflow-y: auto; overflow-x: hidden; overscroll-behavior: contain; }
.reader-layout { max-width: 960px; margin: 0 auto; padding: 28px 24px 60px;
    &.has-outline { display: grid; grid-template-columns: 200px minmax(0, 1fr); gap: 24px; max-width: 1200px; }
}
.reader-outline { position: sticky; top: 24px; align-self: start; font-size: 13px; padding: 8px 0;
    strong { display: block; font-weight: 600; margin: 0 10px 12px; color: #808997; }
    button { display: block; width: 100%; border: 0; border-left: 2px solid transparent; background: transparent; text-align: left; padding: 8px 12px; color: #697383; font-size: inherit; line-height: 1.6; cursor: pointer; overflow-wrap: anywhere;
        &.active { color: #2469bd; border-left-color: #3578d4; background: #eaf0f9; }
        &.nested { padding-left: 22px; }
    }
}
.reader-article { min-width: 0; padding: 28px; background: #fff; border: 1px solid #e8eaf0; border-radius: 8px; line-height: 1.8; overflow-wrap: anywhere;
    ::v-deep * { box-sizing: border-box; max-width: 100%; }
    ::v-deep > div:only-child { max-width: none !important; margin: 0 !important; padding: 0 !important; }
    ::v-deep img { display: block; max-width: 100% !important; width: auto !important; height: auto !important; max-height: min(52vh, 520px) !important; object-fit: contain; margin: 16px auto; cursor: zoom-in; }
    ::v-deep p { margin: .6em 0; }
    ::v-deep h1, ::v-deep h2, ::v-deep h3, ::v-deep h4 { line-height: 1.45; }
    ::v-deep ul, ::v-deep ol { padding-left: 1.6em; }
    ::v-deep table { border-collapse: collapse; }
    ::v-deep td, ::v-deep th { border: 1px solid #e2e6ed; padding: 6px 10px; }
    ::v-deep pre { white-space: pre-wrap; }
    ::v-deep a { color: #3578d4; text-decoration: underline; }
}
@media (max-width: 960px) {
    .reader-layout.has-outline { display: block; }
    .reader-outline { display: none; position: static; margin-bottom: 20px; }
    .outline-open .reader-outline { display: block; }
    .reader-toolbar .reader-directory { display: inline-flex; }
}
@media (max-width: 600px) {
    .reader-toolbar { min-height: 52px; padding: 8px; gap: 4px; }
    .reader-title { display: none; }
    .reader-tools { margin-left: auto; }
    .reader-layout { padding: 12px 8px 32px; }
    .reader-article { padding: 16px 12px; border-radius: 4px; }
}
</style>
