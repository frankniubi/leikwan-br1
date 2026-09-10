# Tenant User Guide Screenshots Design

**Status:** Approved for implementation

**Date:** 2026-08-06

## Goal

把租户知识库从工程说明改成用户可以照着完成任务的操作手册。第一优先是简体中文租户完成首次转发、客户端安装和真实连接验证；英文和繁体中文保持同一事实结构；同时提供一份可投喂给用户 AI 的安全代操作手册。

## User And Success Criteria

目标用户是已经登录 LeiKwan Bridge 租户面板、但不熟悉内部网络术语的用户。用户不需要阅读源代码或猜测隐藏配置，就能完成以下闭环：

1. 找到有效服务并创建第一条 Forward。
2. 在 Windows、Android 或 Linux 中选择正确的 Client 安装路径。
3. 判断面板状态、Client 状态和真实应用请求分别代表什么。
4. 遇到失败时保留现场、收集不含秘密的证据，并知道何时停止操作。

完成标准不是“文章包含更多文字”，而是每个主流程都有当前 UI 的真实截图、明确入口、具体字段、示例值、按钮名称、预期结果和失败回退。

## Scope

### Included

- 重写现有 8 个知识库主题的用户可执行内容，保留现有 slug 和三种 locale。
- 为核心租户流程加入真实页面截图：Getting Started、Create Forward、Client Install、Forwards/Diagnostics、Target Endpoints。
- 修正文档与当前 `master` UI 的术语和路径：
  - 客户端安装必须进入 `Tenant -> Clients -> Install`。
  - 租户创建页选择的是 `Forward Route`，不要求用户选择内部 `Single/Race/Bond`。
  - Target Endpoint 表单包含 `Transport Listen Port`、`Alternate Listen Port` 和 `CF Listen Port` 等实际字段。
  - 排障先执行 `Run Diagnostics`，再查看 `View Diagnostics`。
- 新增 `docs/user/AI-SETUP-HANDOFF.zh-CN.md`，供用户的 AI 逐步代为导航和核对。
- 增加内容、截图、迁移和响应式页面验证。

### Excluded

- 不暴露 Slave、Sidecar、真实 IP、Bearer token、密码、私钥、线路密钥或生产安装命令。
- 不把内部路由组合、供应商拓扑或隐藏 runtime 选项变成租户必填项。
- 不更改转发 API、客户端协议、计费逻辑或权限模型。
- 不用概念图冒充操作截图。Mermaid 只保留为流程概览，不能替代步骤截图。

## Content Architecture

保留现有主题 slug，避免旧收藏和面板链接失效。文章按用户任务组织，而不是按内部模块组织：

1. `getting-started-five-minute-overview`: 五分钟路径和完成判定。
2. `getting-started-first-forward-checklist`: 第一个 Forward 的逐字段操作。
3. `install-client`: 按 Windows、Android、Linux 分支安装 Client。
4. `connect-and-verify`: 同机、跨设备和实际应用协议验证。
5. `troubleshooting-common-mistakes`: 按可见症状排查，包含 Diagnostics 顺序。
6. `connection-modes-single-race-bond`: 解释用户看到的 Forward Route 和结果，不要求用户拼装内部模式。
7. `bond-sidecar-setup`: 仅给确实需要 Target Endpoint 的租户，说明共享 endpoint 的只读边界。
8. `glossary`: 只解释用户会在面板看到的字段和状态。

每篇主流程使用相同的步骤块：

```text
目标 -> 入口 -> 截图和编号 -> 要填的值 -> 点击的按钮 -> 成功证据 -> 失败时停止点
```

截图旁边必须有文字说明，截图本身不承载唯一信息。每个示例值都标注为演示值，避免用户把它误认为自己的目标地址或端口。

## Screenshot Capture And Storage

### Source

截图必须来自当前 `master` 代码实际渲染的页面，而不是手工绘制的 HTML。采集使用脱敏演示租户和本地演示数据库，覆盖真实路由和真实 CSS。演示租户只包含假域名、文档示例端口和不可用的占位目标，不连接生产线路。

### Viewports

- Desktop: 1440 x 960，覆盖左侧租户导航、表单和结果卡片。
- Mobile: 390 x 844，覆盖窄屏导航、表单堆叠和横向滚动表格。

### Asset Layout

```text
master/public/assets/knowledgebase/screenshots/
  zh-Hans/
    01-getting-started.png
    02-forward-form.png
    03-client-install.png
    04-forward-result.png
    05-diagnostics.png
    06-target-endpoint.png
  en/
  zh-TW/
```

同一画面可被三种语言文章复用；如果按钮文字不同，则为该 locale 采集对应截图。文件名只表达步骤，不包含用户、主机或服务 ID。

### Privacy Rules

- 截图中不得出现真实邮箱、密码、token、session、私钥、安装命令、真实 IP、真实域名或生产服务名称。
- 任何动态 ID 都替换为 `123` 等演示值，并在图片旁说明这是演示数据。
- 图片使用非空、描述性 `alt`，并限制最大尺寸，避免移动端撑破文章。
- 发布前通过像素截图检查和文本扫描检查，确认图片目录没有秘密材料。

## Content Refresh And Data Safety

资源 HTML 是版本化源文件，`topics.php` 和 `build_knowledgebase_seed.php` 继续生成三语言 seed。现有 seed 的“已有文章优先”语义不能直接覆盖线上管理员编辑内容，因此新增独立刷新 migration：

1. 每个 locale 记录当前资源正文的 SHA-256 指纹。
2. migration 只在数据库正文等于旧版系统 seed 指纹时更新标题、摘要、正文和更新时间。
3. 数据库正文已被管理员改过时，migration 跳过该文章，并保留后台编辑结果。
4. 新安装仍通过生成的 multilingual seed 获得完整内容；升级环境通过刷新 migration 获得用户手册内容。

刷新 migration 不写入任何凭据，也不依赖外部网络。生成器、资源文件、migration 和回归测试必须在同一提交中保持一致。

## AI Handoff Contract

`docs/user/AI-SETUP-HANDOFF.zh-CN.md` 面向用户自己的 AI，内容包括：

- 目标、前置条件和用户必须亲自确认的事项。
- 可直接导航的页面路径和逐步操作顺序。
- 每一步的成功证据和下一步条件。
- 允许 AI 读取的非敏感信息清单。
- AI 必须要求用户本人粘贴的密码、Bearer token、Linux 安装命令，以及不可保存和不可回显的规则。
- 失败时的只读诊断顺序、脱敏证据格式和明确停止条件。

手册不包含真实账号、固定 token、真实服务器地址或可以直接执行的生产命令。AI 只能辅助导航、填写用户明确提供的值和核对屏幕结果，不能擅自修改高级路由、放开公网端口或重试秘密命令。

## Validation

### Static Checks

- `php -l` 检查新增或修改的 PHP 文件。
- 内容目录测试验证 24 篇文章仍存在，三种 locale 的标题和段落结构对应。
- 内容目录测试验证每个截图 `<img>` 的路径在仓库存在、文件非空、`alt` 非空、宽高稳定，并且只允许知识库截图目录。
- 链接测试只允许知识库主题链接和经过审计的静态租户入口，不允许外部追踪 URL。
- Sanitizer 测试验证图片属性和路径规则仍然生效。

### Browser Checks

- 在脱敏演示租户完成首次 Forward、Client Install、Forwards 和 Diagnostics 流程。
- 对 desktop 和 mobile viewport 各采集一轮截图，检查无横向溢出、按钮可见、字段标签和截图说明不重叠。
- 验证失败分支只显示通用错误和参考 ID，不把秘密或内部拓扑写入文章或截图。

### Release Checks

- 运行 focused knowledgebase tests 和 migration behavior tests。
- 生成 seed 后确认工作树无未生成差异。
- 检查刷新 migration 在“原始正文”和“管理员已编辑正文”两种 fixture 下分别更新和跳过。
- 最终提交只包含用户手册、截图、AI handoff、迁移和对应测试，不带入当前 `frontend-skin-v2` 工作树的无关脏改动。

## Acceptance Criteria

实现完成必须同时满足：

1. 简体中文租户可以从第一篇文章沿着截图和文字完成首次 Forward、Client 安装和真实连接验证。
2. 文章中的每个 UI 名称都能在当前 `master` Controller 或页面中找到对应实现。
3. 三种 locale 的事实和流程一致，只有语言和本地化文案不同。
4. AI handoff 可独立交给用户 AI 使用，并明确秘密材料的停止规则。
5. 线上已有管理员编辑内容不会被刷新 migration 覆盖。
6. focused tests、PHP lint 和桌面/手机截图检查全部通过。
