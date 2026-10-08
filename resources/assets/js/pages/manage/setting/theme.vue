<template>
    <div class="setting-item submit">
        <Form ref="formData" :model="formData" :rules="ruleData" v-bind="formOptions" @submit.native.prevent>
            <FormItem :label="$L('选择主题')" prop="theme">
                <Select v-model="formData.theme" :placeholder="$L('选项主题')">
                    <Option v-for="(item, index) in themeList" :value="item.value" :key="index">{{$L(item.name)}}</Option>
                </Select>
            </FormItem>
            <FormItem :label="$L('字体大小')">
                <Select v-model="formData.font_size" transfer>
                    <Option :value="0">{{$L('跟随系统')}}</Option>
                    <Option v-for="size in [12, 13, 14, 15, 16, 17, 18, 19, 20]" :key="size" :value="size">{{size}}px</Option>
                </Select>
                <div class="form-tip">{{$L('仅对当前账号生效，其他设备刷新后同步。')}}</div>
            </FormItem>
        </Form>
        <div class="setting-footer">
            <Button :loading="loadIng > 0" type="primary" @click="submitForm">{{$L('提交')}}</Button>
            <Button :loading="loadIng > 0" @click="resetForm">{{$L('重置')}}</Button>
        </div>
    </div>
</template>

<script>
import {mapState} from "vuex";

export default {
    data() {
        return {
            loadIng: 0,

            formData: {
                theme: '',
                font_size: 0,
            },

            ruleData: { },
        }
    },

    mounted() {
        this.initData();
    },

    computed: {
        ...mapState([
            'themeConf',
            'themeList',
            'formOptions'
        ])
    },

    methods: {
        async initData() {
            this.loadIng++;
            const userid = this.userId;
            try {
                const {data} = await this.$store.dispatch('call', {url: 'appearance/settings'});
                if (this.userId !== userid) return;
                this.$store.state.accountAppearance = {userid, font_size: data.font_size};
                this.$set(this.formData, 'theme', this.themeConf);
                this.$set(this.formData, 'font_size', data.font_size ?? 0);
                this.formData_bak = $A.cloneJSON(this.formData);
            } catch (e) { $A.messageError(e.msg || '加载失败'); }
            finally { this.loadIng--; }
        },

        submitForm() {
            if (this.loadIng > 0) return;
            this.$refs.formData.validate(async (valid) => {
                if (!valid) return;
                this.loadIng++;
                const userid = this.userId;
                try {
                    const {data} = await this.$store.dispatch('call', {
                        url: 'appearance/save', method: 'post',
                        data: {font_size: this.formData.font_size || null},
                    });
                    if (this.userId !== userid) return;
                    this.$store.state.accountAppearance = {userid, font_size: data.font_size};
                    if (this.formData.theme !== this.themeConf) {
                        await this.$store.dispatch('setTheme', this.formData.theme);
                    }
                    this.formData_bak = $A.cloneJSON(this.formData);
                    $A.messageSuccess('保存成功');
                    $A.Electron?.sendMessage('recreatePreloadPool');
                } catch (e) { $A.messageError(e.msg || '保存失败'); }
                finally { this.loadIng--; }
            });
        },

        resetForm() {
            if (this.formData_bak) this.formData = $A.cloneJSON(this.formData_bak);
        }
    }
}
</script>
