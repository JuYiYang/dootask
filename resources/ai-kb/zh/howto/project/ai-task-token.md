---
id: project.ai-task-token.howto
title: 在项目设置中生成 AI 任务专用令牌
type: howto
feature: project
scope: end-user
locale: zh
aliases:
  - 给 AI 生成任务 Token
  - 导出我负责和协助的任务
  - AI 任务接口
related_tools: []
related_pages: [project_settings]
prerequisites:
  - 是项目负责人或项目管理员
negative:
  - 令牌不能调用普通登录接口或操作非所属账号任务
  - 非系统管理员不能为其他账号生成跨项目令牌
last_verified: v1.9.36
---

# 在项目设置中生成 AI 任务专用令牌

## 入口
项目顶部「⋯」→「项目设置」→「AI 任务专用令牌」。项目负责人和项目管理员可以进入。系统管理员可为本项目成员选择所属账号；其他项目管理者只能为自己生成。

## 操作步骤
1. 填写令牌名称，选择所属账号（如有权限），点击「生成令牌」。
2. 立即复制完整令牌。页面关闭后只保留末尾六位，无法再次查看原文。
3. 使用 `GET /api/projectaitask/tasks`，在 `Authorization` 请求头中传 `Bearer dai_...`。可使用 `page`、`per_page`（最多 100）和 `include_archived=1` 分页读取。
4. 为同一项目、同一账号重新生成令牌时，旧令牌立即失效。令牌没有到期日，也可主动撤销。所属账号失效或退出生成令牌的项目后，令牌也会失效。

## 返回范围
接口返回令牌所属账号在所有项目中作为负责人或协助人员的任务，包含已完成任务；默认排除已归档和已删除任务。每条任务包含所属项目、列表、状态、标题、描述、时间和该账号的身份。接口每个令牌每分钟最多请求 60 次。

## 调整状态与发表评论
- `GET /api/projectaitask/statuses?task_id=...`：查询该账号当前可操作的目标状态 ID、名称和颜色。
- `POST /api/projectaitask/status`：提交 `task_id` 和目标状态 `flow_item_id`。仅能操作所属账号负责或协助的任务；仍遵守该账号在项目中的状态修改权限、工作流流转规则及状态负责人限制。
- `POST /api/projectaitask/comment`：提交 `task_id` 和 `text`，向主任务讨论区发送纯文本评论，最多 5000 字；子任务暂不支持任务讨论。

两个写接口均使用相同的 `Authorization: Bearer dai_...` 请求头。专用令牌不能调用普通登录 API，也不能修改任务的其他字段。
