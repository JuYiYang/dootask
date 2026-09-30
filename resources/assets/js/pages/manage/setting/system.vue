<template>
    <div class="setting-item submit">
        <Tabs v-model="tabAction">
            <TabPane :label="$L('基础设置')" name="general">
                <SystemSetting scope="general"/>
            </TabPane>
            <TabPane :label="$L('帐号与安全')" name="account">
                <SystemSetting scope="account"/>
            </TabPane>
            <TabPane :label="$L('项目设置')" name="project">
                <SystemSetting scope="project"/>
            </TabPane>
            <TabPane :label="$L('任务设置')" name="task">
                <SystemSetting scope="task"/>
            </TabPane>
            <TabPane :label="$L('消息设置')" name="message">
                <SystemSetting scope="message"/>
            </TabPane>
            <TabPane :label="$L('文件与存储')" name="file">
                <SystemSetting scope="file"/>
            </TabPane>
            <TabPane :label="$L('AI 自动跟进')" name="aiAutomation">
                <SystemAiAutomation/>
            </TabPane>
        </Tabs>
    </div>
</template>

<script>
import SystemSetting from "./components/SystemSetting";

import SystemAiAutomation from "./components/SystemAiAutomation.vue";

const VALID_TABS = ['general', 'account', 'project', 'task', 'message', 'file', 'aiAutomation'];
const LEGACY_TABS = {
    setting: 'general',
    taskPriority: 'task',
    columnTemplate: 'project',
    fileSetting: 'file',
};

export default {
    components: {SystemSetting, SystemAiAutomation},
    data() {
        return {
            tabAction: this.tabFromRoute(),
        }
    },
    watch: {
        // 支持深链直达指定分区：dootask://link/setting_system_xxx → query.tab
        '$route.query.tab'() {
            this.tabAction = this.tabFromRoute();
        },
    },
    methods: {
        tabFromRoute() {
            const tab = this.$route?.query?.tab;
            if (VALID_TABS.includes(tab)) {
                return tab;
            }
            return LEGACY_TABS[tab] || 'general';
        },
    },
}
</script>
