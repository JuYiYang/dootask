---
id: app-admin.global-notification.howto
title: 向指定成员发送独立通知
type: howto
feature: app-admin
scope: admin
locale: zh
aliases:
  - 发送通知
  - 全局通知
  - 给自己发送通知
  - 浏览器桌面 App 通知
related_tools: []
related_pages: [application]
prerequisites:
  - 系统管理员权限
negative:
  - 发送通知不创建聊天消息、任务动态或未读记录
  - 浏览器页面关闭或桌面客户端离线时不会补发
  - App 推送服务已受理不表示设备已弹出或用户已读
last_verified: v1.9.36
---

# 向指定成员发送独立通知

打开「应用」→「管理员」→「发送通知」。可跨项目搜索并多选最多 100 个启用的成员账号，也可点「添加自己」。填写纯文字标题（60 字以内）和正文（300 字以内），确认收件人数后发送。

发送结果按收件人列出浏览器、桌面客户端、手机 App 三端状态和原因。多设备时可展开明细。界面会自动查询约一分钟，也可以手动刷新。浏览器和桌面端需要在线，浏览器需要通知权限。旧桌面客户端若没有触发回执，会显示未确认。手机 App 通过已启用并配置的友盟推送服务向已注册且允许通知的设备投递。推送服务受理与客户端触发回执是不同状态。

管理员接口 `POST /api/globalnotification/send` 接收 `request_id`、`title`、`body`、`recipients`。同一发送者使用相同参数和 `request_id` 重试只查询原批次。管理员接口 `GET /api/globalnotification/status` 仅查询本人发出的批次。登录客户端调用 `POST /api/globalnotification/receipt` 时，只能回报发给当前账号且带有对应客户端标识的投递。
