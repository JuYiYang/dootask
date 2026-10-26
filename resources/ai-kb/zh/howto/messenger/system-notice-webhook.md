---
id: messenger.system-notice-webhook.howto
title: 向全体成员群发送系统通知 Webhook
type: howto
feature: messenger
scope: end-user
locale: zh
aliases: [系统通知卡片, 全员群通知, 富文本通知 Webhook]
related_tools: []
related_pages: [system_settings, messenger]
prerequisites: [系统管理员生成独立 Webhook 密钥, 已存在全体成员群]
negative:
  - 只能发送到全体成员群，不能指定任意群聊或私聊
  - 不接受 AI 任务 Token 或普通登录 Token 代替独立密钥
  - 密钥只在生成时返回，重新生成或停用后旧密钥失效
last_verified: v1.9.36
---

# 向全体成员群发送系统通知 Webhook

## 配置
系统管理员进入「系统设置 → 消息设置 → 系统通知 Webhook」，生成并保存密钥。生成、重新生成和停用立即生效。只存储密钥的 SHA-256 摘要；设置查询不返回密钥。默认未启用。

## 调用
向设置显示的 `POST /api/systemnotice/send` 发起 JSON 请求，在请求头传 `Authorization: Bearer snw_...`。

```json
{
  "event_id": "release-20261026-001",
  "title": "系统升级通知",
  "content": "<p><strong>今晚 22:00</strong> 开始更新。</p>",
  "detail": "<h2>更新安排</h2><p>预计耗时 10 分钟。</p><ul><li>请提前保存工作。</li></ul>"
}
```

- `event_id`：必填，最多 100 个字符，只允许字母、数字、点、下划线、冒号和短横线。同一事件重试必须复用；相同 ID 不再次发消息，返回第一次的 `message_id`、`dialog_id` 和 `duplicate: true`。内容变更需要新的事件 ID。重新生成密钥不清除去重记录。
- `title`：普通文本标题，最多 100 字；`content`：卡片富文本 HTML，最多 10000 字符；`detail`：完整详情富文本 HTML，最多 50000 字符。四个字段都必填。
- 支持段落、加粗、颜色、列表、表格、链接、图片。服务端清理脚本、事件处理器、危险 URL 等不安全内容。
- 固定以「系统消息」机器人身份向当前全体成员群发送，不代替某个员工发言。群聊不存在则报错，不创建群、不变更成员。
- 返回 `ret=1` 代表成功，`data` 含 `message_id`、`dialog_id`、`duplicate`。每分钟最多 60 次；失败或网络超时时使用相同 `event_id` 重试。

## 查看
全员群内展示标题和富文本摘要，点击「查看详情」打开文章阅读层：桌面端接近全屏，移动端全屏，正文居中且只有主阅读区纵向滚动。顶部可返回、切换全屏或在新窗口打开；自动根据正文标题生成章节目录，点击可跳转。大图等比限制为约半屏高度，点击打开原图预览。新窗口地址为 `/single/notice/<消息ID>`，仍通过原消息接口验证登录和群聊权限；已撤回或无权访问的消息不能读取。消息正常存储在群聊历史中。发送走现有实时推送；弹出通知仍受客户端权限、会话免打扰和系统通知设置影响。
