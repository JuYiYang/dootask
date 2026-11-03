# 前端事件总线注册表

> **本文件由脚本自动生成，请勿手改。**
>
> - 生成命令: `node scripts/gen-events-map.mjs`
> - 扫描范围: `resources/assets/js` 下所有 `.js` / `.vue` 文件（共 293 个）
> - 事件总线: `resources/assets/js/store/events.js`（mitt 实例）
> - 仅匹配裸 `emitter.emit/on/off(` 调用；`xxx.emitter.emit(`（如 Quill 内部 emitter）不属于本总线，已排除

共 **32** 个静态可解析事件，**133** 处 `emitter.emit/on/off` 调用。

## 事件清单

### `addMeeting`

- **emit（10）**
  - `resources/assets/js/App.vue:451`
  - `resources/assets/js/pages/manage.vue:1220`
  - `resources/assets/js/pages/manage.vue:1227`
  - `resources/assets/js/pages/manage/application.vue:1195`
  - `resources/assets/js/pages/manage/application.vue:1201`
  - `resources/assets/js/pages/manage/components/ChatInput/index.vue:1882`
  - `resources/assets/js/pages/manage/components/DialogView/index.vue:622`
  - `resources/assets/js/pages/manage/components/DialogWrapper.vue:2040`
  - `resources/assets/js/pages/manage/components/DialogWrapper.vue:2048`
  - `resources/assets/js/pages/manage/messenger.vue:1238`
- **on（1）**
  - `resources/assets/js/pages/manage/components/MeetingManager/index.vue:187`
- **off（1）**
  - `resources/assets/js/pages/manage/components/MeetingManager/index.vue:192`

### `addTask`

- **emit（3）**
  - `resources/assets/js/pages/manage/calendar.vue:247`
  - `resources/assets/js/pages/manage/components/DialogWrapper.vue:3536`
  - `resources/assets/js/pages/manage/components/ProjectPanel.vue:1501`
- **on（1）**
  - `resources/assets/js/pages/manage.vue:614`
- **off（1）**
  - `resources/assets/js/pages/manage.vue:632`

### `aiAssistantClosed`

- **emit（1）**
  - `resources/assets/js/components/AIAssistant/index.vue:447`
- **on（1）**
  - `resources/assets/js/components/AIAssistant/float-button.vue:155`
- **off（1）**
  - `resources/assets/js/components/AIAssistant/float-button.vue:164`

### `aiAssistantFloatButtonVisibilityChanged`

- **emit（1）**
  - `resources/assets/js/pages/manage/setting/assistant.vue:59`
- **on（1）**
  - `resources/assets/js/components/AIAssistant/float-button.vue:157`
- **off（1）**
  - `resources/assets/js/components/AIAssistant/float-button.vue:166`

### `aiOperationRequest`

- **emit（1）**
  - `resources/assets/js/store/actions.js:4898`
- **on（1）**
  - `resources/assets/js/components/AIAssistant/float-button.vue:156`
- **off（1）**
  - `resources/assets/js/components/AIAssistant/float-button.vue:165`

### `clickAgainDialog`

- **emit（1）**
  - `resources/assets/js/components/Mobile/Tabbar.vue:188`
- **on（1）**
  - `resources/assets/js/pages/manage/messenger.vue:344`
- **off（1）**
  - `resources/assets/js/pages/manage/messenger.vue:348`

### `createGroup`

- **emit（3）**
  - `resources/assets/js/pages/manage/components/DialogWrapper.vue:2894`
  - `resources/assets/js/pages/manage/components/UserDetail.vue:299`
  - `resources/assets/js/pages/manage/messenger.vue:1243`
- **on（1）**
  - `resources/assets/js/pages/manage.vue:615`
- **off（1）**
  - `resources/assets/js/pages/manage.vue:633`

### `dialogMsgPush`

- **emit（2）**
  - `resources/assets/js/store/actions.js:4947`
  - `resources/assets/js/store/actions.js:4974`
- **on（2）**
  - `resources/assets/js/components/Mobile/Tabbar.vue:50`
  - `resources/assets/js/pages/manage.vue:616`
- **off（2）**
  - `resources/assets/js/components/Mobile/Tabbar.vue:54`
  - `resources/assets/js/pages/manage.vue:634`

### `handleMoveTop`

- **emit（2）**
  - `resources/assets/js/store/actions.js:2818`
  - `resources/assets/js/store/actions.js:3811`
- **on（2）**
  - `resources/assets/js/pages/manage/components/DialogModal.vue:41`
  - `resources/assets/js/pages/manage/components/TaskModal.vue:49`
- **off（2）**
  - `resources/assets/js/pages/manage/components/DialogModal.vue:45`
  - `resources/assets/js/pages/manage/components/TaskModal.vue:53`

### `meetingLeft`

- **emit（1）**
  - `resources/assets/js/App.vue:800`
- **on（1）**
  - `resources/assets/js/pages/manage/components/MeetingManager/index.vue:188`
- **off（1）**
  - `resources/assets/js/pages/manage/components/MeetingManager/index.vue:193`

### `observeMicroApp:close`

- **emit（1）**
  - `resources/assets/js/components/AIAssistant/action-executor.js:234`
- **on（1）**
  - `resources/assets/js/components/MicroApps/index.vue:203`
- **off（1）**
  - `resources/assets/js/components/MicroApps/index.vue:209`

### `observeMicroApp:open`

- **emit（1）**
  - `resources/assets/js/store/actions.js:5484`
- **on（1）**
  - `resources/assets/js/components/MicroApps/index.vue:202`
- **off（1）**
  - `resources/assets/js/components/MicroApps/index.vue:208`

### `observeMicroApp:updatedOrUninstalled`

- **emit（1）**
  - `resources/assets/js/store/mutations.js:462`
- **on（1）**
  - `resources/assets/js/components/MicroApps/index.vue:204`
- **off（1）**
  - `resources/assets/js/components/MicroApps/index.vue:210`

### `openAIAssistant`

- **emit（7）**
  - `resources/assets/js/components/AIAssistant/float-button.vue:504`
  - `resources/assets/js/components/SearchBox.vue:617`
  - `resources/assets/js/pages/manage.vue:1258`
  - `resources/assets/js/pages/manage/components/ChatInput/index.vue:1925`
  - `resources/assets/js/pages/manage/components/ReportDetail.vue:178`
  - `resources/assets/js/pages/manage/components/ReportEdit.vue:270`
  - `resources/assets/js/pages/manage/components/TaskAdd.vue:745`
- **on（1）**
  - `resources/assets/js/components/AIAssistant/index.vue:387`
- **off（1）**
  - `resources/assets/js/components/AIAssistant/index.vue:394`

### `openAIAssistantGlobal`

- **emit（1）**
  - `resources/assets/js/pages/manage.vue:1239`
- **on（1）**
  - `resources/assets/js/components/AIAssistant/float-button.vue:154`
- **off（1）**
  - `resources/assets/js/components/AIAssistant/float-button.vue:163`

### `openDownloadClient`

- **emit（1）**
  - `resources/assets/js/pages/manage.vue:1120`
- **on（1）**
  - `resources/assets/js/components/RightBottom.vue:73`
- **off（1）**
  - `resources/assets/js/components/RightBottom.vue:78`

### `openFavorite`

- **emit（1）**
  - `resources/assets/js/pages/manage/application.vue:1068`
- **on（1）**
  - `resources/assets/js/pages/manage.vue:618`
- **off（1）**
  - `resources/assets/js/pages/manage.vue:636`

### `openManageExport`

- **emit（1）**
  - `resources/assets/js/pages/manage/application.vue:1115`
- **on（1）**
  - `resources/assets/js/pages/manage.vue:620`
- **off（1）**
  - `resources/assets/js/pages/manage.vue:638`

### `openMobileNotification`

- **emit（2）**
  - `resources/assets/js/pages/manage.vue:1606`
  - `resources/assets/js/pages/manage.vue:1644`
- **on（1）**
  - `resources/assets/js/components/Mobile/Notification.vue:38`
- **off（1）**
  - `resources/assets/js/components/Mobile/Notification.vue:42`

### `openProjectInvite`

- **emit（1）**
  - `resources/assets/js/App.vue:463`
- **on（1）**
  - `resources/assets/js/pages/manage/components/ProjectInvite.vue:83`
- **off（1）**
  - `resources/assets/js/pages/manage/components/ProjectInvite.vue:87`

### `openRecent`

- **emit（1）**
  - `resources/assets/js/pages/manage/application.vue:1071`
- **on（1）**
  - `resources/assets/js/pages/manage.vue:619`
- **off（1）**
  - `resources/assets/js/pages/manage.vue:637`

### `openReport`

- **emit（1）**
  - `resources/assets/js/pages/manage/application.vue:1065`
- **on（1）**
  - `resources/assets/js/pages/manage.vue:617`
- **off（1）**
  - `resources/assets/js/pages/manage.vue:635`

### `openSearch`

- **emit（2）**
  - `resources/assets/js/pages/manage.vue:1919`
  - `resources/assets/js/pages/manage/dashboard.vue:787`
- **on（1）**
  - `resources/assets/js/components/SearchBox.vue:133`
- **off（1）**
  - `resources/assets/js/components/SearchBox.vue:137`

### `openUser`

- **emit（4）**
  - `resources/assets/js/components/UserAvatar/index.vue:184`
  - `resources/assets/js/pages/manage/components/DialogWrapper.vue:2963`
  - `resources/assets/js/pages/manage/components/DialogWrapper.vue:4546`
  - `resources/assets/js/pages/manage/messenger.vue:1248`
- **on（1）**
  - `resources/assets/js/pages/manage/components/UserDetail.vue:171`
- **off（1）**
  - `resources/assets/js/pages/manage/components/UserDetail.vue:175`

### `receiveTask`

- **emit（2）**
  - `resources/assets/js/pages/manage/components/ProjectPanel.vue:1939`
  - `resources/assets/js/pages/manage/components/TaskRow.vue:280`
- **on（1）**
  - `resources/assets/js/pages/manage/components/TaskDetail.vue:839`
- **off（1）**
  - `resources/assets/js/pages/manage/components/TaskDetail.vue:846`

### `streamMsgData`

- **emit（1）**
  - `resources/assets/js/store/actions.js:4586`
- **on（1）**
  - `resources/assets/js/pages/manage/components/DialogWrapper.vue:944`
- **off（1）**
  - `resources/assets/js/pages/manage/components/DialogWrapper.vue:954`

### `taskRelationUpdate`

- **emit（1）**
  - `resources/assets/js/store/actions.js:5114`
- **on（1）**
  - `resources/assets/js/pages/manage/components/TaskDetail.vue:840`
- **off（1）**
  - `resources/assets/js/pages/manage/components/TaskDetail.vue:847`

### `taskWorkCalendarChanged`

- **emit（1）**
  - `resources/assets/js/pages/manage/setting/components/SystemWorkCalendar.vue:59`
- **on（1）**
  - `resources/assets/js/mixins/taskWorkCalendar.js:36`
- **off（1）**
  - `resources/assets/js/mixins/taskWorkCalendar.js:38`

### `updateNotification`

- **emit（2）**
  - `resources/assets/js/pages/manage.vue:1117`
  - `resources/assets/js/pages/manage/setting/index.vue:204`
- **on（1）**
  - `resources/assets/js/components/RightBottom.vue:65`
- **off（1）**
  - `resources/assets/js/components/RightBottom.vue:77`

### `useSSOLogin`

- **emit（1）**
  - `resources/assets/js/components/RightBottom.vue:231`
- **on（1）**
  - `resources/assets/js/pages/login.vue:220`
- **off（1）**
  - `resources/assets/js/pages/login.vue:225`

### `userActive`

- **emit（3）**
  - `resources/assets/js/store/actions.js:863`
  - `resources/assets/js/store/actions.js:945`
  - `resources/assets/js/store/actions.js:4886`
- **on（1）**
  - `resources/assets/js/components/UserAvatar/index.vue:43`
- **off（1）**
  - `resources/assets/js/components/UserAvatar/index.vue:47`

### `websocketMsg`

- **emit（1）**
  - `resources/assets/js/store/actions.js:4903`
- **on（2）**
  - `resources/assets/js/pages/manage/components/DialogWrapper.vue:943`
  - `resources/assets/js/pages/manage/components/FileContent.vue:202`
- **off（2）**
  - `resources/assets/js/pages/manage/components/DialogWrapper.vue:955`
  - `resources/assets/js/pages/manage/components/FileContent.vue:225`

## 动态事件名（无法静态解析）

以下调用的第一参数不是字符串字面量，无法静态解析事件名：

- `resources/assets/js/components/MicroApps/index.vue:448` — `emitter.emit(actionName...)`

## 统计

- 事件总数（静态可解析）: **32**
- 只 emit 无 on（疑似死事件）: **0**
- 只 on 无 emit（无人发射）: **0**
- 动态事件名调用: **1**
