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
  - 令牌不能调用普通登录接口或操作非所属账号且非待处理的任务
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
接口返回令牌所属账号在所有项目中作为负责人或协助人员的任务（身份为 `owner` 或 `assistant`），包含已完成任务；同时返回生成令牌所在项目中所有处于「待处理」状态的未归档任务（身份为 `unassigned`）。默认排除已归档和已删除任务。每条任务包含所属项目、列表、状态、标题、描述、时间和该账号的身份，并包含正文截图及图片附件的完整 URL 数组 `images`。接口每个令牌每分钟最多请求 60 次。

## 查询任务详情（正文、截图与附件）
- `GET /api/projectaitask/detail?task_id=...`（或 `/api/projectaitask/task`）：查询指定任务的完整详情，包含任务标题、所属项目与列表、状态与优先级、负责人与协助人列表、富文本正文 `content`、纯文本正文 `content_text`、**正文截图及图片附件的完整 URL 数组 `images`**，以及附件文件列表 `files`（名称、大小、格式、下载地址）。

## 查询动态与讨论记录
- `GET /api/projectaitask/logs?task_id=...`：查询任务的所有历史动态（包括子任务变更记录、状态流转、成员指派等），包含操作人、操作详情、变更数据与发生时间，支持 `page` 和 `per_page` 分页。
- `GET /api/projectaitask/discussion?task_id=...`（或 `/api/projectaitask/comments`）：查询主任务的所有讨论消息（文本、附件等），包含发送人、消息类型、正文内容与发送时间，支持 `page` 和 `per_page` 分页；若尚未开启讨论则返回空列表。

## 调整状态、修改任务与发表评论
- `GET /api/projectaitask/statuses?task_id=...`：查询该账号或本项目待处理任务当前可操作的目标状态 ID、名称和颜色。
- `POST /api/projectaitask/status`：提交 `task_id` 和目标状态 `flow_item_id`。可操作所属账号负责/协助的任务，或本项目内处于「待处理」状态的任务。待处理任务流转时，若任务原本无负责人会自动认领为负责人，若已有负责人会自动添加为协助人，并自动同步加入任务讨论。
- `POST /api/projectaitask/comment`：提交 `task_id` 和 `text`，向主任务讨论区发送纯文本评论，最多 5000 字；子任务暂不支持任务讨论。本项目待处理任务同样允许直接评论。
- `POST /api/projectaitask/update`：提交 `task_id` 以及待修改字段（如 `name` 任务名称、`desc` 描述、`p_level` 优先级等），修改所属账号负责/协助或本项目待处理的任务。
- `POST /api/projectaitask/create_task`（或 `/api/projectaitask/add`）：在令牌所属项目内创建新任务，支持传入 `name` 标题、`column_id` 列表 ID 或名称、`p_level` 优先级、`owner` 负责人与 `assist` 协助人。

所有读写接口均使用相同的 `Authorization: Bearer dai_...` 请求头。专用令牌不能调用普通登录 API。
