# DooTask 开发、提交与部署交接

适用项目：集客佳 DooTask fork。本文用于其他 Agent 接手开发、提交或部署；先阅读仓库根目录 `AGENTS.md`，再阅读本文。

**状态核对日期：2026-10-26。本文的分支、提交和待办是当日快照，接手时必须重新核对。**

## 一、项目与环境

| 项目 | 当前值 |
|---|---|
| 本地源码 | `/Users/mebius/Documents/JIKEJIA/ideaCabinet/dootask` |
| fork | `https://github.com/JuYiYang/dootask.git` |
| 上游 | `https://github.com/kuaifan/dootask.git` |
| 本地当前分支 | `feat/default-project-workflow-template` |
| 生产服务器 | `192.168.3.6:2222`，SSH 用户 `geekago` |
| 本机已有 SSH 别名 | `geekago-wsl`；其他电脑应自行配置 |
| 生产源码 | `/home/geekago/workspace/dootask` |
| 生产容器内源码 | `/var/www` |
| 公网 | `https://work.jikejiacn.com/` |
| 主机 HTTP 端口 | `8080`，所以主机 `127.0.0.1:80` 不通不能证明服务停止 |
| 运行环境 | Windows → WSL Ubuntu → Docker Desktop → DooTask |
| 后端 / 前端 | Laravel 13、LaravelS / Swoole、PHP 8.4 / Vue 2、Vite |

当前本地和服务器已部署提交均为：`000158c feat: 新增任务工作日历与加班排期`。

这个提交已推送到 fork 的 `feat/default-project-workflow-template`。服务器检出分支名仍为 `pro`，其 HEAD 已到该提交；`origin/pro` 与功能分支不一定一致。**部署以核实过的目标 SHA 为准，不能根据 `pro` 这个名字直接拉取或覆盖。**

SSH 密码、登录凭证、任务令牌、模型密钥不写入本文。其他电脑需要通过安全渠道取得自己的访问凭证；不要从其他 Agent 的命令历史复制密码。

## 二、服务器目前有哪些待提交功能

截至核对时，服务器有 **2 项功能、19 个已修改文件**，暂存区为空。生成文件不单独算功能。

### 1. AI 专用令牌创建和编辑任务

| 接口 | 当前源码行为 |
|---|---|
| `POST /api/projectaitask/update` | 编辑令牌账号可访问的任务；包含标题、正文、优先级、人员、时间等字段白名单 |
| `POST /api/projectaitask/create_task` | 在令牌所属项目内创建任务，可指定列表、负责人、协助人等 |
| `POST /api/projectaitask/add` | `create_task` 的别名 |

相关文件：

- `app/Http/Controllers/Api/ProjectAiTaskController.php`
- `app/Module/ProjectAiTaskAccess.php`
- `resources/ai-kb/zh/howto/project/ai-task-token.md`
- `routes/api-map.md` 中增加的相应条目

**生效状态：代码已在生产工作目录，而且公网对这三个路径的 GET 检查均返回“请使用 POST 请求”，证明已可路由到新增方法。尚未通过真实写请求完成验收，不能称为已经验收的正式发布。**

继续此功能时优先核对：

- 跨项目读取/编辑范围、项目成员身份、令牌来源项目的待处理任务规则。
- 修改时间是否检查正确的任务时间权限；不能只验证一般编辑权限。
- `desc` 等文档字段是否真正被模型处理，不能以“进入白名单”代替持久化验证。
- 当前更新白名单未包含 `is_overtime`。如要支持专用令牌强制非工作日排期，需要作为该接口功能继续完善；普通任务接口的加班功能已经部署。
- 创建接口与编辑接口的参数、失败时的原子性、返回字段及动态通知是否一致。

这里记录的是待验收点，本次交接不修改这些接口。

### 2. 侧栏任务搜索与定位

当前源码包含：

- 项目侧栏新增搜索输入框。
- 数字先按任务 ID 查找，失败后尝试标题匹配；非数字按完整标题匹配。
- 找到后切换对应项目并打开任务。
- 尝试将任务卡片滚动居中，并闪烁高亮约 2 秒；子任务使用父任务卡片定位。
- 搜索框浅色、深色样式以及 3 条新增前端文案。

相关文件：

- `resources/assets/js/pages/manage.vue`
- `resources/assets/sass/pages/page-manage.scss`
- `resources/assets/sass/dark.scss`
- `language/original-web.txt`、`language/translate.json`
- `public/language/web/*.js`

**生效状态：源码未提交；当前生产 manifest 引用的资源未发现 `menuProjectTaskSearch` 标记，本次工作日历部署没有把该搜索功能构建上线。**

继续此功能时验收：ID/标题查找、同名任务、分页、已归档任务、无权限任务、跨项目切换、子任务定位、深色和移动布局。源码中“全等标题”是客户端对返回列表进行匹配，不要未经验证就保证检索覆盖全部页。

### 3. 附属差异与本地旧产物

- `routes/api-map.md` 的差异来自未提交的 AI 接口；语言文件差异主要来自侧栏搜索文案。
- 本地另外有 `docs/events-map.md` 行号变化和旧构建留下的未跟踪哈希资源；不要视作第三项功能，也不要直接全部提交。
- 本地与服务器的未提交改动需要逐文件比较，不能只看“文件名相同”就假设内容一致。
- 此次交接文档以及根目录阅读入口，是文档改动，应与上述两项待开发功能分开处理。

## 三、其他 Agent 接手时怎么避免互相干扰

### 开始前，只做一次状态盘点

```bash
cd /Users/mebius/Documents/JIKEJIA/ideaCabinet/dootask
cat AGENTS.md
git branch --show-current
git rev-parse HEAD
git status --short
git diff --stat
git diff --cached --stat
```

记录四件事：本次目标、起始 SHA、已有改动、自己预计修改的文件。

**工作区不干净时，先识别现有改动的归属。不要自动暂存、重置、清理或提交别人的工作。**

- 新功能优先在独立 Git 工作树和独立功能分支开发，起点是核实过的已提交版本。独立工作树不会自动包含当前未提交修改。
- 若接手第二节的某一项功能，先将仅属于该功能的修改完整带入自己的工作树，并记录来源；不要搬运整份工作区差异。
- 不同 Agent 可以各自开发；同一文件同时改动时应明确由谁整合，不能静默覆盖后写入的内容。
- **生产环境同一时间只由一个明确的部署执行者更新。** 本项目目前没有自动落实的跨 Agent 部署锁；开始部署前应检查有无正在运行的发布，再使用同一约定的锁或完成交接。不要把“已经加锁”当作既成事实。
- 只做开发的 Agent 不应去改服务器。需要临时借用服务器运行时测试时，使用容器 `/tmp` 的隔离源码和测试数据库。

当前待提交功能的整合应单独完成一次，之后尽量维持“一个功能分支 → 一个验收版本 → 一个部署产物”。不要每次部署都重新处理相同的混合改动。

## 四、开发阶段负责把功能做完整

### 范围与代码规则

- 沿用 `AGENTS.md` 中的目录、控制器冻结、国际化和 ai-kb 规则。
- 新业务编排放入 `app/Module/` 或 `app/Services/`；不要继续向巨型控制器堆方法。
- 表结构变更通过 migration；前端 API 使用现有 `store.dispatch('call', ...)`。
- 用户可见功能或 API 行为变化时，同步 `resources/ai-kb/` 的对应条目，版本以 `package.json` 为准。
- 需要翻译的新文案在开发阶段处理，不能到部署时才临时扩展翻译范围。历史缺失翻译另列待办，不混进本次功能。

### 验证只做与变更有关的部分

```bash
npm run lint
npm run check:lang
./cmd composer stan
```

再执行对应的功能测试：

- 修改 API public 方法或路由：`./cmd artisan doc:api-map`。
- 修改事件收发：`npm run events:map`。
- 修改任务日期：`node tests/Unit/taskWorkCalendar.test.mjs`，以及 PHP `TaskWorkCalendarTest`。
- PHP 测试示例：`./cmd php vendor/bin/phpunit --filter TaskWorkCalendarTest`。**先确认测试数据库安全，不能直接对生产库运行测试、migration refresh、truncate 或回滚测试。**

本机目前有 Node 和前端依赖，没有可直接使用的 PHP / Docker。需要 PHP 时可使用隔离的现有运行时；没有本地 Docker 不是安装新服务、启动新生产容器或跳过检查的理由。

借用 3.6 PHP 容器测试时：源码放 `/tmp`，显式配置独立或内存测试数据库和 array 缓存/会话，确认实际生效；不能读取生产 `.env` 后直接跑测试，也不能让测试产生通知、邮件或数据变更。

开发时不自行启动 dev server、不反复构建生产包。构建、提交、推送、部署、线上验证分别记录，不能相互替代。

### 开发完成时交付一个明确的“可部署版本”

交接至少包含：

1. 功能说明、目标分支、提交 SHA、修改文件范围。
2. 已执行的测试、结果、对应的源码版本或内容校验值；有修改后只补测受影响的部分。
3. migration 清单，明确新增字段/索引与是否改变历史数据。
4. 是否需要生产构建、PHP 平滑重载或配置重载。
5. 已知限制、未完成项、其他 Agent 的改动清单。
6. 如已授权构建，提供该版本 manifest 和资源完整性检查结果，供部署直接复用。

## 五、提交与构建

- 提交格式：`feat: 中文功能说明`、`fix: 中文修复说明`；英文类型前缀保留，正文用中文。
- 按功能拆分提交，不把与本次无关的接口、样式或实验文件一起提交。
- 使用明确路径 `git add -- <本次文件>`；先看暂存区 diff，再 commit。避免 `git add .`、`git add -A`。
- 源码、必要语言产物、ai-kb 和相关构建资源要能追溯到同一明确版本。
- 普通功能部署不等于发布全平台新版本。没有用户要求，不自行升版本号、打 tag、触发应用发布或 iOS 发布。
- 本仓库的 `dootask-release` 技能适用于其描述的正式 `pro` 发布流程；先判断此次任务是不是该类发布。用户已明确授权的提交、构建、部署不需要再次索要相同确认。

生产前端构建使用项目规定入口：

```bash
./cmd prod
```

该命令会处理环境检查并重新生成构建目录。只在已授权的构建环境执行；不能因为需要构建，就修改生产 `.env`、重启数据库或伪造检查结果。无本地运行时时，选用已核实的隔离构建环境，说明依赖来源。

构建完成后核对 `public/manifest.json` 中的资源确实存在。部署不能把其他 Agent 未完成的前端功能顺带打包。旧哈希资源可保留供已打开的页面继续加载，不能在生产发布时直接清空整个 `public`。

**同一份源码和依赖已经构建并验证过，就复用产物。** 只有源码、依赖、构建配置或相关环境发生变化，才重新构建或重复对应验证。构建产物中的模板空格不能通过随意改写压缩文件“修复”，否则会破坏资源哈希。

## 六、部署：少步骤、可追溯、保护现有状态

部署前需要用户授权生产更新；授权已经明确时继续执行，不重复询问。

正常部署流程：**核对目标差异 → 备份 → 更新文件与必要迁移 → 平滑重载 → 验证**。

### 1. 核对并保护

- 读取服务器当前 HEAD、分支、暂存区、工作区及运行状态，对照目标提交。
- 必须明确这次准备带入哪些改动，不随手拉取整条分支的其他新提交。
- 禁止 `git reset --hard`、`git clean -fd`、强制 checkout 或整目录覆盖来解决脏工作区。
- 现有修改与目标文件冲突时，先形成备份并明确整合方案；不能以“已备份”为理由丢弃改动。确实需要用户决策时说明冲突文件和可选方案。
- 正常无冲突时复用仓库现有更新流程。使用 `./cmd update` 前阅读其技能和脚本；**当前脏工作区不适合直接执行，更不能加 `--force`**。它还可能写 `.env` 的 `UPDATE_TIME`，不适合本次要求配置字节不变的场景。
- 目前没有已经固化的一键安全部署工具。`/tmp` 中的临时脚本、临时索引和 SSH 控制连接都不是长期入口，不要求后续 Agent 依赖它们或复刻其复杂操作。

### 2. 保护清单

下列现有文件不得被功能部署覆盖、替换或提交：

```text
/home/geekago/workspace/dootask/.env
/home/geekago/workspace/dootask/compose.override.yaml
/home/geekago/workspace/dootask/doo.so
/home/geekago/workspace/dootask/doo.so.backup.20260923135341
/home/geekago/workspace/dootask/doo.so.backup.20260923135505
/home/geekago/workspace/dootask/LICENSE.backup.20260923135625
/home/geekago/workspace/dootask/config/LICENSE （如存在）
/home/geekago/dootask-persist/doo.so
/home/geekago/workspace/dootask/docker/nginx/default.conf
```

其他现存 `doo.so*`、授权备份、Compose 和 Nginx 配置也应在部署前核对。原来的五个服务器专用文件已登记在 `.gitignore`；保留这些规则，不要 force add。

对应挂载不能调整：

- `/home/geekago/workspace/dootask` → `/var/www`
- `docker/nginx/default.conf` → `/etc/nginx/templates/default.conf.template`
- 持久授权 `/home/geekago/dootask-persist/doo.so` → PHP 容器 `/usr/lib/doo/doo.so`，保持原有只读挂载。

数据库目录、已有数据卷、`public/uploads`、任务正文和附件存储都属于运行数据，不能随代码包覆盖或清理。不要复制真实 `.env` 或凭证到共享构建包。

### 3. 备份与迁移

- 备份服务器已有工作区差异及本次会替换的文件，记录恢复位置。
- 对授权和配置生成 SHA-256 清单，部署后重新比对。
- 有数据库迁移时，先取得有效数据库备份，检查命令成功、压缩完整性及校验值；需要时复制到本机受限目录，不向外分享 SQL。
- 读取待执行 migration，必要时只执行本次指定的 `--path`，避免顺带运行未确认的历史迁移。
- 迁移只按本次功能所需执行。不得初始化新数据库、导入开发数据、重置账号、改任务内容或成员关系来“验证部署”。
- 新字段应先兼容旧代码，再更新代码与资源，避免新代码先访问尚不存在的字段。

工作日历的已部署迁移仅新增 `project_tasks.is_overtime`，不批量改变旧任务日期。它已在 `000158c` 上执行完成，不需要重复建字段。

### 4. 更新与重载

- 部署已验证的代码和构建产物，保持现有授权、配置、上传、数据目录与无关工作区修改。
- 使用新哈希资源，最后切换 manifest；如语言文件需与保留的改动合并，所有语言数组必须由同一份源数据生成，不能只覆盖某个语言的索引文件。
- 正常 PHP 代码发布优先使用已验证的平滑重载入口：

```bash
cd /home/geekago/workspace/dootask
./cmd php bin/laravels reload
```

- 这里是 `bin/laravels reload`，不是 `artisan laravels reload`。检查 reload 输出和健康状态，不能只看命令退出码。
- 如有明确需要完整重启的配置变更，再按项目规则选择 `./cmd php restart`；不应每次更新都重启 Docker、WSL、MariaDB 或整套 Compose。
- 目标代码、线上文件和 Git 基线应对应明确版本；不要掩盖其他未提交修改。Git 底层 `update-ref` / `read-tree` 是此次特殊混改部署用过的手段，不作为日常流程推荐。

### 5. 验证一次，给出证据

最小验证集按变更范围选择：

- 公网首页 HTTP 200、主要容器健康。
- HTML 确实引用目标版本资源，抽查新入口资源哈希。
- 新接口有真实路由，关键规则在实际运行环境下符合预期。
- 授权与配置校验值一致。
- 需保护的任务日期、成员关系等数据，部署前后作适当核对；线上用户的正常并发操作应单独区分，不能因此自动恢复数据库覆盖新数据。

HTTP 200 不等于业务成功；业务接口检查 `ret=1`。GET 路由存在不等于 POST 写入功能已验收；CLI 规则通过也不等于浏览器交互已验收。无写入授权时，不向真实任务发评论、改状态或发测试邮件来证明发布。

验证通过就结束，不继续补做无关重构、多轮相同检查或新的界面优化。

## 七、回退和故障恢复

- 出现问题先确定是前端、PHP、数据库迁移还是基础设施，再回退对应层。
- 代码回退复用备份代码、旧 manifest 和仍保留的旧哈希资源。
- 可兼容的新增字段通常可以暂时保留，避免为了回退页面先删库字段；不要擅自回滚删除用户新写入的数据。
- 数据库恢复有覆盖部署后新数据的风险，必须明确范围和授权，不能自动整库恢复。
- 保留用户已有未提交修改，不用强制拉取代替回退。

服务器已有恢复配置：

- `/home/geekago/dootask-recovery/recover.py`
- `dootask-recovery.service` / `dootask-recovery.timer`
- Windows 计划任务 `DooTask Automatic Recovery`

本轮已确认 WSL timer 启用。2026-10-19 已将检查顺序调整为先 appstore、后 Nginx：appstore 的 Docker Socket 挂载失效会让 Nginx 因找不到上游而反复重启。不要以为这是数据库损坏，不要删数据卷或重新初始化。Windows 完整重启后的恢复效果需要实际复测，不能由“任务已注册”推断一定成功。

## 八、最新一次正式功能部署记录

- 日期：2026-10-26。
- 提交：`000158cf40615b269ffc16336a2b9cbe514a73f3`。
- 内容：单休默认周日、可配双休、法定节假日与补班判断、默认7个工作日、加班放行、前后端日期校验。
- PHP 测试：11 项、34 断言通过；前端日期/快捷创建测试通过；PHPStan、ESLint、语言校验通过。
- 构建：隔离的本次源码完成生产构建，未包含未提交的侧栏搜索。
- 数据迁移：仅新增 `is_overtime`，已完成；PHP 平滑重载成功。
- 线上：HTTP 200、新入口资源哈希一致、11 个受保护文件校验一致，部署时任务日期和成员关系快照一致。
- 服务器备份：`/home/geekago/dootask-deploy-backups/workcalendar-20261026-114548`。
- 本机备份与结果：`/Users/mebius/Documents/Codex/2026-09-28/s-s/outputs/dootask-deploy-20261026/`。其中数据库文件属于敏感数据，不附在共享交接文档中。

不要每次照抄这个 SHA、备份目录、任务数量或测试结论；后续变更必须产生自己的部署记录。

## 九、用户可直接发给新 Agent 的接手说明

```text
请先读取项目 AGENTS.md 和 docs/DEVELOPMENT_DEPLOYMENT.md。
先检查当前分支、HEAD、暂存区和工作区，把已有改动与本次任务分开。
本次任务：<填写功能或修复目标>。
工作范围：<只分析 / 开发并验证 / 提交推送 / 部署到3.6>。
不要自动把开发任务扩大成生产部署；已明确授权的步骤不需要重复确认。
不要覆盖其他 Agent 的未提交修改。提交使用 feat: 中文内容 或 fix: 中文内容。
开发阶段完成对应测试和必要文档；已验证的同一版本和构建产物不要在部署阶段重复处理。
部署时保护授权、配置、现有数据与上传文件，保留备份并验证真实线上结果。
完成时分别说明：改了什么、测试结果、提交SHA、是否推送、是否部署、待办与其他改动。
```
