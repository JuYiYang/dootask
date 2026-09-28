<template>
    <div class="project-ai-task-tokens">
        <h3>{{$L('AI 任务专用令牌')}}</h3>
        <p class="form-tip">{{$L('令牌可读取所属账号跨项目的负责或协助任务，并按账号权限调整状态、发表评论。永久有效；为同一账号重新生成时旧令牌立即失效。')}}</p>
        <FormItem v-if="userIsAdmin" :label="$L('所属账号')">
            <UserSelect v-model="selectedUsers" :project-id="projectId" :multiple-max="1" :title="$L('选择令牌所属账号')"/>
        </FormItem>
        <FormItem :label="$L('令牌名称')">
            <Input v-model="name" :maxlength="100" :placeholder="$L('例如：AI 助手')"/>
        </FormItem>
        <Button type="primary" :loading="creating" @click="createToken">{{$L('生成令牌')}}</Button>
        <div v-if="newToken" class="token-result">
            <p>{{$L('请立即复制并保存，关闭后无法再次查看完整令牌。')}}</p>
            <Input :value="newToken" readonly/>
            <Button @click="copyToken">{{$L('复制令牌')}}</Button>
        </div>
        <div class="token-endpoint">
            <p>{{$L('请求地址')}}</p>
            <Input :value="endpoint" readonly/>
            <p class="form-tip">{{$L('请求头使用 Authorization: Bearer 令牌。GET tasks 查询任务；GET statuses 查询可选状态；POST status 提交 task_id、flow_item_id；POST comment 提交 task_id、text。')}}</p>
        </div>
        <h4>{{$L('已生成的令牌')}}</h4>
        <div v-if="!tokens.length" class="form-tip">{{$L('暂无令牌')}}</div>
        <div v-for="item in tokens" :key="item.id" class="token-row">
            <div>
                <strong>{{item.name}}</strong>
                <UserAvatar v-if="userIsAdmin" :userid="item.userid" :size="20" showName/>
                <span>••••{{item.token_suffix}}</span>
                <div class="form-tip">{{item.revoked_at ? $L('已撤销') : $L('永久有效')}}</div>
            </div>
            <Button v-if="!item.revoked_at" size="small" @click="revokeToken(item)">{{$L('撤销')}}</Button>
        </div>
    </div>
</template>

<script>
import {mapState} from 'vuex';
import UserSelect from '../../../components/UserSelect.vue';

export default {
    name: 'ProjectAiTaskTokens',
    components: {UserSelect},
    props: {projectId: {type: Number, required: true}},
    data() {
        return {selectedUsers: [], name: '', tokens: [], newToken: '', creating: false};
    },
    computed: {
        ...mapState(['userIsAdmin', 'userId']),
        endpoint() {
            return $A.apiUrl('projectaitask/tasks');
        }
    },
    mounted() {
        this.loadTokens();
    },
    methods: {
        loadTokens() {
            this.$store.dispatch('call', {
                url: 'projectaitask/tokens',
                data: {project_id: this.projectId}
            }).then(({data}) => {
                this.tokens = data || [];
            }).catch(({msg}) => $A.modalError(msg));
        },
        createToken() {
            const userid = this.userIsAdmin ? this.selectedUsers[0] : this.userId;
            if (!userid || !this.name.trim()) {
                $A.messageWarning('请选择账号并填写令牌名称');
                return;
            }
            this.creating = true;
            this.$store.dispatch('call', {
                url: 'projectaitask/create',
                method: 'post',
                data: {project_id: this.projectId, userid, name: this.name.trim()}
            }).then(({data, msg}) => {
                this.newToken = data.token;
                this.name = '';
                $A.messageSuccess(msg);
                this.loadTokens();
            }).catch(({msg}) => $A.modalError(msg)).finally(() => {
                this.creating = false;
            });
        },
        copyToken() {
            this.copyText(this.newToken);
        },
        revokeToken(item) {
            $A.modalConfirm({
                title: '撤销令牌',
                content: '撤销后，使用该令牌的 AI 接口会立即失效。',
                onOk: () => this.$store.dispatch('call', {
                    url: 'projectaitask/revoke',
                    method: 'post',
                    data: {project_id: this.projectId, id: item.id}
                }).then(({msg}) => {
                    $A.messageSuccess(msg);
                    this.loadTokens();
                })
            });
        }
    }
}
</script>

<style lang="scss" scoped>
.project-ai-task-tokens {
    border-top: 1px solid #eee;
    padding: 20px 0;
    h3, h4 { margin: 0 0 12px; }
    .token-result, .token-endpoint { margin: 16px 0; }
    .token-result .ivu-btn { margin-top: 8px; }
    .token-row { display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #eee; padding: 10px 0; }
    .token-row span { margin-left: 8px; }
}
</style>
