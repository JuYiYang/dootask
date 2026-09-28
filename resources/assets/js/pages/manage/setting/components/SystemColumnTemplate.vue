<template>
    <div :class="{'setting-component-item': !embedded}">
        <div>
            <Row class="setting-template">
                <Col span="7">{{$L('名称')}}</Col>
                <Col span="16">{{$L('项目模板')}}</Col>
                <Col span="1" class="setting-row-action"></Col>
            </Row>
            <Row v-for="(item, key) in formDatum" :key="key" class="setting-template">
                <Col span="7">
                    <Input
                        v-model="item.name"
                        :maxlength="20"
                        :placeholder="$L('请输入名称')"/>
                </Col>
                <Col span="16">
                    <TagInput v-if="!item.config" v-model="item.columns"/>
                    <div v-else class="form-tip">{{item.columns.replaceAll(',', '、')}}</div>
                    <Checkbox :value="!!item.default" @on-change="setDefault(key, $event)">{{$L('默认模板')}}</Checkbox>
                    <Button type="text" size="small" @click="openSource(key)">{{$L('从项目复制')}}</Button>
                </Col>
                <Col span="1" class="setting-row-action">
                    <Tooltip :content="formDatum.length > 1 ? $L('删除') : $L('至少保留一项')" placement="top" transfer>
                        <Button
                            type="text"
                            icon="ios-trash-outline"
                            :disabled="formDatum.length <= 1"
                            @click="delDatum(key)"/>
                    </Tooltip>
                </Col>
            </Row>
            <Button class="setting-add-action" type="default" icon="md-add" @click="addDatum">{{$L('添加模板')}}</Button>
        </div>
        <Modal v-model="sourceShow" :title="$L('从项目复制')" :mask-closable="false">
            <div class="form-tip">{{$L('将来源项目的列表、工作流和关联状态复制到此模板。')}}</div>
            <Select v-model="sourceProjectId" filterable transfer :placeholder="$L('选择项目')">
                <Option v-for="project in sourceProjects" :key="project.id" :value="project.id">{{project.name}}</Option>
            </Select>
            <div slot="footer">
                <Button @click="sourceShow=false">{{$L('取消')}}</Button>
                <Button type="primary" :loading="sourceLoad" :disabled="!sourceProjectId" @click="copySource">{{$L('导入配置')}}</Button>
            </div>
        </Modal>
        <div v-if="!embedded" class="setting-footer">
            <Button :loading="loadIng > 0" type="primary" @click="submitForm">{{$L('提交')}}</Button>
            <Button :loading="loadIng > 0" @click="resetForm">{{$L('重置')}}</Button>
        </div>
    </div>
</template>

<script>
import {mapState} from "vuex";

export default {
    name: 'SystemColumnTemplate',
    props: {
        embedded: Boolean,
    },
    data() {
        return {
            loadIng: 0,

            formDatum: [],
            sourceShow: false,
            sourceLoad: false,
            sourceProjects: [],
            sourceProjectId: 0,
            sourceTarget: -1,

            nullDatum: {
                'name': '',
                'columns': '',
                'default': false,
            }
        }
    },

    mounted() {
        this.systemSetting();
    },

    computed: {
        ...mapState(['columnTemplate']),
    },

    watch: {
        columnTemplate: {
            handler(data) {
                this.formDatum = $A.cloneJSON(data);
                if (this.formDatum.length === 0) {
                    this.addDatum();
                }
            },
            immediate: true,
        }
    },

    methods: {
        submitForm() {
            this.systemSetting(true);
        },

        resetForm() {
            this.formDatum = $A.cloneJSON(this.columnTemplate);
        },

        addDatum() {
            this.formDatum.push($A.cloneJSON(this.nullDatum));
        },

        setDefault(index, checked) {
            this.formDatum.forEach((item, key) => this.$set(item, 'default', checked && key === index));
        },

        openSource(index) {
            this.sourceTarget = index;
            this.sourceProjectId = 0;
            this.sourceShow = true;
            this.$store.dispatch('call', {
                url: 'system/column/template?type=projects',
                method: 'post',
            }).then(({data}) => {
                this.sourceProjects = data;
            }).catch(({msg}) => $A.modalError(msg));
        },

        copySource() {
            if (!this.sourceProjectId || this.sourceTarget < 0) return;
            this.sourceLoad = true;
            this.$store.dispatch('call', {
                url: 'system/column/template?type=snapshot',
                method: 'post',
                data: {project_id: this.sourceProjectId},
            }).then(({data}) => {
                const current = this.formDatum[this.sourceTarget];
                this.$set(this.formDatum, this.sourceTarget, {
                    ...data,
                    name: current.name || data.name,
                    columns: data.columns.join(','),
                    default: !!current.default,
                });
                this.sourceShow = false;
            }).catch(({msg}) => $A.modalError(msg)).finally(() => {
                this.sourceLoad = false;
            });
        },

        delDatum(key) {
            if (this.formDatum.length <= 1) {
                return;
            }
            $A.modalConfirm({
                title: '确认删除',
                content: '确定要删除该模板吗？',
                onOk: () => {
                    this.formDatum.splice(key, 1);
                },
            });
        },

        systemSetting(save, silent = false) {
            this.loadIng++;
            return this.$store.dispatch("call", {
                url: 'system/column/template?type=' + (save ? 'save' : 'get'),
                method: 'post',
                data: {
                    list: this.formDatum
                },
            }).then(({data}) => {
                if (save && !silent) {
                    $A.messageSuccess('修改成功');
                }
                this.$store.state.columnTemplate = $A.cloneJSON(data).map(item => {
                    if ($A.isArray(item.columns)) {
                        item.columns = item.columns.join(",")
                    }
                    return item;
                });
            }).catch(({msg}) => {
                if (save && !silent) {
                    $A.modalError(msg);
                }
                if (silent) {
                    return Promise.reject(msg);
                }
            }).finally(_ => {
                this.loadIng--;
            });
        }
    }
}
</script>
