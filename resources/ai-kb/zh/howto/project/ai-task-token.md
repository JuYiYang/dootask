---
id: project.ai-task-token.howto
title: 在项目设置中生成 AI 任务只读令牌
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
  - 令牌不能调用普通登录接口或修改任务
  - 非系统管理员不能为其他账号生成跨项目令牌
last_verified: v1.9.36
---

# 在项目设置中生成 AI 任务只读令牌

## 入口
项目顶部「⋯」→「项目设置」→「AI 任务只读令牌」。项目负责人和项目管理员可以进入。系统管理员可为本项目成员选择所属账号；其他项目管理者只能为自己生成。

## 操作步骤
1. 填写令牌名称，选择所属账号（如有权限），点击「生成令牌」。
2. 立即复制完整令牌。页面关闭后只保留末尾六位，无法再次查看原文。
3. 使用 `GET /api/projectaitask/tasks`，在 `Authorization` 请求头中传 `Bearer dai_...`。可使用 `page`、`per_page`（最多 100）和 `include_archived=1` 分页读取。
4. 不再使用时，在同一位置撤销令牌。令牌有效期为 90 天，也可提前撤销。所属账号失效或退出生成令牌的项目后，令牌也会失效。

## 返回范围
接口返回令牌所属账号在所有项目中作为负责人或协助人员的任务，包含已完成任务；默认排除已归档和已删除任务。每条任务包含所属项目、列表、状态、标题、描述、时间和该账号的身份。接口每个令牌每分钟最多请求 60 次。令牌仅能使用此只读接口，不能访问其他 API 或修改任务。
