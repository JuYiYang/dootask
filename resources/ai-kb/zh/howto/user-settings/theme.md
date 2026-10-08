---
id: user-settings.theme.howto
title: 切换深色/浅色主题
type: howto
feature: user-settings
scope: end-user
locale: zh
aliases:
  - 改主题
  - 深色模式
  - 暗黑模式
  - dark mode
  - 跟随系统
  - 切换主题
  - 浅色模式
related_tools: []
related_pages: [setting]
prerequisites: []
negative:
  - 浏览器 Web 端仅 Chrome 系（含 Edge）才支持深色切换；Safari / Firefox 老版本无效
  - iOS EEUI 端不支持手动切换主题（按系统设置走，提示「仅 Android 设置支持主题功能」）
  - 「跟随系统」依赖系统 / 浏览器 `prefers-color-scheme`，浏览器不支持时退化为浅色
last_verified: v1.9.36
---

# 切换深色/浅色主题

## 入口

- 桌面端：右上角头像 →「设置」→「主题设置」
- 移动端（Android EEUI）：「我的」→「设置」→「主题设置」
- 客户端：Electron 通过 IPC 同步主题，浏览器通过 localStorage 缓存

## 三种模式

| 模式 | 含义 |
|---|---|
| auto / 跟随系统 | 根据系统或浏览器 `prefers-color-scheme` 自动切换 |
| light / 浅色 | 强制浅色主题 |
| dark / 深色 | 强制深色主题 |

state 中存为 `themeConf`（用户选择）+ `themeName`（实际生效）。

## 操作步骤

1. 进入「主题设置」子页
2. 在「选择主题」下拉选 `auto` / `light` / `dark`
3. 点击「提交」后立即切换；缓存到 localStorage `__system:themeConf__`
4. Electron 客户端会 IPC 通知 preload 池重建，确保新窗口主题一致

## 不支持的环境

- Safari / 旧版 Firefox：无 `prefers-color-scheme` 或不响应主题切换，会弹「仅客户端或 Chrome 浏览器支持主题功能」
- iOS EEUI：不支持手动选主题，提示「仅 Android 设置支持主题功能」
- 部分插件 / 微应用未做深色样式适配，会显示与主站不一致的颜色

## 与高亮 / 颜色字段的关系

主题只影响界面整体配色，不会改：

- 任务卡片颜色（见 [[task.field.color.concept]] 之类的字段）
- 看板列颜色
- 用户自定义头像 / 头像背景

## 账号字体大小

在「主题设置」中选择「字体大小」并提交，可选12至20px整数或「跟随系统」。字号保存在当前账号，普通账号也可调整，仅影响自身；跨设备登录会加载同一设置，其他已打开页面刷新后同步。切换账号或退出登录不会沿用上一账号的个人字号。未设置或选择「跟随系统」时使用系统默认字号（管理员在系统设置→基础设置配置，未配置为14px）。标题、正文和辅助文字按原有比例缩放，富文本显式字号和独立插件页面保持自身设置。

`GET api/appearance/settings`读取当前账号字号，`POST api/appearance/save`提交`font_size`整数或null（恢复系统默认）。须登录，不接受指定其他账号，不能修改他人字号。
