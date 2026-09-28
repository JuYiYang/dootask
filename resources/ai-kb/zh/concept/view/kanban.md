---
id: view.kanban.concept
title: 看板视图
type: concept
feature: view
scope: end-user
locale: zh
aliases:
  - 看板是什么
  - 项目看板
  - kanban
  - 任务列分栏
  - 看板视图怎么用
  - 拖拽任务改状态
related_tools: [list_tasks, get_project]
related_pages: [project_detail]
prerequisites: []
negative:
  - 触屏 / 移动端禁用列与任务的拖拽（避免误触），需用任务详情页改 column_id
  - 部门只读模式（department_readonly）下看板所有编辑操作被禁用
  - 一个项目至少要有一个列，删完全部列后无法新建任务
last_verified: v1.9.36
---

# 看板视图

## 定义
看板视图是 DooTask 项目详情页的默认视图（menuType='column'）。任务按「列」（column）水平分栏，每张任务卡按时间或优先级在列内纵向排列。整体类似 Trello 的卡片式工作流。

## 关键属性
- **列（column）**：项目级配置，由项目负责人 / 管理员维护；每条任务必属于一个列（task.column_id）
- **任务卡**：显示任务名 / 工作流状态 / 负责人头像 / 截止时间 / 标签
- **新增任务入口**：每列标题旁的「+」可在该列顶部新建
- **拖拽**：桌面端非触屏时，可把任务卡拖到另一列；目标列如关联状态，拖拽也会触发任务状态流转

## 看板列的管理
- 新增列：详见 [[view.kanban.howto.add-column]]
- 改色：列标题「···」→ 选预设颜色，详见 [[view.kanban.howto.column-color]]
- 重命名 / 删除：列标题「···」菜单
- 关联状态：列标题「···」→「关联状态」，每列可选一个工作流状态；不关联时拖入任务只改列表
- 拖动列顺序：抓住列头横向拖（桌面端）

## 与"工作流状态"的关系
- 列（column）是项目层面的固定分栏
- 工作流状态（flow_item）是任务自身的可流转字段
- 看板视图的"列"始终是 column，不会按 flow_item 分栏；按 flow_item 分栏走「工作流」筛选
- 只有跨列表拖入已关联状态的列才尝试改变状态；必须满足工作流流转规则和任务状态权限，失败时列表与状态均不改变

## 完成 / 隐藏
- 顶部「显示已完成」开关控制是否在每列展示已完成任务（默认隐藏）
- 项目设置可配置自动归档：完成 N 天后自动从看板移出
