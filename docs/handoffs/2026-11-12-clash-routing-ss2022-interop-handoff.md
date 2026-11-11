# Handoff: Clash 分流 + SS2022 互通 + 用户功能包（2026-11-11 → 2026-11-12）

**分支**: `feat/clash-routing-user-device-limit`（worktree `C:\Users\frankniubi\Downloads\leikwan-feature`）
**HEAD**: `d3dd335c`（第二轮：F4 面板 + F3 双端 + iOS 内存守护 + Ultra 延迟，见「第二轮（2026-11-12）」）
**CI 状态**: 全绿 —— Android/Windows/Linux/macOS client、Routing E2E、**SS2022 Interop 4/4**（iOS/Android × sing-box/Xray 真实握手成功）
**生产状态**: 面板侧已部署（2026-11-11 04:00Z，备份 clash-devlimit-20261111-035857）；**客户端未发版、分支未合 master**。用户明确要求：现阶段不动生产。

## 整个工作的目的

用户（面板运营者）提出的需求包，按四个 wave 推进：

1. **Clash 兼容分流**（已完成，面板侧已上线）：Android/iOS 支持 Clash 规则粘贴导入 + 面板同步路由方向。
2. **用户级设备上限**（已完成，面板侧已上线）：`users.online_device_limit` 覆盖套餐值。
3. **SS2022 密钥互通**（本次会话完成）：用户导入的节点来自三种服务端生态（sing-box 超长密钥 / Xray raw ASCII / 标准 base64），客户端原来一律连不上；做四端密钥规范化 + 互通 CI 证明。
4. **用户功能包**（F1/F2 已完成；F3/F4 已设计未实施）：iOS「Ultra 授权不可用」修复、隧道内延迟显示、Surge .list 规则导入、用户规则云端同步。

附带：`clash-assessment/` 是 GPL 的 Clash 客户端，仅作语法参考，**不嵌 mihomo、不复制 GPL 代码**（用户确认的路线）。

## 已完成（按 commit 顺序）

### Wave 1-2（上一个会话，已部署面板侧）
- `55466b18` Clash 路由 + 设备上限 + 刷新 + KB（面板+双端）
- `ecc6fe99`/`5058f69f` routing-e2e CI（App 真实生成配置跑真 sing-box core，双端 17/17×2）
- 面板迁移已上生产（只新增文件，无整目录覆盖）

### Wave 3：SS2022 密钥规范化（本次会话核心交付）
- `bc461f25` 四端 normalizer（Android/iOS/Desktop）+ 面板 HostedNodeSpec 放宽 + 单测
- `f8089cdb`→`92a61be5` 十五轮 interop CI 迭代修完所有问题（见下面"踩坑清单"）
- `tools/ss2022-interop/`：harness + 两个 server 配置 + README；CI workflow `ss2022-interop.yml`
- **最终验证**：`{ios, android} × {sing-box 1.13.12, xray v25.9.11}` 全部真实握手 + TCP 往返成功
- `6a0dfc35` 修复了一个**会漏到用户的真 bug**：四端 normalizer 原本输出无 padding base64，而 sing-box 客户端库要求标准 padded——互通测试的价值证明

### Wave 4a：F1 + F2（`5ae1ea92`，随互通 CI 全绿验证）
- **F1 iOS「Ultra 授权不可用」修复**（client 68 的根因）：首次失败在 plan-time grant 上时立即重试（下次尝试先 pull 新 grant），不再等 5-60s 退避；`UltraPolicy.validationFailure()` 记录具体是哪个校验拒绝 + expires_at；重试节奏抽成 Core 的 `UltraRetryPolicy`（可单测）
- **F2 隧道内延迟显示**（iOS + Android）：合成 `probe` urltest 组（成员 [活跃出口, "direct"]，不被任何 route rule/final 引用），libbox group 流把 URLTestDelay 推到 UI。Android：`writeGroups` 原本只进 logcat，现在喂 `TrafficMonitor.reportLatency`；iOS：`StatusMonitor` 订阅 `LibboxCommandGroup`（writeGroups 原是空 stub），经 `TunnelStatusSnapshot.latencyMs` 到 dashboard 的延迟 label。Ultra runtime-only 桥接模式跳过（probe 会测回环）；诚实的 relay-RTT 探测是 phase 2
- 测试：Android 100/100 套件 + Desktop 1652 全绿；iOS 走 CI swift test

### 面板诊断（client 68 / iOS「Ultra 授权不可用」）
根因 = pull 缓存的 ultra_access 过期 + generation ~15-20 分钟轮换（信誉指纹参与 hash）之间的空窗；面板侧零拒绝记录（ultra_lease_refusals 表 client 68 = 0 行）。F1 的立即重试 + 诊断日志就是修复。详见 memory `ios-ultra-unavailable-client68-20261111`。

## 踩坑清单（互通 CI 抓出的 6 个真问题）

1. **iOS Swift `case keyLength + 1...:`** 在 Int switch 里非法（编译错）
2. **iOS `Data(base64Encoded:)` 严格 padding**（43 字符无 padding 返回 nil → 掉进 raw-ASCII 分支）
3. **C# `Convert.FromBase64String` 同样严格 padding**（三端三个语言踩同一坑）
4. **sing-box multi-user 服务端 `password` 字段 = iPSK 单独**（不是 iPSK:uPSK；uPSK 走 `users[]`）——本地拉 sing-shadowsocks@v0.2.8 源码验证
5. **Xray v25.9.11 SS2022 入站严格 base64**（main 分支 ParseKey 的 raw-ASCII 回退是之后才加的）——本地拉 v25.9.11 源码验证
6. **CI artifact 传递**：upload-artifact 显式文件路径不带目录结构；xray chmod 要按 leg 条件执行

**铁律沉淀**：SS2022 密钥一律**标准 padded base64**（`base64.StdEncoding`）。详见 memory `ss2022-key-normalization-interop-green`。

## 第二轮（2026-11-12）：剩余项全部实现

| Commit | 内容 |
|---|---|
| `27aca9a0` | F4 schema：`20260722_add_route_directions_client_rules.sql`（**无 FK**，按 20261012 先例；测试会拒绝 FK）+ schema.sql 镜像 |
| `e9cad232` | F4 PR1：`RouteDirectionService` / `ClientRuleValidator` / ProvisioningService CRUD（sync_version 乐观并发、tombstone、scope 归属）；heartbeat 记录两个 capability；pull 合并五键 + 规则摘要进 `config_revision`；`POST/PUT/DELETE /api/client/client-rules[/{id}]`（pull 同款设备门 + 30/min） |
| `0f27706f` | 运维 CLI `master/scripts/route_directions.php list/save/entitle` |
| `4d01caee` | F4 PR2：`/admin/route-directions`（方向 + ready 绑定、套餐授权、规则列表/删除；CSRF；zh 文案） |
| `09c8f727` | F3 Android：`SurgeListConverter` + `ImportedRuleListStore`（filesDir 文件）+ `RuleListFetcher`；`IMPORTED_LIST` → `type local, format source`；路由页 URL 导入 / 更新；>200 行或含 AND/OR/NOT 的粘贴走列表 |
| `cf95a94c` | F3 iOS：同规格 Core 转换器；app group `user-rule-sets/`（**不在** `rule-sets/`，RuleSetInstaller 会清空它）；`USER-LIST` 规则仅导入器可建；5 语言文案 |
| `8e1ee2bc` | iOS 内存守护：phys_footprint 5 s 采样 + memory-pressure；44 MB 或压力事件 → CloseConnections（20 s 冷却），36 MB 记日志 |
| `ddb24c9b` / `d3dd335c` | F2 phase 2：Ultra 模式（无 probe 组）对正在用的 relay 计时 TCP 握手（RST 也算一个 RTT），两端 |

与批准方案的有意偏差（都写在 commit message 里）：规则摘要进 config_revision；ready 绑定必须同一个 TCP/UDP 端口；URL 之类带冒号的垃圾报 `invalid_destination_ip`；pull 中 route-rule 查询失败不影响 pull。

**验证**：面板全量 447 + 新增 4 个测试通过，16 个失败与基线完全一致（本地缺 artifact / RSA）；Android 102/102 套件；CI 快照 `3d3811aa`（F4+F3）Android / Linux+panel / SS2022 Interop / Routing E2E 全绿，iOS app 构建走 `ci/ios-rule-lists`。

## 仍未做（需要你决定或需要生产）

- **部署 F4 面板**（生产冻结中）：先在 prod-shaped dump 上跑 `route_direction_schema_test.php` + `route_direction_client_rules_test.php`；只新增迁移文件；部署后用 admin 页或 CLI 建方向（ready = helper forward + 同一本地端口）并授权给套餐。
- **客户端发版**（可选更新，绝不强推）：F1/F2/F3/SS2022/内存守护都在分支上，未合 master。
- iOS 大列表（ChinaMax 级）仍由 NE 内的 sing-box 解析进 Go heap；内存守护兜底，Surge 式「主 App 预编译」是后续结构项。
- MTU 9000 未改（无测速数据前不动吞吐）。

## 验证清单（接手后跑一遍）

```bash
# Android
cd leikwan-android && ./gradlew :app:testDebugUnitTest --no-daemon   # 100/100 套件
# Desktop
cd LeikwanBridge.Desktop && dotnet test                               # 1652 个
# 面板
php -d extension=openssl -d extension=pdo_sqlite master/tests/hosted_node_spec_test.php
# iOS —— 本机无 Xcode，靠 CI（codex/macos-client push 触发）
# 互通 —— SS2022 Interop workflow 4/4
```

## 关键文件索引

| 功能 | 文件 |
|---|---|
| SS2022 normalizer | `leikwan-android/.../shadowsocks/SS2022KeyNormalizer.kt`、`ios/Core/.../Shadowsocks/SS2022KeyNormalizer.swift`、`LeikwanBridge.Desktop/.../Shadowsocks/SS2022KeyNormalizer.cs` |
| 互通 harness | `tools/ss2022-interop/{harness.py, server-*.json}` + `.github/workflows/ss2022-interop.yml` |
| F1 重试策略 | `ios/Core/.../Ultra/UltraRetryPolicy.swift`（+测试）、`ios/PacketTunnel/UltraLeaseManager.swift`（catch 块） |
| F1 诊断 | `ios/Core/.../Ultra/UltraModels.swift` `UltraPolicy.build/validationFailure` |
| F2 probe 组 | 两端 `SingboxConfigBuilder`（`latencyProbeOutbound`）；Android `LeikwanVpnService.writeGroups` + `TrafficMonitor`；iOS `StatusMonitor.writeGroups` + `TunnelProtocol.TunnelStatusSnapshot.latencyMs` |
| F2 UI | `ios/App/Views/DashboardView.swift`（LatencyLabel）、`leikwan-android/.../ConnectionsScreen.kt`（ms chip） |
| F3 落点 | 两端 `SingboxConfigBuilder`（F2 刚改过，rebase 后再动）、iOS `AppGroup`（新 userRuleSetDirectory）、`RuleSetInstaller`（勿清用户目录） |
| F4 权威方案 | `docs/superpowers/plans/2026-07-22-backend-admin-route-direction-client-rules.md` |
| F4 面板落点 | `master/src/Controllers/ClientAgentController.php`（heartbeat ~1681、pull ~1083、revision ~1170）、`Bootstrap.php`（路由 ~390） |
| Android 已就绪的 F4 半 | `PanelApi.kt`（client-rules 端点）、`data/clientrules/ClientRouteRuleStore.kt`、`PanelModels.kt`（@SerializedName 契约） |

## 运维注意事项

- 生产冻结令（用户 2026-11-11 下达）在本 handoff 写就时仍有效——不要 SSH 面板/171/任何线上机器
- CI 走 GitHub Actions（sanitized snapshot 流程推 `codex/macos-client`；本机代理端口可能变动——检查 `netstat -ano | grep LISTENING | grep sing-box` 对应端口）
- 本机代理曾从 2080 变 2082（sing-box 重启），git/curl 的 `http.proxy` 参数要跟着改
