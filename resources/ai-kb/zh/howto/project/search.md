---
id: project.search.howto
title: 搜索 / 筛选项目
type: howto
feature: project
scope: end-user
locale: zh
aliases:
  - 找项目
  - 搜项目
  - 项目筛选
  - 找不到项目
  - 项目快搜
related_tools: [list_projects]
related_pages: [project_list]
prerequisites: []
negative:
  - 默认不显示已归档项目，要切到「已归档」筛选
  - 普通成员搜不到自己未加入的项目（即便项目名公开）
  - 不支持按项目内任务名 / 描述全文搜（要走 [[search 全局搜索]]）
last_verified: v1.7.90
---

# 搜索 / 筛选项目

## 入口
- **桌面端**：项目列表顶部搜索框
- **桌面端快捷键**：Cmd/Ctrl + P 唤起项目快搜
- **左侧栏**：项目分组顶部「🔍」按钮
- **移动端**：项目 Tab → 顶部搜索框

## 支持的筛选维度
| 维度 | 取值 | 说明 |
|---|---|---|
| 名称 | 关键字 | 模糊匹配 Project.name |
| 类型 | all / team / personal | 团队 / 个人 |
| 归档状态 | all / yes / no | 按 archived_at |
| 时间范围 | 起止日期 | 按 created_at |

## 操作步骤
1. 输入项目名关键字（中英文均可）
2. 可选切「类型 / 归档 / 时间」筛选
3. 列表实时过滤

## 普通成员 vs 管理员的差异
- 普通成员：只能搜到自己已加入的项目（ProjectUser 命中）
- 站点管理员（userIsAdmin）：能搜全站项目，包括没参与的

## 项目内全文搜
- 想搜项目内任务、消息、文件：走顶部全局搜（Cmd/Ctrl + K）唤起的全局搜索
- 搜索结果按权限过滤，看不到无权限的内容
- 全局搜底层用 Manticore，详细能力会在 search feature 的 chunk 起草后补充

## 不支持
- 不支持按"项目内任务标题"反向搜项目
- 不支持按拥有者筛选项目
- 不支持自定义"我喜欢的项目"标签筛选（仅置顶 / 排序，见 [[project.sort.howto]]）


## 侧栏任务快速定位
项目侧栏的任务搜索支持输入任务 ID 或完整标题。数字优先按 ID 查询；标题按返回结果进行全等匹配。找到后切换到所属项目、打开任务，并尝试滚动定位与短暂高亮；子任务定位到父任务卡片。仅能访问当前账号有权限的任务，同名标题及未返回分页中的结果可能无法唯一定位，优先使用任务 ID。
