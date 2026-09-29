---
id: email-notice.config.howto
title: 配置邮件 SMTP 服务器
type: howto
feature: email-notice
scope: admin
locale: zh
aliases:
  - 配置 SMTP
  - 设置邮箱服务器
  - 邮件配置
  - SMTP 怎么配
  - 系统怎么发邮件
  - 邮箱设置
related_tools: []
related_pages: []
prerequisites:
  - 需要系统管理员权限
  - 已申请到 SMTP 账号（如 qq / 阿里云邮 / 自建邮件服务）
negative:
  - 当环境变量 SYSTEM_SETTING=disabled 时禁止从界面修改邮箱设置
  - 不支持选择 SSL/TLS 加密方式，默认使用 STARTTLS（端口决定）
  - 密码字段不能为空（即便部分 SMTP 服务允许匿名）
last_verified: v1.9.36
---

# 配置邮件 SMTP 服务器

## 入口
桌面端：左侧栏「应用」→「管理员」分区 →「邮件通知」

## 操作步骤
1. 「邮箱服务器设置」区填写：
   - SMTP 服务器（如 `smtp.exmail.qq.com`）
   - 端口（25 / 465 / 587 视服务商）
   - 账号（完整邮箱地址，做发件人和登录名）
   - 密码（一般是邮箱授权码，非登录密码）
2. 点「邮件发送测试」按钮验收（详见 [[email-notice.send-test.howto]]）
3. 在「邮件通知设置」区按需开启「注册验证」「消息提醒」
4. 在「任务邮件汇报」区开启定时汇报，设置每天或工作日、汇报时间及任务范围
5. 在「忽略邮箱地址」区可填入不发邮件的地址清单（换行分隔）
6. 底部「提交」保存

## 关键字段
| 字段 | 说明 | 默认 |
|---|---|---|
| reg_verify | 开启后新账号需验证邮箱、改邮箱要验证码 | close |
| notice_msg | 开启后会按规则发未读消息汇总邮件 | close |
| msg_unread_user_minute | 单聊未读多少分钟后发邮件，-1=不发 | -1 |
| msg_unread_group_minute | 群聊未读多少分钟后发邮件，-1=不发 | -1 |
| msg_unread_time_ranges | 仅在这些时间段内允许发未读邮件 | 空 |
| ignore_addr | 忽略地址列表 | 空 |
| task_report_enabled | 定时任务汇报开关 | close |
| task_report_time | 按服务器时区发送的时间 | 09:00 |
| task_report_days | 每天或工作日 | daily |
| task_report_scope | 全部未完成任务，或仅逾期及今日到期任务 | all |

## 任务汇报规则
- 每分钟检查设置，到指定时间后，按账号汇总其作为负责人或协助人的跨项目任务。
- 只包含账号仍参与的项目中的未完成、未归档任务；无相关任务、邮箱无效或在忽略列表中的账号不发送。
- 同一账号每天最多发送一封。发送失败会在当天后续检查中重试。
- 邮件正文是完整 HTML 富文本：优先突出逾期和今日到期任务，逐项显示项目、状态、角色与截止时间。
- 点击任一任务会打开该任务详情，链接格式为 `https://work.jikejiacn.com/single/task/{任务ID}`；页尾可快捷打开工作台。`TASK_REPORT_BASE_URL` 可覆盖公开域名，默认是 `https://work.jikejiacn.com`。
- 初次配置时任务汇报默认关闭；修改汇报时间不会补发以前日期的邮件。

## 不支持
- DooTask 不内置邮件服务器，必须接外部 SMTP
- 修改后立即生效，无需重启
